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
            return [__('workflows.editor.add_a_trigger_to_start')];
        }

        foreach ($graph->nodes as $node) {
            if ($node->id === '') {
                $errors[] = __('workflows.editor.every_node_needs_an_id');

                continue;
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $node->id)) {
                $errors[] = __('workflows.editor.node_id_id_must_start', ['id' => $node->id]);
            }

            if (! $this->registry->has($node->type)) {
                $errors[] = __('workflows.editor.node_name_has_an_unknown', ['name' => $node->name, 'type' => $node->type]);

                continue;
            }

            $definition = $this->registry->make($node->type);

            if ($definition->kind() === NodeKind::Trigger) {
                $triggers++;

                if ($graph->incoming($node->id) !== []) {
                    $errors[] = __('workflows.editor.trigger_name_cannot_have_incoming', ['name' => $node->name]);
                }
            }

            $inputs = $definition->inputs();

            foreach ($inputs as $field) {
                $value = $node->config[$field->name] ?? $field->default;

                if ($field->required && $field->isVisible($node->config, $inputs) && ($value === null || $value === '' || $value === [])) {
                    $errors[] = __('workflows.editor.node_name_needs_a_value', ['name' => $node->name, 'field' => $field->label]);
                }
            }
        }

        if ($triggers === 0) {
            $errors[] = __('workflows.editor.add_a_trigger_to_start');
        }

        $ids = [];
        foreach ($graph->edges as $edge) {
            if (isset($ids[$edge->id])) {
                $errors[] = __('workflows.editor.connection_ids_must_be_unique', ['id' => $edge->id]);
            }
            $ids[$edge->id] = true;

            if (! $graph->has($edge->source) || ! $graph->has($edge->target)) {
                $errors[] = __('workflows.editor.connection_id_points_to_a', ['id' => $edge->id]);

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
                    $errors[] = __('workflows.editor.node_name_has_no_error', ['name' => $source->name]);
                }
            } elseif (! in_array($edge->sourcePort, $ports, true)) {
                $errors[] = __('workflows.editor.node_name_has_no_output', ['name' => $source->name, 'port' => $edge->sourcePort]);
            }
        }

        if ($graph->hasCycle()) {
            $errors[] = __('workflows.editor.connections_cannot_form_a_cycle');
        }

        return array_values(array_unique($errors));
    }
}
