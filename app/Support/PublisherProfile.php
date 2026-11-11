<?php

namespace App\Support;

use App\Models\User;

class PublisherProfile
{
    /** Columns of the users table that may appear on public listing pages. */
    public const PUBLIC_USER_COLUMNS = 'id,name,role,created_at,contact_phone,contact_email,messenger_username,viber_number';

    /**
     * Eager-load definition for a listing's publisher, including only a verified broker licence.
     *
     * @return array<string, mixed>
     */
    public static function eagerLoads(string $relation = 'owner'): array
    {
        return [
            $relation => fn ($query) => $query->select(explode(',', self::PUBLIC_USER_COLUMNS)),
            $relation.'.brokerVerifications' => fn ($query) => $query
                ->where('status', 'verified')
                ->select(['id', 'broker_id', 'license_number', 'agency_name', 'status']),
        ];
    }

    /**
     * Public profile of a listing publisher. Every publisher was approved by an
     * administrator: owners and developers through their publisher application,
     * brokers through PRC licence verification.
     *
     * @return array{id: int, name: string, role: string, role_label: string, verified: bool, license_number: ?string, agency_name: ?string, member_since: ?string, contact: array{phone: ?string, email: ?string, messenger_url: ?string, viber_url: ?string}}
     */
    public static function make(User $user): array
    {
        $verification = $user->relationLoaded('brokerVerifications')
            ? $user->brokerVerifications->firstWhere('status', 'verified')
            : $user->brokerVerifications()->where('status', 'verified')->first();

        $isBroker = $user->role === 'broker';

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'role_label' => match ($user->role) {
                'broker' => 'Licensed broker',
                'developer' => 'Developer',
                default => 'Property owner',
            },
            'verified' => $isBroker ? $verification !== null : in_array($user->role, ['owner', 'developer'], true),
            'license_number' => $isBroker ? $verification?->license_number : null,
            'agency_name' => $isBroker ? $verification?->agency_name : null,
            'member_since' => $user->created_at?->toDateString(),
            'contact' => [
                'phone' => $user->contact_phone,
                'email' => $user->contact_email,
                'messenger_url' => $user->messenger_username ? 'https://m.me/'.rawurlencode($user->messenger_username) : null,
                'viber_url' => $user->viber_number ? 'viber://chat?number='.rawurlencode(self::internationalNumber($user->viber_number)) : null,
            ],
        ];
    }

    /**
     * Convert a Philippine mobile number such as 09171234567 to +639171234567.
     */
    public static function internationalNumber(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return str_starts_with($digits, '0') ? '+63'.substr($digits, 1) : '+'.$digits;
    }
}
