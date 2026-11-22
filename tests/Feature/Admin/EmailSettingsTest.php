<?php

namespace Tests\Feature\Admin;

use App\Mail\WorkflowEmail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admin_saves_smtp_settings_encrypted_and_keeps_the_password_when_left_empty(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $page = Livewire::actingAs($admin)->test('pages::admin.email');

        $page->set('enabled', true)->set('host', 'smtp.acme.test')->set('port', '587')->set('encryption', 'tls')
            ->set('username', 'robot')->set('password', 's3cret-pass')->set('fromAddress', 'robot@acme.test')->set('fromName', 'Acme')
            ->call('save')->assertHasNoErrors();
        $page->set('host', 'mail.acme.test')->call('save')->assertHasNoErrors();

        $this->assertSame([
            'host' => 'mail.acme.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'robot', 'password' => 's3cret-pass',
            'from_address' => 'robot@acme.test', 'from_name' => 'Acme',
        ], Organization::current()->mail);
        $this->assertStringNotContainsString('s3cret-pass', (string) DB::table('organizations')->value('mail'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.mail_updated']);
    }

    public function test_requires_a_host_and_a_valid_from_address(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.email')
            ->set('enabled', true)->set('host', '')->set('fromAddress', 'not-an-address')
            ->call('save')
            ->assertHasErrors(['host' => 'required', 'fromAddress' => 'email']);

        $this->assertNull(Organization::current()->mail);
    }

    public function test_turning_it_off_clears_the_settings(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        Organization::current()->update(['mail' => ['host' => 'smtp.acme.test', 'port' => 587, 'from_address' => 'robot@acme.test']]);

        Livewire::actingAs($admin)->test('pages::admin.email')->assertSet('enabled', true)->set('enabled', false)->call('save');

        $this->assertNull(Organization::current()->mail);
    }

    public function test_send_test_email_goes_to_the_admin_through_the_organization_smtp_server(): void
    {
        Mail::fake();
        $admin = User::factory()->orgAdmin()->create();
        Organization::current()->update(['mail' => ['host' => 'smtp.acme.test', 'port' => 587, 'from_address' => 'robot@acme.test']]);

        Livewire::actingAs($admin)->test('pages::admin.email')->call('sendTest');

        Mail::assertSent(WorkflowEmail::class, fn (WorkflowEmail $mail) => $mail->hasTo($admin->email) && $mail->mailer === 'organization');
    }
}
