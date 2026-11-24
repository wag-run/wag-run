<?php

namespace App\Credentials;

/**
 * One configurable field of an authentication setting type.
 */
final readonly class CredentialField
{
    /**
     * @param  'text'|'url'|'select'  $type
     * @param  array<string, string>  $options  value => label, for select fields
     * @param  bool  $multiline  edited in a text area, for a value with line breaks such as a private key
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',
        public bool $secret = false,
        public bool $required = true,
        public array $options = [],
        public ?string $default = null,
        public ?string $help = null,
        public bool $multiline = false,
    ) {}

    public static function text(string $name, string $label, bool $required = true, ?string $default = null, ?string $help = null): self
    {
        return new self($name, $label, required: $required, default: $default, help: $help);
    }

    public static function secret(string $name, string $label, bool $required = true, ?string $help = null, bool $multiline = false): self
    {
        return new self($name, $label, secret: true, required: $required, help: $help, multiline: $multiline);
    }

    public static function url(string $name, string $label, bool $required = false, ?string $help = null): self
    {
        return new self($name, $label, type: 'url', required: $required, help: $help);
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function select(string $name, string $label, array $options, ?string $default = null): self
    {
        return new self($name, $label, type: 'select', options: $options, default: $default);
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', 'string', $this->multiline ? 'max:16000' : 'max:4000'];

        if ($this->type === 'url') {
            $rules[] = 'url';
        }

        if ($this->type === 'select') {
            $rules[] = 'in:'.implode(',', array_keys($this->options));
        }

        return $rules;
    }
}
