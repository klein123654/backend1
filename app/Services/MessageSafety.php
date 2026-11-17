<?php

namespace App\Services;

/**
 * Warns the reader of a message that asks for money before a viewing, a common
 * rental scam. Keyword rules catch candidates (English, Filipino, Cebuano);
 * the AI then gives a second opinion so honest questions like "Is a deposit
 * required?" are not flagged. If the AI is unavailable, the rule-based
 * warning stands. Messages are never blocked, only marked.
 */
class MessageSafety
{
    public const GENERIC_WARNING = 'This message mentions sending money or payment details.';

    /** Payment words and payment channels used in the Philippines. */
    private const PATTERNS = [
        '/\b(deposit|reservation|reserve)\s*(fee|money|payment)?\b/i',
        '/\b(down\s*payment|downpayment|advance\s*(payment|rent)|processing\s*fee|holding\s*fee)\b/i',
        '/\b(send|transfer|pay|wire|deposit)\b.{0,40}\b(money|payment|amount|fee|php|₱|pesos?)\b/iu',
        '/\b(g-?cash|maya|paymaya|bank\s*transfer|bdo|bpi|metrobank|landbank|western\s*union|palawan|cebuana|m\s*lhuillier|remittance)\b/i',
        '/\b(account\s*(number|no\.?|name)|acct\s*no)\b/i',
        '/\b(bayad|magbayad|ipadala|padala|hulog|ihulog)\b/iu',
    ];

    public function __construct(private DeepSeekService $deepSeek) {}

    /** Returns a short warning for the recipient, or null when nothing looks wrong. */
    public function check(string $message): ?string
    {
        if (! $this->mentionsPayment($message)) {
            return null;
        }

        $assessment = $this->deepSeek->assessPaymentRisk($message);
        if ($assessment === null) {
            return self::GENERIC_WARNING;
        }

        if (! $assessment['risky']) {
            return null;
        }

        return $assessment['reason'] !== '' ? $assessment['reason'] : self::GENERIC_WARNING;
    }

    public function mentionsPayment(string $message): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}
