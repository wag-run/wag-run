<?php

namespace App\Engine\Nodes\Actions;

use App\Credentials\Types\AiProvider;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Sends a prompt to an LLM through the Laravel AI SDK with the workspace's
 * own provider key. Returns free text, or structured data matching a schema
 * whose fields later nodes can pick in the data picker.
 */
class LlmCall extends Node
{
    /**
     * The value types a structured output field can have.
     *
     * @return array<string, string>
     */
    public static function schemaTypes(): array
    {
        return [
            'string' => __('nodes.llm_call.schema_types.string'),
            'number' => __('nodes.llm_call.schema_types.number'),
            'integer' => __('nodes.llm_call.schema_types.integer'),
            'boolean' => __('nodes.llm_call.schema_types.boolean'),
            'array' => __('nodes.llm_call.schema_types.array'),
        ];
    }

    public static function type(): string
    {
        return 'core.llm';
    }

    public function label(): string
    {
        return __('nodes.llm_call.llm_call');
    }

    public function description(): string
    {
        return __('nodes.llm_call.sends_a_prompt_to_an');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.llm_call.ai');
    }

    public function icon(): string
    {
        return 'sparkles';
    }

    public function timeout(): int
    {
        return 600;
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('common.ai_provider_key'), Field::CREDENTIAL, required: true, credentialTypes: ['ai_provider']),
            new Field('model', __('nodes.llm_call.model'), Field::TEXT, required: true, description: __('nodes.llm_call.provider_s_model_name_such')),
            new Field('system', __('common.instructions'), Field::TEXTAREA, templated: true),
            new Field('prompt', __('nodes.llm_call.prompt'), Field::TEXTAREA, required: true, templated: true),
            new Field('output', __('nodes.llm_call.response'), Field::SELECT, required: true, default: 'text', options: ['text' => __('nodes.llm_call.free_text'), 'structured' => __('nodes.llm_call.structured_data')]),
            new Field('schema', __('nodes.llm_call.structured_fields'), Field::SCHEMA, fields: [
                new Field('name', __('common.key'), Field::TEXT, required: true),
                new Field('type', __('common.type'), Field::SELECT, required: true, default: 'string', options: static::schemaTypes()),
                new Field('description', __('common.description'), Field::TEXT),
                new Field('required', __('common.required'), Field::BOOLEAN, default: true),
            ]),
            new Field('timeout', __('common.timeout_seconds'), Field::NUMBER, default: 120),
        ];
    }

    public function outputs(array $config = []): array
    {
        $usage = new Output('usage', __('nodes.llm_call.token_usage'), 'object', children: [
            new Output('input_tokens', __('nodes.llm_call.input_tokens'), 'number'),
            new Output('output_tokens', __('nodes.llm_call.output_tokens'), 'number'),
        ]);

        if (($config['output'] ?? 'text') !== 'structured') {
            return [new Output('text', __('nodes.llm_call.response_text'), 'string'), $usage];
        }

        return [
            new Output('data', __('nodes.llm_call.structured_data'), 'object', children: array_map(
                fn (array $field) => new Output((string) $field['name'], (string) $field['name'], match ($field['type'] ?? 'string') {
                    'integer', 'number' => 'number',
                    'boolean' => 'boolean',
                    'array' => 'array',
                    default => 'string',
                }, $field['description'] ?? null),
                $this->schemaFields($config['schema'] ?? []),
            )),
            $usage,
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $credential = $context->credential($context->input('credential'));

        if (! $credential || $credential->type !== 'ai_provider') {
            return NodeResult::fail(__('nodes.llm_call.choose_an_ai_provider_key'), retryable: false);
        }

        $driver = (string) ($credential->config['provider'] ?? '');

        if (! array_key_exists($driver, AiProvider::PROVIDERS)) {
            return NodeResult::fail(__('nodes.llm_call.unsupported_ai_provider_provider', ['provider' => $driver]), retryable: false);
        }

        $provider = Ai::build(array_filter([
            'driver' => $driver,
            'key' => $credential->config['api_key'] ?? null,
            'url' => $credential->config['base_url'] ?? null,
        ]));

        $fields = $context->input('output') === 'structured' ? $this->schemaFields($context->input('schema', [])) : [];

        if ($context->input('output') === 'structured' && $fields === []) {
            return NodeResult::fail(__('nodes.llm_call.add_at_least_one_structured'), retryable: false);
        }

        $agent = agent(
            instructions: (string) $context->input('system', ''),
            schema: $fields === [] ? null : fn (JsonSchema $schema) => $this->schema($schema, $fields),
        );

        try {
            $response = $agent->prompt(
                (string) $context->input('prompt'),
                provider: [$provider],
                model: (string) $context->input('model'),
                timeout: max(5, min(600, (int) $context->input('timeout', 120))),
            );
        } catch (Throwable $e) {
            return NodeResult::fail(__('nodes.llm_call.ai_provider_returned_an_error', ['message' => $e->getMessage()]));
        }

        $usage = [
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ];

        return NodeResult::complete($response instanceof StructuredAgentResponse
            ? ['data' => $response->toArray(), 'usage' => $usage]
            : ['text' => (string) $response, 'usage' => $usage]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function schemaFields(mixed $fields): array
    {
        return array_values(array_filter(
            is_array($fields) ? $fields : [],
            fn ($field) => is_array($field) && is_string($field['name'] ?? null) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field['name']),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    protected function schema(JsonSchema $schema, array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $property = match ($field['type'] ?? 'string') {
                'number' => $schema->number(),
                'integer' => $schema->integer(),
                'boolean' => $schema->boolean(),
                'array' => $schema->array()->items($schema->string()),
                default => $schema->string(),
            };

            if (filled($field['description'] ?? null)) {
                $property = $property->description((string) $field['description']);
            }

            $properties[$field['name']] = ($field['required'] ?? true) ? $property->required() : $property;
        }

        return $properties;
    }
}
