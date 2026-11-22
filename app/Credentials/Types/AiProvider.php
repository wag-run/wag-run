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
        return __('AI provider key');
    }

    public function description(): string
    {
        return __('An API key for an LLM provider, used by the LLM call node.');
    }

    public function fields(): array
    {
        return [
            CredentialField::select('provider', __('Provider'), self::PROVIDERS, default: 'anthropic'),
            CredentialField::secret('api_key', __('API key'), required: false, help: __('Not needed for a local Ollama server.')),
            CredentialField::url('base_url', __('Base URL'), help: __('Optional, and required for an OpenAI-compatible endpoint. Overrides the provider endpoint, for example a self-hosted Ollama server.')),
        ];
    }
}
