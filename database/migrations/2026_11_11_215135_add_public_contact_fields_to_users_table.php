<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact details a publisher chooses to show publicly on their listings.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_phone', 30)->nullable()->after('phone');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->string('messenger_username', 60)->nullable()->after('contact_email');
            $table->string('viber_number', 30)->nullable()->after('messenger_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contact_phone', 'contact_email', 'messenger_username', 'viber_number']);
        });
    }
};
