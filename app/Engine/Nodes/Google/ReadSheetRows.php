<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Nodes\Actions\ForLoop;
use Illuminate\Http\Client\PendingRequest;

/**
 * Reads a Google Sheets sheet as a list of rows, ready for a For loop or a
 * Filter. Each row is an object of its values by header, with its row
 * number under _row for Update row. It can keep only the rows where one
 * column holds a value, which finds a row by its ID or email.
 */
class ReadSheetRows extends GoogleSheetsAction
{
    public static function type(): string
    {
        return 'google.read_sheet_rows';
    }

    public function label(): string
    {
        return __('nodes.google_sheets.read_rows');
    }

    public function description(): string
    {
        return __('nodes.google_sheets.read_rows_description');
    }

    protected function actionInputs(): array
    {
        return [
            new Field('cells', __('nodes.google_sheets.cells'), Field::TEXT, templated: true, description: __('nodes.google_sheets.cells_help')),
            new Field('headers', __('nodes.google_sheets.first_row_is_headers'), Field::BOOLEAN, default: true, description: __('nodes.google_sheets.first_row_is_headers_help')),
            new Field('match_column', __('nodes.google_sheets.only_rows_where'), Field::TEXT, templated: true, description: __('nodes.google_sheets.only_rows_where_help')),
            new Field('match_value', __('nodes.google_sheets.equals'), Field::TEXT, templated: true, description: __('nodes.google_sheets.equals_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('rows', __('nodes.google_sheets.rows'), 'array', __('nodes.google_sheets.rows_help')),
            new Output('count', __('nodes.google_sheets.row_count'), 'number'),
            new Output('headers', __('nodes.google_sheets.headers'), 'array', __('nodes.google_sheets.headers_help')),
        ];
    }

    protected function run(PendingRequest $api, string $spreadsheet, string $sheet, NodeContext $context): array|NodeResult
    {
        $read = GoogleSheets::read($api, $spreadsheet, GoogleSheets::range($sheet, (string) $context->input('cells', '')));
        $headers = (bool) $context->input('headers', true);
        $keys = GoogleSheets::keys($read['values'], $headers, $read['column']);
        $keep = null;
        $column = trim((string) $context->input('match_column', ''));

        if ($column !== '') {
            $index = GoogleSheets::column($column, $keys, $read['column']);

            if ($index === null) {
                return NodeResult::fail(__('nodes.google_sheets.no_such_column', ['column' => $column]), retryable: false);
            }

            $value = $context->input('match_value');
            $keep = fn (array $cells) => GoogleSheets::matches($cells[$index - $read['column']] ?? '', $value);
        }

        $rows = GoogleSheets::rows($read['values'], $keys, $headers, $read['row'], $keep);

        if (count($rows) > ForLoop::MAX_ITEMS) {
            return NodeResult::fail(__('nodes.google_sheets.too_many_rows', ['count' => count($rows), 'max' => ForLoop::MAX_ITEMS]), retryable: false);
        }

        return ['rows' => $rows, 'count' => count($rows), 'headers' => $keys];
    }
}
