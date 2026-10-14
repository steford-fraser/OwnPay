<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Voucherpay;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Customer\CheckoutIdentityService;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * VoucherPay - prepaid voucher payments (proof of concept).
 *
 * Flow: initiate() opens a voucher session on the transaction and sends the payer to page();
 * apply()/remove() build up the list of vouchers covering the amount (stored in transaction
 * metadata, nothing deducted yet); confirm() deducts every voucher under row locks and completes
 * the payment through the core callback pipeline in the same DB transaction, so a failure
 * rolls the deduction back. Vouchers come from one platform-wide pool usable at any brand.
 *
 * @phpstan-type Applied array{voucher_id: int, code: string, amount: numeric-string, balance: numeric-string}
 * @phpstan-type Ctx array{trx: string, txn: array<string, mixed>, meta: array{session: string, amount: numeric-string, currency: string, applied: array<int, Applied>, identity: mixed}}
 */
final class VoucherPayGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    private const SLUG = 'npictt-voucher';
    private const DEFAULT_LABEL = 'Prepaid Voucher';
    private const MAX_VOUCHERS = 5;

    private ?Container $container = null;

    /** Gateway name shown on the payer's receipt / status page. */
    public function statusLabel(): string
    {
        return 'NPICTT Payment Gateway';
    }

    public static function metadata(): array
    {
        return [
            'name' => 'NPICTT Payment Gateway - Voucher', 'slug' => self::SLUG, 'version' => '1.0.0',
            'description' => 'Prepaid voucher payments with split tender',
            'author' => 'NPICTT', 'type' => 'gateway',
        ];
    }

    public function slug(): string { return self::SLUG; }

    public function register(EventManager $events, Container $container): void
    {
        $this->container = $container;

        $events->addFilter('checkout.render', function (mixed $data) use ($events): mixed {
            if (!is_array($data) || !is_array($data['gateways'] ?? null)) {
                return $data;
            }
            $mid = $this->getInt($this->getArray($data, 'txn')['merchant_id'] ?? 0);
            // Filter listeners run inside this plugin's DB sandbox, which blocks reads of core op_* tables.
            $events->pushOwner('core');
            try {
                $label = $this->checkoutLabel($mid);
            } finally {
                $events->popOwner();
            }
            $gateways = $data['gateways'];
            foreach ($gateways as $cat => $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $i => $gw) {
                    if (is_array($gw) && ($gw['slug'] ?? null) === self::SLUG) {
                        $gw['name'] = $label;
                        $list[$i] = $gw;
                    }
                }
                $gateways[$cat] = $list;
            }
            $data['gateways'] = $gateways;
            return $data;
        });
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    public function capabilities(): array
    {
        return [Capability::GATEWAY, Capability::DASHBOARD];
    }

    public function fields(): array
    {
        return [
            ['name' => 'checkout_label', 'label' => 'Checkout Label', 'type' => 'text', 'default' => self::DEFAULT_LABEL],
        ];
    }

    public function supports(string $feature): bool
    {
        return $feature === 'refund';
    }

    public function initiate(array $params, array $credentials): array
    {
        $trxId = $params['trx_id'];
        $returnBase = self::baseUrlOf($params['redirect_url']);
        if ($trxId === '' || $returnBase === '') {
            throw new \RuntimeException('Voucher payment initiation is missing the transaction reference.');
        }

        $session = 'vch_' . bin2hex(random_bytes(12));
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET gateway_trx_id = :sid,
                 metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.voucherpay', CAST(:m AS JSON))
             WHERE trx_id = :trx",
            [
                'sid' => $session,
                'trx' => $trxId,
                'm'   => (string) json_encode([
                    'session'  => $session,
                    'amount'   => $this->toDecimalString($params['amount']),
                    'currency' => strtoupper($params['currency']),
                    'applied'  => [],
                ]),
            ]
        );

        return [
            'redirect_url' => $returnBase . '/checkout/voucher/' . rawurlencode($trxId) . '/page',
            'session_id'   => $session,
        ];
    }

    /**
     * Succeeds when vouchers have been redeemed for the transaction; the redeemed total is
     * returned as the amount so core rejects any mismatch with the transaction amount.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $trxId = $this->getString($callbackData['trx_id'] ?? '');
        if ($trxId === '') {
            return ['success' => false, 'status' => 'failed'];
        }
        $total = $this->store()->redeemedTotal($trxId);
        if (bccomp($total, '0', 2) <= 0) {
            return ['success' => false, 'status' => 'failed', 'trx_id' => $trxId];
        }
        return [
            'success'        => true,
            'gateway_trx_id' => $this->getString($callbackData['session'] ?? ''),
            'amount'         => $total,
            'status'         => 'completed',
            'trx_id'         => $trxId,
        ];
    }

    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        $trxId = Database::getInstance()->fetchColumn(
            'SELECT trx_id FROM op_transactions WHERE gateway_trx_id = :g LIMIT 1',
            ['g' => $gatewayTrxId]
        );
        if (!is_string($trxId) || $trxId === '') {
            return ['success' => false, 'error' => 'Voucher transaction not found.'];
        }

        $left = $this->store()->refund($trxId, VoucherStore::money($amount));
        if (bccomp($left, '0', 2) > 0) {
            return ['success' => false, 'error' => "Only part of the refund could be credited to vouchers ({$left} left)."];
        }
        return ['success' => true, 'refund_id' => 'vrf_' . bin2hex(random_bytes(8))];
    }

    /**
     * GET /checkout/voucher/{trx}/page
     */
    public function page(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        return self::identityVerified($ctx['meta']) ? $this->render($request, $ctx) : $this->renderIdentify($request, $ctx);
    }

    /**
     * POST /checkout/voucher/{trx}/identify - payer enters their email or mobile number.
     */
    public function identify(Request $request): Response
    {
        return $this->identityStep($request, 'identify');
    }

    /**
     * POST /checkout/voucher/{trx}/signup - new payer's details.
     */
    public function signup(Request $request): Response
    {
        return $this->identityStep($request, 'signup');
    }

    /**
     * POST /checkout/voucher/{trx}/verify - verification code.
     */
    public function verifyCode(Request $request): Response
    {
        return $this->identityStep($request, 'verify');
    }

    /**
     * POST /checkout/voucher/{trx}/identify/reset - start over with a different email or phone.
     */
    public function identifyReset(Request $request): Response
    {
        return $this->identityStep($request, 'reset');
    }

    /**
     * POST /checkout/voucher/{trx}/apply - allocates a voucher towards what is still due.
     */
    public function apply(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if (!self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $meta = $ctx['meta'];
        $applied = $meta['applied'];
        $due = VoucherStore::remaining($meta['amount'], $applied);
        $code = VoucherStore::normalize($this->getString($request->post('code', '')));

        if ($code === '') {
            return $this->render($request, $ctx, 'Enter a voucher code.', true);
        }
        if (bccomp($due, '0', 2) <= 0) {
            return $this->render($request, $ctx, 'The payment is already fully covered - press Pay.', true);
        }
        if (count($applied) >= self::MAX_VOUCHERS) {
            return $this->render($request, $ctx, 'You can use at most ' . self::MAX_VOUCHERS . ' vouchers on one payment.', true);
        }
        foreach ($applied as $a) {
            if ($a['code'] === $code) {
                return $this->render($request, $ctx, 'That voucher is already applied.', true);
            }
        }

        $voucher = $this->store()->find($code);
        $reason = VoucherStore::rejectReason($voucher, $meta['currency']);
        if ($reason !== null || $voucher === null) {
            return $this->render($request, $ctx, $reason ?? 'Voucher not valid.', true);
        }

        $balance = VoucherStore::money($voucher['balance'] ?? null);
        $take = VoucherStore::allocate($balance, $due);
        $applied[] = [
            'voucher_id' => $this->getInt($voucher['id'] ?? 0),
            'code'       => $code,
            'amount'     => $take,
            'balance'    => $balance,
        ];
        $meta['applied'] = $applied;
        $this->saveMeta($ctx['trx'], $meta);
        $ctx['meta'] = $meta;

        $left = VoucherStore::remaining($meta['amount'], $applied);
        $message = bccomp($left, '0', 2) > 0
            ? "Voucher applied: {$meta['currency']} {$take}. {$meta['currency']} {$left} still due - enter another voucher."
            : "Voucher applied: {$meta['currency']} {$take}. The payment is fully covered - press Pay.";
        return $this->render($request, $ctx, $message);
    }

    /**
     * POST /checkout/voucher/{trx}/remove - drops a voucher from the applied list.
     */
    public function remove(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if (!self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $code = VoucherStore::normalize($this->getString($request->post('code', '')));
        $meta = $ctx['meta'];
        $meta['applied'] = self::reallocate(
            $meta['amount'],
            array_values(array_filter($meta['applied'], static fn(array $a): bool => $a['code'] !== $code))
        );
        $this->saveMeta($ctx['trx'], $meta);
        $ctx['meta'] = $meta;

        return $this->render($request, $ctx, 'Voucher removed.');
    }

    /**
     * POST /checkout/voucher/{trx}/confirm - deducts the vouchers and completes the payment.
     */
    public function confirm(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if (!self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $meta = $ctx['meta'];
        if ($meta['applied'] === [] || bccomp(VoucherStore::remaining($meta['amount'], $meta['applied']), '0', 2) > 0) {
            return $this->render($request, $ctx, 'Apply vouchers covering the full amount before paying.', true);
        }

        $trxId = $ctx['trx'];
        $txnId = $this->getInt($ctx['txn']['id'] ?? 0);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $db = Database::getInstance();

        $claimed = $db->update(
            "UPDATE op_transactions SET status = 'callback_processing'
             WHERE id = :id AND merchant_id = :mid AND status = 'processing' AND gateway_slug = :gw",
            ['id' => $txnId, 'mid' => $mid, 'gw' => self::SLUG]
        );
        if ($claimed === 0) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        try {
            $db->transaction(function () use ($trxId, $mid, $meta): void {
                $this->store()->redeem($trxId, $mid, $meta['currency'], $meta['applied']);
                $svc = $this->container?->get(GatewayApiService::class);
                if (!$svc instanceof GatewayApiService) {
                    throw new \RuntimeException('Payment service unavailable.');
                }
                $result = $svc->handleCallback($mid, self::SLUG, ['trx_id' => $trxId, 'session' => $meta['session']]);
                if (!$result['success']) {
                    throw new \RuntimeException($this->getString($result['error'] ?? 'Payment was not completed.'));
                }
            });
        } catch (VoucherBalanceChanged $e) {
            $db->update(
                "UPDATE op_transactions SET status = 'processing' WHERE id = :id AND status = 'callback_processing'",
                ['id' => $txnId]
            );
            $meta['applied'] = [];
            $this->saveMeta($trxId, $meta);
            $ctx['meta'] = $meta;
            return $this->render($request, $ctx, $e->getMessage(), true);
        } catch (\Throwable $e) {
            $this->logger()?->warning('VoucherPay payment not completed: ' . $e->getMessage(), ['trx_id' => $trxId]);
            $txnService = $this->container?->get(TransactionService::class);
            if ($txnService instanceof TransactionService) {
                $txnService->fail($txnId, $mid, 'Voucher payment could not be completed.');
            }
        }

        return Response::redirect($this->statusUrl($trxId), 303);
    }

    /**
     * GET /checkout/voucher/{trx}/cancel - nothing was deducted yet, so just return to checkout.
     */
    public function cancel(Request $request): Response
    {
        return Response::redirect('/checkout/' . rawurlencode($request->param('trx')), 303);
    }

    /**
     * GET /admin/gateways/vouchers
     */
    public function adminIndex(Request $request): Response
    {
        return $this->admin()->index($request);
    }

    /**
     * POST /admin/gateways/vouchers/issue
     */
    public function adminIssue(Request $request): Response
    {
        return $this->admin()->issue($request);
    }

    /**
     * Recomputes allocations in order after a voucher is removed, so earlier vouchers
     * absorb as much of the total as their balance allows.
     *
     * @param numeric-string $total
     * @param array<int, Applied> $applied
     * @return array<int, Applied>
     */
    public static function reallocate(string $total, array $applied): array
    {
        $due = bcadd($total, '0', 2);
        $out = [];
        foreach ($applied as $a) {
            if (bccomp($due, '0', 2) <= 0) {
                break;
            }
            $a['amount'] = VoucherStore::allocate($a['balance'], $due);
            $due = bcsub($due, $a['amount'], 2);
            $out[] = $a;
        }
        return $out;
    }

    private function store(): VoucherStore
    {
        return new VoucherStore(Database::getInstance());
    }

    private function admin(): VoucherAdmin
    {
        if ($this->container === null) {
            throw new \RuntimeException('Voucher gateway is not booted.');
        }
        return new VoucherAdmin($this->container, $this->store());
    }

    /**
     * @return Ctx|Response
     */
    private function context(Request $request): array|Response
    {
        $trxId = $request->param('trx');
        $txn = $trxId !== '' ? Database::getInstance()->fetchOne(
            'SELECT id, merchant_id, customer_id, trx_id, status, gateway_slug, metadata FROM op_transactions WHERE trx_id = :t LIMIT 1',
            ['t' => $trxId]
        ) : null;
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $raw = is_string($txn['metadata'] ?? null) ? json_decode($txn['metadata'], true) : null;
        $vp = is_array($raw) && is_array($raw['voucherpay'] ?? null) ? $raw['voucherpay'] : null;
        if (($txn['gateway_slug'] ?? '') !== self::SLUG || ($txn['status'] ?? '') !== 'processing' || $vp === null) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        $applied = [];
        foreach (is_array($vp['applied'] ?? null) ? $vp['applied'] : [] as $a) {
            if (is_array($a)) {
                $applied[] = [
                    'voucher_id' => $this->getInt($a['voucher_id'] ?? 0),
                    'code'       => $this->getString($a['code'] ?? ''),
                    'amount'     => VoucherStore::money($a['amount'] ?? null),
                    'balance'    => VoucherStore::money($a['balance'] ?? null),
                ];
            }
        }

        return [
            'trx'  => $trxId,
            'txn'  => $txn,
            'meta' => [
                'session'  => $this->getString($vp['session'] ?? ''),
                'amount'   => VoucherStore::money($vp['amount'] ?? null),
                'currency' => $this->getString($vp['currency'] ?? ''),
                'applied'  => $applied,
                'identity' => $vp['identity'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function identityVerified(array $meta): bool
    {
        return CheckoutIdentityService::isVerified(CheckoutIdentityService::state($meta['identity'] ?? null));
    }

    /**
     * @param 'identify'|'signup'|'verify'|'reset' $action
     */
    private function identityStep(Request $request, string $action): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if (self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }

        $input = [];
        foreach (CheckoutIdentityService::INPUT_FIELDS as $field) {
            $input[$field] = $this->getString($request->post($field, ''));
        }
        $state = CheckoutIdentityService::state($ctx['meta']['identity']);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        [$next, $error] = $this->identityService()->handle($action, $mid, $ctx['trx'], $state, $input);

        $ctx['meta']['identity'] = $next;
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        return $error !== null
            ? $this->renderIdentify($request, $ctx, $error, 422)
            : Response::redirect($this->path($ctx['trx'], 'page'), 303);
    }

    /**
     * @param Ctx $ctx
     */
    private function renderIdentify(Request $request, array $ctx, string $error = '', int $status = 200): Response
    {
        $state = CheckoutIdentityService::state($ctx['meta']['identity']);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $customerId = $this->getInt($ctx['txn']['customer_id'] ?? 0);
        return $this->render($request, $ctx, '', false, 'identify', [
            'ci'    => $this->identityService()->viewVars($mid, $state, $customerId > 0 ? $customerId : null),
            'error' => $error,
        ], $status);
    }

    private function identityService(): CheckoutIdentityService
    {
        $svc = $this->container?->get(CheckoutIdentityService::class);
        if (!$svc instanceof CheckoutIdentityService) {
            throw new \RuntimeException('Customer verification is unavailable.');
        }
        return $svc;
    }

    /**
     * @param Ctx $ctx
     * @param array<string, mixed> $vars
     */
    private function render(Request $request, array $ctx, string $message = '', bool $isError = false, string $template = 'page', array $vars = [], ?int $status = null): Response
    {
        $twig = $this->container?->get(Environment::class);
        if (!$twig instanceof Environment) {
            return Response::html('Payment page is unavailable.', 500);
        }
        $this->registerViews($twig);

        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $merchant = Database::getInstance()->fetchOne('SELECT name, settings FROM op_merchants WHERE id = :id', ['id' => $mid]);
        $settings = is_string($merchant['settings'] ?? null) ? json_decode($merchant['settings'], true) : null;
        $color = is_array($settings) ? $this->getString($settings['primary_color'] ?? '') : '';

        $meta = $ctx['meta'];
        $html = $twig->render('@gateway_voucherpay/' . $template . '.twig', $vars + [
            'nonce'     => $this->getString($request->getAttribute('csp_nonce', '')),
            'merchant'  => [
                'name'  => $this->getString($merchant['name'] ?? 'Merchant'),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#059669',
                'logo'  => is_array($settings) ? $this->getString($settings['logo'] ?? '') : '',
            ],
            'trx'       => $ctx['trx'],
            'amount'    => $meta['amount'],
            'currency'  => $meta['currency'],
            'applied'   => $meta['applied'],
            'remaining' => VoucherStore::remaining($meta['amount'], $meta['applied']),
            'message'   => $message,
            'is_error'  => $isError,
            'token'     => $this->formToken($ctx['trx'], $meta['session']),
            'base'      => '/checkout/voucher/' . rawurlencode($ctx['trx']),
            'label'     => $this->checkoutLabel($mid),
            'can_add'   => count($meta['applied']) < self::MAX_VOUCHERS,
        ]);

        return Response::html($html, $status ?? ($isError ? 422 : 200))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function registerViews(Environment $twig): void
    {
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array('gateway_voucherpay', $loader->getNamespaces(), true)) {
            $loader->addPath(__DIR__ . '/views', 'gateway_voucherpay');
        }
    }

    private function formToken(string $trxId, string $session): string
    {
        return hash_hmac('sha256', 'voucher-form|' . $trxId, $session);
    }

    /**
     * @param array{trx: string, meta: array{session: string}} $ctx
     */
    private function validToken(Request $request, array $ctx): bool
    {
        return $ctx['meta']['session'] !== ''
            && hash_equals($this->formToken($ctx['trx'], $ctx['meta']['session']), $this->getString($request->post('token', '')));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function saveMeta(string $trxId, array $meta): void
    {
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.voucherpay', CAST(:m AS JSON))
             WHERE trx_id = :trx",
            ['trx' => $trxId, 'm' => (string) json_encode($meta)]
        );
    }

    private function checkoutLabel(int $merchantId): string
    {
        $label = '';
        try {
            $configs = $this->container?->get(GatewayConfigRepository::class);
            $encryptor = $this->container?->get(FieldEncryptor::class);
            if ($merchantId > 0 && $configs instanceof GatewayConfigRepository && $encryptor instanceof FieldEncryptor) {
                $enc = $configs->forTenant($merchantId)->findCredentialsBySlug(self::SLUG);
                $creds = $enc !== null && $enc !== '' ? json_decode($encryptor->decrypt($enc), true) : null;
                $label = is_array($creds) ? trim($this->getString($creds['checkout_label'] ?? '')) : '';
            }
        } catch (\Throwable $e) {
            $this->logger()?->warning('VoucherPay checkout label lookup failed: ' . $e->getMessage());
        }
        return $label !== '' ? $label : self::DEFAULT_LABEL;
    }

    private function path(string $trxId, string $step): string
    {
        return '/checkout/voucher/' . rawurlencode($trxId) . '/' . $step;
    }

    private function statusUrl(string $trxId): string
    {
        return '/checkout/' . rawurlencode($trxId) . '/status';
    }

    private static function baseUrlOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function logger(): ?Logger
    {
        $logger = $this->container?->has(Logger::class) ? $this->container->get(Logger::class) : null;
        return $logger instanceof Logger ? $logger : null;
    }
}
