<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\WorkspaceManager;
use Illuminate\Auth\Events\Login;

/**
 * On sign-in, gives someone their first workspace in each organization where
 * they have none yet, see {@see WorkspaceManager::welcome()}.
 */
class GiveNewcomersAWorkspace
{
    public function __construct(protected WorkspaceManager $workspaces) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->workspaces->welcome($event->user);
        }
    }
}
