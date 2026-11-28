<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Models\Workspace;
use App\Services\OAuthConnections;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What the Google Sheets nodes share: the connected Google account, where a
 * sheet is, and how its rows read as objects. A row is keyed by the headers
 * in the first row it reads, or by column letter, and carries its row
 * number under ROW so a later node can change that row.
 */
class GoogleSheets
{
    public const CREDENTIAL_TYPE = 'google';

    public const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * The key a row's number in the sheet is under.
     */
    public const ROW = '_row';

    /**
     * The Google account, spreadsheet, and sheet fields every Sheets node starts with.
     *
     * @return list<Field>
     */
    public static function inputs(bool $templated = true): array
    {
        return [
            new Field('credential', __('nodes.google_sheets.google_account'), Field::CREDENTIAL, required: true, credentialTypes: [self::CREDENTIAL_TYPE], description: __('nodes.google_sheets.connect_one_under_authentication')),
            new Field('spreadsheet', __('nodes.google_sheets.spreadsheet'), Field::TEXT, required: true, templated: $templated, description: __('nodes.google_sheets.spreadsheet_help')),
            new Field('sheet', __('nodes.google_sheets.sheet'), Field::TEXT, required: true, default: 'Sheet1', templated: $templated),
        ];
    }

    /**
     * A request to the Sheets API as the workspace's connected Google account.
     *
     * @throws RuntimeException when the account isn't there or its token can't be refreshed
     */
    public static function request(Workspace $workspace, mixed $credentialId): PendingRequest
    {
        $credential = is_string($credentialId) && Str::isUuid($credentialId)
            ? $workspace->credentials()->whereKey($credentialId)->first()
            : null;

        if (! $credential || $credential->type !== self::CREDENTIAL_TYPE) {
            throw new RuntimeException(__('nodes.google_sheets.connection_not_found'));
        }

        return Http::baseUrl(self::API)
            ->withToken(app(OAuthConnections::class)->accessToken($credential))
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * The spreadsheet's id, from the id itself or its URL, ready for a path.
     *
     * @throws RuntimeException when it's empty
     */
    public static function spreadsheetId(string $value): string
    {
        $value = trim($value);
        $id = preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $value, $match) ? $match[1] : $value;

        return $id === '' ? throw new RuntimeException(__('nodes.google_sheets.spreadsheet_required')) : rawurlencode($id);
    }

    /**
     * A sheet's cells in A1 notation, such as 'Orders'!A1:C: the whole sheet
     * when $cells is empty.
     *
     * @throws RuntimeException when the sheet's name is empty
     */
    public static function a1(string $sheet, string $cells = ''): string
    {
        $sheet = trim($sheet);

        if ($sheet === '') {
            throw new RuntimeException(__('nodes.google_sheets.sheet_required'));
        }

        return "'".str_replace("'", "''", $sheet)."'".(trim($cells) === '' ? '' : '!'.trim($cells));
    }

    /**
     * {@see a1()}, ready for a path.
     */
    public static function range(string $sheet, string $cells = ''): string
    {
        return rawurlencode(self::a1($sheet, $cells));
    }

    /**
     * The values in a range, with the row and column they start at. Numbers
     * and booleans come as such and dates as they're shown; empty rows and
     * columns after the last value are left out.
     *
     * @return array{row: int, column: int, values: list<list<mixed>>}
     *
     * @throws RequestException when Google refuses
     * @throws ConnectionException when Google can't be reached
     */
    public static function read(PendingRequest $api, string $spreadsheet, string $range): array
    {
        $response = $api->get("{$spreadsheet}/values/{$range}", [
            'majorDimension' => 'ROWS',
            'valueRenderOption' => 'UNFORMATTED_VALUE',
            'dateTimeRenderOption' => 'FORMATTED_STRING',
        ]);

        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $answered = (string) $response->json('range');
        preg_match('/^([A-Za-z]*)(\d*)/', str_contains($answered, '!') ? Str::afterLast($answered, '!') : '', $start);

        return [
            'row' => ($start[2] ?? '') === '' ? 1 : (int) $start[2],
            'column' => ($start[1] ?? '') === '' ? 0 : self::columnIndex($start[1]),
            'values' => array_values(array_map(fn (mixed $row) => array_values((array) $row), (array) ($response->json('values') ?? []))),
        ];
    }

