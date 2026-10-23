<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'broker_id', 'title', 'slug', 'property_type',
        'listing_type', 'description', 'price', 'currency', 'status',
        'address_line', 'barangay', 'city', 'province', 'postal_code',
        'latitude', 'longitude', 'bedrooms', 'bathrooms', 'floor_area',
        'lot_area', 'parking_spaces', 'published_at',
        'reviewed_by', 'reviewed_at', 'review_notes',
        'ai_tags',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_tags' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'broker_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(PropertyReport::class);
    }
}
