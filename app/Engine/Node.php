<?php

namespace App\Engine;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;

/**
 * The contract every trigger and action implements, built-in or custom.
 *
 * A node declares the inputs it expects and the outputs it produces, so the
 * editor and data picker treat every node the same way, and does its work in
 * execute(). Register node classes with the NodeRegistry.
 */
abstract class Node
{
    /**
     * A stable, unique identifier stored in workflow graphs, such as "core.http".
     */
    abstract public static function type(): string;

    abstract public function label(): string;

    /**
     * The label for a node with these settings. Most nodes have one label;
     * a While loop that checks after each pass reads "Do while loop".
     *
     * @param  array<string, mixed>  $config
     */
    public function labelFor(array $config): string
    {
        return $this->label();
    }

    abstract public function kind(): NodeKind;

    abstract public function execute(NodeContext $context): NodeResult;

    public function description(): string
    {
        return '';
    }

    /**
     * Groups nodes in the editor's palette. Groups of triggers come first.
     */
    public function category(): string
    {
        return $this->kind() === NodeKind::Trigger ? __('common.triggers') : __('common.actions');
    }

    /**
     * Where the node sits in the editor's palette: its group, then any
     * groups nested inside it, such as Triggers › Other apps › GitHub.
     *
     * @return non-empty-list<string>
     */
    public function paletteGroup(): array
    {
        return [$this->category()];
    }

    /**
     * A Heroicon name for the editor.
     */
    public function icon(): string
    {
        return 'cube';
    }

    /**
     * @return list<Field>
     */
    public function inputs(): array
    {
        return [];
    }

    /**
     * The help text under one of this node's fields, given its settings so
     * far. Most nodes show the field's own description.
     *
     * @param  array<string, mixed>  $config
     */
    public function fieldHelp(Field $field, array $config): ?string
    {
        return $field->description;
    }

    /**
     * The outputs this node produces with the given config. Most nodes ignore
     * the config; an LLM call node with a structured schema does not.
     *
     * @param  array<string, mixed>  $config
     * @return list<Output>
     */
    public function outputs(array $config = []): array
    {
        return [];
    }

    /**
     * The named output ports edges can leave from, besides the error port.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function ports(array $config = []): array
    {
        return ['main'];
    }

    /**
     * Problems with this node's settings that would make it misbehave,
     * beyond missing required fields, reported when publishing or testing.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function configProblems(array $config): array
    {
        return [];
    }

    /**
     * For a trigger, the labels of the inputs it requires that $input lacks.
     * A run started without them fails before its first step.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function missingInputs(array $config, array $input): array
    {
        return [];
    }

    /**
     * How the editor labels one of this node's output ports, or null for
     * the default label.
     *
     * @param  array<string, mixed>  $config
     */
    public function portLabel(string $port, array $config = []): ?string
    {
        return null;
    }

    /**
     * For nodes whose ports follow the rows of a list field: how ports move
     * when row $index of $field is removed, as old port => new port (null
     * when its connections should be dropped). Unlisted ports stay as they are.
     *
     * @param  array<string, mixed>  $config  the config before the removal
     * @return array<string, string|null>
     */
    public function portsAfterRowRemoved(string $field, int $index, array $config = []): array
    {
        return [];
    }

    /**
     * Whether an action can be connected to this node's error output.
     */
    public function hasErrorPort(): bool
    {
        return $this->kind() === NodeKind::Action;
    }

    /**
     * Whether this node waits for every incoming branch before running.
     */
    public function joinsBranches(): bool
    {
        return false;
    }

    /**
     * Whether this node runs part of the flow in passes (NodeResult::repeat).
     * Its own step then always reads loop as its own: before the first pass
     * loop.index and loop.previous are null and loop.results is empty, and
     * after a pass they are what a step inside that pass reads.
     */
    public function repeats(): bool
    {
        return false;
    }

    public function defaultRetryPolicy(): RetryPolicy
    {
        return new RetryPolicy;
    }

    /**
     * Seconds a single attempt may run before the sweep treats its worker as dead.
     */
    public function timeout(): int
    {
        return 300;
    }

    /**
     * Continue a waiting step. The default handles timers and child workflows;
     * nodes that wait for a response override this, and so do nodes that
     * repeat part of the flow, which otherwise stop after one pass.
     */
    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        return match ($resumption->reason) {
            ResumeReason::TimerElapsed => NodeResult::complete($context->state()['output'] ?? null),
            ResumeReason::ChildCompleted => NodeResult::complete($resumption->payload['output'] ?? null),
            ResumeReason::ChildFailed => NodeResult::fail($resumption->payload['message'] ?? __('engine.child_workflow_failed')),
            ResumeReason::External => NodeResult::complete($resumption->payload),
            ResumeReason::PassFinished => NodeResult::complete(['passes' => ($context->state()['index'] ?? 0) + 1], $context->state()['done_port'] ?? 'done'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'type' => static::type(),
            'label' => $this->label(),
            'description' => $this->description(),
            'kind' => $this->kind()->value,
            'category' => $this->category(),
            'icon' => $this->icon(),
            'inputs' => array_map(fn (Field $field) => $field->toArray(), $this->inputs()),
            'outputs' => array_map(fn (Output $output) => $output->toArray(), $this->outputs()),
            'ports' => $this->ports(),
            'has_error_port' => $this->hasErrorPort(),
            'joins_branches' => $this->joinsBranches(),
        ];
    }
}
