<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Output;

/**
 * Starts the workflow when a tag is pushed to a GitHub repository, such as
 * a version tag for a release.
 */
class NewTag extends GitHubTrigger
{
    public static function type(): string
    {
        return 'github.new_tag';
    }

    public function label(): string
    {
        return __('nodes.github.new_tag');
    }

    public function description(): string
    {
        return __('nodes.github.new_tag_description');
    }

    public function icon(): string
    {
        return 'tag';
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('tag', __('nodes.github.tag'), 'string'),
            new Output('sha', __('nodes.github.commit_sha'), 'string'),
            new Output('repository', __('nodes.github.repository'), 'string'),
            new Output('url', __('nodes.github.url'), 'string'),
        ];
    }

    /**
     * GitHub lists tags by name, not newest first, so a new tag can land on
     * any page.
     */
    protected function readsEveryPage(): bool
    {
        return true;
    }

    protected function webhookEvents(): array
    {
        return ['push'];
    }

    protected function pollRequest(string $repository, array $config): array
    {
        return ["/repos/{$repository}/tags", ['per_page' => 100]];
    }

    protected function fromPoll(mixed $body, string $repository, array $config): array
    {
        return array_values(array_map(fn (array $tag) => [
            'id' => (string) $tag['name'],
            'event' => $this->event($repository, (string) $tag['name'], (string) ($tag['commit']['sha'] ?? '')),
        ], array_filter((array) $body, fn (mixed $tag) => is_array($tag) && isset($tag['name']))));
    }

    protected function fromWebhook(string $event, array $payload, string $repository, array $config): ?array
    {
        $ref = (string) ($payload['ref'] ?? '');

        if ($event !== 'push' || ! str_starts_with($ref, 'refs/tags/') || ! ($payload['created'] ?? false) || ($payload['deleted'] ?? false)) {
            return null;
        }

        return $this->event($repository, substr($ref, strlen('refs/tags/')), (string) ($payload['after'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    protected function event(string $repository, string $tag, string $sha): array
    {
        return ['tag' => $tag, 'sha' => $sha, 'repository' => $repository, 'url' => "https://github.com/{$repository}/tree/".rawurlencode($tag)];
    }
}
