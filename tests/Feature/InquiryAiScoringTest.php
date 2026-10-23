<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InquiryAiScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_is_saved_with_unscored_priority_when_deepseek_is_not_configured(): void
    {
        config(['services.deepseek.api_key' => null]);
        $buyer = User::create([
            'name' => 'Inquiry Buyer',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'buyer',
            'account_status' => 'active',
        ]);
        $owner = User::create([
            'name' => 'Listing Owner',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'owner',
            'account_status' => 'active',
        ]);
        $property = Property::create([
            'owner_id' => $owner->id,
            'title' => 'Butuan Commercial Warehouse',
            'slug' => 'butuan-commercial-warehouse',
            'property_type' => 'Warehouses',
            'listing_type' => 'rent',
            'description' => 'Commercial warehouse space.',
            'price' => 50000,
            'currency' => 'PHP',
            'status' => 'published',
            'address_line' => 'J.C. Aquino Avenue',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'published_at' => now(),
        ]);
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/properties/'.$property->id.'/inquiries', [
            'name' => $buyer->name,
            'email' => $buyer->email,
            'phone' => '09123456789',
            'message' => 'I have a budget of PHP 50,000 per month and need a warehouse by next month.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.ai_summary', null)
            ->assertJsonPath('data.ai_priority', 'unscored');

        $this->assertDatabaseHas('inquiries', [
            'property_id' => $property->id,
            'buyer_id' => $buyer->id,
            'ai_summary' => null,
            'ai_priority' => 'unscored',
        ]);
    }
}
