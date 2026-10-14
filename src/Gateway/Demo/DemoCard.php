<?php
declare(strict_types=1);

namespace OwnPay\Gateway\Demo;

/**
 * Card rules shared by the simulated hosted-card gateways: Luhn and expiry validation,
 * test-card outcomes, 3-D Secure decisions and HMAC signing of the posted-back result.
 *
 * No real card is ever charged; only the brand and last four digits are kept by callers.
 */
final class DemoCard
{
    /** One-time passcode accepted by the simulated 3-D Secure challenge. */
    public const OTP = '123456';

    /** Payer-facing message per outcome. */
    public const OUTCOMES = [
        'approved'           => 'Approved',
        'declined'           => 'Your card was declined by the issuer.',
        'insufficient_funds' => 'Insufficient funds on the card.',
        'expired_card'       => 'The card has expired.',
        'auth_failed'        => '3-D Secure authentication failed.',
    ];

    /** Test card number => outcome ('challenge' = 3-D Secure OTP step). */
    public const TEST_CARDS = [
        '4242424242424242' => 'approved',
        '4000000000000002' => 'declined',
        '4000000000009995' => 'insufficient_funds',
        '4000000000000069' => 'expired_card',
        '4000000000003220' => 'challenge',
    ];

    /** Options for a gateway's "3-D Secure" setting. */
    public const THREE_DS_OPTIONS = [
        'test_cards' => 'Only for the 3DS test card',
        'always'     => 'Always challenge',
        'never'      => 'Never challenge',
    ];

    public static function sign(string $trxId, string $session, string $outcome, string $amount, string $secret): string
    {
        return hash_hmac('sha256', implode('|', [$trxId, $session, $outcome, $amount]), $secret);
    }

    public static function luhn(string $digits): bool
    {
        if (!preg_match('/^\d{12,19}$/', $digits)) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }

    /**
     * Resolves a Luhn-valid card number to an outcome, or 'challenge' when 3-D Secure applies.
     */
    public static function outcome(string $number, string $threeDs): string
    {
        $outcome = self::TEST_CARDS[$number] ?? 'approved';
        if ($threeDs === 'always' && $outcome === 'approved') {
            return 'challenge';
        }
        if ($threeDs === 'never' && $outcome === 'challenge') {
            return 'approved';
        }
        return $outcome;
    }

    public static function brand(string $number): string
    {
        return match (true) {
            str_starts_with($number, '4')                    => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $number) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $number)           => 'Amex',
            default                                          => 'Card',
        };
    }

    /**
     * Returns a payer-facing validation error, or null when the card details are acceptable.
     */
    public static function validate(string $name, string $number, string $expiry, string $cvc, ?int $now = null): ?string
    {
        if ($name === '') {
            return 'Enter the name on the card.';
        }
        if (!self::luhn($number)) {
            return 'The card number is not valid.';
        }
        if (!preg_match('#^(0[1-9]|1[0-2])\s*/\s*(\d{2})$#', $expiry, $m)) {
            return 'Enter the expiry date as MM/YY.';
        }
        $now ??= time();
        $expiresYm = (2000 + (int) $m[2]) * 100 + (int) $m[1];
        if ($expiresYm < (int) date('Ym', $now)) {
            return 'The card expiry date is in the past.';
        }
        if (!preg_match('/^\d{3,4}$/', $cvc)) {
            return 'Enter the 3 or 4 digit security code.';
        }
        return null;
    }

    public static function message(string $outcome): string
    {
        return self::OUTCOMES[$outcome] ?? 'Payment was not completed.';
    }
}
