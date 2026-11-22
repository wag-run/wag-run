<?php

namespace App\Engine\Definitions;

/**
 * An input a node expects: one configurable field in the editor.
 *
 * Templated fields are text whose {{ expression }} placeholders are filled
 * in from the run's data before the node sees them; expression fields are
 * one expression. Both use the same expression language. Plain fields are
 * passed as is.
 */
final readonly class Field
{
    public const TEXT = 'text';

    public const TEXTAREA = 'textarea';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const SELECT = 'select';

    public const JSON = 'json';

    public const LIST = 'list';

    public const KEY_VALUE = 'key_value';

    public const CREDENTIAL = 'credential';

    public const SECRET = 'secret';

    public const WORKFLOW = 'workflow';

    public const USER = 'user';

    /**
     * Several users, stored as a list of user ids.
     */
    public const USERS = 'users';

    public const DURATION = 'duration';

    public const SCHEMA = 'schema';

    /**
     * @param  array<string, string>  $options  value => label, for select fields
     * @param  list<string>  $credentialTypes  allowed authentication types, for credential fields
     * @param  list<Field>  $fields  item fields, for list fields
     * @param  array<string, string|list<string>>  $visibleWhen  shown and saved only while each named
     *                                                           sibling field has one of these values
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::TEXT,
        public bool $required = false,
        public mixed $default = null,
        public ?string $description = null,
        public bool $templated = false,
        public bool $expression = false,
        public array $options = [],
        public array $credentialTypes = [],
        public array $fields = [],
        public array $visibleWhen = [],
    ) {}

    /**
     * Whether this field applies, given its sibling fields' values (missing
     * ones take their defaults from $siblings).
     *
     * @param  array<string, mixed>  $values
     * @param  list<Field>  $siblings
     */
    public function isVisible(array $values, array $siblings = []): bool
    {
        foreach ($this->visibleWhen as $name => $allowed) {
            $value = $values[$name] ?? null;

            if ($value === null || $value === '') {
                $value = collect($siblings)->firstWhere('name', $name)?->default;
            }

            if (! in_array((string) $value, array_map('strval', (array) $allowed), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'description' => $this->description,
            'templated' => $this->templated,
            'expression' => $this->expression,
            'options' => $this->options,
            'credential_types' => $this->credentialTypes,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
            'visible_when' => $this->visibleWhen,
        ];
    }
}
