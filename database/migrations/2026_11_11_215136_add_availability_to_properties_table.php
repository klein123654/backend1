<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track whether a listing is still available, and when its publisher last confirmed it.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('availability', 20)->default('available')->after('status');
            $table->timestamp('availability_confirmed_at')->nullable()->after('availability');
            $table->timestamp('availability_reminder_sent_at')->nullable()->after('availability_confirmed_at');
            $table->index(['status', 'availability', 'availability_confirmed_at']);
        });

        // Give existing listings a fresh confirmation date so none disappear on deploy.
        DB::table('properties')->update(['availability_confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['status', 'availability', 'availability_confirmed_at']);
            $table->dropColumn(['availability', 'availability_confirmed_at', 'availability_reminder_sent_at']);
        });
    }
};
