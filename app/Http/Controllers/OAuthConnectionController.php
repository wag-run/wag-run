<?php

namespace App\Http\Controllers;

use App\Credentials\OAuthCredentialType;
use App\Models\Credential;
use App\Models\Workspace;
use App\Services\OAuthConnections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Connects an OAuth authentication setting: sends the person to the
 * provider, and takes them back to where they started, the workspace's
 * Authentication page or a node in the editor.
 */
class OAuthConnectionController extends Controller
{
    public function connect(Request $request, Workspace $workspace, Credential $credential, OAuthConnections $connections): RedirectResponse
    {
        $type = $credential->credentialType();
        abort_unless($type instanceof OAuthCredentialType, 404);

        if (! $type->isConfigured($credential->config)) {
            return redirect()->route('workspaces.credentials', $workspace)
                ->with('oauth_error', __('oauth.app_not_set_up', ['provider' => $type->label(), 'keys' => implode(', ', $type->environmentKeys())]));
        }

        return redirect()->away($connections->begin($credential, $request->user()));
    }

    public function callback(Request $request, string $type, OAuthConnections $connections): RedirectResponse
    {
        $returnUrl = $connections->returnUrl((string) $request->query('state', ''));

        try {
            $credential = $connections->complete($type, $request, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->to($returnUrl)->with('oauth_error', $e->getMessage());
        }

        return redirect()->to($returnUrl)
            ->with('oauth_status', __('oauth.connected', ['key' => $credential->key, 'account' => $credential->config['account'] ?? $credential->credentialType()->label()]));
    }
}
