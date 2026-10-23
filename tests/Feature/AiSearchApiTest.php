<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_a_friendly_message_when_deepseek_is_not_configured(): void
    {
        config(['services.deepseek.api_key' => null]);

        $this->postJson('/api/ai/search', [
            'query' => 'warehouse space in Butuan',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
            ]);
    }

    public function test_search_is_limited_to_ten_requests_per_minute_for_guests(): void
    {
        config(['services.deepseek.api_key' => null]);

        for ($requestNumber = 1; $requestNumber <= 10; $requestNumber++) {
            $this->postJson('/api/ai/search', [
                'query' => 'warehouse space in Butuan',
            ])->assertOk();
        }

        $this->postJson('/api/ai/search', [
            'query' => 'warehouse space in Butuan',
        ])->assertStatus(429);
    }

    public function test_search_is_limited_to_ten_requests_per_minute_for_each_sanctum_user(): void
    {
        config(['services.deepseek.api_key' => null]);
        $user = User::create([
            'name' => 'AI Search Test',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'buyer',
            'account_status' => 'active',
        ]);
        Sanctum::actingAs($user);

        for ($requestNumber = 1; $requestNumber <= 10; $requestNumber++) {
            $this->postJson('/api/ai/search', [
                'query' => 'warehouse space in Butuan',
            ])->assertOk();
        }

        $this->postJson('/api/ai/search', [
            'query' => 'warehouse space in Butuan',
        ])->assertStatus(429);
    }
}
