<?php

namespace Tests\Feature\Engine;

use App\Engine\Redactor;
use App\Models\Credential;
use App\Models\Secret;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class MaskingTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_secret_and_credential_values_are_masked_before_step_rows_are_written(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        Credential::factory()->for($workspace)->create(['config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'ghp_tokenvalue']]);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'leak' => ['emit', ['value' => 'key=sk_live_supersecret token=ghp_tokenvalue']]],
            ['start->leak'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow, ['password' => 'sk_live_supersecret']);

        $leak = $this->stepFor($execution, 'leak');
        $masked = 'key='.Redactor::MASK.' token='.Redactor::MASK;
        $this->assertSame($masked, $leak->input['value']);
        $this->assertSame($masked, $leak->output['value']);
        $this->assertSame(['password' => Redactor::MASK], $execution->input);

        $rows = json_encode($execution->steps()->get(['input', 'output'])->toArray()).json_encode($execution->withoutRelations()->toArray());
        $this->assertStringNotContainsString('sk_live_supersecret', $rows);
        $this->assertStringNotContainsString('ghp_tokenvalue', $rows);
    }

    public function test_short_values_and_other_workspaces_secrets_are_not_masked(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => '42']);
        Secret::factory()->for(Workspace::factory())->create(['value' => 'other-workspace-secret']);

        $redactor = Redactor::forWorkspace($workspace);

        $this->assertSame(['n' => 'answer 42', 'x' => 'other-workspace-secret'], $redactor->mask(['n' => 'answer 42', 'x' => 'other-workspace-secret']));
    }
}
