<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ButuanSpacesSchemaTest extends TestCase
{
    public function test_butuanspaces_tables_and_user_fields_exist(): void
    {
        foreach ([
            'properties',
            'property_images',
            'broker_verifications',
            'inquiries',
            'inquiry_messages',
            'property_reports',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertTrue(
            Schema::hasColumns('users', ['phone', 'role', 'account_status'])
        );
    }

    public function test_user_relationships_are_configured(): void
    {
        $user = new User();

        $this->assertInstanceOf(HasMany::class, $user->ownedProperties());
        $this->assertInstanceOf(HasMany::class, $user->brokerProperties());
        $this->assertInstanceOf(HasMany::class, $user->brokerVerifications());
        $this->assertInstanceOf(HasMany::class, $user->buyerInquiries());
        $this->assertInstanceOf(HasMany::class, $user->assignedInquiries());
    }
}
