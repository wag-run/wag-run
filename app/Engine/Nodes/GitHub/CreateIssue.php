<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class CreateIssue extends GitHubAction
{
    public static function type(): string
    {
        return 'github.create_issue';
    }

    public function label(): string
    {
        return __('nodes.github.create_issue');
    }

    public function description(): string
    {
        return __('nodes.github.create_issue_description');
    }

    public function icon(): string
    {
        return 'exclamation-circle';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('title', __('nodes.github.title'), Field::TEXT, required: true, templated: true),
            new Field('body', __('nodes.github.body'), Field::TEXTAREA, templated: true, description: __('nodes.github.markdown_is_supported')),
            new Field('labels', __('nodes.github.labels'), Field::TEXT, templated: true, description: __('nodes.github.separated_by_commas')),
            new Field('assignees', __('nodes.github.assignees'), Field::TEXT, templated: true, description: __('nodes.github.usernames_separated_by_commas')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('number', __('nodes.github.issue_number'), 'number'),
            new Output('url', __('nodes.github.url'), 'string'),
            new Output('id', __('nodes.github.id'), 'number'),
        ];
    }

    protected function send(PendingRequest $github, string $repository, NodeContext $context): Response
    {
        return $github->post("/repos/{$repository}/issues", array_filter([
            'title' => (string) $context->input('title'),
            'body' => (string) $context->input('body', ''),
            'labels' => $this->listOf($context->input('labels')),
            'assignees' => $this->listOf($context->input('assignees')),
        ], fn (mixed $value) => $value !== '' && $value !== []));
    }

    protected function output(Response $response, string $repository, NodeContext $context): array
    {
        return ['number' => $response->json('number'), 'url' => $response->json('html_url'), 'id' => $response->json('id')];
    }
}
