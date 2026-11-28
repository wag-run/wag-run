<?php

namespace Tests\Feature\Editor;

use App\Engine\Redactor;
use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class MaskingInEditorTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
        $this->workspace = Workspace::factory()->create();
        $this->workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.webhook_trigger', 'login' => ['capture', ['value' => '{{ trigger.body.next_page_token }}']]],
            ['start->login'],
        ), $this->workspace);
    }

    private function editor(WorkspaceRole $role = WorkspaceRole::Editor): Testable
    {
        $user = User::factory()->memberOf($this->workspace, $role)->create();

        return Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $this->workspace, 'workflow' => $this->workflow]);
    }

    public function test_the_masking_tab_lists_what_the_last_run_masked_and_why_with_a_count(): void
    {
        $this->editor()->call('selectNode', 'start')->assertSee(__('workflows.edit.masking.no_run_yet'));

        $this->runWorkflow($this->workflow, ['body' => ['token' => 'tok-1', 'items' => [['api_key' => 'k-1'], ['api_key' => 'k-2']], 'next_page_token' => 'p-2'], 'headers' => ['Authorization' => 'Bearer abcdefghij']]);

        $editor = $this->editor()->call('selectNode', 'start');
        $this->assertSame([
            ['body.token', 'Built-in name: token', true],
            ['body.items[*].api_key', 'Built-in name: api_key', true],
            ['body.next_page_token', 'Built-in name: *_token', true],
            ['headers.Authorization', 'Built-in header: Authorization', true],
        ], array_map(fn (array $row) => [$row['path'], $row['reason'], $row['by_name']], $editor->get('lastRunMasking')['rows']));
        $editor->assertSee('Masking · 4')->assertSeeHtmlInOrder(['data-test="inspector-panel-masking"', 'body.items[*].api_key', 'data-test="dont-mask"']);

        $this->editor()->call('selectNode', 'login')->assertSet('lastRunMasking.rows.0.path', 'token')->assertSet('lastRunMasking.count', 1);
    }

    public function test_dont_mask_adds_the_path_to_never_mask_which_the_next_run_follows(): void
    {
        $input = ['body' => ['token' => 'tok-1', 'next_page_token' => 'cursor-2']];
        $this->runWorkflow($this->workflow, $input);

        $editor = $this->editor()->call('selectNode', 'start')->call('dontMask', 1);

        $this->assertSame(['never' => ['body.next_page_token']], $this->workflow->refresh()->draft()->node('start')->masking);
        $editor->assertSet('lastRunMasking.count', 1)->assertSee(__('workflows.edit.masking.turned_off_by_never_mask'));
        $this->assertSame(['node' => 'start', 'list' => 'never', 'path' => 'body.next_page_token'], AuditLog::query()->where('action', 'workflow.masking_added')->sole()->metadata);

        app(Workflows::class)->publish($this->workflow->refresh());
        $run = $this->runWorkflow($this->workflow->refresh(), $input);
        $this->assertSame(['body' => ['token' => Redactor::MASK, 'next_page_token' => 'cursor-2']], $run->input);
    }

    public function test_always_and_never_mask_paths_are_added_checked_and_removed(): void
    {
        $editor = $this->editor()->call('selectNode', 'start')
            ->set('newMaskPath.always', 'body..token')->call('addMaskPath', 'always')->assertHasErrors('newMaskPath.always')
            ->set('newMaskPath.always', ' data[*]["license.key"] ')->call('addMaskPath', 'always')->assertHasNoErrors()
            ->set('newMaskPath.never', 'cursor_token')->call('addMaskPath', 'never')
            ->assertSet('newMaskPath', ['always' => '', 'never' => ''])
            ->assertSeeHtmlInOrder(['data-test="mask-always"', 'data[*][&quot;license.key&quot;]', 'data-test="mask-never"', 'cursor_token']);

        $this->assertSame(['always' => ['data[*]["license.key"]'], 'never' => ['cursor_token']], $this->workflow->refresh()->draft()->node('start')->masking);

        $editor->call('removeMaskPath', 'always', 0);
        $this->assertSame(['never' => ['cursor_token']], $this->workflow->refresh()->draft()->node('start')->masking);
        $this->assertSame(
            ['workflow.masking_added', 'workflow.masking_added', 'workflow.masking_removed'],
            AuditLog::query()->where('workspace_id', $this->workspace->id)->orderBy('id')->pluck('action')->filter(fn (string $action) => str_starts_with($action, 'workflow.masking'))->values()->all(),
        );
    }

    public function test_viewers_see_the_masking_tab_but_cannot_change_it(): void
    {
        $this->runWorkflow($this->workflow, ['body' => ['token' => 'tok-1']]);

        $this->editor(WorkspaceRole::Viewer)->call('selectNode', 'start')
            ->assertSeeHtmlInOrder(['data-test="inspector-panel-masking"', '<fieldset class="min-w-0 space-y-6" disabled>', 'body.token'])
            ->assertDontSeeHtml('data-test="dont-mask"')
            ->assertDontSeeHtml('data-test="mask-always-input"')
            ->call('addMaskPath', 'always')
            ->assertForbidden();
    }
}
