<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Notifications\ListingRemoved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingEditAndDeleteTest extends TestCase
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

    private function makeProperty(User $owner, string $status = 'published'): Property
    {
        $title = 'Listing '.Str::random(6);

        return Property::create([
            'owner_id' => $owner->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'property_type' => 'office',
            'listing_type' => 'rent',
            'description' => 'Original description.',
            'price' => 50000,
            'currency' => 'PHP',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'address_line' => 'Test address',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
        ]);
    }

    public function test_editing_a_published_listing_sends_it_back_to_review(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/'.$property->id, ['price' => 65000])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.published_at', null);

        $this->getJson('/api/properties/'.$property->id)->assertNotFound();
    }

    public function test_editing_a_draft_keeps_it_a_draft(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner, 'draft');
        Sanctum::actingAs($owner);

        $this->patchJson('/api/my/properties/'.$property->id, ['price' => 65000])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_owner_can_delete_their_published_listing_and_its_inquiries(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner);
        Inquiry::create([
            'property_id' => $property->id,
            'buyer_id' => $this->makeUser('buyer')->id,
            'name' => 'Buyer',
            'email' => 'buyer@example.test',
            'message' => 'Is this still available?',
            'status' => 'new',
        ]);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/my/properties/'.$property->id)->assertOk();

        $this->assertModelMissing($property);
        $this->assertDatabaseCount('inquiries', 0);
    }

    public function test_owner_cannot_edit_or_delete_another_owners_listing(): void
    {
        $property = $this->makeProperty($this->makeUser());
        Sanctum::actingAs($this->makeUser());

        $this->patchJson('/api/my/properties/'.$property->id, ['price' => 1])->assertForbidden();
        $this->deleteJson('/api/my/properties/'.$property->id)->assertForbidden();
        $this->assertModelExists($property);
    }

    public function test_admin_can_delete_any_listing_and_the_owner_is_emailed(): void
    {
        Notification::fake();
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner, 'pending');
        Sanctum::actingAs($this->makeUser('admin'));

        $this->deleteJson('/api/admin/properties/'.$property->id, ['reason' => 'Test listing'])->assertOk();

        $this->assertModelMissing($property);
        Notification::assertSentTo($owner, ListingRemoved::class, fn (ListingRemoved $notification) => $notification->reason === 'Test listing');
    }

    public function test_non_admin_cannot_use_the_admin_delete_endpoint(): void
    {
        $owner = $this->makeUser();
        $property = $this->makeProperty($owner);
        Sanctum::actingAs($owner);

        $this->deleteJson('/api/admin/properties/'.$property->id)->assertForbidden();
        $this->assertModelExists($property);
    }
}
