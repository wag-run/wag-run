<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class McpServerSwitchTest extends TestCase
{
    public function test_turning_the_mcp_server_off_removes_its_endpoint_and_oauth_routes(): void
    {
        $_ENV['MCP_SERVER_ENABLED'] = $_SERVER['MCP_SERVER_ENABLED'] = 'false';

        try {
            $this->refreshApplication();
        } finally {
            unset($_ENV['MCP_SERVER_ENABLED'], $_SERVER['MCP_SERVER_ENABLED']);
        }

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
        $this->getJson('/.well-known/oauth-authorization-server')->assertNotFound();
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/callback']])->assertNotFound();
    }
}
