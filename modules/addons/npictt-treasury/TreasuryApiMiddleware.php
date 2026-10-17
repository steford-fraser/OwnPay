<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Enforces treasury_code on POST /api/v1/payments and attaches normalized parts to the request.
 */
final class TreasuryApiMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($request->method() !== 'POST' || $request->path() !== '/api/v1/payments') {
            return $next($request);
        }

        $body = $request->json();
        if (!is_array($body)) {
            $body = [];
        }

        // Accept top-level treasury_code / tiers, or the same keys under metadata / custom_metadata.
        $input = $this->flattenTreasuryInput($body);

        $validator = $this->validator();
        $midVal = $request->getAttribute('merchant_id');
        $merchantId = is_numeric($midVal) ? (int) $midVal : null;

        $result = $validator->validate($input, $merchantId);
        if (!($result['ok'] ?? false)) {
            $code = is_string($result['code'] ?? null) ? $result['code'] : TreasuryValidator::ERR_REQUIRED;
            $base = is_string($result['message'] ?? null) ? $result['message'] : TreasuryValidator::MESSAGE_REQUIRED;
            $message = $code === TreasuryValidator::ERR_REQUIRED
                ? TreasuryValidator::MESSAGE_API
                : $base;
            return Response::apiError($code, $message, 'treasury_code', 422);
        }

        /** @var array{head: string, sub_head: string, item: string, sub_item: string, code_full: string} $treasury */
        $treasury = $result['treasury'];
        $request->setAttribute('treasury_code', $treasury);
        Plugin::$requestTreasury = $treasury;

        try {
            return $next($request);
        } finally {
            Plugin::$requestTreasury = null;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function flattenTreasuryInput(array $body): array
    {
        $meta = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
        $custom = is_array($meta['custom_metadata'] ?? null) ? $meta['custom_metadata'] : [];
        if (is_string($body['metadata'] ?? null)) {
            $decoded = json_decode($body['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
                $custom = is_array($decoded['custom_metadata'] ?? null) ? $decoded['custom_metadata'] : [];
            }
        }

        return array_merge($custom, $meta, $body);
    }

    private function validator(): TreasuryValidator
    {
        $v = $this->container->get(TreasuryValidator::class);
        if (!$v instanceof TreasuryValidator) {
            throw new \RuntimeException('TreasuryValidator unavailable');
        }
        return $v;
    }
}
