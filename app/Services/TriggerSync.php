<?php

namespace App\Services;

use App\Engine\EventTrigger;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Triggers\FormTrigger;
use App\Engine\Nodes\Triggers\ScheduleTrigger;
use App\Engine\Nodes\Triggers\WebhookTrigger;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a workflow's webhook URLs, form pages, schedules and event triggers
 * in step with its published version.
 *
 * An event trigger ({@see EventTrigger}) that receives webhooks registers
 * one with the other app once the publish is committed, and removes it when
 * the trigger changes or goes away. A polling one starts polling right
 * away, and forgets what it has seen when its settings change.
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

    public function __construct(protected NodeRegistry $registry) {}

    public function sync(Workflow $workflow): void
    {
        $graph = $workflow->publishedVersion?->toGraph();
        $types = collect(self::TYPES)->mapWithKeys(fn (string $type, string $class) => [$class::type() => $type]);
        $kept = [];
        $retiring = [];
        $subscribing = [];
        $moving = [];

        foreach ($graph->nodes ?? [] as $node) {
            $listener = $this->eventTrigger($node->type);
            $type = $types[$node->type] ?? match (true) {
                $listener === null => null,
                $listener->listensBy($node->config) === EventTrigger::WEBHOOK => WorkflowTrigger::SUBSCRIPTION,
                default => WorkflowTrigger::POLL,
            };

            if (! $type) {
                continue;
            }

            $trigger = WorkflowTrigger::query()->firstOrNew(['workflow_id' => $workflow->id, 'node_id' => $node->id]);
            $typeChanged = $trigger->type !== $type;

            if ($typeChanged) {
                $trigger->key = in_array($type, [WorkflowTrigger::WEBHOOK, WorkflowTrigger::FORM, WorkflowTrigger::SUBSCRIPTION], true) ? Str::random(40) : null;
            }

            $nextRunAt = match ($type) {
                WorkflowTrigger::SCHEDULE => ScheduleTrigger::nextRunAfter($node->config, now()),
                WorkflowTrigger::POLL => $typeChanged ? now() : ($trigger->next_run_at ?? now()),
                default => null,
            };

            if ($listener) {
                $fingerprint = md5((string) json_encode($node->config));

                if ($typeChanged || ($trigger->state['fingerprint'] ?? null) !== $fingerprint) {
                    $retiring[] = $trigger->state;
                    $trigger->state = ['fingerprint' => $fingerprint, 'node_type' => $node->type];
                }

                if ($type === WorkflowTrigger::SUBSCRIPTION && ! isset($trigger->state['subscription'])) {
                    $subscribing[] = [$trigger, $node->config];
                } elseif ($type === WorkflowTrigger::SUBSCRIPTION) {
                    $moving[] = $trigger;
                }
            } else {
                $retiring[] = $trigger->state;
                $trigger->state = null;
            }

            $trigger->fill([
                'workspace_id' => $workflow->workspace_id,
                'type' => $type,
                'next_run_at' => $nextRunAt,
            ])->save();

            $kept[] = $trigger->id;
        }

        $removed = $workflow->hasMany(WorkflowTrigger::class)->whereNotIn('id', $kept)->get();
        array_push($retiring, ...$removed->pluck('state')->all());
        WorkflowTrigger::query()->whereKey($removed->modelKeys())->delete();

        $workspace = $workflow->workspace;
        DB::afterCommit(function () use ($workspace, $retiring, $subscribing, $moving) {
            $this->unsubscribe($workspace, $retiring);

            foreach ($subscribing as [$trigger, $config]) {
                $this->subscribe($trigger, $config);
            }

            foreach ($moving as $trigger) {
                $this->moveWebhook($trigger);
            }
        });
    }

    /**
     * Register a trigger's webhook again when its URL has changed since it
     * was registered, as when APP_URL changes. The new webhook is registered
     * before the old one is removed, and the old one is kept if registering
     * fails, so the trigger is never left with none. A webhook registered
     * before its URL was recorded is registered again once.
     */
    protected function moveWebhook(WorkflowTrigger $trigger): void
    {
        $state = $trigger->state ?? [];
        $url = $trigger->url();
        $listener = $this->eventTrigger((string) ($state['node_type'] ?? ''));

        if ($trigger->type !== WorkflowTrigger::SUBSCRIPTION || ! $listener || $url === null
            || ! isset($state['subscription']) || ($state['url'] ?? null) === $url) {
            return;
        }

        $workspace = $trigger->workflow->workspace;
        $secret = Str::random(40);

        try {
            $subscription = $listener->subscribe((array) ($state['config'] ?? []), $workspace, $url, $secret);
        } catch (Throwable $e) {
            $trigger->state = [...$state, 'error' => $e->getMessage()];
            $trigger->save();

            return;
        }

        $trigger->state = [...$state, 'secret' => $secret, 'subscription' => $subscription, 'url' => $url, 'error' => null];
        $trigger->save();
        $this->unsubscribe($workspace, [$state]);
    }

    /**
     * Remove the webhooks a workflow registered with other apps, before the
     * workflow is deleted.
     */
    public function forget(Workflow $workflow): void
    {
        $this->unsubscribe($workflow->workspace, $workflow->hasMany(WorkflowTrigger::class)->get()->pluck('state')->all());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function subscribe(WorkflowTrigger $trigger, array $config): void
    {
        $listener = $this->eventTrigger((string) ($trigger->state['node_type'] ?? ''));
        $url = $trigger->url();

        if (! $listener || $url === null) {
            return;
        }

        $secret = Str::random(40);

        try {
            $subscription = $listener->subscribe($config, $trigger->workflow->workspace, $url, $secret);
            $trigger->state = [...$trigger->state, 'secret' => $secret, 'subscription' => $subscription, 'config' => $config, 'url' => $url, 'error' => null];
        } catch (Throwable $e) {
            $trigger->state = [...$trigger->state, 'error' => $e->getMessage()];
        }

        $trigger->save();
    }

    /**
     * Remove the webhooks the triggers with these states registered.
     *
     * @param  array<int, mixed>  $states  trigger states, as stored; a trigger without one has null
     */
    protected function unsubscribe(Workspace $workspace, array $states): void
    {
        foreach ($states as $state) {
            if (! is_array($state)) {
                continue;
            }

            $listener = $this->eventTrigger((string) ($state['node_type'] ?? ''));

            if (! $listener || ! isset($state['subscription'])) {
                continue;
            }

            try {
                $listener->unsubscribe((array) ($state['config'] ?? []), $workspace, (array) $state['subscription']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    protected function eventTrigger(string $type): ?EventTrigger
    {
        if ($type === '' || ! $this->registry->has($type)) {
            return null;
        }

        $node = $this->registry->make($type);

        return $node instanceof EventTrigger ? $node : null;
    }
}
