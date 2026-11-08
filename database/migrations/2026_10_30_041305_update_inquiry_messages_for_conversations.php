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
        Schema::table('inquiry_messages', function (Blueprint $table) {
            $table->renameColumn('message', 'body');
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('last_message_at');
        });

        Schema::table('inquiry_messages', function (Blueprint $table) {
            $table->renameColumn('body', 'message');
        });
    }
};
