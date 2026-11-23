<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Mcp\Servers\WorkflowServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * The MCP server. MCP clients such as Claude connect through OAuth: they
 * register themselves, then the person approves them on a consent page.
 * A personal access token works too, as a bearer token.
 */
Mcp::oauthRoutes();

Mcp::web('/mcp', WorkflowServer::class)
    ->middleware(['auth:api', EnsureUserIsActive::class, 'throttle:120,1'])
    ->name('mcp');
