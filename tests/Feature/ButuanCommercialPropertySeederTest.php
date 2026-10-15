<?php

namespace Tests\Feature;

use App\Models\Property;
use Database\Seeders\ButuanCommercialPropertySeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ButuanCommercialPropertySeederTest extends TestCase
{
    public function test_it_seeds_twenty_idempotent_butuan_commercial_fixtures(): void
    {
        try {
            $seeder = app(ButuanCommercialPropertySeeder::class);
            $seeder->run();

            $this->assertDatabaseCount('properties', 20);
            $this->assertSame(20, Property::query()
                ->where('city', 'Butuan City')
                ->where('province', 'Agusan del Norte')
                ->where('status', 'published')
                ->whereIn('property_type', ['Office Spaces', 'Retail Spaces', 'Warehouses', 'Commercial Lots'])
                ->where('description', 'like', 'Synthetic local test fixture only.%')
                ->count());

            $seeder->run();

            $this->assertDatabaseCount('properties', 20);
        } finally {
            $fixtureIds = DB::table('properties')
                ->where('slug', 'like', 'sample-%')
                ->where('description', 'like', 'Synthetic local test fixture only.%')
                ->pluck('id');

            DB::table('properties')->whereIn('id', $fixtureIds)->delete();
            DB::table('users')->where('email', 'sample-owner@butuanspaces.test')->delete();
        }
    }

    public function test_it_refuses_to_seed_outside_local_and_testing_environments(): void
    {
        config(['app.env' => 'production']);

        try {
            app(ButuanCommercialPropertySeeder::class)->run();
            self::fail('The sample seeder should reject production environments.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Sample property data may only be seeded in local/testing environments using a local database.',
                $exception->getMessage(),
            );
        }

    }

    public function test_it_refuses_to_seed_using_a_remote_database_host(): void
    {
        $connection = config('database.default');
        config(["database.connections.{$connection}.host" => 'aws-0-ap-southeast-1.pooler.supabase.com']);

        try {
            app(ButuanCommercialPropertySeeder::class)->run();
            self::fail('The sample seeder should reject remote database hosts.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Sample property data may only be seeded in local/testing environments using a local database.',
                $exception->getMessage(),
            );
        }
    }

    public function test_it_refuses_a_remote_database_url_even_if_the_host_setting_is_local(): void
    {
        $connection = config('database.default');
        config([
            "database.connections.{$connection}.host" => '127.0.0.1',
            "database.connections.{$connection}.url" => 'postgres://test@aws-0-ap-southeast-1.pooler.supabase.com/postgres',
        ]);

        try {
            app(ButuanCommercialPropertySeeder::class)->run();
            self::fail('The sample seeder should reject remote database URLs.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Sample property data may only be seeded in local/testing environments using a local database.',
                $exception->getMessage(),
            );
        }
    }
}
