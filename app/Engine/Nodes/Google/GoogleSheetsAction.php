<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

/**
 * An action on one sheet of a Google Sheets spreadsheet, as the workspace's
 * connected Google account. Subclasses add their fields and call the API.
 */
abstract class GoogleSheetsAction extends Node
{
    /**
     * Call the API and return the node's output, or a result such as a failure of its own.
     *
     * @param  string  $spreadsheet  the spreadsheet's id, ready for a path
     * @param  string  $sheet  the sheet's name
     * @return array<string, mixed>|NodeResult
     *
     * @throws RuntimeException when a field is not usable
     * @throws RequestException when Google refuses
     * @throws ConnectionException when Google can't be reached
     */
    abstract protected function run(PendingRequest $api, string $spreadsheet, string $sheet, NodeContext $context): array|NodeResult;

    /**
     * Fields after the sheet.
     *
     * @return list<Field>
     */
    protected function actionInputs(): array
    {
        return [];
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.google_sheets.google');
    }

    public function icon(): string
    {
        return 'table-cells';
    }

    public function inputs(): array
    {
        return [...GoogleSheets::inputs(), ...$this->actionInputs()];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $api = GoogleSheets::request($context->workspace(), $context->input('credential'));
            $result = $this->run($api, GoogleSheets::spreadsheetId((string) $context->input('spreadsheet')), (string) $context->input('sheet'), $context);

            return $result instanceof NodeResult ? $result : NodeResult::complete($result);
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.google_sheets.could_not_reach_google', ['message' => $e->getMessage()]));
        } catch (RequestException $e) {
            return NodeResult::fail(GoogleSheets::failure($e->response), retryable: $e->response->serverError() || $e->response->status() === 429);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }
    }
}
