<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TermsAndPasswordRulesTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'email' => 'person@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registration_requires_agreeing_to_the_terms(): void
    {
        $this->postJson('/api/register', $this->registration(['accept_terms' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.accept_terms.0', 'Please agree to the Terms of Use and Privacy Policy to create an account.');
        $this->assertDatabaseMissing('users', ['email' => 'person@example.test']);
    }

    public function test_registration_records_when_and_which_terms_were_accepted(): void
    {
        Notification::fake();
        config(['app.terms_version' => '2026-11-17']);

        $this->postJson('/api/register', $this->registration())->assertCreated();

        $user = User::where('email', 'person@example.test')->firstOrFail();
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('2026-11-17', $user->terms_version);
    }

    public function test_new_google_accounts_record_terms_acceptance(): void
    {
        config(['services.google.client_id' => 'client-123', 'app.terms_version' => '2026-11-17']);
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'client-123', 'iss' => 'https://accounts.google.com', 'exp' => (string) (time() + 600),
            'email' => 'google.person@example.test', 'email_verified' => 'true', 'name' => 'Google Person',
        ])]);

        $this->postJson('/api/auth/google', ['credential' => 'token'])->assertCreated();

        $this->assertSame('2026-11-17', User::where('email', 'google.person@example.test')->value('terms_version'));
    }

    public function test_passwords_need_a_letter_and_a_number_everywhere(): void
    {
        $this->postJson('/api/register', $this->registration(['password' => 'onlyletters', 'password_confirmation' => 'onlyletters']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $user = User::factory()->create(['password' => 'CurrentPass123', 'account_status' => 'active']);
        Sanctum::actingAs($user);
        $this->putJson('/api/user/password', [
            'current_password' => 'CurrentPass123',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
