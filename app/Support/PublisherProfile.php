<?php

namespace App\Support;

use App\Models\User;

class PublisherProfile
{
    /** Columns of the users table that may appear on public listing pages. */
    public const PUBLIC_USER_COLUMNS = 'id,name,role,created_at,contact_phone,contact_email,messenger_username,viber_number';

    /** Broker verification columns that may appear on public listing pages. */
    public const PUBLIC_VERIFICATION_COLUMNS = [
        'id', 'broker_id', 'status', 'license_number', 'prc_valid_until',
        'dhsud_registration_number', 'dhsud_valid_until', 'firm_name',
        'firm_position', 'firm_address', 'firm_registration_number', 'affiliations',
    ];

    /**
     * Eager-load definition for a listing's publisher, including only a verified broker's credentials.
     *
     * @return array<string, mixed>
     */
    public static function eagerLoads(string $relation = 'owner'): array
    {
        return [
            $relation => fn ($query) => $query->select(explode(',', self::PUBLIC_USER_COLUMNS)),
            $relation.'.brokerVerifications' => self::verifiedCredentials(...),
        ];
    }

    /**
     * Constrain a brokerVerifications query to the public columns of a verified record.
     */
    public static function verifiedCredentials($query): void
    {
        $query->where('status', 'verified')->select(self::PUBLIC_VERIFICATION_COLUMNS);
    }

    /**
     * Public profile of a listing publisher. Every publisher was approved by an
     * administrator: owners through their publisher application, brokers through
     * a check of their PRC licence and DHSUD registration.
     *
     * A broker's badge is shown only while both credentials are valid. When one
     * has expired the profile stays public with credential_status "expired", so
     * listings can say the registration is being renewed.
     *
     * @return array<string, mixed>
     */
    public static function make(User $user): array
    {
        $verification = $user->relationLoaded('brokerVerifications')
            ? $user->brokerVerifications->firstWhere('status', 'verified')
            : $user->brokerVerifications()->where('status', 'verified')->first();

        $isBroker = $user->role === 'broker';
        $broker = $isBroker && $verification ? [
            'license_number' => $verification->license_number,
            'prc_valid_until' => $verification->prc_valid_until?->toDateString(),
            'dhsud_registration_number' => $verification->dhsud_registration_number,
            'dhsud_valid_until' => $verification->dhsud_valid_until?->toDateString(),
            'credential_status' => $verification->credential_status,
            'firm' => $verification->firm_name ? [
                'name' => $verification->firm_name,
                'position' => $verification->firm_position,
                'address' => $verification->firm_address,
                'registration_number' => $verification->firm_registration_number,
            ] : null,
            'affiliations' => $verification->affiliations ?? [],
        ] : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role,
            'role_label' => $isBroker ? 'Licensed broker' : 'Property owner',
            // Owners were approved through their application; brokers need current credentials.
            'verified' => $isBroker ? (bool) $verification?->credentialsCurrent() : $user->role === 'owner',
            // Approved at some point (the public profile and listings stay reachable while renewing).
            'approved' => $isBroker ? $verification !== null : $user->role === 'owner',
            'license_number' => $broker['license_number'] ?? null,
            'agency_name' => $broker['firm']['name'] ?? null,
            'broker' => $broker,
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
