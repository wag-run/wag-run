<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An action on a GitHub repository, as the workspace's connected GitHub
 * account. Subclasses add their fields and make one API call.
 */
abstract class GitHubAction extends Node
{
    /**
     * Make the API call.
     */
    abstract protected function send(PendingRequest $github, string $repository, NodeContext $context): Response;

    /**
     * The node's output, from GitHub's answer.
     *
     * @return array<string, mixed>
     */
    abstract protected function output(Response $response, string $repository, NodeContext $context): array;

    /**
     * Fields after the account and repository.
     *
     * @return list<Field>
     */
    abstract protected function actionInputs(): array;

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.github.github');
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.github.github_account'), Field::CREDENTIAL, required: true, credentialTypes: ['github'], description: __('nodes.github.connect_one_under_authentication')),
            new Field('repository', __('nodes.github.repository'), Field::TEXT, required: true, templated: true, description: __('nodes.github.owner_and_name')),
            ...$this->actionInputs(),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $repository = GitHub::repository($context->input('repository'));
            $response = $this->send(GitHub::request($context->workspace(), $context->input('credential')), $repository, $context);
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.github.could_not_reach_github', ['message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        if (! $response->successful()) {
            return NodeResult::fail(GitHub::failure($response), retryable: $response->serverError() || $response->status() === 429);
        }

        return NodeResult::complete($this->output($response, $repository, $context));
    }

    /**
     * Comma-separated text, or a list, as a list of trimmed values.
     *
     * @return list<string>
     */
    protected function listOf(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn (mixed $item) => trim((string) $item), $items), fn (string $item) => $item !== ''));
    }
}
