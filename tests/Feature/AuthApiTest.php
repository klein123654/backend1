<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'phone' => '09171234567',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'role'],
            ])
            ->assertJsonPath('user.role', 'buyer');

        $this->assertDatabaseHas('users', [
            'email' => 'buyer@example.com',
            'role' => 'buyer',
            'account_status' => 'active',
        ]);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Another Buyer',
            'email' => 'existing@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertUnprocessable();
    }

    public function test_user_can_log_in_and_get_a_token(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'SecurePass123!',
            'account_status' => 'active',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'SecurePass123!',
        ])->assertOk()
          ->assertJsonStructure(['token', 'user'])
          ->assertJsonPath('user.email', 'login@example.com');
    }

    public function test_login_rejects_incorrect_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'WrongPassword123!',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_view_profile_and_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
