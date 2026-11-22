<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Graph\WorkflowGraph;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_publishing_creates_numbered_versions_and_rollback_republishes_an_old_graph(): void
    {
        $editor = User::factory()->create();
        $service = app(Workflows::class);
        $workflow = $service->create(Workspace::factory()->create(), 'Orders', $editor);

        $service->saveDraft($workflow, WorkflowGraph::fromArray($this->graph(['start' => 'start'])));
        $v1 = $service->publish($workflow, $editor);
        $service->saveDraft($workflow, WorkflowGraph::fromArray($this->graph(['start' => 'start', 'a' => 'emit'], ['start->a'])));
        $this->assertTrue($workflow->refresh()->hasUnpublishedChanges());
        $v2 = $service->publish($workflow, $editor);

        $v3 = $service->rollback($workflow, $v1, $editor);

        $workflow->refresh();
        $this->assertSame([1, 2, 3], $workflow->versions()->orderBy('number')->pluck('number')->all());
        $this->assertSame($v3->id, $workflow->published_version_id);
        $this->assertEquals($v1->graph, $v3->graph);
        $this->assertEquals($v1->graph, $workflow->draft_graph);
        $this->assertSame('Rollback to version 1', $v3->note);
        $this->assertNotSame($v2->id, $workflow->published_version_id);
        $this->assertSame(['workflow.created', 'workflow.published', 'workflow.published', 'workflow.rolled_back'],
            AuditLog::orderBy('id')->pluck('action')->all());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidGraphs(): array
    {
        return [
            'no trigger' => [['nodes' => [['id' => 'a', 'type' => 'test.emit']], 'edges' => []], 'Add a trigger to start the workflow.'],
            'unknown node type' => [['nodes' => [['id' => 's', 'type' => 'test.start'], ['id' => 'x', 'type' => 'acme.missing', 'name' => 'Mystery']], 'edges' => []], 'Node "Mystery" has an unknown type [acme.missing].'],
            'edge to a missing node' => [['nodes' => [['id' => 's', 'type' => 'test.start']], 'edges' => [['id' => 'e1', 'source' => 's', 'target' => 'ghost']]], 'Connection [e1] points to a node that does not exist.'],
            'unknown port' => [['nodes' => [['id' => 's', 'type' => 'test.start'], ['id' => 'r', 'type' => 'test.route', 'name' => 'Check'], ['id' => 'a', 'type' => 'test.emit']], 'edges' => [['id' => 'e1', 'source' => 's', 'target' => 'r'], ['id' => 'e2', 'source' => 'r', 'source_port' => 'maybe', 'target' => 'a']]], 'Node "Check" has no output named "maybe".'],
            'error port on a trigger' => [['nodes' => [['id' => 's', 'type' => 'test.start', 'name' => 'Start'], ['id' => 'a', 'type' => 'test.emit']], 'edges' => [['id' => 'e1', 'source' => 's', 'source_port' => 'error', 'target' => 'a']]], 'Node "Start" has no error output.'],
            'cycle' => [['nodes' => [['id' => 's', 'type' => 'test.start'], ['id' => 'a', 'type' => 'test.emit'], ['id' => 'b', 'type' => 'test.emit']], 'edges' => [['id' => 'e1', 'source' => 's', 'target' => 'a'], ['id' => 'e2', 'source' => 'a', 'target' => 'b'], ['id' => 'e3', 'source' => 'b', 'target' => 'a']]], 'Connections cannot form a cycle. Use a For loop node to repeat steps.'],
            'node id unusable in templates' => [['nodes' => [['id' => 'my-node', 'type' => 'test.start']], 'edges' => []], 'Node id [my-node] must start with a letter or underscore and use only letters, numbers and underscores, so templates can reference it.'],
            'edge into a trigger' => [['nodes' => [['id' => 's', 'type' => 'test.start', 'name' => 'Start'], ['id' => 'a', 'type' => 'test.emit']], 'edges' => [['id' => 'e1', 'source' => 'a', 'target' => 's']]], 'Trigger "Start" cannot have incoming connections.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    #[DataProvider('invalidGraphs')]
    public function test_refuses_to_publish_a_graph_that_cannot_run(array $graph, string $message): void
    {
        $workflow = Workflow::factory()->create(['draft_graph' => $graph]);

        try {
            app(Workflows::class)->publish($workflow);
            $this->fail('Publishing should have been refused.');
        } catch (ValidationException $e) {
            $this->assertContains($message, $e->errors()['graph']);
        }

        $this->assertNull($workflow->refresh()->published_version_id);
    }

    public function test_cannot_start_a_workflow_that_was_never_published(): void
    {
        $this->expectException(RuntimeException::class);

        app(Engine::class)->start(Workflow::factory()->create());
    }
}
