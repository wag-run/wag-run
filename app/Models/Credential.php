<?php

namespace App\Models;

use App\Credentials\CredentialType;
use App\Credentials\CredentialTypeRegistry;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable workspace authentication setting (HTTP Basic, API token, AI
 * provider key, and later OAuth). The whole config is encrypted at rest.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $key
 * @property string $type
 * @property array<string, mixed> $config
 * @property string|null $description
 */
#[Fillable(['key', 'type', 'config', 'description'])]
#[Hidden(['config'])]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
        ];
    }

    public function credentialType(): CredentialType
    {
        return app(CredentialTypeRegistry::class)->get($this->type);
    }

    /**
     * The config values of fields marked secret, for redaction.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        $values = [];

        foreach ($this->credentialType()->fields() as $field) {
            $value = $this->config[$field->name] ?? null;

            if ($field->secret && is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
