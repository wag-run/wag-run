<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;

/**
 * Starts the workflow when a GitHub Actions run fails in a repository,
 * optionally only for one Actions workflow or one branch.
 */
class WorkflowRunFailed extends GitHubTrigger
{
    public static function type(): string
    {
        return 'github.workflow_run_failed';
    }

    public function label(): string
    {
        return __('nodes.github.actions_run_failed');
    }

    public function description(): string
    {
        return __('nodes.github.actions_run_failed_description');
    }

    public function icon(): string
    {
        return 'x-circle';
    }

    protected function extraInputs(): array
    {
        return [
            new Field('workflow', __('nodes.github.actions_workflow'), Field::TEXT, description: __('nodes.github.actions_workflow_filter_help')),
            new Field('branch', __('nodes.github.branch'), Field::TEXT, description: __('nodes.github.branch_filter_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('id', __('nodes.github.run_id'), 'number'),
            new Output('workflow', __('nodes.github.actions_workflow'), 'string'),
            new Output('run_number', __('nodes.github.run_number'), 'number'),
            new Output('branch', __('nodes.github.branch'), 'string'),
            new Output('sha', __('nodes.github.commit_sha'), 'string'),
            new Output('event', __('nodes.github.started_by_event'), 'string'),
            new Output('actor', __('nodes.github.actor'), 'string'),
            new Output('url', __('nodes.github.url'), 'string'),
            new Output('repository', __('nodes.github.repository'), 'string'),
        ];
    }

    protected function webhookEvents(): array
    {
        return ['workflow_run'];
    }

    protected function pollRequest(string $repository, array $config): array
    {
        return ["/repos/{$repository}/actions/runs", array_filter([
            'status' => 'failure',
            'per_page' => 30,
            'branch' => trim((string) ($config['branch'] ?? '')),
        ], fn (mixed $value) => $value !== '')];
    }

    protected function fromPoll(mixed $body, string $repository, array $config): array
    {
        $runs = array_filter((array) ($body['workflow_runs'] ?? []), fn (mixed $run) => is_array($run) && isset($run['id']) && $this->wanted($run, $config));

        return array_values(array_map(fn (array $run) => ['id' => (string) $run['id'], 'event' => $this->event($repository, $run)], $runs));
    }

    protected function fromWebhook(string $event, array $payload, string $repository, array $config): ?array
    {
        $run = $payload['workflow_run'] ?? null;

        if ($event !== 'workflow_run' || ($payload['action'] ?? null) !== 'completed' || ! is_array($run) || ($run['conclusion'] ?? null) !== 'failure' || ! $this->wanted($run, $config)) {
            return null;
        }

        return $this->event($repository, $run);
    }

    /**
     * Whether the run matches the workflow and branch filters. The workflow
     * filter matches the Actions workflow's name or its file name.
     *
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $config
     */
    protected function wanted(array $run, array $config): bool
    {
        $workflow = trim((string) ($config['workflow'] ?? ''));
        $branch = trim((string) ($config['branch'] ?? ''));

        return ($workflow === '' || strcasecmp((string) ($run['name'] ?? ''), $workflow) === 0 || basename((string) ($run['path'] ?? '')) === $workflow)
            && ($branch === '' || ($run['head_branch'] ?? null) === $branch);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    protected function event(string $repository, array $run): array
    {
        return [
            'id' => $run['id'],
            'workflow' => $run['name'] ?? null,
            'run_number' => $run['run_number'] ?? null,
            'branch' => $run['head_branch'] ?? null,
            'sha' => $run['head_sha'] ?? null,
            'event' => $run['event'] ?? null,
            'actor' => $run['actor']['login'] ?? null,
            'url' => $run['html_url'] ?? null,
            'repository' => $repository,
        ];
    }
}
