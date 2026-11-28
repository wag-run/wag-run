<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

/**
 * Adds a row after the last one in a Google Sheets sheet, as the workspace's
 * connected Google account. Values are entered as if typed, so numbers and
 * dates are recognised.
 */
class AppendSheetRow extends GoogleSheetsAction
{
    public static function type(): string
    {
        return 'google.append_sheet_row';
    }

    public function label(): string
    {
        return __('nodes.google_sheets.add_row');
    }

    public function description(): string
    {
        return __('nodes.google_sheets.add_row_description');
    }

    protected function actionInputs(): array
    {
        return [
            new Field('values', __('nodes.google_sheets.values'), Field::LIST, required: true, templated: true, default: [['value' => '']], description: __('nodes.google_sheets.values_help'), fields: [
                new Field('value', __('nodes.google_sheets.value'), Field::TEXT),
            ]),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('range', __('nodes.google_sheets.updated_range'), 'string'),
            new Output('rows', __('nodes.google_sheets.rows_added'), 'number'),
        ];
    }

    protected function run(PendingRequest $api, string $spreadsheet, string $sheet, NodeContext $context): array|NodeResult
    {
        $values = array_map(
            fn (mixed $row) => GoogleSheets::cellValue(is_array($row) ? ($row['value'] ?? null) : $row),
            array_values((array) $context->input('values', [])),
        );

        $response = $api
            ->withQueryParameters(['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'])
            ->post("{$spreadsheet}/values/".GoogleSheets::range($sheet).':append', [
                'majorDimension' => 'ROWS',
                'values' => [$values],
            ]);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        return [
            'range' => $response->json('updates.updatedRange'),
            'rows' => (int) ($response->json('updates.updatedRows') ?? 1),
        ];
    }
}
