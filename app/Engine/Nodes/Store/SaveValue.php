<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Models\Workspace;

/**
 * Saves a value under a key, replacing what was there. The value can be
 * anything JSON can hold: text, a number, true or false, a list, or an
 * object.
 */
class SaveValue extends StoreAction
{
    public static function type(): string
    {
        return 'core.store_save';
    }

    public function label(): string
    {
        return __('nodes.store.save_value');
    }

    public function description(): string
    {
        return __('nodes.store.save_value_description');
    }

    public function icon(): string
    {
        return 'circle-stack';
    }

    public function inputs(): array
    {
        return [
            ...$this->keyFields(),
            new Field('value', __('common.value'), Field::TEXTAREA, templated: true, description: __('nodes.store.value_help')),
            new Field('parse_json', __('nodes.compose.parse_the_result_as_json'), Field::BOOLEAN, default: false),
            ...$this->expiryFields(),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('key', __('common.key'), 'string'),
            new Output('value', __('common.value'), 'any', __('nodes.store.saved_value_help')),
        ];
    }

    protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult
    {
        $value = $context->input('value');

        if ($context->input('parse_json') && is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return NodeResult::fail(__('nodes.compose.result_is_not_valid_json', ['error' => json_last_error_msg()]), retryable: false);
            }

            $value = $decoded;
        }

        $this->store->put($workspace, $namespace, $key, $value, $this->expiresAt($context));

        return NodeResult::complete(['key' => $key, 'value' => $value]);
    }
}
