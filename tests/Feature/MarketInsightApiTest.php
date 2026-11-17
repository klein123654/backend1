<?php

namespace Tests\Feature;

use App\Services\DeepSeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesListings;
use Tests\TestCase;

class MarketInsightApiTest extends TestCase
{
    use MakesListings, RefreshDatabase;

    private function seedMarket(): void
    {
        $owner = $this->makeUser('owner');
        $listings = [
            // Retail: 3 listings; offices: 3 listings (a tie); warehouse: 1.
            ['Retail Spaces', 'rent', 20000, 50, 'Libertad'],
            ['Retail Spaces', 'rent', 30000, 100, 'Libertad'],
            ['Retail Spaces', 'sale', 5000000, 200, 'Doongan'],
            ['Office Spaces', 'rent', 15000, 30, 'Libertad'],
            ['Office Spaces', 'rent', 25000, 50, 'Doongan'],
            ['Office Spaces', 'rent', 35000, null, 'Doongan'],
            ['Warehouses', 'sale', 9000000, 1000, 'Baan KM 3'],
        ];
        foreach ($listings as [$type, $listingType, $price, $area, $barangay]) {
            $this->makeListing($owner, [
                'property_type' => $type, 'listing_type' => $listingType, 'price' => $price,
                'floor_area' => $area, 'barangay' => $barangay,
            ]);
        }
        $this->makeListing($owner, ['property_type' => 'Warehouses', 'status' => 'draft', 'price' => 1]);
    }

    public function test_figures_keep_rent_and_sale_apart_and_rank_in_code(): void
    {
        $this->seedMarket();
        $this->mock(DeepSeekService::class)->shouldReceive('marketInsight')->once()
            ->andReturn(['success' => true, 'summary' => 'AI summary.']);

        $response = $this->getJson('/api/ai/market-insight')->assertOk()
            ->assertJsonPath('aggregates.total_listings', 7)
            ->assertJsonPath('aggregates.highlights.most_listed_types', ['Office Spaces', 'Retail Spaces'])
            ->assertJsonPath('aggregates.highlights.most_listed_count', 3)
            ->assertJsonPath('aggregates.highlights.top_barangays', ['Doongan', 'Libertad'])
            ->assertJsonPath('aggregates.highlights.top_barangay_count', 3)
            ->assertJsonPath('summary', 'AI summary.')
            ->assertJsonPath('ai_written', true);

        $retail = collect($response->json('aggregates.by_type'))->firstWhere('property_type', 'Retail Spaces');
        $this->assertEquals(['rent', 'sale'], array_column($retail['prices'], 'listing_type'));
        $this->assertEquals(25000, $retail['prices'][0]['median_price'], 'Monthly rents are not mixed with the ₱5M sale');
        $this->assertEquals(350, $retail['prices'][0]['median_price_per_sqm']);
        $this->assertEquals(5000000, $retail['prices'][1]['median_price']);

        $office = collect($response->json('aggregates.by_type'))->firstWhere('property_type', 'Office Spaces');
        $this->assertEquals(500, $office['prices'][0]['median_price_per_sqm'], 'Listings without floor area are left out of price per sq m');
    }

    public function test_no_summary_until_enough_listings_are_published(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeListing($owner);
        $this->mock(DeepSeekService::class)->shouldNotReceive('marketInsight');

        $this->getJson('/api/ai/market-insight')->assertOk()
            ->assertJsonPath('summary', null)
            ->assertJsonPath('aggregates.total_listings', 1)
            ->assertJsonPath('message', 'A market summary appears once at least 3 listings are published.');
    }

    public function test_successful_summary_is_cached(): void
    {
        $this->seedMarket();
        $this->mock(DeepSeekService::class)->shouldReceive('marketInsight')->once()
            ->andReturn(['success' => true, 'summary' => 'Cached summary.']);

        $this->getJson('/api/ai/market-insight')->assertJsonPath('summary', 'Cached summary.');
        $this->getJson('/api/ai/market-insight')->assertJsonPath('summary', 'Cached summary.');
    }

    public function test_without_ai_a_correct_summary_is_written_from_the_figures(): void
    {
        $this->seedMarket();
        $this->mock(DeepSeekService::class)->shouldReceive('marketInsight')->twice()
            ->andReturn(['success' => false, 'summary' => null, 'message' => 'AI service error. Please try again.']);

        $this->getJson('/api/ai/market-insight')->assertOk()
            ->assertJsonPath('ai_written', false)
            ->assertJsonPath('summary', 'There are 7 published commercial listings in Butuan City. The most listed property types are Office Spaces and Retail Spaces (3 listings each). Doongan and Libertad have the most listings of any barangay (3 each).');
        $this->getJson('/api/ai/market-insight')->assertJsonPath('ai_written', false);
    }
}
