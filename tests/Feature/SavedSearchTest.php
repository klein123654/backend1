<?php

namespace Tests\Feature;

use App\Notifications\SavedSearchMatched;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    public function test_user_can_save_list_and_delete_searches(): void
    {
        Sanctum::actingAs($user = $this->makeUser());

        $id = $this->postJson('/api/my/saved-searches', [
            'name' => 'Warehouses in Bancasi',
            'filters' => ['property_type' => 'Warehouses', 'barangay' => 'Bancasi', 'max_price' => 100000, 'sort' => 'ignored'],
        ])->assertCreated()->assertJsonPath('data.filters.barangay', 'Bancasi')->assertJsonMissingPath('data.filters.sort')->json('data.id');

        $this->getJson('/api/my/saved-searches')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->makeUser());
        $this->deleteJson("/api/my/saved-searches/{$id}")->assertNotFound();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/my/saved-searches/{$id}")->assertOk();
        $this->getJson('/api/my/saved-searches')->assertJsonCount(0, 'data');
    }

    public function test_empty_search_cannot_be_saved(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/my/saved-searches', ['name' => 'Anything', 'filters' => ['search' => '']])->assertStatus(422);
    }

    public function test_approving_a_matching_listing_emails_subscribers_once(): void
    {
        Notification::fake();
        $owner = $this->makeUser('owner');
        $match = $this->makeUser();
        $noMatch = $this->makeUser();
        $match->savedSearches()->create(['name' => 'Cheap warehouses', 'filters' => ['property_type' => 'Warehouses', 'max_price' => 60000]]);
        $match->savedSearches()->create(['name' => 'Libertad', 'filters' => ['barangay' => 'libertad']]);
        $noMatch->savedSearches()->create(['name' => 'Offices', 'filters' => ['property_type' => 'Office Spaces']]);
        $listing = $this->makeListing($owner, ['status' => 'pending', 'published_at' => null]);

        Sanctum::actingAs($this->makeUser('admin'));
        $this->patchJson("/api/admin/properties/{$listing->id}", ['status' => 'published'])->assertOk();

        Notification::assertSentToTimes($match, SavedSearchMatched::class, 1);
        Notification::assertNotSentTo($noMatch, SavedSearchMatched::class);
    }
}
