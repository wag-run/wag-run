<?php

namespace App\Engine\Graph;

use App\Engine\NodeRegistry;
use App\Enums\NodeKind;

/**
 * Checks that a graph can be published and run.
 */
class GraphValidator
{
    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return list<string> problems, empty when the graph is valid
     */
    public function validate(WorkflowGraph $graph): array
    {
        $errors = [];
        $triggers = 0;

        if ($graph->nodes === []) {
            return [__('Add a trigger to start the workflow.')];
        }

        foreach ($graph->nodes as $node) {
            if ($node->id === '') {
                $errors[] = __('Every node needs an id.');

                continue;
            }

            if (! $this->registry->has($node->type)) {
                $errors[] = __('Node ":name" has an unknown type [:type].', ['name' => $node->name, 'type' => $node->type]);

                continue;
            }

            $definition = $this->registry->make($node->type);

            if ($definition->kind() === NodeKind::Trigger) {
                $triggers++;

                if ($graph->incoming($node->id) !== []) {
                    $errors[] = __('Trigger ":name" cannot have incoming connections.', ['name' => $node->name]);
                }
            }

            foreach ($definition->inputs() as $field) {
                $value = $node->config[$field->name] ?? $field->default;

                if ($field->required && ($value === null || $value === '' || $value === [])) {
                    $errors[] = __('Node ":name" needs a value for ":field".', ['name' => $node->name, 'field' => $field->label]);
                }
            }
        }

        if ($triggers === 0) {
            $errors[] = __('Add a trigger to start the workflow.');
        }

        $ids = [];
        foreach ($graph->edges as $edge) {
            if (isset($ids[$edge->id])) {
                $errors[] = __('Connection ids must be unique: [:id] is repeated.', ['id' => $edge->id]);
            }
            $ids[$edge->id] = true;

            if (! $graph->has($edge->source) || ! $graph->has($edge->target)) {
                $errors[] = __('Connection [:id] points to a node that does not exist.', ['id' => $edge->id]);

                continue;
            }

            $source = $graph->node($edge->source);

            if (! $this->registry->has($source->type)) {
                continue;
            }

            $definition = $this->registry->make($source->type);
            $ports = $definition->ports($source->config);

            if ($edge->sourcePort === 'error') {
                if (! $definition->hasErrorPort()) {
                    $errors[] = __('Node ":name" has no error output.', ['name' => $source->name]);
                }
            } elseif (! in_array($edge->sourcePort, $ports, true)) {
                $errors[] = __('Node ":name" has no output named ":port".', ['name' => $source->name, 'port' => $edge->sourcePort]);
            }
        }

        if ($graph->hasCycle()) {
            $errors[] = __('Connections cannot form a cycle. Use a For loop node to repeat steps.');
        }

        return array_values(array_unique($errors));
    }
}
