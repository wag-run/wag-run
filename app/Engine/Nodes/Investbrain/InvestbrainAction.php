<?php

namespace App\Engine\Nodes\Investbrain;

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
 * An action in Investbrain, as the person whose API token the node's
 * setting holds. Subclasses add their fields and call the API.
 */
abstract class InvestbrainAction extends Node
{
    /**
     * Call the API and return the node's output.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a field is not usable or the answer isn't JSON
     * @throws RequestException when Investbrain refuses
     * @throws ConnectionException when Investbrain can't be reached
     */
    abstract protected function run(PendingRequest $api, NodeContext $context): array;

    /**
     * Fields after the API token.
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
        return __('nodes.investbrain.investbrain');
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.investbrain.api_token'), Field::CREDENTIAL, required: true, credentialTypes: [Investbrain::CREDENTIAL_TYPE], description: __('nodes.investbrain.api_token_help')),
            ...$this->actionInputs(),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            return NodeResult::complete($this->run(Investbrain::request($context->workspace(), $context->input('credential')), $context));
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.investbrain.could_not_reach', ['message' => $e->getMessage()]));
        } catch (RequestException $e) {
            return NodeResult::fail(Investbrain::failure($e->response), retryable: $e->response->serverError() || $e->response->status() === 429);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }
    }

    /**
     * A templated field's value as one segment of the API's path.
     *
     * @throws RuntimeException with $whenEmpty when the field is empty
     */
    protected function segment(?string $value, string $whenEmpty): string
    {
        return $value === null ? throw new RuntimeException($whenEmpty) : rawurlencode($value);
    }

    /**
     * The filters a list asks for, leaving out empty ones.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, array<string, string>>
     */
    protected function filters(array $filters): array
    {
        $filters = array_filter($filters, fn (?string $value) => $value !== null && $value !== '');

        return $filters === [] ? [] : ['filter' => $filters];
    }
}
