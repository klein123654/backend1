<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PropertySortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::create([
            'name' => 'Owner',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => 'owner',
            'account_status' => 'active',
        ]);

        foreach ([['Mid', 2000000, 2], ['Cheap', 500000, 1], ['Pricey', 9000000, 3]] as [$title, $price, $daysAgo]) {
            Property::create([
                'owner_id' => $owner->id,
                'title' => $title,
                'slug' => Str::slug($title),
                'property_type' => 'house',
                'listing_type' => 'sale',
                'description' => 'Sorting test listing.',
                'price' => $price,
                'currency' => 'PHP',
                'status' => 'published',
                'published_at' => now()->subDays($daysAgo),
                'address_line' => 'Test address',
                'city' => 'Butuan City',
                'province' => 'Agusan del Norte',
            ]);
        }
    }

    public function test_listings_can_be_sorted_by_price_ascending(): void
    {
        $this->getJson('/api/properties?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Cheap', 'Mid', 'Pricey']);
    }

    public function test_listings_can_be_sorted_by_price_descending(): void
    {
        $this->getJson('/api/properties?sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Pricey', 'Mid', 'Cheap']);
    }

    public function test_listings_default_to_newest_first(): void
    {
        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Cheap', 'Mid', 'Pricey']);
    }

    public function test_unknown_sort_option_is_rejected(): void
    {
        $this->getJson('/api/properties?sort=random')->assertUnprocessable();
    }
}
