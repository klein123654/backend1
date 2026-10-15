<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class ButuanCommercialPropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $databaseUrl = config("database.connections.{$connection}.url");
        $urlHost = is_string($databaseUrl) ? parse_url($databaseUrl, PHP_URL_HOST) : null;
        $host = $urlHost ?: config("database.connections.{$connection}.host");
        $databaseIsLocal = ($driver === 'sqlite' && ! $urlHost)
            || ($driver === 'pgsql' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));

        if (! in_array(config('app.env'), ['local', 'testing'], true) || ! $databaseIsLocal) {
            throw new RuntimeException(
                'Sample property data may only be seeded in local/testing environments using a local database.'
            );
        }

        $owner = User::query()->firstOrCreate(
            ['email' => 'sample-owner@butuanspaces.test'],
            [
                'name' => 'ButuanSpaces Sample Owner',
                'password' => Str::random(64),
                'role' => 'owner',
                'account_status' => 'active',
            ],
        );

        $properties = [
            ['jca-retail', 'Sample Retail Unit near J.C. Aquino Avenue', 'Retail Spaces', 'For Rent', 22000, 'Sample commercial unit near J.C. Aquino Avenue', 'Dagohoy', 48, null, 2],
            ['downtown-office', 'Sample Office Suite in Downtown Butuan', 'Office Spaces', 'For Rent', 28000, 'Sample office suite near the downtown business area', 'Holy Redeemer', 72, null, 1],
            ['libertad-warehouse', 'Sample Warehouse Space in Libertad', 'Warehouses', 'For Rent', 65000, 'Sample warehouse unit near the Libertad commercial corridor', 'Libertad', 420, 600, 4],
            ['montilla-commercial-lot', 'Sample Commercial Lot near Montilla Boulevard', 'Commercial Lots', 'For Sale', 6800000, 'Sample commercial lot near Montilla Boulevard', 'Imadejas', null, 320, null],
            ['villa-kananga-retail', 'Sample Retail Space in Villa Kananga', 'Retail Spaces', 'For Rent', 18500, 'Sample neighborhood retail space in Villa Kananga', 'Villa Kananga', 55, null, 2],
            ['diego-silang-office', 'Sample Office Floor in Diego Silang', 'Office Spaces', 'For Rent', 42000, 'Sample office floor near the central business area', 'Diego Silang', 115, null, 3],
            ['bancasi-warehouse', 'Sample Storage and Warehouse Unit in Bancasi', 'Warehouses', 'For Rent', 48000, 'Sample storage unit with loading access in Bancasi', 'Bancasi', 300, 450, 3],
            ['baan-corner-retail', 'Sample Corner Retail Unit in Baan', 'Retail Spaces', 'For Sale', 4200000, 'Sample corner retail unit in the Baan area', 'Baan', 92, 125, 2],
            ['san-vicente-commercial-lot', 'Sample Commercial Lot in San Vicente', 'Commercial Lots', 'For Sale', 5100000, 'Sample lot intended for a small commercial development', 'San Vicente', null, 280, null],
            ['limaha-office', 'Sample Office Suite in Limaha', 'Office Spaces', 'For Rent', 24500, 'Sample office suite suitable for a small professional team', 'Limaha', 64, null, 1],
            ['ong-yiu-retail', 'Sample Storefront in Ong Yiu', 'Retail Spaces', 'For Rent', 16000, 'Sample street-level storefront in a neighborhood commercial area', 'Ong Yiu', 42, null, 1],
            ['mahay-warehouse', 'Sample Light Industrial Warehouse in Mahay', 'Warehouses', 'For Sale', 7900000, 'Sample warehouse property with space for light commercial use', 'Mahay', 510, 720, 5],
            ['sumilihon-commercial-lot', 'Sample Development Lot in Sumilihon', 'Commercial Lots', 'For Sale', 3600000, 'Sample development lot for a future commercial project', 'Sumilihon', null, 400, null],
            ['tiniwisan-office', 'Sample Business Office in Tiniwisan', 'Office Spaces', 'For Rent', 19500, 'Sample office space for a local service business', 'Tiniwisan', 58, null, 2],
            ['amparo-retail', 'Sample Neighborhood Retail Unit in Amparo', 'Retail Spaces', 'For Rent', 13500, 'Sample small retail unit for local testing workflows', 'Amparo', 36, null, 1],
            ['doongan-workshop', 'Sample Workshop and Warehouse in Doongan', 'Warehouses', 'For Rent', 52000, 'Sample workshop space with an attached storage area', 'Doongan', 350, 500, 4],
            ['golden-ribbon-retail', 'Sample Retail Space in Golden Ribbon', 'Retail Spaces', 'For Sale', 5850000, 'Sample retail property near an established commercial area', 'Golden Ribbon', 105, 140, 3],
            ['ambago-commercial-lot', 'Sample Commercial Lot in Ambago', 'Commercial Lots', 'For Sale', 2950000, 'Sample lot for a small neighborhood commercial project', 'Ambago', null, 250, null],
            ['baan-office', 'Sample Flexible Office Space in Baan Riverside', 'Office Spaces', 'For Rent', 31000, 'Sample flexible office space for a growing local business', 'Baan Riverside', 88, null, 2],
            ['san-vicente-retail', 'Sample Market-Ready Retail Unit in San Vicente', 'Retail Spaces', 'For Rent', 24000, 'Sample retail unit designed for a local shop or service', 'San Vicente', 68, null, 2],
        ];

        foreach ($properties as $index => [$slug, $title, $propertyType, $listingType, $price, $address, $barangay, $floorArea, $lotArea, $parkingSpaces]) {
            Property::query()->firstOrCreate(
                ['slug' => 'sample-'.$slug],
                [
                    'owner_id' => $owner->id,
                    'title' => $title,
                    'property_type' => $propertyType,
                    'listing_type' => $listingType,
                    'description' => 'Synthetic local test fixture only. This is not a real property offer. '.$address.'.',
                    'price' => $price,
                    'currency' => 'PHP',
                    'status' => 'published',
                    'address_line' => $address,
                    'barangay' => $barangay,
                    'city' => 'Butuan City',
                    'province' => 'Agusan del Norte',
                    'floor_area' => $floorArea,
                    'lot_area' => $lotArea,
                    'parking_spaces' => $parkingSpaces,
                    'published_at' => now()->subDays($index + 1),
                ],
            );
        }
    }
}
