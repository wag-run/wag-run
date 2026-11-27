<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeactivatedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_user_cannot_sign_in(): void
    {
        User::factory()->orgAdmin()->create();
        $user = User::factory()->deactivated()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This account has been deactivated.']);

        $this->assertGuest();
    }

    public function test_deactivated_user_with_wrong_password_gets_the_standard_failure(): void
    {
        User::factory()->orgAdmin()->create();
        $user = User::factory()->deactivated()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_signed_in_user_is_signed_out_once_deactivated(): void
    {
        User::factory()->orgAdmin()->create();
        $user = User::factory()->deactivated()->create();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_active_user_with_wrong_password_gets_the_standard_failure(): void
    {
        $user = User::factory()->orgAdmin()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
