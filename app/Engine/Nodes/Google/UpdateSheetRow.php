<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * Changes cells in a row of a Google Sheets sheet, such as writing a status
 * back to the row a run came from. It finds the row by its number, or by a
 * column's value, which changes every row that holds it. Columns are named
 * by the headers in the sheet's first row, or by letter, and columns left
 * out keep their values. Values are entered as if typed.
 */
class UpdateSheetRow extends GoogleSheetsAction
{
    public const BY_NUMBER = 'number';

    public const BY_VALUE = 'value';

    public static function type(): string
    {
        return 'google.update_sheet_row';
    }

    public function label(): string
    {
        return __('nodes.google_sheets.update_row');
    }

    public function description(): string
    {
        return __('nodes.google_sheets.update_row_description');
    }

    protected function actionInputs(): array
    {
        return [
            new Field('find_by', __('nodes.google_sheets.find_the_row_by'), Field::SELECT, required: true, default: self::BY_NUMBER, options: [
                self::BY_NUMBER => __('nodes.google_sheets.its_row_number'),
                self::BY_VALUE => __('nodes.google_sheets.a_value_in_a_column'),
            ]),
            new Field('row', __('nodes.google_sheets.row_number'), Field::NUMBER, templated: true, description: __('nodes.google_sheets.row_number_help'), visibleWhen: ['find_by' => self::BY_NUMBER]),
            new Field('match_column', __('nodes.google_sheets.where_column'), Field::TEXT, templated: true, description: __('nodes.google_sheets.where_column_help'), visibleWhen: ['find_by' => self::BY_VALUE]),
            new Field('match_value', __('nodes.google_sheets.equals'), Field::TEXT, templated: true, description: __('nodes.google_sheets.update_equals_help'), visibleWhen: ['find_by' => self::BY_VALUE]),
            new Field('values', __('nodes.google_sheets.new_values'), Field::KEY_VALUE, required: true, templated: true, description: __('nodes.google_sheets.new_values_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('rows', __('nodes.google_sheets.rows_updated'), 'number'),
            new Output('row_numbers', __('nodes.google_sheets.row_numbers'), 'array'),
        ];
    }

    protected function run(PendingRequest $api, string $spreadsheet, string $sheet, NodeContext $context): array|NodeResult
    {
        $values = array_filter((array) $context->input('values', []), fn (mixed $value, mixed $column) => trim((string) $column) !== '', ARRAY_FILTER_USE_BOTH);

        if ($values === []) {
            return NodeResult::fail(__('nodes.google_sheets.nothing_to_update'), retryable: false);
        }

        $byValue = $context->input('find_by') === self::BY_VALUE;
        $matchColumn = trim((string) $context->input('match_column', ''));
        $number = filter_var($context->input('row'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($byValue && $matchColumn === '') {
            return NodeResult::fail(__('nodes.google_sheets.match_column_required'), retryable: false);
        }

        if (! $byValue && $number === false) {
            return NodeResult::fail(__('nodes.google_sheets.row_number_invalid', ['row' => (string) GoogleSheets::cellValue($context->input('row'))]), retryable: false);
        }

        $read = GoogleSheets::read($api, $spreadsheet, GoogleSheets::range($sheet, $byValue ? '' : '1:1'));
        $keys = GoogleSheets::keys(array_slice($read['values'], 0, 1), true);
        $columns = [];

        foreach (array_keys($values) as $column) {
            $columns[$column] = GoogleSheets::column((string) $column, $keys)
                ?? throw new RuntimeException(__('nodes.google_sheets.no_such_column', ['column' => $column]));
        }

        $numbers = $byValue ? $this->matchingRows($read['values'], $keys, $matchColumn, $context->input('match_value')) : [$number];

        if ($numbers === []) {
            return ['rows' => 0, 'row_numbers' => []];
        }

        $data = [];

        foreach ($numbers as $row) {
            foreach ($values as $column => $value) {
                $data[] = ['range' => GoogleSheets::a1($sheet, GoogleSheets::columnLetter($columns[$column]).$row), 'values' => [[GoogleSheets::cellValue($value)]]];
            }
        }

        $response = $api->post("{$spreadsheet}/values:batchUpdate", ['valueInputOption' => 'USER_ENTERED', 'data' => $data]);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        return ['rows' => count($numbers), 'row_numbers' => $numbers];
    }

    /**
     * The numbers of the rows below the headers whose $column holds $value.
     *
     * @param  list<list<mixed>>  $values  the whole sheet, from A1
     * @param  list<string>  $keys
     * @return list<int>
     */
    protected function matchingRows(array $values, array $keys, string $column, mixed $value): array
    {
        $index = GoogleSheets::column($column, $keys)
            ?? throw new RuntimeException(__('nodes.google_sheets.no_such_column', ['column' => $column]));

        return array_column(GoogleSheets::rows($values, $keys, true, keep: fn (array $cells) => GoogleSheets::matches($cells[$index] ?? '', $value)), GoogleSheets::ROW);
    }
}
