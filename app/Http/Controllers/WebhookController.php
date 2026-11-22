<?php

namespace App\Http\Controllers;

use App\Engine\Engine;
use App\Models\WorkflowTrigger;
use App\Support\AddressAllowlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Receives requests for webhook triggers and starts the workflow.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $key, Engine $engine): JsonResponse
    {
        $trigger = WorkflowTrigger::query()
            ->where('type', WorkflowTrigger::WEBHOOK)
            ->where('key', $key)
            ->with('workflow.publishedVersion', 'workflow.workspace')
            ->first();

        abort_unless($trigger && $trigger->workflow->publishedVersion, 404);

        $config = $trigger->config();
        $method = strtoupper((string) ($config['method'] ?? 'POST'));

        if ($method !== 'ANY' && $request->method() !== $method) {
            return response()->json(['message' => __('Method not allowed.')], 405);
        }

        if (! $this->verified($request, $config, $trigger)) {
            return response()->json(['message' => __('The request could not be verified.')], 403);
        }

        $execution = $engine->start($trigger->workflow, $this->payload($request), triggerType: 'webhook', triggerNodeId: $trigger->node_id);

        return response()->json(['execution_id' => $execution->id, 'status' => $execution->status->value], 202);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function verified(Request $request, array $config, WorkflowTrigger $trigger): bool
    {
        $verification = $config['verification'] ?? 'none';

        if ($verification === 'ip') {
            $ip = (string) $request->ip();
            $allowed = AddressAllowlist::parse((string) ($config['allowed_ips'] ?? ''));

            return array_any($allowed, fn (string $entry) => AddressAllowlist::ipMatches($ip, $entry));
        }

        if (! in_array($verification, ['token', 'signature'], true)) {
            return true;
        }

        $secret = $trigger->workflow->workspace->secrets()->where('key', $config['secret'] ?? '')->first()?->value;

        if (blank($secret)) {
            return false;
        }

        if ($verification === 'token') {
            $sent = (string) ($request->header($config['header'] ?? '') ?: $request->header('X-Webhook-Token') ?: $request->query('token', ''));

            return $sent !== '' && hash_equals($secret, $sent);
        }

        $sent = (string) $request->header(filled($config['header'] ?? null) ? $config['header'] : 'X-Signature', '');
        $sent = Str::after($sent, 'sha256=');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return $sent !== '' && hash_equals($expected, strtolower($sent));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $body = $request->isJson() ? $request->json()->all() : ($request->request->all() ?: $request->getContent());

        return [
            'method' => $request->method(),
            'headers' => collect($request->headers->all())
                ->except(['cookie', 'authorization', 'x-webhook-token', 'x-signature'])
                ->map(fn (array $values) => implode(', ', $values))
                ->all(),
            'query' => $request->query(),
            'body' => $body,
            'ip' => $request->ip(),
        ];
    }
}
