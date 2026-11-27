<?php

namespace App\Engine\Graph;

use App\Engine\Definitions\Field;
use App\Engine\Editor\FieldFacts;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Store\UpdateDataStore;
use App\Enums\NodeKind;
use App\Enums\ValueOperation;
use App\Enums\ValueType;
use App\Models\Workspace;

/**
 * Checks a graph's use of the workspace's data store, which the
 * GraphValidator can't see: placeholders that read an entry that doesn't
 * exist, and Update data store nodes that write to a missing or read-only
 * entry, use an operation that doesn't fit its type, or leave out a
 * collection's item key. These would read as empty or fail the step when
 * the workflow runs. A workflow variable's starting value is checked for
 * missing entries too.
 */
class StoreReferenceValidator
{
    public function __construct(
        protected NodeRegistry $registry,
        protected PlaceholderReferences $references,
    ) {}

    /**
     * @return list<string> problems, each naming its node, empty when there are none
     */
    public function validate(WorkflowGraph $graph, Workspace $workspace): array
    {
        $errors = [];

        foreach ($this->declarationProblems($graph, $workspace) as $name => $problems) {
            foreach ($problems as $problem) {
                $errors[] = __('workflows.editor.variable_name_problem', ['name' => $name, 'problem' => $problem]);
            }
        }

        foreach ($this->problems($graph, $workspace) as $nodeId => $problems) {
            foreach ($problems as $problem) {
                $errors[] = __('workflows.editor.node_name_problem', ['name' => $graph->node($nodeId)->name, 'problem' => $problem]);
            }
        }

        return $errors;
    }

    /**
     * Workflow variables whose starting value reads an entry that doesn't
     * exist, by variable name. Every run would get an empty value there.
     *
     * @return array<string, list<string>>
     */
    public function declarationProblems(WorkflowGraph $graph, Workspace $workspace): array
    {
        $entries = null;
        $problems = [];

        foreach ($graph->variables as $variable) {
            foreach ($this->entriesRead($variable->value) as $key) {
                $entries ??= FieldFacts::storeEntries($workspace);

                if (! isset($entries[$key])) {
                    $problems[$variable->name][] = __('workflows.editor.reads_missing_entry', ['key' => $key]);
                }
            }
        }

        return $problems;
    }

    /**
     * @param  string|null  $onlyNodeId  check this node alone, as the editor does for the selected one
     * @return array<string, list<string>> problems by node id, for the nodes that have any
     */
    public function problems(WorkflowGraph $graph, Workspace $workspace, ?string $onlyNodeId = null): array
    {
        $entries = null;
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

            foreach ($this->entriesRead($node->config) as $key) {
                $entries ??= FieldFacts::storeEntries($workspace);

                if (! isset($entries[$key])) {
                    $found[] = __('workflows.editor.reads_missing_entry', ['key' => $key]);
                }
            }

            foreach ($definition?->inputs() ?? [] as $field) {
                $key = $field->type === Field::STORE_ENTRY ? (string) ($node->config[$field->name] ?? '') : '';

                if ($key === '') {
                    continue;
                }

                $entries ??= FieldFacts::storeEntries($workspace);

                if (! isset($entries[$key])) {
                    $found[] = __('nodes.store.entry_not_found', ['key' => $key]);
                } elseif ($definition instanceof UpdateDataStore) {
                    array_push($found, ...$this->writeProblems($node->config, $key, $entries[$key]));
                }
            }

            if ($found !== []) {
                $problems[$node->id] = array_values(array_unique($found));
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array{type: ValueType, collection: bool, read_only: bool}  $entry
     * @return list<string>
     */
    protected function writeProblems(array $config, string $key, array $entry): array
    {
        $problems = [];
        $operation = ValueOperation::tryFrom((string) ($config['operation'] ?? ValueOperation::Set->value)) ?? ValueOperation::Set;

        if ($entry['read_only']) {
            $problems[] = __('nodes.store.entry_is_read_only', ['key' => $key]);
        }

        if (! $operation->fits($entry['type'])) {
            $problems[] = __('engine.values.operation_does_not_fit', ['operation' => $operation->label(), 'type' => $entry['type']->label()]);
        }

        if ($entry['collection'] && trim((string) ($config['item_key'] ?? '')) === '') {
            $problems[] = __('nodes.store.item_key_is_required');
        }

        return $problems;
    }

    /**
     * The keys of the entries a value's placeholders read by name, as
     * store.key or store['key'].
     *
     * @return list<string>
     */
    public function entriesRead(mixed $value): array
    {
        return $this->references->under('store', $value);
    }
}
