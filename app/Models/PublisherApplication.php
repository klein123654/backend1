<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublisherApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'requested_role', 'ownership_type', 'business_name',
        'license_number', 'prc_valid_until', 'dhsud_registration_number',
        'dhsud_valid_until', 'firm_name', 'firm_position', 'firm_address',
        'firm_registration_number', 'affiliations', 'status', 'reviewed_by',
        'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'prc_valid_until' => 'date:Y-m-d',
            'dhsud_valid_until' => 'date:Y-m-d',
            'affiliations' => 'array',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
