<?php

namespace App\Services;

use App\Engine\Nodes\Triggers\FormTrigger;
use App\Engine\Nodes\Triggers\ScheduleTrigger;
use App\Engine\Nodes\Triggers\WebhookTrigger;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Support\Str;

/**
 * Keeps a workflow's webhook URLs, form pages and schedules in step with its
 * published version.
 */
class TriggerSync
{
    /**
     * @var array<string, string>
     */
    public const TYPES = [
        WebhookTrigger::class => WorkflowTrigger::WEBHOOK,
        FormTrigger::class => WorkflowTrigger::FORM,
        ScheduleTrigger::class => WorkflowTrigger::SCHEDULE,
    ];

    public function sync(Workflow $workflow): void
    {
        $graph = $workflow->publishedVersion?->toGraph();
        $types = collect(self::TYPES)->mapWithKeys(fn (string $type, string $class) => [$class::type() => $type]);
        $kept = [];

        foreach ($graph->nodes ?? [] as $node) {
            $type = $types[$node->type] ?? null;

            if (! $type) {
                continue;
            }

            $trigger = WorkflowTrigger::query()->firstOrNew(['workflow_id' => $workflow->id, 'node_id' => $node->id]);

            if ($trigger->type !== $type) {
                $trigger->key = $type === WorkflowTrigger::SCHEDULE ? null : Str::random(40);
            }

            $trigger->fill([
                'workspace_id' => $workflow->workspace_id,
                'type' => $type,
                'next_run_at' => $type === WorkflowTrigger::SCHEDULE ? ScheduleTrigger::nextRunAfter($node->config, now()) : null,
            ])->save();

            $kept[] = $trigger->id;
        }

        $workflow->hasMany(WorkflowTrigger::class)->whereNotIn('id', $kept)->delete();
    }
}
