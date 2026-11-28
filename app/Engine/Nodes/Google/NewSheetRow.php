<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\EventTrigger;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workspace;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Starts the workflow when a row is added to a Google Sheets sheet. Google
 * Sheets has no webhooks, so it reads the sheet every few minutes and
 * remembers how many rows it had: each row past that count is new. Rows
 * deleted lower the count, so the rows that take their place count as new
 * again. A row inserted between others shifts the last row down, and that
 * one is taken for new.
 *
 * A run's input is the row as an object of its values by the headers in
 * the sheet's first row, with its row number under _row.
 */
class NewSheetRow extends Node implements EventTrigger
{
    /**
     * The most runs one check starts. Rows past them start runs at the next check.
     */
    public const MAX_EVENTS = 100;

    public static function type(): string
    {
        return 'google.new_sheet_row';
    }

    public function label(): string
    {
        return __('nodes.google_sheets.new_row');
    }

    public function description(): string
    {
        return __('nodes.google_sheets.new_row_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'table-cells';
    }

    public function paletteGroup(): array
    {
        return [__('common.triggers'), __('common.other_apps'), __('nodes.google_sheets.google')];
    }

    public function inputs(): array
    {
        return [
            ...GoogleSheets::inputs(templated: false),
            new Field('poll_minutes', __('nodes.google_sheets.check_every_minutes'), Field::NUMBER, default: 5),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output(GoogleSheets::ROW, __('nodes.google_sheets.row_number'), 'number', __('nodes.google_sheets.new_row_output_help')),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }

    public function listensBy(array $config): string
    {
        return EventTrigger::POLL;
    }

    public function pollEvery(array $config): int
    {
        return max(1, (int) ($config['poll_minutes'] ?? 5));
    }

    public function poll(array $config, Workspace $workspace, ?array $state): array
    {
        $api = GoogleSheets::request($workspace, $config['credential'] ?? null);

        try {
            $values = GoogleSheets::read($api, GoogleSheets::spreadsheetId((string) ($config['spreadsheet'] ?? '')), GoogleSheets::range((string) ($config['sheet'] ?? '')))['values'];
        } catch (RequestException $e) {
            throw new RuntimeException(GoogleSheets::failure($e->response));
        }

        $last = count($values);
        $seen = $state === null ? $last : (int) ($state['rows'] ?? $last);

        if ($last <= $seen) {
            return ['events' => [], 'state' => ['rows' => $last]];
        }

        $keys = GoogleSheets::keys($values, true);
        $events = [];
        $through = max($seen, 1);

        for ($index = max($seen, 1); $index < $last && count($events) < self::MAX_EVENTS; $index++) {
            $through = $index + 1;
            $row = GoogleSheets::row($keys, $values[$index], $index + 1);

            if ($row !== null) {
                $events[] = $row;
            }
        }

        return ['events' => $events, 'state' => ['rows' => $through]];
    }

    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array
    {
        throw new RuntimeException(__('nodes.google_sheets.no_webhooks'));
    }

    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void {}

    public function receive(array $config, Request $request, string $secret): ?array
    {
        return null;
    }
}
