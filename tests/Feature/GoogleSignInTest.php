<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class GoogleSignInTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function fakeGoogle(array $claims = []): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'test-client.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com',
            'exp' => (string) (time() + 600), 'email' => 'Juan@Example.test', 'email_verified' => 'true', 'name' => 'Juan Dela Cruz',
            ...$claims,
        ])]);
    }

    public function test_disabled_without_client_id(): void
    {
        config(['services.google.client_id' => null]);
        $this->postJson('/api/auth/google', ['credential' => 'x'])->assertNotFound();
    }

    public function test_first_sign_in_creates_a_verified_buyer(): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'token'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'juan@example.test')
            ->assertJsonPath('user.role', 'buyer')
            ->assertJsonPath('user.email_verified', true);
    }

    public function test_existing_account_signs_in_with_matching_email(): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);
        $existing = $this->makeUser('owner', ['email' => 'juan@example.test']);
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'token'])
            ->assertOk()->assertJsonPath('user.id', $existing->id);
        $this->assertSame(1, User::count());
    }

    public function test_rejects_tokens_issued_for_another_app(): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);
        $this->fakeGoogle(['aud' => 'someone-else']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(422);
        $this->assertSame(0, User::count());
    }

    public function test_rejects_unverified_google_email(): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertStatus(422);
        $this->assertSame(0, User::count());
    }

    public function test_suspended_account_cannot_sign_in(): void
    {
        config(['services.google.client_id' => 'test-client.apps.googleusercontent.com']);
        $this->makeUser('buyer', ['email' => 'juan@example.test', 'account_status' => 'suspended']);
        $this->fakeGoogle();

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertForbidden();
    }
}
