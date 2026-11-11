<?php

namespace Tests\Feature;

use App\Models\BrokerVerification;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublisherDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test '.Str::random(6),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('TestPassword123!'),
            'role' => $role,
            'account_status' => 'active',
        ], $attributes));
    }

    private function makeVerifiedBroker(array $attributes = []): User
    {
        $broker = $this->makeUser('broker', $attributes);
        BrokerVerification::create([
            'broker_id' => $broker->id,
            'license_number' => 'PRC-0012345',
            'agency_name' => 'Agusan Realty',
            'status' => 'verified',
        ]);

        return $broker;
    }

    private function makeListing(User $owner, array $attributes = []): Property
    {
        $title = 'Listing '.Str::random(6);

        return Property::create(array_merge([
            'owner_id' => $owner->id,
            'broker_id' => $owner->role === 'broker' ? $owner->id : null,
            'title' => $title,
            'slug' => Str::slug($title),
            'property_type' => 'office',
            'listing_type' => 'rent',
            'description' => 'Office space.',
            'price' => 30000,
            'currency' => 'PHP',
            'status' => 'published',
            'published_at' => now(),
            'availability' => 'available',
            'availability_confirmed_at' => now(),
            'address_line' => 'J.C. Aquino Ave',
            'barangay' => 'Libertad',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ], $attributes));
    }

    public function test_public_listing_shows_verified_broker_licence_and_contact_links(): void
    {
        $broker = $this->makeVerifiedBroker([
            'contact_phone' => '09171234567',
            'messenger_username' => 'agusan.realty',
            'viber_number' => '09181234567',
        ]);
        $listing = $this->makeListing($broker);

        $this->getJson('/api/properties/'.$listing->id)
            ->assertOk()
            ->assertJsonPath('data.publisher.verified', true)
            ->assertJsonPath('data.publisher.license_number', 'PRC-0012345')
            ->assertJsonPath('data.publisher.agency_name', 'Agusan Realty')
            ->assertJsonPath('data.publisher.contact.phone', '09171234567')
            ->assertJsonPath('data.publisher.contact.messenger_url', 'https://m.me/agusan.realty')
            ->assertJsonPath('data.publisher.contact.viber_url', 'viber://chat?number=%2B639181234567')
            ->assertJsonMissingPath('data.owner.contact_phone');
    }

    public function test_owner_listings_are_marked_verified_without_a_licence(): void
    {
        $listing = $this->makeListing($this->makeUser('owner'));

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonPath('data.0.publisher.verified', true)
            ->assertJsonPath('data.0.publisher.role_label', 'Property owner')
            ->assertJsonPath('data.0.publisher.license_number', null);
    }

    public function test_publisher_profile_shows_verified_broker_and_live_listing_count(): void
    {
        $broker = $this->makeVerifiedBroker();
        $this->makeListing($broker);
        $this->makeListing($broker, ['availability' => 'rented']);

        $this->getJson('/api/publishers/'.$broker->id)
            ->assertOk()
            ->assertJsonPath('data.name', $broker->name)
            ->assertJsonPath('data.active_listing_count', 1);
    }

    public function test_profiles_are_not_available_for_buyers_or_unverified_brokers(): void
    {
        $this->getJson('/api/publishers/'.$this->makeUser('buyer')->id)->assertNotFound();
        $this->getJson('/api/publishers/'.$this->makeUser('broker')->id)->assertNotFound();
    }

    public function test_listings_can_be_filtered_by_publisher_and_barangay(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeListing($owner, ['title' => 'Libertad Office']);
        $this->makeListing($owner, ['title' => 'Doongan Shop', 'barangay' => 'Doongan']);
        $this->makeListing($this->makeUser('owner'), ['title' => 'Someone Else']);

        $this->getJson('/api/properties?publisher='.$owner->id.'&barangay=libertad')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Libertad Office']);
    }

    public function test_barangay_counts_include_only_publicly_visible_listings(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeListing($owner);
        $this->makeListing($owner);
        $this->makeListing($owner, ['barangay' => 'Doongan']);
        $this->makeListing($owner, ['barangay' => 'Doongan', 'availability' => 'sold']);
        $this->makeListing($owner, ['barangay' => 'Ampayon', 'status' => 'pending', 'published_at' => null]);

        $this->getJson('/api/barangays')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['barangay' => 'Libertad', 'listing_count' => 2],
                ['barangay' => 'Doongan', 'listing_count' => 1],
            ]]);
    }

    public function test_publishers_can_save_public_contact_details(): void
    {
        Sanctum::actingAs($this->makeUser('owner'));

        $this->patchJson('/api/user', [
            'contact_phone' => '09171234567',
            'contact_email' => 'leasing@example.test',
            'messenger_username' => 'butuan.leasing',
        ])
            ->assertOk()
            ->assertJsonPath('user.contact_phone', '09171234567')
            ->assertJsonPath('user.messenger_username', 'butuan.leasing');
    }

    public function test_invalid_contact_numbers_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('broker'));

        $this->patchJson('/api/user', ['contact_phone' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_phone');
    }

    public function test_buyers_cannot_set_public_contact_details(): void
    {
        $buyer = $this->makeUser('buyer');
        Sanctum::actingAs($buyer);

        $this->patchJson('/api/user', ['contact_phone' => '09171234567'])->assertOk();

        $this->assertNull($buyer->fresh()->contact_phone);
    }
}
