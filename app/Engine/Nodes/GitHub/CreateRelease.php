<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Creates a release, and its tag when the tag doesn't exist yet.
 */
class CreateRelease extends GitHubAction
{
    public static function type(): string
    {
        return 'github.create_release';
    }

    public function label(): string
    {
        return __('nodes.github.create_release');
    }

    public function description(): string
    {
        return __('nodes.github.create_release_description');
    }

    public function icon(): string
    {
        return 'rocket-launch';
    }

    protected function actionInputs(): array
    {
        return [
            new Field('tag', __('nodes.github.tag'), Field::TEXT, required: true, templated: true),
            new Field('target', __('nodes.github.target'), Field::TEXT, templated: true, description: __('nodes.github.target_help')),
            new Field('name', __('common.name'), Field::TEXT, templated: true, description: __('nodes.github.release_name_help')),
            new Field('body', __('nodes.github.release_notes'), Field::TEXTAREA, templated: true),
            new Field('generate_notes', __('nodes.github.generate_release_notes'), Field::BOOLEAN, default: false, description: __('nodes.github.generate_release_notes_help')),
            new Field('draft', __('nodes.github.draft'), Field::BOOLEAN, default: false),
            new Field('prerelease', __('nodes.github.prerelease'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', __('nodes.github.id'), 'number'),
            new Output('tag', __('nodes.github.tag'), 'string'),
            new Output('url', __('nodes.github.url'), 'string'),
        ];
    }

    protected function send(PendingRequest $github, string $repository, NodeContext $context): Response
    {
        return $github->post("/repos/{$repository}/releases", array_filter([
            'tag_name' => trim((string) $context->input('tag')),
            'target_commitish' => trim((string) $context->input('target', '')),
            'name' => (string) $context->input('name', ''),
            'body' => (string) $context->input('body', ''),
            'generate_release_notes' => (bool) $context->input('generate_notes', false),
            'draft' => (bool) $context->input('draft', false),
            'prerelease' => (bool) $context->input('prerelease', false),
        ], fn (mixed $value) => $value !== ''));
    }

    protected function output(Response $response, string $repository, NodeContext $context): array
    {
        return ['id' => $response->json('id'), 'tag' => $response->json('tag_name'), 'url' => $response->json('html_url')];
    }
}
