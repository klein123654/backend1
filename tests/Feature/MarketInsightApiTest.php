<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketInsightApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_published_listing_aggregates_and_message_without_ai_key(): void
    {
        config(['services.deepseek.api_key' => null]);
        $owner = User::create([
            'name' => 'Market Insight Owner',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'owner',
            'account_status' => 'active',
        ]);

        foreach ([100000, 300000] as $price) {
            Property::create([
                'owner_id' => $owner->id,
                'title' => 'Private listing title',
                'slug' => Str::uuid()->toString(),
                'property_type' => 'Warehouses',
                'listing_type' => 'sale',
                'description' => 'Private listing description.',
                'price' => $price,
                'currency' => 'PHP',
                'status' => 'published',
                'address_line' => 'Private street',
                'city' => 'Butuan City',
                'province' => 'Agusan del Norte',
                'published_at' => now(),
            ]);
        }

        Property::create([
            'owner_id' => $owner->id,
            'title' => 'Draft listing must not be counted',
            'slug' => Str::uuid()->toString(),
            'property_type' => 'Warehouses',
            'listing_type' => 'sale',
            'description' => 'Draft listing description.',
            'price' => 900000,
            'currency' => 'PHP',
            'status' => 'draft',
            'address_line' => 'Private street',
            'city' => 'Cebu City',
            'province' => 'Cebu',
        ]);

        $this->getJson('/api/ai/market-insight')
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'aggregates' => [
                    'average_price_by_type' => [[
                        'property_type' => 'Warehouses',
                        'average_price' => 200000,
                        'listing_count' => 2,
                    ]],
                    'count_by_location' => [[
                        'city' => 'Butuan City',
                        'province' => 'Agusan del Norte',
                        'listing_count' => 2,
                    ]],
                ],
                'summary' => null,
                'message' => 'AI service is not configured yet. Please add DEEPSEEK_API_KEY to .env.',
            ])
            ->assertJsonMissingPath('aggregates.average_price_by_type.0.title');
    }
}
