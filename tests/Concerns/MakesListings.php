<?php

namespace Tests\Concerns;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait MakesListings
{
    protected function makeUser(string $role = 'buyer', array $attributes = []): User
    {
        return User::create([
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('CurrentPass123!'),
            'role' => $role,
            'account_status' => 'active',
            ...$attributes,
        ]);
    }

    protected function makeListing(User $owner, array $attributes = []): Property
    {
        $title = $attributes['title'] ?? 'Listing '.Str::random(6);

        return Property::create([
            'owner_id' => $owner->id,
            'broker_id' => $owner->role === 'broker' ? $owner->id : null,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(4),
            'property_type' => 'Warehouses',
            'listing_type' => 'rent',
            'description' => 'Commercial space in Butuan City.',
            'price' => 50000,
            'currency' => 'PHP',
            'status' => 'published',
            'address_line' => 'J.C. Aquino Avenue',
            'barangay' => 'Libertad',
            'city' => 'Butuan City',
            'province' => 'Agusan del Norte',
            'published_at' => now(),
            ...$attributes,
        ]);
    }
}
