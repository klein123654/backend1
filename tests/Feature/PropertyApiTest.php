<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'owner'): User
    {
        return User::create([
            'name' => 'Test ' . Str::random(8),
            'email' => Str::uuid() . '@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function makeProperty(User $owner, array $attributes = []): Property
    {
        $title = $attributes['title'] ?? 'Test listing ' . Str::random(8);

        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'property_type' => 'house',
            'listing_type' => 'sale',
            'description' => 'Automated test listing.',
            'price' => 1000000,
            'currency' => 'PHP',
            'status' => 'draft',
            'address_line' => 'Test address',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ], $attributes));
    }

    public function test_public_search_returns_only_published_listings(): void
    {
        $owner = $this->makeUser();

        $this->makeProperty($owner, [
            'title' => 'Published listing',
            'slug' => 'published-listing',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->makeProperty($owner, [
            'title' => 'Draft listing',
            'slug' => 'draft-listing',
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/properties');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published listing');
    }

    public function test_public_search_can_filter_by_city_and_price(): void
    {
        $owner = $this->makeUser();

        $this->makeProperty($owner, [
            'title' => 'Matching listing',
            'slug' => 'matching-listing',
            'city' => 'Butuan City',
            'price' => 1500000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->makeProperty($owner, [
            'title' => 'Other city listing',
            'slug' => 'other-city-listing',
            'city' => 'Cebu City',
            'price' => 1500000,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->getJson(
            '/api/properties?city=butuan&min_price=1000000&max_price=2000000'
        );

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Matching listing');
    }

    public function test_public_cannot_view_a_draft_listing(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner);

        $this->getJson('/api/properties/' . $property->id)
            ->assertNotFound();
    }

    public function test_owner_can_create_a_draft_listing(): void
    {
        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/my/properties', [
            'title' => 'New test listing',
            'property_type' => 'house',
            'listing_type' => 'sale',
            'description' => 'A property created during an automated test.',
            'price' => 2500000,
            'address_line' => 'Example street',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'owner_id' => 999999,
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.owner_id', $owner->id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        $this->assertDatabaseHas('properties', [
            'title' => 'New test listing',
            'owner_id' => $owner->id,
            'status' => 'draft',
        ]);
    }

    public function test_non_owner_cannot_create_a_listing(): void
    {
        Sanctum::actingAs($this->makeUser('buyer'));

        $this->postJson('/api/my/properties', [
            'title' => 'Not allowed',
            'property_type' => 'house',
            'listing_type' => 'sale',
            'description' => 'Test description.',
            'price' => 100000,
            'address_line' => 'Example street',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ])->assertForbidden();
    }

    public function test_owner_cannot_update_another_owners_property(): void
    {
        $firstOwner = $this->makeUser('owner');
        $secondOwner = $this->makeUser('owner');
        $property = $this->makeProperty($firstOwner);

        Sanctum::actingAs($secondOwner);

        $this->patchJson('/api/my/properties/' . $property->id, [
            'title' => 'Unauthorized edit',
        ])->assertForbidden();

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'title' => $property->title,
        ]);
    }

    public function test_owner_can_update_their_own_listing_without_publishing_it(): void
    {
        $owner = $this->makeUser('owner');
        $property = $this->makeProperty($owner);

        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/' . $property->id, [
            'title' => 'Updated title',
            'status' => 'published',
            'owner_id' => 999999,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.owner_id', $owner->id);
    }

    public function test_owner_listing_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/my/properties')->assertUnauthorized();
    }
}
