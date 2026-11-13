<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class ListingViewCountTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    public function test_views_are_counted_once_per_visitor_and_shown_only_to_the_owner(): void
    {
        $owner = $this->makeUser('owner');
        $listing = $this->makeListing($owner);

        $this->getJson("/api/properties/{$listing->id}")->assertOk()->assertJsonMissingPath('data.views_count');
        $this->getJson("/api/properties/{$listing->id}")->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])->getJson("/api/properties/{$listing->id}");
        $this->withHeader('User-Agent', 'facebookexternalhit/1.1')->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])->getJson("/api/properties/{$listing->id}");

        $this->assertSame(2, $listing->fresh()->views_count);

        Sanctum::actingAs($owner);
        $this->getJson("/api/properties/{$listing->id}");
        $this->assertSame(2, $listing->fresh()->views_count, 'Owner visits are not counted');
        $this->getJson('/api/my/properties')->assertJsonPath('data.0.views_count', 2);
    }
}
