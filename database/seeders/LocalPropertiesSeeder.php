<?php

namespace Database\Seeders;

use App\Models\BrokerVerification;
use App\Models\Inquiry;
use App\Models\InquiryMessage;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\PropertyReport;
use App\Models\PublisherApplication;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LocalPropertiesSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureLocalDatabase();

        $faker = fake('en_PH');
        $admin = $this->seedUser('admin@local.test', 'admin', $faker->name());
        $buyer = $this->seedUser('buyer@local.test', 'buyer', $faker->name());
        $owners = [];
        $developers = [];
        $brokers = [];

        for ($number = 1; $number <= 10; $number++) {
            $owners[] = $this->seedUser("owner{$number}@local.test", 'owner', $faker->name());
        }

        for ($number = 1; $number <= 8; $number++) {
            $developers[] = $this->seedUser("developer{$number}@local.test", 'developer', $faker->name());
        }

        for ($number = 1; $number <= 6; $number++) {
            $brokers[] = $this->seedUser("broker{$number}@local.test", 'broker', $faker->name());
        }

        $titles = [
            'Riverside Commercial Space',
            'Draft Riverside Unit',
            'Brokered Riverside Unit',
            'Modern Office Suite near J.C. Aquino Avenue',
            'Residential House with Business Frontage in Baan KM 3',
            'Townhouse-Style Commercial Unit in Libertad',
            'Professional Office in Holy Redeemer Pob.',
            'Executive Office Space in Villa Kananga',
            'Retail Arcade Unit in Ambago',
            'Neighborhood Storefront in Dagohoy Pob.',
            'Condo-Style Retail Studio near Montilla Boulevard',
            'Corner Shop in Baan Riverside Pob.',
            'Townhouse Retail Unit in San Vicente',
            'Market-Ready Shop in Ong Yiu Pob.',
            'Residential Condo with Retail Front in Doongan',
            'Boutique Store in Golden Ribbon Pob.',
            'Warehouse with Loading Bay in Bancasi',
            'Distribution Warehouse in Mahay',
            'Draft Storage Unit in Taguibo',
            'Light Industrial Warehouse in Doongan',
            'Workshop and Storage Space in Tiniwisan',
            'High-Ceiling Warehouse in Ampayon',
            'Logistics Facility in Dumalagan',
            'Commercial Lot near Sumilihon',
            'Draft Development Lot in Basag',
            'House-and-Lot Development Site in Babag',
            'Commercial Lot with Road Frontage in Taligaman',
            'Townhouse Development Lot in Bonbon',
            'Mixed-Use Commercial Lot in Cabcabon',
            'Residential House Lot in Taguibo',
        ];

        $barangays = [
            'Baan KM 3', 'Dagohoy Pob.', 'Holy Redeemer Pob.', 'Libertad',
            'Villa Kananga', 'Diego Silang Pob.', 'Bancasi', 'San Vicente',
            'Limaha Pob.', 'Ong Yiu Pob.', 'Mahay', 'Sumilihon',
            'Tiniwisan', 'Amparo', 'Golden Ribbon Pob.', 'Ambago',
            'Doongan', 'Baan Riverside Pob.', 'Taguibo', 'Ampayon',
            'Masao', 'Babag', 'Dumalagan', 'New Society Village Pob.',
            'Maguinda', 'Bonbon', 'Buhangin Pob.', 'Antongalon',
            'Cabcabon', 'Baobaoan',
        ];

        $streets = [
            'J.C. Aquino Avenue', 'Montilla Boulevard', 'Langihan Road',
            'Libertad Road', 'Bancasi Airport Road', 'Mahay Road',
            'Cabadbaran-Butuan Road', 'T. Calo Extension', 'Doongan Road',
            'Villa Kananga Road',
        ];

        $propertyTypes = array_merge(
            array_fill(0, 8, 'Office Spaces'),
            array_fill(0, 8, 'Retail Spaces'),
            array_fill(0, 7, 'Warehouses'),
            array_fill(0, 7, 'Commercial Lots'),
        );

        $roleAssignments = [
            ['role' => 'owner', 'user_index' => 0],
            ['role' => 'owner', 'user_index' => 1],
            ['role' => 'broker', 'user_index' => 0],
        ];

        for ($userIndex = 2; $userIndex < 10; $userIndex++) {
            $roleAssignments[] = ['role' => 'owner', 'user_index' => $userIndex];
        }

        for ($index = 0; $index < 10; $index++) {
            $roleAssignments[] = ['role' => 'developer', 'user_index' => $index % count($developers)];
        }

        for ($index = 1; $index < 10; $index++) {
            $roleAssignments[] = ['role' => 'broker', 'user_index' => $index % count($brokers)];
        }

        $statuses = array_fill(0, 30, 'pending');
        foreach ([0, 3, 4, 11, 13, 21, 22, 23, 24, 25] as $index) {
            $statuses[$index] = 'published';
        }
        foreach ([1, 2, 5, 12, 18, 26] as $index) {
            $statuses[$index] = 'draft';
        }
        foreach ([8, 10, 14, 17, 27, 29] as $index) {
            $statuses[$index] = 'rejected';
        }

        $emptyImageIndexes = [1, 2, 5];
        $smallImageCounts = [3 => 1, 4 => 2, 6 => 1, 7 => 2];
        $properties = [];
        $propertyRoles = [];

        foreach ($titles as $index => $title) {
            $assignment = $roleAssignments[$index];
            $role = $assignment['role'];
            $listingUser = match ($role) {
                'owner' => $owners[$assignment['user_index']],
                'developer' => $developers[$assignment['user_index']],
                'broker' => $brokers[$assignment['user_index']],
            };
            $status = $statuses[$index];
            $propertyType = $propertyTypes[$index];
            $barangay = $barangays[$index];
            $price = 500_000 + ($index * 500_000);
            $listingType = ['rent', 'sale', 'lease'][$index % 3];
            $imageCount = in_array($index, $emptyImageIndexes, true)
                ? 0
                : ($smallImageCounts[$index] ?? 3 + ($index % 4));

            $property = Property::query()->updateOrCreate(
                ['title' => $title, 'owner_id' => $listingUser->id],
                [
                    'broker_id' => $role === 'broker' ? $listingUser->id : null,
                    'slug' => 'local-'.Str::slug($title),
                    'property_type' => $propertyType,
                    'listing_type' => $listingType,
                    'description' => $this->descriptionFor($title, $barangay),
                    'price' => $price,
                    'currency' => 'PHP',
                    'status' => $status,
                    'address_line' => ($index + 10).' '.$streets[$index % count($streets)],
                    'barangay' => $barangay,
                    'city' => 'Butuan City',
                    'province' => 'Agusan del Norte',
                    'latitude' => $index % 4 === 0 ? null : 8.94 + (($index % 12) * 0.006),
                    'longitude' => $index % 4 === 0 ? null : 125.53 + (($index % 12) * 0.006),
                    'bedrooms' => $index % 5 === 0 ? null : $index % 7,
                    'bathrooms' => $index % 6 === 0 ? null : 1 + ($index % 4),
                    'floor_area' => $propertyType === 'Commercial Lots' || $index % 7 === 0
                        ? null
                        : 45 + ($index * 18),
                    'lot_area' => $propertyType === 'Commercial Lots' || $index % 5 === 0
                        ? 160 + ($index * 22)
                        : null,
                    'parking_spaces' => $index % 4 === 0 ? null : $index % 6,
                    'published_at' => $status === 'published' ? now()->subDays($index + 1) : null,
                    'reviewed_by' => $status === 'rejected' ? $admin->id : null,
                    'reviewed_at' => $status === 'rejected' ? now()->subDays(1) : null,
                    'review_notes' => $status === 'rejected'
                        ? 'Please update the listing details before resubmitting.'
                        : null,
                    'ai_tags' => $this->tagsFor($index),
                ],
            );

            $properties[$index] = $property;
            $propertyRoles[$index] = $role;

            for ($imageNumber = 1; $imageNumber <= $imageCount; $imageNumber++) {
                $imagePath = "https://picsum.photos/seed/property-{$property->id}-{$imageNumber}/800/600";

                PropertyImage::query()->updateOrCreate(
                    ['property_id' => $property->id, 'path' => $imagePath],
                    [
                        'alt_text' => "{$title}, photo {$imageNumber}",
                        'sort_order' => $imageNumber - 1,
                        'is_primary' => $imageNumber === 1,
                    ],
                );
            }
        }

        $this->seedSavedProperties($buyer, $properties, $statuses);
        $this->seedInquiries($buyer, $owners, $developers, $brokers, $admin, $properties, $propertyRoles);
        $this->seedPublisherApplications($buyer, $admin);
        $this->seedBrokerVerifications($brokers, $admin);
        $this->seedReports($buyer, $admin, $properties);
    }

    private function seedUser(string $email, string $role, string $name): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'phone' => fake('en_PH')->numerify('09#########'),
                'role' => $role,
                'account_status' => 'active',
            ],
        );

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function seedSavedProperties(User $buyer, array $properties, array $statuses): void
    {
        $savedProperties = [];
        foreach ($properties as $index => $property) {
            if ($statuses[$index] === 'published') {
                $savedProperties[] = $property;
            }
            if (count($savedProperties) === 5) {
                break;
            }
        }

        foreach ($savedProperties as $property) {
            DB::table('saved_properties')->updateOrInsert(
                ['user_id' => $buyer->id, 'property_id' => $property->id],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function seedInquiries(
        User $buyer,
        array $owners,
        array $developers,
        array $brokers,
        User $admin,
        array $properties,
        array $propertyRoles,
    ): void {
        $inquiryPlan = [
            ['property_index' => 0, 'status' => 'new'],
            ['property_index' => 3, 'status' => 'in_progress'],
            ['property_index' => 4, 'status' => 'resolved'],
            ['property_index' => 11, 'status' => 'new'],
            ['property_index' => 13, 'status' => 'in_progress'],
            ['property_index' => 21, 'status' => 'new'],
            ['property_index' => 22, 'status' => 'in_progress'],
            ['property_index' => 23, 'status' => 'closed'],
            ['property_index' => 24, 'status' => 'new'],
            ['property_index' => 25, 'status' => 'in_progress'],
        ];

        foreach ($inquiryPlan as $index => $plan) {
            $property = $properties[$plan['property_index']];
            $propertyRole = $propertyRoles[$plan['property_index']];
            $publisher = match ($propertyRole) {
                'owner' => $owners[0],
                'developer' => $developers[0],
                'broker' => $brokers[0],
            };
            $initialMessage = 'Hello, I am interested in '.$property->title.'. Could you share viewing details?';
            $inquiry = Inquiry::query()->updateOrCreate(
                [
                    'property_id' => $property->id,
                    'buyer_id' => $buyer->id,
                    'message' => $initialMessage,
                ],
                [
                    'assigned_broker_id' => $propertyRole === 'broker' ? $property->broker_id : null,
                    'name' => $buyer->name,
                    'email' => $buyer->email,
                    'phone' => $buyer->phone,
                    'status' => $plan['status'],
                    'ai_summary' => 'Buyer requests property information and viewing details.',
                    'ai_priority' => ['low', 'medium', 'high'][$index % 3],
                ],
            );

            $inquiry->forceFill(['last_message_at' => now()->subDays(10 - $index)])->save();

            InquiryMessage::query()->updateOrCreate(
                ['inquiry_id' => $inquiry->id, 'sender_id' => $buyer->id, 'body' => $initialMessage],
                ['is_internal' => false],
            );

            if ($plan['status'] !== 'new') {
                $reply = 'Thank you for your interest. We can arrange a viewing this week.';
                InquiryMessage::query()->updateOrCreate(
                    ['inquiry_id' => $inquiry->id, 'sender_id' => $publisher->id, 'body' => $reply],
                    ['is_internal' => false],
                );
            }
        }
    }

    private function seedPublisherApplications(User $buyer, User $admin): void
    {
        $applications = [
            ['requested_role' => 'owner', 'status' => 'pending'],
            ['requested_role' => 'broker', 'status' => 'approved'],
            ['requested_role' => 'developer', 'status' => 'rejected'],
        ];

        foreach ($applications as $application) {
            $reviewed = $application['status'] !== 'pending';
            PublisherApplication::query()->updateOrCreate(
                ['user_id' => $buyer->id, 'requested_role' => $application['requested_role']],
                [
                    'ownership_type' => $application['requested_role'] === 'owner' ? 'individual' : null,
                    'business_name' => $application['requested_role'] === 'developer' ? 'Agusan Development Group' : null,
                    'license_number' => $application['requested_role'] === 'broker' ? 'PRC-LOCAL-0002' : null,
                    'agency_name' => $application['requested_role'] === 'broker' ? 'Butuan Realty Partners' : null,
                    'status' => $application['status'],
                    'reviewed_by' => $reviewed ? $admin->id : null,
                    'reviewed_at' => $reviewed ? now()->subDays(2) : null,
                    'review_notes' => $application['status'] === 'rejected'
                        ? 'Please provide additional business documentation.'
                        : null,
                ],
            );
        }
    }

    private function seedBrokerVerifications(array $brokers, User $admin): void
    {
        foreach ([['index' => 0, 'status' => 'verified'], ['index' => 1, 'status' => 'pending']] as $verification) {
            $reviewed = $verification['status'] === 'verified';
            BrokerVerification::query()->updateOrCreate(
                ['broker_id' => $brokers[$verification['index']]->id],
                [
                    'license_number' => 'PRC-LOCAL-'.str_pad((string) ($verification['index'] + 1), 4, '0', STR_PAD_LEFT),
                    'agency_name' => 'Butuan Realty Partners',
                    'document_path' => null,
                    'status' => $verification['status'],
                    'reviewed_by' => $reviewed ? $admin->id : null,
                    'reviewed_at' => $reviewed ? now()->subDays(3) : null,
                    'review_notes' => null,
                ],
            );
        }
    }

    private function seedReports(User $buyer, User $admin, array $properties): void
    {
        foreach ([
            ['property_index' => 0, 'reason' => 'Display / Layout: Sample open report', 'status' => 'open'],
            ['property_index' => 3, 'reason' => 'Listing Information: Sample resolved report', 'status' => 'resolved'],
        ] as $report) {
            $resolved = $report['status'] === 'resolved';
            PropertyReport::query()->updateOrCreate(
                [
                    'property_id' => $properties[$report['property_index']]->id,
                    'reporter_id' => $buyer->id,
                    'reason' => $report['reason'],
                ],
                [
                    'reporter_name' => $buyer->name,
                    'reporter_email' => $buyer->email,
                    'details' => 'Synthetic local report for exercising the moderation interface.',
                    'status' => $report['status'],
                    'reviewed_by' => $resolved ? $admin->id : null,
                    'reviewed_at' => $resolved ? now()->subDay() : null,
                    'resolution_notes' => $resolved ? 'Resolved as a seeded demonstration report.' : null,
                ],
            );
        }
    }

    private function descriptionFor(string $title, string $barangay): string
    {
        return "Synthetic local listing for layout testing only. {$title} is located in {$barangay}, Butuan City, Agusan del Norte. "
            .'This sample is not a real property offer and is included to preview the listing layout.';
    }

    private function tagsFor(int $index): array
    {
        $tagSets = [
            ['near port', 'highway access'],
            ['high-ceiling', 'loading bay'],
            ['corner lot', 'flood-free'],
            ['near downtown', 'parking'],
        ];

        return $tagSets[$index % count($tagSets)];
    }

    private function ensureLocalDatabase(): void
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
                'Local properties may only be seeded in local/testing environments using a local database.',
            );
        }
    }
}
