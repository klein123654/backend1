<?php

namespace Tests\Feature;

use App\Models\PropertyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII='
        ));
    }

    public function test_a_listing_cannot_have_more_than_ten_photos(): void
    {
        [$owner, $listing] = $this->listingWithPhotos(9);
        Sanctum::actingAs($owner);

        $this->post("/api/my/properties/{$listing->id}", [
            '_method' => 'PATCH',
            'images' => [$this->png('a.png'), $this->png('b.png')],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.images.0', 'A listing can have up to 10 photos. You can add 1 more.');
        $this->assertSame(9, $listing->images()->count());

        $this->post("/api/my/properties/{$listing->id}", [
            '_method' => 'PATCH',
            'images' => [$this->png('a.png')],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(10, $listing->images()->count());
    }

    public function test_removing_photos_makes_room_for_new_ones(): void
    {
        [$owner, $listing, $images] = $this->listingWithPhotos(10);
        Sanctum::actingAs($owner);

        $this->post("/api/my/properties/{$listing->id}", [
            '_method' => 'PATCH',
            'remove_image_ids' => [$images[0]->id],
            'images' => [$this->png('a.png')],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(10, $listing->images()->count());
    }

    public function test_wrong_photo_type_gets_a_plain_message(): void
    {
        [$owner, $listing] = $this->listingWithPhotos(1);
        Sanctum::actingAs($owner);

        $response = $this->post("/api/my/properties/{$listing->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame('Each photo must be a JPG, PNG or WebP image.', $response->json('errors')['images.0'][0]);
    }
}
