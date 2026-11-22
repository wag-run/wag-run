<?php

namespace App\Credentials;

use InvalidArgumentException;

class CredentialTypeRegistry
{
    /**
     * @var array<string, CredentialType>
     */
    protected array $types = [];

    public function register(CredentialType $type): void
    {
        $this->types[$type->key()] = $type;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    public function get(string $key): CredentialType
    {
        return $this->types[$key] ?? throw new InvalidArgumentException("Unknown authentication type [{$key}].");
    }

    /**
     * @return array<string, CredentialType>
     */
    public function all(): array
    {
        return $this->types;
    }
}
