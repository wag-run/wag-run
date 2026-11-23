<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Mcp\Servers\WorkflowServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * The MCP server. MCP clients such as Claude connect through OAuth: they
 * register themselves, then the person approves them on a consent page.
 * A personal access token works too, as a bearer token.
 *
 * MCP_SERVER_ENABLED=false turns it off: no MCP endpoint, discovery or
 * client registration routes are registered.
 */
if (! config('services.mcp_server_enabled')) {
    return;
}

// Client registration is public by design (RFC 7591), and each one creates
// an OAuth client, so limit how often it can be called.
Route::middleware('throttle:10,1')->group(function () {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', WorkflowServer::class)
    ->middleware(['auth:api', EnsureUserIsActive::class, 'throttle:120,1'])
    ->name('mcp');
