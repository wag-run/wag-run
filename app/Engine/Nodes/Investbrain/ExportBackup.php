<?php

namespace App\Engine\Nodes\Investbrain;

use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The backup workbook Investbrain's Import / Export page makes, as a file of
 * the run. Investbrain builds it in the background, so the step starts the
 * export, checks on it every few seconds for up to a minute, and downloads
 * it once it's ready.
 */
class ExportBackup extends InvestbrainAction
{
    /**
     * How many times the export's status is checked before giving up.
     */
    public const CHECKS = 12;

    /**
     * Seconds between checks.
     */
    public const SECONDS_BETWEEN_CHECKS = 5;

    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function type(): string
    {
        return 'investbrain.export_backup';
    }

    public function label(): string
    {
        return __('nodes.investbrain.export_backup');
    }

    public function description(): string
    {
        return __('nodes.investbrain.export_backup_description');
    }

    public function icon(): string
    {
        return 'archive-box-arrow-down';
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('file', __('nodes.investbrain.backup_workbook'), 'file'),
            new Output('export_id', __('nodes.investbrain.export_id'), 'string'),
            new Output('created_at', __('nodes.investbrain.export_started_at'), 'string'),
            new Output('expires_at', __('nodes.investbrain.export_expires_at'), 'string'),
        ];
    }

    protected function run(PendingRequest $api, NodeContext $context): array|NodeResult
    {
        $response = $api->post('/export');

        if ($response->notFound()) {
            throw new RuntimeException(__('nodes.investbrain.no_backup_exports'));
        }

        $export = $this->export(Investbrain::json($response));

        for ($check = 1; $export['status'] === 'pending' && $check <= self::CHECKS; $check++) {
            Sleep::for(self::SECONDS_BETWEEN_CHECKS)->seconds();
            $context->heartbeat();

            $response = $api->get("/export/{$export['id']}");

            if ($response->notFound()) {
                throw new RuntimeException(__('nodes.investbrain.export_gone'));
            }

            $export = $this->export(Investbrain::json($response));
        }

        return match ($export['status']) {
            'success' => [
                'file' => $this->download($api, $context, $export['id']),
                'export_id' => $export['id'],
                'created_at' => $export['created_at'] ?? null,
                'expires_at' => $export['expires_at'] ?? null,
            ],
            'failed' => NodeResult::fail(__('nodes.investbrain.export_failed')),
            default => NodeResult::fail(__('nodes.investbrain.export_not_ready', ['seconds' => self::CHECKS * self::SECONDS_BETWEEN_CHECKS])),
        };
    }

    /**
     * An export as Investbrain describes it.
     *
     * @param  array<string, mixed>  $body
     * @return array{id: string, status: string, created_at?: string, expires_at?: string}
     *
     * @throws RuntimeException when it doesn't describe an export
     */
    protected function export(array $body): array
    {
        if (! Str::isUuid($body['id'] ?? null) || ! in_array($body['status'] ?? null, ['pending', 'success', 'failed'], true)) {
            throw new RuntimeException(__('nodes.investbrain.unexpected_answer'));
        }

        return $body;
    }

    /**
     * Save the finished workbook as a file of the run. It is read from the
     * same address as the rest of the API, not the download_url Investbrain
     * answers with.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException when Investbrain refuses
     * @throws RuntimeException when the workbook is too large
     */
    protected function download(PendingRequest $api, NodeContext $context, string $id): array
    {
        $response = $api->timeout(120)->accept('*/*')->get("/export/{$id}/download");

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $workbook = $response->body();
        $limit = (int) config('workflows.files.max_bytes');

        if (strlen($workbook) > $limit) {
            throw new RuntimeException(__('run_files.too_large', ['limit' => round($limit / 1048576).' MB']));
        }

        return $context->storeFile($workbook, $this->fileName($response), self::MIME);
    }

    protected function fileName(Response $response): string
    {
        $name = preg_match('/filename="?([^";]+)"?/i', (string) $response->header('Content-Disposition'), $match)
            ? basename(trim($match[1]))
            : '';

        return $name !== '' ? $name : 'investbrain_backup.xlsx';
    }
}
