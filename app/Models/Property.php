<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'availability', 'availability_confirmed_at', 'availability_reminder_sent_at',
    ];

    /** Days after the last confirmation when the publisher is asked if the listing is still available. */
    public const REMIND_AFTER_DAYS = 45;

    /** Days after the last confirmation when an unconfirmed listing is hidden from the public. */
    public const HIDE_AFTER_DAYS = 60;

    public const AVAILABILITY_OPTIONS = ['available', 'rented', 'sold'];

    protected static function booted(): void
    {
        // A newly created listing counts as freshly confirmed by its publisher.
        static::creating(function (Property $property): void {
            $property->availability_confirmed_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'availability_confirmed_at' => 'datetime',
            'availability_reminder_sent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'ai_tags' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Listings the public can see: approved, still available, and confirmed recently.
     *
     * @param  Builder<Property>  $query
     */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('availability', 'available')
            ->where('availability_confirmed_at', '>=', now()->subDays(self::HIDE_AFTER_DAYS));
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->availability === 'available'
            && $this->availability_confirmed_at !== null
            && $this->availability_confirmed_at->gte(now()->subDays(self::HIDE_AFTER_DAYS));
    }

    /**
     * Record that the publisher confirmed the listing's availability just now.
     */
    public function markAvailabilityConfirmed(string $availability = 'available'): void
    {
        $this->availability = $availability;
        $this->availability_confirmed_at = now();
        $this->availability_reminder_sent_at = null;
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
