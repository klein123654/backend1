<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brokers now give both credentials with their validity dates (PRC licence,
     * renewed every 3 years, and DHSUD registration, renewed yearly) and
     * structured details of the real estate firm they belong to.
     */
    public function up(): void
    {
        foreach (['broker_verifications', 'publisher_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->renameColumn('agency_name', 'firm_name');
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->date('prc_valid_until')->nullable()->after('license_number');
                $table->string('dhsud_registration_number', 100)->nullable()->after('prc_valid_until');
                $table->date('dhsud_valid_until')->nullable()->after('dhsud_registration_number');
                $table->string('firm_position', 120)->nullable()->after('firm_name');
                $table->string('firm_address', 255)->nullable()->after('firm_position');
                $table->string('firm_registration_number', 100)->nullable()->after('firm_address');
                $table->json('affiliations')->nullable()->after('firm_registration_number');

                if ($tableName === 'broker_verifications') {
                    $table->timestamp('dhsud_checked_at')->nullable()->after('reviewed_at');
                    $table->timestamp('expiry_reminder_sent_at')->nullable()->after('dhsud_checked_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['broker_verifications', 'publisher_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropColumn([
                    'prc_valid_until', 'dhsud_registration_number', 'dhsud_valid_until',
                    'firm_position', 'firm_address', 'firm_registration_number', 'affiliations',
                    ...($tableName === 'broker_verifications' ? ['dhsud_checked_at', 'expiry_reminder_sent_at'] : []),
                ]);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->renameColumn('firm_name', 'agency_name');
            });
        }
    }
};