    /**
     * Each column's key: its header from the first row, or its letter when
     * the sheet has no headers or the header is blank. A repeated header gets
     * its letter added, such as "Total (D)".
     *
     * @param  list<list<mixed>>  $values
     * @return list<string>
     */
    public static function keys(array $values, bool $headers, int $firstColumn = 0): array
    {
        $width = max([0, ...array_map('count', $values)]);
        $keys = [];

        for ($index = 0; $index < $width; $index++) {
            $letter = self::columnLetter($firstColumn + $index);
            $header = $headers ? $values[0][$index] ?? '' : '';
            $name = is_scalar($header) ? trim((string) $header) : '';
            $key = $name === '' || $name === self::ROW ? $letter : $name;
            $keys[] = in_array($key, $keys, true) ? "{$key} ({$letter})" : $key;
        }

        return $keys;
    }

    /**
     * The rows as objects, leaving out the header row, empty rows, and rows
     * $keep turns down.
     *
     * @param  list<list<mixed>>  $values
     * @param  list<string>  $keys
     * @param  (callable(list<mixed>): bool)|null  $keep
     * @return list<array<string, mixed>>
     */
    public static function rows(array $values, array $keys, bool $headers, int $firstRow = 1, ?callable $keep = null): array
    {
        $rows = [];

        foreach ($values as $index => $cells) {
            if ($headers && $index === 0) {
                continue;
            }

            $row = self::row($keys, $cells, $firstRow + $index);

            if ($row !== null && ($keep === null || $keep($cells))) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One row as an object, or null when it's empty.
     *
     * @param  list<string>  $keys
     * @param  list<mixed>  $cells
     * @return array<string, mixed>|null
     */
    public static function row(array $keys, array $cells, int $number): ?array
    {
        if (array_filter($cells, fn (mixed $cell) => $cell !== '' && $cell !== null) === []) {
            return null;
        }

        $row = [];

        foreach ($keys as $index => $key) {
            $row[$key] = $cells[$index] ?? '';
        }

        $row[self::ROW] = $number;

        return $row;
    }

    /**
     * Where the column named $name is, counted from column A: by its key,
     * exactly and then ignoring case, or else as a column letter such as C.
     *
     * @param  list<string>  $keys
     */
    public static function column(string $name, array $keys, int $firstColumn = 0): ?int
    {
        $name = trim($name);
        $index = array_search($name, $keys, true);

        if ($index === false) {
            $index = array_search(mb_strtolower($name), array_map('mb_strtolower', $keys), true);
        }

        if ($index !== false) {
            return $firstColumn + $index;
        }

        return preg_match('/^[A-Za-z]{1,3}$/', $name) ? self::columnIndex($name) : null;
    }

    /**
     * Whether a cell holds $value: the same text, ignoring spaces at the
     * ends, or the same number.
     */
    public static function matches(mixed $cell, mixed $value): bool
    {
        $cell = self::text($cell);
        $value = self::text($value);

        return is_numeric($cell) && is_numeric($value) ? (float) $cell === (float) $value : $cell === $value;
    }

    /**
     * A value to write to a cell: lists and objects as JSON.
     */
    public static function cellValue(mixed $value): mixed
    {
        return is_scalar($value) || $value === null ? $value : json_encode($value);
    }

    /**
     * The letter of the column $index places from A: 0 is A, 26 is AA.
     */
    public static function columnLetter(int $index): string
    {
        $letter = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letter = chr(65 + ($index - 1) % 26).$letter;
        }

        return $letter;
    }

    /**
     * How many places from A the column with $letter is: A is 0.
     */
    public static function columnIndex(string $letter): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letter)) as $character) {
            $index = $index * 26 + ord($character) - 64;
        }

        return $index - 1;
    }

    /**
     * What a refusal from Google says.
     */
    public static function failure(Response $response): string
    {
        return __('nodes.google_sheets.google_answered', [
            'status' => $response->status(),
            'message' => $response->json('error.message') ?? Str::limit(trim($response->body()), 300),
        ]);
    }

    protected static function text(mixed $value): string
    {
        return trim(match (true) {
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_scalar($value) => (string) $value,
            $value === null => '',
            default => (string) json_encode($value),
        });
    }
}
