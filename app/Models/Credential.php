<?php

namespace App\Models;

use App\Credentials\CredentialType;
use App\Credentials\CredentialTypeRegistry;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable workspace authentication setting (HTTP Basic, API token, AI
 * provider key, OAuth connection). The whole config is encrypted at rest.
 *
 * Nodes refer to a setting by its UUID, and people know it by its
 * description, which is unique in the workspace.
 *
 * @property string $id a UUID
 * @property int $workspace_id
 * @property string $type
 * @property array<string, mixed> $config
 * @property string $description
 * @property-read Workspace $workspace
 */
#[Fillable(['type', 'config', 'description'])]
#[Hidden(['config'])]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory, HasUuids;

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
     * The secret values in the config, for redaction.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        return $this->credentialType()->secretValues($this->config);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
