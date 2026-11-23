<?php

namespace App\Credentials;

/**
 * A kind of reusable workspace authentication setting. Integrations add their
 * own types by registering a subclass with the registry; OAuth types extend
 * {@see OAuthCredentialType}.
 */
abstract class CredentialType
{
    /**
     * The stable identifier stored on the credential row.
     */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /**
     * @return list<CredentialField>
     */
    abstract public function fields(): array;

    /**
     * Validation rules for a config array of this type, keyed under $prefix.
     *
     * @return array<string, list<string>>
     */
    public function rules(string $prefix = 'config'): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
            $rules["{$prefix}.{$field->name}"] = $field->rules();
        }

        return $rules;
    }

    /**
     * Default config values for a new setting of this type.
     *
     * @return array<string, string|null>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields() as $field) {
            $defaults[$field->name] = $field->default;
        }

        return $defaults;
    }

    /**
     * @return list<string>
     */
    public function secretFieldNames(): array
    {
        return array_values(array_map(
            fn (CredentialField $field) => $field->name,
            array_filter($this->fields(), fn (CredentialField $field) => $field->secret),
        ));
    }

    /**
     * The secret values in a config of this type, for redaction.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function secretValues(array $config): array
    {
        $values = [];

        foreach ($this->secretFieldNames() as $name) {
            $value = $config[$name] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Config values the settings form doesn't edit, kept when someone saves
     * the setting, such as the tokens an OAuth connection got.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function keptOnSave(array $config): array
    {
        return [];
    }
}
