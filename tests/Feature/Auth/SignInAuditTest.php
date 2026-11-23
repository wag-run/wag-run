<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SignInAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_password_sign_in_as_an_organization_wide_entry(): void
    {
        $user = User::factory()->orgMember()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $entry = AuditLog::query()->where('action', 'auth.signed_in')->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame($user->id, $entry->subject_id);
        $this->assertSame($user->email, $entry->subject_label);
        $this->assertNull($entry->workspace_id);
        $this->assertSame(['method' => 'password'], $entry->metadata);
    }

    public function test_does_not_record_a_failed_sign_in(): void
    {
        $user = User::factory()->orgMember()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.signed_in']);
    }

    public function test_does_not_record_a_session_restored_from_the_remember_me_cookie(): void
    {
        $user = User::factory()->orgMember()->create(['remember_token' => 'remember-token']);
        $recaller = Auth::guard('web')->getRecallerName();

        $this->withCookie($recaller, $user->id.'|remember-token|'.$user->getAuthPassword())
            ->get(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.signed_in']);
    }
}
