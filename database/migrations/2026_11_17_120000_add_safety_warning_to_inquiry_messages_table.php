<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiry_messages', function (Blueprint $table) {
            // Shown to the recipient when the message looks like a payment scam.
            $table->string('safety_warning', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inquiry_messages', function (Blueprint $table) {
            $table->dropColumn('safety_warning');
        });
    }
};
