<?php

namespace App\Credentials;

/**
 * A kind of reusable workspace authentication setting. Integrations add their
 * own types (OAuth later) by registering a subclass with the registry.
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
}
