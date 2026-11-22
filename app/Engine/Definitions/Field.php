<?php

namespace App\Engine\Definitions;

/**
 * An input a node expects: one configurable field in the editor.
 *
 * Templated fields are rendered with the template engine (Twig) against the
 * run's data before the node sees them; expression fields are evaluated with
 * the expression engine (ExpressionLanguage). Plain fields are passed as is.
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

    public const WORKFLOW = 'workflow';

    public const USER = 'user';

    public const DURATION = 'duration';

    public const SCHEMA = 'schema';

    /**
     * @param  array<string, string>  $options  value => label, for select fields
     * @param  list<string>  $credentialTypes  allowed authentication types, for credential fields
     * @param  list<Field>  $fields  item fields, for list fields
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
    ) {}

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
        ];
    }
}
