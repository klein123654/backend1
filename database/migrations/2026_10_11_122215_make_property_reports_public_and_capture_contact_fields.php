<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_reports', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->change();
            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_reports', function (Blueprint $table) {
            $table->dropColumn(['reporter_name', 'reporter_email']);
            $table->foreignId('property_id')->nullable(false)->change();
        });
    }
};
