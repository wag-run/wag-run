<?php

namespace Tests\Feature\Nodes;

use App\Engine\Graph\WorkflowGraph;
use App\Engine\Nodes\Triggers\ManualTrigger;
use App\Engine\Nodes\Triggers\ScheduleTrigger;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\Secret;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use App\Services\Workflows;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class TriggersTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>  $graph
     */
    private function publish(array $graph, ?Workspace $workspace = null): Workflow
    {
        $workflow = Workflow::factory()->for($workspace ?? Workspace::factory())->create(['draft_graph' => $graph]);
        app(Workflows::class)->publish($workflow);

        return $workflow->refresh();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function webhook(array $config = [], ?Workspace $workspace = null): WorkflowTrigger
    {
        $workflow = $this->publish($this->graph(
            ['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none', ...$config]], 'echo' => ['core.compose', ['template' => '{{ trigger.body.order_id }}']]],
            ['hook->echo'],
        ), $workspace);

        return $workflow->triggers()->sole();
    }

    public function test_publishing_registers_stable_webhook_and_form_urls_and_drops_removed_ones(): void
    {
        $graph = $this->graph([
            'hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']],
            'form' => ['core.form_trigger', ['title' => 'Signup', 'access' => 'public', 'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'email']]]],
        ]);
        $workflow = $this->publish($graph);
        $keys = $workflow->triggers()->pluck('key', 'node_id')->all();

        $this->assertSame(['form', 'hook'], array_keys(collect($keys)->sortKeys()->all()));
        $this->assertSame(route('webhooks.receive', $keys['hook']), $workflow->triggers()->where('node_id', 'hook')->sole()->url());

        app(Workflows::class)->publish($workflow);
        $this->assertSame($keys, $workflow->triggers()->pluck('key', 'node_id')->all(), 'keys survive republishing');

        app(Workflows::class)->saveDraft($workflow, WorkflowGraph::fromArray($this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]])));
        app(Workflows::class)->publish($workflow);
        $this->assertSame(['hook'], $workflow->triggers()->pluck('node_id')->all());
    }

    public function test_webhook_starts_a_run_with_the_request_as_input(): void
    {
        $trigger = $this->webhook();

        $response = $this->postJson(route('webhooks.receive', $trigger->key).'?source=shop', ['order_id' => 991], ['Authorization' => 'Bearer hidden']);

        $execution = Execution::sole();
        $response->assertAccepted()->assertJson(['execution_id' => $execution->id]);
        $this->assertSame('webhook', $execution->trigger_type);
        $this->assertSame(['order_id' => 991], $execution->input['body']);
        $this->assertSame(['source' => 'shop'], $execution->input['query']);
        $this->assertArrayNotHasKey('authorization', $execution->input['headers']);
        $this->assertSame(991, $this->stepFor($execution, 'echo')->output['value']);
    }

    public function test_webhook_rejects_unknown_urls_and_other_methods(): void
    {
        $trigger = $this->webhook();

        $this->postJson(route('webhooks.receive', 'nope'))->assertNotFound();
        $this->getJson(route('webhooks.receive', $trigger->key))->assertStatus(405);
        $this->assertSame(0, Execution::count());
    }

    public function test_webhook_token_verification(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'hook_token', 'value' => 'tkn-1234567']);
        $trigger = $this->webhook(['verification' => 'token', 'secret' => 'hook_token'], $workspace);
        $url = route('webhooks.receive', $trigger->key);

        $this->postJson($url, [], ['X-Webhook-Token' => 'wrong'])->assertForbidden();
        $this->postJson($url)->assertForbidden();
        $this->postJson($url, [], ['X-Webhook-Token' => 'tkn-1234567'])->assertAccepted();
    }

    public function test_webhook_signature_verification(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'signing', 'value' => 'whsec_abc123']);
        $trigger = $this->webhook(['verification' => 'signature', 'secret' => 'signing', 'header' => 'X-Hub-Signature-256'], $workspace);
        $body = json_encode(['order_id' => 5]);
        $url = route('webhooks.receive', $trigger->key);

        $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong')], content: $body)->assertForbidden();
        $this->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'whsec_abc123')], content: $body)->assertAccepted();
    }

    public function test_webhook_ip_allowlist(): void
    {
        $trigger = $this->webhook(['verification' => 'ip', 'allowed_ips' => "10.0.0.0/8\n192.168.1.5"]);
        $url = route('webhooks.receive', $trigger->key);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson($url)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.5.6'])->postJson($url)->assertAccepted();
    }

    public function test_schedule_starts_runs_when_due_and_moves_to_the_next_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 08:59:30', 'UTC'));
        $workflow = $this->publish($this->graph(['tick' => ['core.schedule_trigger', ['mode' => 'cron', 'cron' => '0 9 * * 1-5', 'timezone' => 'UTC']]]));
        $trigger = $workflow->triggers()->sole();
        $this->assertSame('2026-11-23 09:00:00', $trigger->next_run_at->toDateTimeString());

        $this->artisan('workflows:run-schedules')->assertSuccessful();
        $this->assertSame(0, Execution::count(), 'not due yet');

        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:10', 'UTC'));
        $this->artisan('workflows:run-schedules')->expectsOutputToContain('Started 1 scheduled run(s).');

        $execution = Execution::sole();
        $this->assertSame('schedule', $execution->trigger_type);
        $this->assertSame('2026-11-23T09:00:00+00:00', $execution->input['scheduled_at']);
        $this->assertSame('2026-11-24 09:00:00', $trigger->refresh()->next_run_at->toDateTimeString());
    }

    public function test_cron_schedules_honor_their_time_zone_and_intervals_count_from_now(): void
    {
        $after = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');

        $this->assertSame('2026-11-23 14:00:00', ScheduleTrigger::nextRunAfter(['mode' => 'cron', 'cron' => '0 9 * * *', 'timezone' => 'America/New_York'], $after)->toDateTimeString());
        $this->assertSame('2026-11-23 12:15:00', ScheduleTrigger::nextRunAfter(['mode' => 'interval', 'interval_minutes' => 15], $after)->toDateTimeString());
        $this->assertNull(ScheduleTrigger::nextRunAfter(['mode' => 'cron', 'cron' => 'not cron'], $after));
    }

    /**
     * @param  'public'|'members'  $access
     */
    private function form(string $access = 'public', ?Workspace $workspace = null): WorkflowTrigger
    {
        return $this->publish($this->graph(['signup' => ['core.form_trigger', [
            'title' => 'Join the beta',
            'access' => $access,
            'success_message' => 'You are on the list.',
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'plan', 'label' => 'Plan', 'type' => 'select', 'options' => ['free' => 'Free', 'pro' => 'Pro']],
            ],
        ]]]), $workspace)->triggers()->sole();
    }

    public function test_public_form_renders_validates_and_starts_a_run(): void
    {
        $trigger = $this->form();

        $this->get(route('forms.show', $trigger->key))->assertOk()->assertSee('Join the beta')->assertSee('Email');

        Livewire::test('pages::forms.show', ['key' => $trigger->key])
            ->set('values.email', 'not-an-email')
            ->set('values.plan', 'enterprise')
            ->call('submit')
            ->assertHasErrors(['values.email', 'values.plan']);
        $this->assertSame(0, Execution::count());

        Livewire::test('pages::forms.show', ['key' => $trigger->key])
            ->set('values.email', 'ada@example.com')
            ->set('values.plan', 'pro')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('You are on the list.');

        $execution = Execution::sole();
        $this->assertSame('form', $execution->trigger_type);
        $this->assertSame(['email' => 'ada@example.com', 'plan' => 'pro'], $execution->input['values']);
    }

    public function test_members_only_form_requires_a_workspace_member(): void
    {
        $workspace = Workspace::factory()->create();
        $trigger = $this->form('members', $workspace);

        $this->get(route('forms.show', $trigger->key))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->installMember()->create())->get(route('forms.show', $trigger->key))->assertForbidden();
        $this->actingAs(User::factory()->memberOf($workspace, WorkspaceRole::Guest)->create())->get(route('forms.show', $trigger->key))->assertForbidden();
        $this->actingAs(User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create())->get(route('forms.show', $trigger->key))->assertOk();
    }

    public function test_unknown_form_is_not_found(): void
    {
        $this->get(route('forms.show', 'missing'))->assertNotFound();
    }

    public function test_manual_trigger_declares_rules_for_its_inputs(): void
    {
        $rules = ManualTrigger::rules(['fields' => [
            ['name' => 'customer_id', 'type' => 'number', 'required' => true],
            ['name' => 'note', 'type' => 'text'],
        ]]);

        $this->assertSame(['customer_id' => ['required', 'numeric'], 'note' => ['nullable', 'string', 'max:10000']], $rules);
    }

    public function test_every_workflow_can_be_run_manually_from_its_first_trigger(): void
    {
        $workflow = $this->publish($this->graph(
            ['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'echo' => ['core.compose', ['template' => '{{ trigger.body.x }}']]],
            ['hook->echo'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['x' => 'manual']]);

        $this->assertSame('manual', $this->stepFor($execution, 'echo')->output['value']);
    }
}
