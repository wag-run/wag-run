<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Comments on an issue or a pull request, which GitHub numbers the same way.
 */
class AddComment extends GitHubAction
{
    public static function type(): string
    {
        return 'github.add_comment';
    }

    public function label(): string
    {
        return __('nodes.github.add_comment');
    }

    public function description(): string
    {
        return __('nodes.github.add_comment_description');
    }

    public function icon(): string
    {
        return 'chat-bubble-left-ellipsis';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('number', __('nodes.github.issue_or_pull_request_number'), Field::TEXT, required: true, templated: true),
            new Field('body', __('nodes.github.comment'), Field::TEXTAREA, required: true, templated: true, description: __('nodes.github.markdown_is_supported')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', __('nodes.github.id'), 'number'),
            new Output('url', __('nodes.github.url'), 'string'),
        ];
    }

    protected function send(PendingRequest $github, string $repository, NodeContext $context): Response
    {
        $number = (int) ltrim(trim((string) $context->input('number')), '#');

        return $github->post("/repos/{$repository}/issues/{$number}/comments", ['body' => (string) $context->input('body')]);
    }

    protected function output(Response $response, string $repository, NodeContext $context): array
    {
        return ['id' => $response->json('id'), 'url' => $response->json('html_url')];
    }
}
