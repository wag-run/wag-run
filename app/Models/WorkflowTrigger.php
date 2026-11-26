<?php

namespace App\Models;

use App\Engine\EventTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A published trigger the app listens for: a webhook URL, a form page, a
 * schedule, or another app's events, which it either polls for or receives
 * through a webhook it registered there. Synced from the published graph on
 * every publish, keeping each node's key stable so URLs do not change.
 *
 * @property int $id
 * @property string $workflow_id
 * @property int $workspace_id
 * @property string $node_id
 * @property string $type
 * @property string|null $key
 * @property Carbon|null $next_run_at
 * @property array<string, mixed>|null $state see {@see EventTrigger}
 * @property-read Workflow $workflow
 */
#[Fillable(['workflow_id', 'workspace_id', 'node_id', 'type', 'key', 'next_run_at', 'state'])]
class WorkflowTrigger extends Model
{
    public const WEBHOOK = 'webhook';

    public const FORM = 'form';

    public const SCHEDULE = 'schedule';

    /**
     * Polls another app for new events.
     */
    public const POLL = 'poll';

    /**
     * Receives another app's events through a webhook registered there.
     */
    public const SUBSCRIPTION = 'subscription';

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'state' => 'encrypted:array',
        ];
    }

    /**
     * The address the trigger listens on, built from APP_URL rather than the
     * request, so it is the same whichever hostname the install was reached by.
     */
    public function url(): ?string
    {
        $route = match ($this->type) {
            self::WEBHOOK => 'webhooks.receive',
            self::FORM => 'forms.show',
            self::SUBSCRIPTION => 'integrations.events',
            default => null,
        };

        return $route === null ? null : rtrim((string) config('app.url'), '/').route($route, $this->key, absolute: false);
    }

    /**
     * The trigger node's config in the workflow's published version.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $graph = $this->workflow->publishedVersion?->toGraph();

        return $graph && $graph->has($this->node_id) ? $graph->node($this->node_id)->config : [];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
