<?php

namespace App\Engine\Definitions;

use App\Credentials\CredentialTypeRegistry;
use App\Credentials\OAuthCredentialType;

/**
 * An input a node expects: one configurable field in the editor.
 *
 * Templated fields are text whose {{ }} placeholders are filled in from the
 * run's data before the node sees them; a field that is a single
 * placeholder keeps the value's type. Plain fields are passed as is.
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

    /**
     * An entry of the workspace's data store, stored by its key. Sibling
     * fields can depend on the chosen entry through "<name>.type" (a
     * ValueType value) and "<name>.collection" ("yes" or "no") in their
     * visibleWhen and optionsWhen.
     */
    public const STORE_ENTRY = 'store_entry';

    /**
     * One of the workflow's variables, stored by its name. Sibling fields
     * can depend on the chosen variable's type through "<name>.type" (a
     * ValueType value) in their visibleWhen and optionsWhen.
     */
    public const VARIABLE = 'variable';

    public const USER = 'user';

    /**
     * Several users, stored as a list of user ids.
     */
    public const USERS = 'users';

    public const DURATION = 'duration';

    public const SCHEMA = 'schema';

    /**
     * A five-part cron expression. The editor puts it into words and lists
     * its next runs, in the time zone of a sibling field named "timezone".
     */
    public const CRON = 'cron';

    /**
     * A time zone name such as Europe/London, chosen from the ones this
     * server knows.
     */
    public const TIMEZONE = 'timezone';

    /**
     * @param  array<int|string, string>  $options  value => label, for select fields
     * @param  list<string>  $credentialTypes  allowed authentication types, for credential fields
     * @param  list<Field>  $fields  item fields, for list fields
     * @param  array<string, string|list<string>>  $visibleWhen  shown and saved only while each named
     *                                                           sibling field has one of these values
     * @param  array<string, string|list<string>>  $jsonWhen  edited and checked as JSON while each named
     *                                                        sibling field has one of these values
     * @param  array<int|string, array<string, string|list<string>>>  $optionsWhen  for select fields, option value =>
     *                                                                              the sibling values it is offered for;
     *                                                                              an option not listed is always offered
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = self::TEXT,
        public bool $required = false,
        public mixed $default = null,
        public ?string $description = null,
        public bool $templated = false,
        public array $options = [],
        public array $credentialTypes = [],
        public array $fields = [],
        public array $visibleWhen = [],
        public array $jsonWhen = [],
        public array $optionsWhen = [],
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
        return $this->siblingsMatch($this->visibleWhen, $values, $siblings);
    }

    /**
     * Whether the editor edits this field as JSON: a JSON field always, any
     * other while its sibling fields have the values $jsonWhen names.
     *
     * @param  array<string, mixed>  $values
     * @param  list<Field>  $siblings
     */
    public function isJson(array $values, array $siblings = []): bool
    {
        return $this->type === self::JSON || ($this->jsonWhen !== [] && $this->siblingsMatch($this->jsonWhen, $values, $siblings));
    }

    /**
     * A select field's options that fit its sibling fields' values.
     *
     * @param  array<string, mixed>  $values
     * @param  list<Field>  $siblings
     * @return array<int|string, string>
     */
    public function optionsFor(array $values, array $siblings = []): array
    {
        return array_filter(
            $this->options,
            fn (int|string $option) => ! isset($this->optionsWhen[$option]) || $this->siblingsMatch($this->optionsWhen[$option], $values, $siblings),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * For a credential field, the OAuth type among the ones it accepts whose
     * accounts connect right from the node, such as GitHub's, if any.
     */
    public function connectableOAuthType(): ?OAuthCredentialType
    {
        $registry = app(CredentialTypeRegistry::class);

        foreach ($this->credentialTypes as $key) {
            $type = $registry->has($key) ? $registry->get($key) : null;

            if ($type instanceof OAuthCredentialType && $type->connectsFromNodes()) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|list<string>>  $conditions
     * @param  array<string, mixed>  $values
     * @param  list<Field>  $siblings
     */
    private function siblingsMatch(array $conditions, array $values, array $siblings): bool
    {
        foreach ($conditions as $name => $allowed) {
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
            'options' => $this->options,
            'credential_types' => $this->credentialTypes,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
            'visible_when' => $this->visibleWhen,
            'json_when' => $this->jsonWhen,
            'options_when' => $this->optionsWhen,
        ];
    }
}
