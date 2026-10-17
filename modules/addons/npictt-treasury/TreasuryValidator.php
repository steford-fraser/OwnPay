<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

/**
 * Parse, format, and catalog-validate Trinidad & Tobago 4-tier treasury codes.
 *
 * Wire format (Estimates / Comptroller of Accounts style):
 * Head(2) / Sub-Head(2) / Item(3) / Sub-Item(2) e.g. 18/02/001/01
 *
 * @phpstan-type TreasuryParts array{head: string, sub_head: string, item: string, sub_item: string, code_full: string}
 */
final class TreasuryValidator
{
    public const PATTERN = '/^[0-9A-Z]{2}\/[0-9]{2}\/[0-9]{3}\/[0-9]{2}$/';

    public const ERR_REQUIRED = 'TREASURY_CODE_REQUIRED';
    public const ERR_INVALID = 'TREASURY_CODE_INVALID';
    public const ERR_UNMAPPED = 'TREASURY_CODE_UNMAPPED';

    public const MESSAGE_REQUIRED = 'Missing or invalid 4-tier Trinidad Treasury Code classification (Head, Sub-Head, Item, Sub-Item). Send treasury_code as e.g. 18/02/001/01, or head/sub_head/item/sub_item.';

    public const MESSAGE_API = 'API payments require a registered Treasury Code. Include treasury_code (e.g. 18/02/001/01) on POST /api/v1/payments, or the four tier fields. Catalog: Admin → Treasury Codes.';

    public const MESSAGE_PAYMENT_LINK = 'This payment link has no Treasury Code assigned. An admin must edit the payment link and select Head / Sub-Head / Item / Sub-Item before customers can pay.';

    public const MESSAGE_INVOICE = 'This invoice has no Treasury Code assigned. An admin must edit the invoice and select Head / Sub-Head / Item / Sub-Item before it can be paid.';

    public const MESSAGE_INTENT = 'This payment intent has no Treasury Code. Recreate the intent via POST /api/v1/payments with treasury_code (e.g. 18/02/001/01).';

    public const MESSAGE_TRANSACTION = 'Payment blocked: no Treasury Code on this transaction. Assign a code on the payment link/invoice, or pass treasury_code when creating the payment intent.';

    /** Safe copy for payers / browser checkout (never mention treasury internals). */
    public const MESSAGE_PUBLIC = 'This payment is unavailable right now. Please try again later or contact the organisation that sent you this link.';

    public function __construct(private readonly TreasuryCodeRepository $codes) {}

    /**
     * Extract treasury input from API/admin payloads.
     *
     * @param array<string, mixed> $input
     * @return TreasuryParts|null
     */
    public function extract(array $input): ?array
    {
        $nested = $input['treasury_code'] ?? $input['treasury'] ?? null;
        if (is_string($nested) && $nested !== '') {
            return $this->parseString($nested);
        }
        if (is_array($nested)) {
            return $this->fromParts($nested);
        }

        if (isset($input['head'], $input['sub_head'], $input['item'], $input['sub_item'])) {
            return $this->fromParts($input);
        }

        $head = $input['treasury_head'] ?? null;
        $sub = $input['treasury_sub_head'] ?? null;
        $item = $input['treasury_item'] ?? null;
        $subItem = $input['treasury_sub_item'] ?? null;
        if ($head !== null && $sub !== null && $item !== null && $subItem !== null) {
            return $this->fromParts([
                'head' => $head,
                'sub_head' => $sub,
                'item' => $item,
                'sub_item' => $subItem,
            ]);
        }

        return null;
    }

    /**
     * @return TreasuryParts|null
     */
    public function parseString(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if (!preg_match(self::PATTERN, $code)) {
            return null;
        }
        [$head, $subHead, $item, $subItem] = explode('/', $code);
        return [
            'head' => $head,
            'sub_head' => $subHead,
            'item' => $item,
            'sub_item' => $subItem,
            'code_full' => $code,
        ];
    }

    /**
     * @param array<string, mixed> $parts
     * @return TreasuryParts|null
     */
    public function fromParts(array $parts): ?array
    {
        $head = strtoupper(trim((string) ($parts['head'] ?? '')));
        $subHead = trim((string) ($parts['sub_head'] ?? ''));
        $item = trim((string) ($parts['item'] ?? ''));
        $subItem = trim((string) ($parts['sub_item'] ?? ''));

        if ($head === '' || $subHead === '' || $item === '' || $subItem === '') {
            return null;
        }

        $subHead = str_pad(preg_replace('/\D/', '', $subHead) ?? '', 2, '0', STR_PAD_LEFT);
        $item = str_pad(preg_replace('/\D/', '', $item) ?? '', 3, '0', STR_PAD_LEFT);
        $subItem = str_pad(preg_replace('/\D/', '', $subItem) ?? '', 2, '0', STR_PAD_LEFT);
        $head = substr(preg_replace('/[^0-9A-Z]/', '', $head) ?? '', 0, 2);
        if (strlen($head) < 2) {
            $head = str_pad($head, 2, '0', STR_PAD_LEFT);
        }

        $full = "{$head}/{$subHead}/{$item}/{$subItem}";
        if (!preg_match(self::PATTERN, $full)) {
            return null;
        }

        return [
            'head' => $head,
            'sub_head' => $subHead,
            'item' => $item,
            'sub_item' => $subItem,
            'code_full' => $full,
        ];
    }

    /**
     * Validate format + active catalog membership.
     *
     * @param array<string, mixed> $input
     * @return array{ok: true, treasury: TreasuryParts}|array{ok: false, code: string, message: string}
     */
    public function validate(array $input, ?int $merchantId = null): array
    {
        $parsed = $this->extract($input);
        if ($parsed === null) {
            return ['ok' => false, 'code' => self::ERR_REQUIRED, 'message' => self::MESSAGE_REQUIRED];
        }

        if (!$this->codes->isActive($parsed['code_full'], $merchantId)) {
            return [
                'ok' => false,
                'code' => self::ERR_INVALID,
                'message' => 'Treasury code is not registered or is inactive: ' . $parsed['code_full'],
            ];
        }

        return ['ok' => true, 'treasury' => $parsed];
    }

    /**
     * Numeric-only payload for intent/txn metadata and webhooks.
     *
     * @param TreasuryParts $treasury
     * @return array{treasury_code: string, treasury: array{head: string, sub_head: string, item: string, sub_item: string}}
     */
    public function metadataPayload(array $treasury): array
    {
        return [
            'treasury_code' => $treasury['code_full'],
            'treasury' => [
                'head' => $treasury['head'],
                'sub_head' => $treasury['sub_head'],
                'item' => $treasury['item'],
                'sub_item' => $treasury['sub_item'],
            ],
        ];
    }
}
