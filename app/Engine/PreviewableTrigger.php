<?php

namespace App\Engine;

use App\Models\Workspace;
use RuntimeException;

/**
 * A trigger that can say, from the editor, what a check would find right
 * now, so people can try its settings before publishing. The editor shows
 * a Check now button for it.
 */
interface PreviewableTrigger
{
    /**
     * What a check would find with these settings: how many items the source
     * lists, how many would be skipped, the newest few as a run would receive
     * them, and anything about the settings worth pointing out.
     *
     * @param  array<string, mixed>  $config
     * @return array{total: int, skipped: int, events: list<array<string, mixed>>, warning: string|null}
     *
     * @throws RuntimeException when the source can't be read with these settings
     */
    public function preview(array $config, Workspace $workspace, int $limit = 3): array;
}
