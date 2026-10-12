<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('broker_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('license_number')->nullable();
            $table->string('agency_name')->nullable();
            $table->string('document_path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broker_verifications');
    }
};
