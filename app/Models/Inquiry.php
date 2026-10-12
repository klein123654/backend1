<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'buyer_id', 'assigned_broker_id',
        'name', 'email', 'phone', 'message', 'status',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function assignedBroker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_broker_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InquiryMessage::class);
    }
}
