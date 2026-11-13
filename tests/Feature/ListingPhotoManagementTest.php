<?php

namespace Tests\Feature;

use App\Models\PropertyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class ListingPhotoManagementTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function listingWithPhotos(int $count = 3): array
    {
        config(['filesystems.default' => 'public']);
        Storage::fake('public');
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner);
        $images = [];
        for ($i = 0; $i < $count; $i++) {
            Storage::disk('public')->put("properties/photo{$i}.jpg", 'x');
            $images[] = PropertyImage::create([
                'property_id' => $listing->id, 'path' => "/storage/properties/photo{$i}.jpg",
                'sort_order' => $i, 'is_primary' => $i === 0,
            ]);
        }

        return [$owner, $listing, $images];
    }

    public function test_owner_can_remove_reorder_and_choose_cover_without_re_review(): void
    {
        [$owner, $listing, $images] = $this->listingWithPhotos();
        Sanctum::actingAs($owner);

        $this->patchJson("/api/my/properties/{$listing->id}", [
            'remove_image_ids' => [$images[0]->id],
            'image_order' => [$images[2]->id, $images[1]->id],
            'primary_image_id' => $images[2]->id,
        ])->assertOk();

        $remaining = $listing->fresh()->images;
        $this->assertSame([$images[2]->id, $images[1]->id], $remaining->pluck('id')->all());
        $this->assertTrue($remaining->first()->is_primary);
        $this->assertFalse($remaining->last()->is_primary);
        Storage::disk('public')->assertMissing('properties/photo0.jpg');
        $this->assertSame('published', $listing->fresh()->status, 'Removing or reordering photos keeps the listing live');
    }

    public function test_cannot_remove_every_photo(): void
    {
        [$owner, $listing, $images] = $this->listingWithPhotos(1);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/my/properties/{$listing->id}", ['remove_image_ids' => [$images[0]->id]])
            ->assertStatus(422)->assertJsonValidationErrors('images');
        $this->assertModelExists($images[0]);
    }

    public function test_cannot_touch_photos_of_another_listing(): void
    {
        [$owner, $listing] = $this->listingWithPhotos(1);
        [, , $foreign] = $this->listingWithPhotos(1);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/my/properties/{$listing->id}", ['remove_image_ids' => [$foreign[0]->id]])
            ->assertStatus(422);
        $this->assertModelExists($foreign[0]);
    }
}
