<?php

namespace App\Engine\Graph;

use App\Engine\Definitions\Field;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Variables\UpdateVariable;
use App\Engine\RunVariables;
use App\Enums\NodeKind;
use App\Enums\ValueOperation;
use App\Services\DataStore;
use RuntimeException;
use Throwable;

/**
 * Checks a graph's workflow variables: that each declaration can start a
 * run, that every {{ vars.<name> }} placeholder reads a declared variable,
 * and that each Update variable node names one and uses an operation that
 * fits its type. Any of these would fail a run, at its start or at a step.
 */
class VariableValidator
{
    public function __construct(
        protected NodeRegistry $registry,
        protected PlaceholderReferences $references,
    ) {}

    /**
     * @return list<string> problems, each naming its variable or node, empty when there are none
     */
    public function validate(WorkflowGraph $graph): array
    {
        $errors = [];

        foreach ($this->declarationProblems($graph) as $name => $problems) {
            foreach ($problems as $problem) {
                $errors[] = $name === '' ? $problem : __('workflows.editor.variable_name_problem', ['name' => $name, 'problem' => $problem]);
            }
        }

        foreach ($this->problems($graph) as $nodeId => $problems) {
            foreach ($problems as $problem) {
                $errors[] = __('workflows.editor.node_name_problem', ['name' => $graph->node($nodeId)->name, 'problem' => $problem]);
            }
        }

        return $errors;
    }

    /**
     * Problems with the declarations themselves, by variable name. One
     * that belongs to no variable, such as too many, is under ''.
     *
     * @return array<string, list<string>>
     */
    public function declarationProblems(WorkflowGraph $graph): array
    {
        $problems = [];
        $max = (int) config('workflows.variables.max_count');

        if (count($graph->variables) > $max) {
            $problems[''][] = __('workflows.editor.too_many_variables', ['max' => $max]);
        }

        foreach ($graph->variables as $variable) {
            $found = [
                ...$this->nameProblems($variable->name),
                ...$this->startingValueProblems($variable),
            ];

            if ($found !== []) {
                $problems[$variable->name] = array_values(array_unique($found));
            }
        }

        return $problems;
    }

    /**
     * @param  string|null  $onlyNodeId  check this node alone, as the editor does for the selected one
     * @return array<string, list<string>> problems by node id, for the nodes that have any
     */
    public function problems(WorkflowGraph $graph, ?string $onlyNodeId = null): array
    {
        $problems = [];

        foreach ($graph->nodes as $node) {
            if ($onlyNodeId !== null && $node->id !== $onlyNodeId) {
                continue;
            }

            $definition = $this->registry->has($node->type) ? $this->registry->make($node->type) : null;

            if ($definition?->kind() === NodeKind::Note) {
                continue;
            }

            $found = [];

            foreach ($this->references->under('vars', $node->config) as $name) {
                if (! isset($graph->variables[$name])) {
                    $found[] = __('workflows.editor.reads_undeclared_variable', ['name' => $name]);
                }
            }

            foreach ($definition?->inputs() ?? [] as $field) {
                $name = $field->type === Field::VARIABLE && is_scalar($node->config[$field->name] ?? null) ? (string) $node->config[$field->name] : '';

                if ($name === '') {
                    continue;
                }

                $type = ($graph->variables[$name] ?? null)?->valueType();
                $operation = ValueOperation::tryFrom((string) ($node->config['operation'] ?? ValueOperation::Set->value)) ?? ValueOperation::Set;

                if (! isset($graph->variables[$name])) {
                    $found[] = __('engine.variables.not_declared', ['name' => $name]);
                } elseif ($definition instanceof UpdateVariable && $type && ! $operation->fits($type)) {
                    $found[] = __('engine.values.operation_does_not_fit', ['operation' => $operation->label(), 'type' => $type->label()]);
                }
            }

            if ($found !== []) {
                $problems[$node->id] = array_values(array_unique($found));
            }
        }

        return $problems;
    }

    /**
     * Why $name can't be a variable's name, if it can't.
     *
     * @return list<string>
     */
    public function nameProblems(string $name): array
    {
        return preg_match(DataStore::KEY_PATTERN, $name) === 1 && mb_strlen($name) <= DataStore::KEY_MAX_LENGTH
            ? []
            : [__('common.use_letters_numbers_and_underscores')];
    }

    /**
     * Why a variable's type and starting value could not start a run. A
     * starting value with no placeholders is converted now; one with
     * placeholders can only be checked for what it reads.
     *
     * @return list<string>
     */
    public function startingValueProblems(GraphVariable $variable): array
    {
        $type = $variable->valueType();

        if ($type === null) {
            return [__('engine.variables.unknown_type', ['type' => $variable->type])];
        }

        if (mb_strlen($variable->value) > RunVariables::STARTING_VALUE_MAX_LENGTH) {
            return [__('workflows.editor.starting_value_too_long', ['max' => number_format(RunVariables::STARTING_VALUE_MAX_LENGTH)])];
        }

        if (! str_contains($variable->value, '{{')) {
            try {
                if ($variable->value !== '') {
                    $type->encode($type->convert($variable->value), (int) config('workflows.variables.max_value_bytes'));
                }
            } catch (RuntimeException $e) {
                return [$e->getMessage()];
            }

            return [];
        }

        try {
            $unreadable = array_diff($this->references->roots($variable->value), RunVariables::STARTING_VALUE_ROOTS);
        } catch (Throwable) {
            return [__('workflows.editor.starting_value_cannot_be_read')];
        }

        return array_values(array_map(
            fn (string $root) => __('workflows.editor.starting_value_cannot_read', ['name' => $root]),
            $unreadable,
        ));
    }
}
