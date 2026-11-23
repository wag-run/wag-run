<?php

namespace App\Http\Controllers;

use App\Engine\Engine;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use App\Services\WorkflowFiles;
use App\Support\AddressAllowlist;
use App\Support\RunChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

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
            return response()->json(['message' => __('webhooks.method_not_allowed')], 405);
        }

        if (! $this->verified($request, $config, $trigger)) {
            return response()->json(['message' => __('webhooks.request_could_not_be_verified')], 403);
        }

        try {
            $payload = $this->payload($request, $trigger->workflow->workspace);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 413);
        }

        $execution = $engine->start($trigger->workflow, $payload, triggerType: 'webhook', triggerNodeId: $trigger->node_id, startedBy: RunChain::from($request));

        if (($execution->error['code'] ?? null) === Engine::RUN_LIMIT_REACHED) {
            return response()->json(['execution_id' => $execution->id, 'status' => $execution->status->value, 'message' => $execution->error['message']], 429, ['Retry-After' => '60']);
        }

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
     * What the trigger receives. Uploaded files arrive as files; so does a
     * body that is not text, such as an image posted as is.
     *
     * @return array<string, mixed>
     */
    protected function payload(Request $request, Workspace $workspace): array
    {
        $files = app(WorkflowFiles::class);
        $body = $request->isJson() ? $request->json()->all() : ($request->request->all() ?: $request->getContent());

        if (is_string($body) && $body !== '' && ! mb_check_encoding($body, 'UTF-8')) {
            $type = trim(Str::before((string) $request->header('Content-Type'), ';')) ?: null;
            $body = $files->store($workspace, null, $body, 'body'.($type ? '.'.(MimeTypes::getDefault()->getExtensions($type)[0] ?? 'bin') : ''), $type);
        }

        $uploads = array_map(
            fn (mixed $upload) => is_array($upload)
                ? array_values(array_map(fn (UploadedFile $file) => $files->storeUpload($workspace, null, $file), Arr::flatten($upload)))
                : $files->storeUpload($workspace, null, $upload),
            $request->allFiles(),
        );

        return array_filter([
            'method' => $request->method(),
            'headers' => collect($request->headers->all())
                ->except(['cookie', 'authorization', 'x-webhook-token', 'x-signature', strtolower(RunChain::HEADER)])
                ->map(fn (array $values) => implode(', ', $values))
                ->all(),
            'query' => $request->query(),
            'body' => $body,
            'files' => $uploads ?: null,
            'ip' => $request->ip(),
        ], fn (mixed $value) => $value !== null);
    }
}
