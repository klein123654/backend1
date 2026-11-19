<?php

namespace App\Support;

class BrokerCredentials
{
    /** Notice shown to brokers wherever they submit their credentials. */
    public const DHSUD_NOTICE = 'ButuanSpaces checks with DHSUD every year whether your broker registration is still active. Keep your DHSUD registration current; listings cannot be added while it is expired.';

    /** Professional organisations a broker may say they belong to. */
    public const SUGGESTED_AFFILIATIONS = [
        'Butuan Realty Board',
        'Philippine Association of Real Estate Boards (PAREB)',
        'Real Estate Brokers Association of the Philippines (REBAP)',
        'National Real Estate Association (NREA)',
    ];

    /**
     * Validation rules for a broker's credentials and firm details.
     *
     * @param  string|null  $requiredWhen  e.g. "requested_role,broker" to require the fields only for that case
     * @return array<string, array<int, string>>
     */
    public static function rules(?string $requiredWhen = null): array
    {
        $required = $requiredWhen ? 'required_if:'.$requiredWhen : 'required';

        return [
            'license_number' => [$required, 'nullable', 'string', 'max:100'],
            'prc_valid_until' => [$required, 'nullable', 'date', 'after_or_equal:today'],
            'dhsud_registration_number' => [$required, 'nullable', 'string', 'max:100'],
            'dhsud_valid_until' => [$required, 'nullable', 'date', 'after_or_equal:today'],
            'firm_name' => [$required, 'nullable', 'string', 'max:255'],
            'firm_position' => ['nullable', 'string', 'max:120'],
            'firm_address' => ['nullable', 'string', 'max:255'],
            'firm_registration_number' => ['nullable', 'string', 'max:100'],
            'affiliations' => ['nullable', 'array', 'max:6'],
            'affiliations.*' => ['nullable', 'string', 'distinct', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'license_number.required' => 'Enter your PRC broker license number.',
            'license_number.required_if' => 'Enter your PRC broker license number.',
            'prc_valid_until.required' => 'Enter the date your PRC license is valid until.',
            'prc_valid_until.required_if' => 'Enter the date your PRC license is valid until.',
            'prc_valid_until.after_or_equal' => 'Your PRC license has expired. Renew it with PRC first.',
            'dhsud_registration_number.required' => 'Enter your DHSUD broker registration number.',
            'dhsud_registration_number.required_if' => 'Enter your DHSUD broker registration number.',
            'dhsud_valid_until.required' => 'Enter the date your DHSUD registration is valid until.',
            'dhsud_valid_until.required_if' => 'Enter the date your DHSUD registration is valid until.',
            'dhsud_valid_until.after_or_equal' => 'Your DHSUD registration has expired. Renew it with DHSUD first.',
            'firm_name.required' => 'Enter the real estate firm or brokerage you belong to.',
            'firm_name.required_if' => 'Enter the real estate firm or brokerage you belong to.',
        ];
    }

    /**
     * Drop blank affiliation entries and surrounding spaces.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        if (array_key_exists('affiliations', $data)) {
            $data['affiliations'] = array_values(array_filter(
                array_map(fn ($name) => trim((string) $name), $data['affiliations'] ?? []),
                fn (string $name) => $name !== '',
            )) ?: null;
        }

        return $data;
    }
}
