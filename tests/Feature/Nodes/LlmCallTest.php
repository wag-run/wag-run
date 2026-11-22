<?php

namespace Tests\Feature\Nodes;

use App\Engine\Nodes\Actions\LlmCall;
use App\Enums\ExecutionStatus;
use App\Models\Credential;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class LlmCallTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>  $config
     */
    private function llmWorkflow(array $config, ?Workspace $workspace = null): Workflow
    {
        $workspace ??= Workspace::factory()->create();
        Credential::factory()->for($workspace)->aiProvider('anthropic')->create(['key' => 'claude']);

        return $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'ask' => ['core.llm', ['credential' => 'claude', 'model' => 'claude-sonnet-5-5', ...$config]]],
            ['start->ask'],
        ), $workspace);
    }

    public function test_returns_free_text_for_a_templated_prompt(): void
    {
        AnonymousAgent::fake(['A short summary.']);
        $workflow = $this->llmWorkflow(['system' => 'Be brief.', 'prompt' => 'Summarize order {{ trigger.id }}']);

        $execution = $this->runWorkflow($workflow, ['id' => 42]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame('A short summary.', $this->stepFor($execution, 'ask')->output['text']);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Summarize order 42');
    }

    public function test_returns_structured_data_matching_the_schema(): void
    {
        StructuredAnonymousAgent::fake([['sentiment' => 'positive', 'score' => 9]]);
        $workflow = $this->llmWorkflow([
            'prompt' => 'Rate this review',
            'output' => 'structured',
            'schema' => [['name' => 'sentiment', 'type' => 'string'], ['name' => 'score', 'type' => 'integer']],
        ]);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['sentiment' => 'positive', 'score' => 9], $this->stepFor($execution, 'ask')->output['data']);
    }

    public function test_structured_fields_become_outputs_for_the_data_picker(): void
    {
        $outputs = app(LlmCall::class)->outputs([
            'output' => 'structured',
            'schema' => [['name' => 'sentiment', 'type' => 'string'], ['name' => 'tags', 'type' => 'array']],
        ]);

        $this->assertSame(['sentiment', 'tags'], array_map(fn ($child) => $child->name, $outputs[0]->children));
        $this->assertSame('array', $outputs[0]->children[1]->type);
    }

    public function test_requires_an_ai_provider_key_from_the_workspace(): void
    {
        AnonymousAgent::fake();
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['key' => 'not_ai']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'ask' => ['core.llm', ['credential' => 'not_ai', 'model' => 'm', 'prompt' => 'hi']]],
            ['start->ask'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Choose an AI provider key from this workspace\'s authentication settings.', $execution->error['message']);
        AnonymousAgent::assertNeverPrompted();
    }
}
