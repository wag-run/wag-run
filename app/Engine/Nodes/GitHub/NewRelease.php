<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;

/**
 * Starts the workflow when a release is published in a GitHub repository.
 * Drafts don't count until they're published.
 */
class NewRelease extends GitHubTrigger
{
    public static function type(): string
    {
        return 'github.new_release';
    }

    public function label(): string
    {
        return __('nodes.github.new_release');
    }

    public function description(): string
    {
        return __('nodes.github.new_release_description');
    }

    public function icon(): string
    {
        return 'rocket-launch';
    }

    protected function extraInputs(): array
    {
        return [new Field('include_prereleases', __('nodes.github.include_prereleases'), Field::BOOLEAN, default: true)];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('tag', __('nodes.github.tag'), 'string'),
            new Output('name', __('common.name'), 'string'),
            new Output('body', __('nodes.github.release_notes'), 'string'),
            new Output('url', __('nodes.github.url'), 'string'),
            new Output('prerelease', __('nodes.github.prerelease'), 'boolean'),
            new Output('author', __('nodes.github.author'), 'string'),
            new Output('published_at', __('nodes.github.published_at'), 'string'),
            new Output('repository', __('nodes.github.repository'), 'string'),
        ];
    }

    protected function webhookEvents(): array
    {
        return ['release'];
    }

    protected function pollRequest(string $repository, array $config): array
    {
        return ["/repos/{$repository}/releases", ['per_page' => 30]];
    }

    protected function fromPoll(mixed $body, string $repository, array $config): array
    {
        $releases = array_filter((array) $body, fn (mixed $release) => is_array($release) && isset($release['id']) && $this->wanted($release, $config));

        return array_values(array_map(fn (array $release) => ['id' => (string) $release['id'], 'event' => $this->event($repository, $release)], $releases));
    }

    protected function fromWebhook(string $event, array $payload, string $repository, array $config): ?array
    {
        $release = $payload['release'] ?? null;

        if ($event !== 'release' || ($payload['action'] ?? null) !== 'published' || ! is_array($release) || ! $this->wanted($release, $config)) {
            return null;
        }

        return $this->event($repository, $release);
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $config
     */
    protected function wanted(array $release, array $config): bool
    {
        return ! ($release['draft'] ?? false) && (($config['include_prereleases'] ?? true) || ! ($release['prerelease'] ?? false));
    }

    /**
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    protected function event(string $repository, array $release): array
    {
        return [
            'tag' => $release['tag_name'] ?? null,
            'name' => $release['name'] ?? null,
            'body' => $release['body'] ?? null,
            'url' => $release['html_url'] ?? null,
            'prerelease' => (bool) ($release['prerelease'] ?? false),
            'author' => $release['author']['login'] ?? null,
            'published_at' => $release['published_at'] ?? null,
            'repository' => $repository,
        ];
    }
}
