<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * An AI provider key for the LLM call node, so each workspace brings its own.
 */
class AiProvider extends CredentialType
{
    /**
     * @var array<string, string>
     */
    public const PROVIDERS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
        'mistral' => 'Mistral',
        'groq' => 'Groq',
        'xai' => 'xAI',
        'deepseek' => 'DeepSeek',
        'openrouter' => 'OpenRouter',
        'cohere' => 'Cohere',
        'ollama' => 'Ollama',
        'openai-compatible' => 'OpenAI-compatible endpoint',
    ];

    public function key(): string
    {
        return 'ai_provider';
    }

    public function label(): string
    {
        return __('common.ai_provider_key');
    }

    public function description(): string
    {
        return __('credentials.ai_provider.api_key_for_an_llm');
    }

    public function fields(): array
    {
        return [
            CredentialField::select('provider', __('common.provider'), self::PROVIDERS, default: 'anthropic'),
            CredentialField::secret('api_key', __('credentials.ai_provider.api_key'), required: false, help: __('credentials.ai_provider.not_needed_for_a_local')),
            CredentialField::url('base_url', __('credentials.ai_provider.base_url'), help: __('credentials.ai_provider.optional_and_required_for_an')),
        ];
    }
}
