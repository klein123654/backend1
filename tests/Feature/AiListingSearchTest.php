<?php

namespace Tests\Feature;

use App\Services\AiListingSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class AiListingSearchTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    public function test_request_words_extracted_as_keywords_do_not_exclude_real_matches(): void
    {
        $owner = $this->makeUser('owner');
        $match = $this->makeListing($owner, ['title' => 'Storage unit with loading access', 'property_type' => 'Warehouses', 'listing_type' => 'For Rent', 'price' => 48000, 'floor_area' => 300]);
        $this->makeListing($owner, ['title' => 'Big warehouse', 'property_type' => 'Warehouses', 'listing_type' => 'For Rent', 'price' => 90000, 'floor_area' => 600]);
        $this->makeListing($owner, ['title' => 'Warehouse for sale', 'property_type' => 'Warehouses', 'listing_type' => 'For Sale', 'price' => 50000, 'floor_area' => 400]);

        $results = app(AiListingSearch::class)->find([
            'location' => '', 'property_type' => 'Warehouses', 'listing_type' => 'rent',
            'max_price' => 60000, 'min_size' => 300, 'keywords' => ['warehouse', 'for rent'],
        ]);

        $this->assertSame([$match->id], array_column($results, 'id'));
    }

    public function test_feature_keywords_rank_listings_instead_of_filtering_them(): void
    {
        $owner = $this->makeUser('owner');
        $plain = $this->makeListing($owner, ['title' => 'Warehouse in Bancasi', 'published_at' => now()]);
        $featured = $this->makeListing($owner, ['title' => 'Warehouse with loading bay', 'published_at' => now()->subDay()]);

        $results = app(AiListingSearch::class)->find(['keywords' => ['loading bay']]);

        $this->assertSame([$featured->id, $plain->id], array_column($results, 'id'));
    }

    public function test_only_publicly_visible_listings_are_returned(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeListing($owner, ['status' => 'draft', 'published_at' => null]);

        $this->assertSame([], app(AiListingSearch::class)->find([]));
    }
}
