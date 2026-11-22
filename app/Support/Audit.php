<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records who changed workflows, secrets, membership and organization settings,
 * and every secret reveal.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  User|null  $actor  who acted, when it is not the signed-in user
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Workspace $workspace = null,
        array $metadata = [],
        ?string $label = null,
        ?User $actor = null,
    ): AuditLog {
        $organizationId = $workspace->organization_id ?? Organization::current()?->id;

        return AuditLog::create([
            'organization_id' => $organizationId,
            'workspace_id' => $workspace?->id,
            'user_id' => $actor->id ?? Auth::id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $label ?? static::labelFor($subject),
            'metadata' => $metadata ?: null,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    protected static function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        foreach (['key', 'name', 'email'] as $attribute) {
            if (filled($subject->getAttribute($attribute))) {
                return (string) $subject->getAttribute($attribute);
            }
        }

        return null;
    }
}
