<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('broker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->string('property_type');
            $table->string('listing_type');
            $table->text('description');
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('PHP');
            $table->string('status')->default('draft');
            $table->string('address_line');
            $table->string('barangay')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('postal_code')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->decimal('bathrooms', 4, 1)->nullable();
            $table->decimal('floor_area', 12, 2)->nullable();
            $table->decimal('lot_area', 12, 2)->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'listing_type']);
            $table->index(['city', 'province']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
