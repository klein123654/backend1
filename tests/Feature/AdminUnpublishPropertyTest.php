<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUnpublishPropertyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Test '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ]);
    }

    private function makePublishedProperty(User $owner): Property
    {
        return Property::create([
            'owner_id' => $owner->id,
            'title' => 'Published test listing',
            'slug' => 'published-test-listing',
            'property_type' => 'Retail Spaces',
            'listing_type' => 'For Rent',
            'description' => 'Automated test listing.',
            'price' => 25000,
            'currency' => 'PHP',
            'status' => 'published',
            'address_line' => 'Test address',
            'barangay' => 'Bancasi',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'published_at' => now(),
        ]);
    }

    public function test_active_admin_can_unpublish_a_listing_and_it_disappears_from_public_search(): void
    {
        $admin = $this->makeUser('admin');
        $property = $this->makePublishedProperty($this->makeUser('owner'));
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/properties/'.$property->id.'/unpublish', [
            'review_notes' => 'Listing details need correction.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.reviewed_by', $admin->id)
            ->assertJsonPath('data.review_notes', 'Listing details need correction.');

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'status' => 'draft',
            'published_at' => null,
            'reviewed_by' => $admin->id,
        ]);

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_admin_cannot_unpublish_a_listing(): void
    {
        $property = $this->makePublishedProperty($this->makeUser('owner'));
        Sanctum::actingAs($this->makeUser('owner'));

        $this->patchJson('/api/admin/properties/'.$property->id.'/unpublish', [
            'review_notes' => 'Test reason.',
        ])->assertForbidden();

        $this->assertDatabaseHas('properties', [
            'id' => $property->id,
            'status' => 'published',
        ]);
    }

    public function test_admin_cannot_unpublish_a_listing_that_is_not_published(): void
    {
        $admin = $this->makeUser('admin');
        $property = $this->makePublishedProperty($this->makeUser('owner'));
        $property->update(['status' => 'draft', 'published_at' => null]);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/properties/'.$property->id.'/unpublish', [
            'review_notes' => 'Test reason.',
        ])->assertStatus(409);
    }

    public function test_unpublishing_requires_a_reason(): void
    {
        Sanctum::actingAs($this->makeUser('admin'));
        $property = $this->makePublishedProperty($this->makeUser('owner'));

        $this->patchJson('/api/admin/properties/'.$property->id.'/unpublish', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');
    }
}
