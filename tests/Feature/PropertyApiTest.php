<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PropertyApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'owner'): User
    {
        return User::create([
            'name' => 'Test '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function makeProperty(User $owner, array $attributes = []): Property
    {
        $title = $attributes['title'] ?? 'Test listing '.Str::random(8);

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
        $admin = $this->makeUser('admin');

        $this->makeProperty($owner, [
            'title' => 'Published listing',
            'slug' => 'published-listing',
            'status' => 'published',
            'published_at' => now(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => 'Internal approval note.',
        ]);

        $this->makeProperty($owner, [
            'title' => 'Draft listing',
            'slug' => 'draft-listing',
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/properties');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published listing')
            ->assertJsonMissingPath('data.0.reviewed_by')
            ->assertJsonMissingPath('data.0.reviewed_at')
            ->assertJsonMissingPath('data.0.review_notes');
    }

    public function test_public_search_filters_published_listings_by_ai_tags(): void
    {
        $owner = $this->makeUser();
        $taggedProperty = $this->makeProperty($owner, [
            'title' => 'Port-side warehouse',
            'slug' => 'port-side-warehouse',
            'status' => 'published',
            'published_at' => now(),
            'ai_tags' => ['near port', 'high-ceiling'],
        ]);
        $this->makeProperty($owner, [
            'title' => 'Retail storefront',
            'slug' => 'retail-storefront',
            'status' => 'published',
            'published_at' => now(),
            'ai_tags' => ['corner lot'],
        ]);

        $this->getJson('/api/properties?ai_tags%5B%5D=near%20port')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $taggedProperty->id)
            ->assertJsonPath('data.0.ai_tags', ['near port', 'high-ceiling']);
    }

    public function test_public_property_details_do_not_expose_administrator_review_fields(): void
    {
        $owner = $this->makeUser();
        $admin = $this->makeUser('admin');
        $property = $this->makeProperty($owner, [
            'status' => 'published',
            'published_at' => now(),
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => 'Internal approval note.',
        ]);

        $this->getJson('/api/properties/'.$property->id)
            ->assertOk()
            ->assertJsonMissingPath('data.reviewed_by')
            ->assertJsonMissingPath('data.reviewed_at')
            ->assertJsonMissingPath('data.review_notes');
    }

    public function test_public_property_details_include_saved_map_coordinates(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner, [
            'status' => 'published',
            'published_at' => now(),
            'latitude' => 8.9475,
            'longitude' => 125.5406,
        ]);

        $this->getJson('/api/properties/'.$property->id)
            ->assertOk()
            ->assertJsonPath('data.latitude', '8.9475000')
            ->assertJsonPath('data.longitude', '125.5406000');
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

        $this->getJson('/api/properties/'.$property->id)
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

    public function test_owner_can_create_a_property_when_ai_tagging_is_unavailable(): void
    {
        config(['services.deepseek.api_key' => null]);
        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);

        $this->postJson('/api/my/properties', [
            'title' => 'Untagged commercial space',
            'property_type' => 'Warehouses',
            'listing_type' => 'rent',
            'description' => 'Commercial warehouse space for business use.',
            'price' => 50000,
            'address_line' => 'J.C. Aquino Avenue',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Untagged commercial space')
            ->assertJsonPath('data.ai_tags', []);

        $this->assertDatabaseHas('properties', [
            'title' => 'Untagged commercial space',
            'ai_tags' => '[]',
        ]);
    }

    public function test_property_image_upload_uses_a_bucket_relative_path_and_preserves_mime_type(): void
    {
        config(['services.deepseek.api_key' => null]);
        config(['filesystems.default' => 'supabase']);
        Storage::fake('supabase');
        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);

        $response = $this->post('/api/my/properties', [
            'title' => 'Image Upload Test Property',
            'property_type' => 'Warehouses',
            'listing_type' => 'rent',
            'description' => 'A commercial warehouse listing for image upload verification.',
            'price' => 50000,
            'address_line' => 'J.C. Aquino Avenue',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'images' => [UploadedFile::fake()->createWithContent(
                'warehouse.png',
                base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII='),
            )],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $imagePath = $response->json('data.images.0.path');
        $objectPath = parse_url($imagePath, PHP_URL_PATH);

        $this->assertIsString($objectPath);
        $this->assertStringContainsString('/properties/', $objectPath);
        $this->assertStringNotContainsString('/property-images/property-images/', $objectPath);

        $storagePath = basename($objectPath);
        $storedPath = 'properties/'.$storagePath;
        Storage::disk('supabase')->assertExists($storedPath);
        $this->assertSame('image/png', Storage::disk('supabase')->mimeType($storedPath));
    }

    public function test_owner_can_save_map_coordinates_with_a_listing(): void
    {
        $owner = $this->makeUser('owner');
        Sanctum::actingAs($owner);

        $this->postJson('/api/my/properties', [
            'title' => 'Pinned test listing',
            'property_type' => 'Retail Spaces',
            'listing_type' => 'rent',
            'description' => 'A listing with a selected map location.',
            'price' => 25000,
            'address_line' => 'J.C. Aquino Avenue',
            'barangay' => 'Dagohoy Pob.',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'latitude' => 8.9475,
            'longitude' => 125.5406,
        ])->assertCreated()
            ->assertJsonPath('data.latitude', 8.9475)
            ->assertJsonPath('data.longitude', 125.5406);
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

        $this->patchJson('/api/my/properties/'.$property->id, [
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

        $this->patchJson('/api/my/properties/'.$property->id, [
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
