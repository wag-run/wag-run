<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CancelRun;
use App\Mcp\Tools\GetRun;
use App\Mcp\Tools\GetWorkflow;
use App\Mcp\Tools\ListRuns;
use App\Mcp\Tools\ListWorkflows;
use App\Mcp\Tools\ListWorkspaces;
use App\Mcp\Tools\StartRun;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('wag.run')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    wag.run runs workflows built on a visual canvas. Workflows live in workspaces.

    Start with list-workspaces, then list-workflows to find a workflow and the inputs it takes.
    start-run starts a run of the published version; the run continues in the background, so
    follow it with get-run until its status is complete, failed, cancelled or skipped. A run can
    wait for a long time on a person's approval or task.
    MARKDOWN)]
class WorkflowServer extends Server
{
    protected array $tools = [
        ListWorkspaces::class,
        ListWorkflows::class,
        GetWorkflow::class,
        StartRun::class,
        ListRuns::class,
        GetRun::class,
        CancelRun::class,
    ];
}
