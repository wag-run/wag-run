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
    public const SCHEMA_TYPES = ['string' => 'Text', 'number' => 'Number', 'integer' => 'Whole number', 'boolean' => 'Yes/no', 'array' => 'List of text'];

    public static function type(): string
    {
        return 'core.llm';
    }

    public function label(): string
    {
        return __('LLM call');
    }

    public function description(): string
    {
        return __('Sends a prompt to an LLM and returns the response.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('AI');
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
            new Field('credential', __('AI provider key'), Field::CREDENTIAL, required: true, credentialTypes: ['ai_provider']),
            new Field('model', __('Model'), Field::TEXT, required: true, description: __('The provider\'s model name, such as claude-sonnet-5-5 or gpt-6.')),
            new Field('system', __('Instructions'), Field::TEXTAREA, templated: true),
            new Field('prompt', __('Prompt'), Field::TEXTAREA, required: true, templated: true),
            new Field('output', __('Response'), Field::SELECT, required: true, default: 'text', options: ['text' => __('Free text'), 'structured' => __('Structured data')]),
            new Field('schema', __('Structured fields'), Field::SCHEMA, fields: [
                new Field('name', __('Key'), Field::TEXT, required: true),
                new Field('type', __('Type'), Field::SELECT, required: true, default: 'string', options: self::SCHEMA_TYPES),
                new Field('description', __('Description'), Field::TEXT),
                new Field('required', __('Required'), Field::BOOLEAN, default: true),
            ]),
            new Field('timeout', __('Timeout (seconds)'), Field::NUMBER, default: 120),
        ];
    }

    public function outputs(array $config = []): array
    {
        $usage = new Output('usage', __('Token usage'), 'object', children: [
            new Output('input_tokens', __('Input tokens'), 'number'),
            new Output('output_tokens', __('Output tokens'), 'number'),
        ]);

        if (($config['output'] ?? 'text') !== 'structured') {
            return [new Output('text', __('Response text'), 'string'), $usage];
        }

        return [
            new Output('data', __('Structured data'), 'object', children: array_map(
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
            return NodeResult::fail(__('Choose an AI provider key from this workspace\'s authentication settings.'), retryable: false);
        }

        $driver = (string) ($credential->config['provider'] ?? '');

        if (! array_key_exists($driver, AiProvider::PROVIDERS)) {
            return NodeResult::fail(__('Unsupported AI provider [:provider].', ['provider' => $driver]), retryable: false);
        }

        $provider = Ai::build(array_filter([
            'driver' => $driver,
            'key' => $credential->config['api_key'] ?? null,
            'url' => $credential->config['base_url'] ?? null,
        ]));

        $fields = $context->input('output') === 'structured' ? $this->schemaFields($context->input('schema', [])) : [];

        if ($context->input('output') === 'structured' && $fields === []) {
            return NodeResult::fail(__('Add at least one structured field.'), retryable: false);
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
            return NodeResult::fail(__('The AI provider returned an error: :message', ['message' => $e->getMessage()]));
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
