<?php

namespace Database\Factories;

use App\Engine\Graph\WorkflowGraph;
use App\Enums\ConcurrencyPolicy;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(3, true),
            'concurrency' => ConcurrencyPolicy::Allow,
            'draft_graph' => WorkflowGraph::empty()->toArray(),
        ];
    }

    /**
     * Use a graph as the draft and publish it as version 1, without validation.
     *
     * @param  array<string, mixed>  $graph
     */
    public function published(array $graph): static
    {
        return $this->state(['draft_graph' => $graph])->afterCreating(function (Workflow $workflow) use ($graph) {
            $version = $workflow->versions()->create(['number' => 1, 'graph' => $graph]);
            $workflow->forceFill(['published_version_id' => $version->id])->save();
        });
    }

    public function concurrency(ConcurrencyPolicy $policy): static
    {
        return $this->state(['concurrency' => $policy]);
    }
}
