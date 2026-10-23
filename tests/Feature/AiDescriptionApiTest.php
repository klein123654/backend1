<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiDescriptionApiTest extends TestCase
{
    public function test_returns_a_friendly_http_200_response_when_deepseek_is_not_configured(): void
    {
        config(['services.deepseek.api_key' => null]);

        $this->postJson('/api/ai/describe', [
            'title' => 'Warehouse opportunity',
            'size' => 350,
            'location' => 'J.C. Aquino Avenue, Butuan City, Agusan del Norte',
            'price' => 50000,
            'type' => 'Warehouses',
            'listing_type' => 'For Rent',
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
            ]);
    }
}
