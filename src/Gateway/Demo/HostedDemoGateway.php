<?php
declare(strict_types=1);

namespace OwnPay\Gateway\Demo;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Security\FieldEncryptor;
use OwnPay\Service\Customer\CheckoutIdentityService;
use OwnPay\Service\Payment\GatewayApiService;
use OwnPay\Service\Payment\TransactionService;
use OwnPay\Service\System\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Plumbing shared by the simulated multi-method hosted gateways (GovPay, CourtPay): the payment
 * session stored in transaction metadata, payer identification, the card + 3-D Secure flow,
 * split-tender prepaid codes and completion through the core callback pipeline.
 *
 * The using class must also use GatewayDefaults and define the constants SLUG (plugin slug),
 * PATH (hosted page prefix, e.g. '/checkout/govpay/'), META (transaction metadata key),
 * VIEWS (Twig namespace), DEFAULT_LABEL, SESSION_TTL (seconds) and METHODS (method => tab label;
 * each method is toggled by a "method_{name}" yes/no setting).
 *
 * Session meta: session, expires_at, amount, currency, stage ('pay'|'3ds'|'done'), method,
 * identity, applied (prepaid codes), card {brand, last4}, plus anything the gateway adds.
 *
 * @phpstan-type Ctx array{trx: string, txn: array<string, mixed>, meta: array<string, mixed>, creds: array<string, string>}
 * @phpstan-type Applied array{code_id: int, code: string, channel: string, amount: numeric-string, balance: numeric-string}
 */
trait HostedDemoGateway
{
    private const MAX_CODES = 5;

    private ?Container $container = null;

    /** @var array<int, array<string, string>> Decrypted credentials per merchant, memoised for the request. */
    private array $credentials = [];

    /** @var array<string, string> */
    private array $dataUris = [];

    /**
     * Brand variables (logos, colours, support lines) added to every hosted-page render.
     *
     * @return array<string, mixed>
     */
    abstract protected function brandVars(): array;

    abstract protected function codeStore(): PrepaidCodeStore;

    public function slug(): string { return self::SLUG; }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    /**
     * Renames this gateway on the checkout grid to the brand's "Checkout Label".
     */
    protected function registerLabelFilter(EventManager $events): void
    {
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

    public function checkoutLabel(int $merchantId): string
    {
        $label = trim($this->credentialsFor($merchantId)['checkout_label'] ?? '');
        return $label !== '' ? $label : self::DEFAULT_LABEL;
    }

    /**
     * Opens the hosted-page session on the transaction and returns the redirect to it.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $extra Gateway-specific session fields.
     * @return array{redirect_url: string, session_id: string}
     */
    protected function openSession(array $params, array $credentials, string $sessionPrefix, array $extra = []): array
    {
        if ($this->getString($credentials['api_key'] ?? '') === '') {
            throw new \RuntimeException(static::metadata()['name'] . ' API key is not configured.');
        }
        if (self::enabledMethods($credentials) === []) {
            throw new \RuntimeException('No ' . static::metadata()['name'] . ' payment methods are enabled.');
        }
        $trxId = $params['trx_id'];
        $returnBase = self::baseUrlOf($params['redirect_url']);
        if ($trxId === '' || $returnBase === '') {
            throw new \RuntimeException('Payment initiation is missing the transaction reference.');
        }

        $session = $sessionPrefix . bin2hex(random_bytes(12));
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET gateway_trx_id = :sid,
                 metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), :path, CAST(:m AS JSON))
             WHERE trx_id = :trx",
            [
                'sid'  => $session,
                'trx'  => $trxId,
                'path' => '$.' . self::META,
                'm'    => (string) json_encode($extra + [
                    'session'    => $session,
                    'expires_at' => time() + self::SESSION_TTL,
                    'amount'     => $this->toDecimalString($params['amount']),
                    'currency'   => strtoupper($params['currency']),
                    'stage'      => 'pay',
                    'method'     => null,
                    'applied'    => [],
                ]),
            ]
        );

        return [
            'redirect_url' => $returnBase . self::PATH . rawurlencode($trxId) . '/page',
            'session_id'   => $session,
        ];
    }

    /**
     * Payment methods enabled in the gateway settings, in display order.
     *
     * @param array<string, mixed> $creds
     * @return array<int, string>
     */
    public static function enabledMethods(array $creds): array
    {
        $out = [];
        foreach (self::METHODS as $method => $_label) {
            $flag = $creds['method_' . $method] ?? 'yes';
            if (!is_string($flag) || $flag !== 'no') {
                $out[] = $method;
            }
        }
        return $out;
    }

    /**
     * Card result check: ['trx_id', 'session', 'outcome', 'amount', 'signature'].
     *
     * @param array<string, mixed> $data
     * @return array{success: bool, gateway_trx_id?: string, amount?: string, status?: string, trx_id?: string, message?: string}
     */
    protected function verifyCard(array $data, string $secret): array
    {
        $trxId = $this->getString($data['trx_id'] ?? '');
        $session = $this->getString($data['session'] ?? '');
        $outcome = $this->getString($data['outcome'] ?? '');
        $amount = $this->getString($data['amount'] ?? '');
        $signature = $this->getString($data['signature'] ?? '');

        $fail = ['success' => false, 'status' => 'failed', 'trx_id' => $trxId];
        if ($secret === '' || $trxId === '' || $session === '' || $signature === '' || !is_numeric($amount)) {
            return $fail;
        }
        if (!hash_equals(DemoCard::sign($trxId, $session, $outcome, $amount, $secret), $signature)) {
            return $fail;
        }
        if ($outcome !== 'approved') {
            return $fail + ['message' => DemoCard::message($outcome)];
        }
        return [
            'success'        => true,
            'gateway_trx_id' => $session,
            'amount'         => $this->toDecimalString($amount),
            'status'         => 'completed',
            'trx_id'         => $trxId,
        ];
    }

    /**
     * Succeeds when $total has been taken for the transaction (codes or wallet); the total is
     * returned as the amount so core rejects any mismatch with the transaction amount.
     *
     * @param numeric-string $total
     * @return array{success: bool, gateway_trx_id?: string, amount?: string, status?: string, trx_id?: string}
     */
    protected static function verifyTaken(string $trxId, string $session, string $total): array
    {
        if ($trxId === '' || bccomp($total, '0', 2) <= 0) {
            return ['success' => false, 'status' => 'failed', 'trx_id' => $trxId];
        }
        return ['success' => true, 'gateway_trx_id' => $session, 'amount' => $total, 'status' => 'completed', 'trx_id' => $trxId];
    }

    /**
     * Finds the transaction behind a gateway session id (for refunds).
     *
     * @return array{trx_id: string, meta: array<string, mixed>}|null
     */
    protected function transactionBySession(string $gatewayTrxId): ?array
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT trx_id, metadata FROM op_transactions WHERE gateway_trx_id = :g AND gateway_slug = :s LIMIT 1',
            ['g' => $gatewayTrxId, 's' => self::SLUG]
        );
        if ($row === null || !is_string($row['trx_id'] ?? null)) {
            return null;
        }
        return ['trx_id' => $row['trx_id'], 'meta' => self::metaOf($row)];
    }

    // ---------------------------------------------------------------- hosted page plumbing

    /**
     * Loads the transaction, session and credentials for a hosted-page request.
     *
     * @return Ctx|Response
     */
    protected function context(Request $request): array|Response
    {
        $trxId = $request->param('trx');
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }

        $meta = self::metaOf($txn);
        if (($txn['gateway_slug'] ?? '') !== self::SLUG
            || ($txn['status'] ?? '') !== 'processing'
            || !isset($meta['session'], $meta['stage'])
        ) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        $mid = $this->getInt($txn['merchant_id'] ?? 0);
        $creds = $this->credentialsFor($mid);
        if (($creds['api_key'] ?? '') === '') {
            return Response::html(static::metadata()['name'] . ' is not configured for this brand.', 403);
        }

        if ($this->getInt($meta['expires_at'] ?? 0) < time()) {
            $svc = $this->container?->get(TransactionService::class);
            if ($svc instanceof TransactionService) {
                $svc->fail($this->getInt($txn['id'] ?? 0), $mid, 'Payment session expired.');
            }
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        return ['trx' => $trxId, 'txn' => $txn, 'meta' => $meta, 'creds' => $creds];
    }

    /**
     * Resolves the method tab to show: the requested one if enabled, else the first enabled.
     *
     * @param Ctx $ctx
     */
    protected function activeMethod(array $ctx, string $requested): string
    {
        $enabled = self::enabledMethods($ctx['creds']);
        return in_array($requested, $enabled, true) ? $requested : ($enabled[0] ?? '');
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function identityVerified(array $meta): bool
    {
        return CheckoutIdentityService::isVerified(CheckoutIdentityService::state($meta['identity'] ?? null));
    }

    /**
     * Runs one payer-identification step, then returns to the page (optionally on a method tab).
     *
     * @param 'identify'|'signup'|'verify'|'reset' $action
     */
    protected function identityStep(Request $request, string $action): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $method = $this->activeMethod($ctx, $this->getString($request->post('method', '')));
        $back = $this->path($ctx['trx'], 'page') . ($method !== '' ? '?method=' . rawurlencode($method) : '');
        if ($ctx['meta']['stage'] !== 'pay' || self::identityVerified($ctx['meta']) || !$this->validToken($request, $ctx)) {
            return Response::redirect($back, 303);
        }

        $input = [];
        foreach (CheckoutIdentityService::INPUT_FIELDS as $field) {
            $input[$field] = $this->getString($request->post($field, ''));
        }
        $state = CheckoutIdentityService::state($ctx['meta']['identity'] ?? null);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        [$next, $error] = $this->identityService()->handle($action, $mid, $ctx['trx'], $state, $input);

        $ctx['meta']['identity'] = $next;
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        return $error !== null
            ? $this->renderPage($request, $ctx, $method, ['error' => $error], 422)
            : Response::redirect($back, 303);
    }

    /**
     * Identification partial variables for the current payer.
     *
     * @param Ctx $ctx
     * @return array<string, mixed>
     */
    protected function identifyVars(array $ctx): array
    {
        $state = CheckoutIdentityService::state($ctx['meta']['identity'] ?? null);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $customerId = $this->getInt($ctx['txn']['customer_id'] ?? 0);
        return $this->identityService()->viewVars($mid, $state, $customerId > 0 ? $customerId : null);
    }

    /**
     * Verified payer contact, or null before identification completes.
     *
     * @param array<string, mixed> $meta
     * @return array{type: 'email'|'phone', value: string}|null
     */
    protected static function verifiedContact(array $meta): ?array
    {
        $state = CheckoutIdentityService::state($meta['identity'] ?? null);
        return CheckoutIdentityService::isVerified($state) ? $state['contact'] : null;
    }

    /**
     * Renders the method page; each gateway supplies its own per-method variables.
     *
     * @param Ctx $ctx
     * @param array<string, mixed> $vars
     */
    abstract protected function renderPage(Request $request, array $ctx, string $method, array $vars = [], int $status = 200): Response;

    // ---------------------------------------------------------------- card + 3-D Secure

    /**
     * Validates the posted card and resolves the test outcome (3DS challenge or processing page).
     *
     * @param Ctx $ctx
     * @param array<string, mixed> $extraMeta Gateway fields saved with the card attempt.
     */
    protected function cardSubmit(Request $request, array $ctx, array $extraMeta = []): Response
    {
        $name = trim($this->getString($request->post('cardholder', '')));
        $number = (string) preg_replace('/\D/', '', $this->getString($request->post('card_number', '')));
        $expiry = trim($this->getString($request->post('expiry', '')));
        $cvc = trim($this->getString($request->post('cvc', '')));

        $error = DemoCard::validate($name, $number, $expiry, $cvc);
        if ($error !== null) {
            return $this->renderPage($request, $ctx, 'card', ['error' => $error, 'name' => $name] + $extraMeta, 422);
        }

        $card = ['brand' => DemoCard::brand($number), 'last4' => substr($number, -4)];
        $outcome = DemoCard::outcome($number, $ctx['creds']['three_ds'] ?? 'test_cards');
        $ctx['meta'] = ['method' => 'card', 'card' => $card] + $extraMeta + $ctx['meta'];
        if ($outcome === 'challenge') {
            $ctx['meta']['stage'] = '3ds';
            $this->saveMeta($ctx['trx'], $ctx['meta']);
            return Response::redirect($this->path($ctx['trx'], '3ds'), 303);
        }
        return $this->cardFinish($request, $ctx, $outcome);
    }

    /**
     * GET shows the issuer one-time-passcode challenge; POST checks it.
     */
    protected function threeDsStep(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($ctx['meta']['stage'] !== '3ds') {
            return Response::redirect($this->path($ctx['trx'], 'page'), 303);
        }
        if ($request->method() !== 'POST') {
            return $this->render($request, '3ds', $ctx, ['card' => $this->cardOf($ctx['meta'])]);
        }
        if (!$this->validToken($request, $ctx)) {
            return Response::redirect($this->path($ctx['trx'], '3ds'), 303);
        }
        $otp = trim($this->getString($request->post('otp', '')));
        return $this->cardFinish($request, $ctx, hash_equals(DemoCard::OTP, $otp) ? 'approved' : 'auth_failed');
    }

    /**
     * @param Ctx $ctx
     */
    private function cardFinish(Request $request, array $ctx, string $outcome): Response
    {
        $ctx['meta'] = ['stage' => 'done', 'outcome' => $outcome] + $ctx['meta'];
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        $session = $this->getString($ctx['meta']['session'] ?? '');
        $amount = $this->getString($ctx['meta']['amount'] ?? '0.00');
        $delay = max(0, min(10, $this->getInt($ctx['creds']['processing_delay'] ?? 2)));

        return $this->render($request, 'processing', $ctx, [
            'card'      => $this->cardOf($ctx['meta']),
            'outcome'   => $outcome,
            'delay'     => $delay,
            'signature' => DemoCard::sign($ctx['trx'], $session, $outcome, $amount, $ctx['creds']['api_key'] ?? ''),
        ]);
    }

    /**
     * Signed card result posted back by the processing page; completes or fails the payment.
     */
    protected function cardReturn(Request $request): Response
    {
        $trxId = $request->param('trx');
        $txn = $this->findTransaction($trxId);
        if ($txn === null) {
            return Response::html('Payment not found.', 404);
        }
        $meta = self::metaOf($txn);
        $outcome = $this->getString($request->post('outcome', ''));
        $txnId = $this->getInt($txn['id'] ?? 0);
        $mid = $this->getInt($txn['merchant_id'] ?? 0);

        if (!$this->claim($txnId, $mid)) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        $success = false;
        try {
            $result = $this->gatewayApi()->handleCallback($mid, self::SLUG, [
                'trx_id'    => $trxId,
                'method'    => 'card',
                'session'   => $this->getString($meta['session'] ?? ''),
                'outcome'   => $outcome,
                'amount'    => $this->getString($request->post('amount', '')),
                'signature' => $this->getString($request->post('signature', '')),
            ]);
            $success = (bool) $result['success'];
        } catch (\Throwable $e) {
            $this->logger()?->error(static::metadata()['name'] . ' card return failed: ' . $e->getMessage(), ['trx_id' => $trxId]);
        }

        if (!$success) {
            $this->failClaimed($txnId, $mid, DemoCard::message($outcome));
        }
        return Response::redirect($this->statusUrl($trxId), 303);
    }

    // ---------------------------------------------------------------- prepaid codes

    /**
     * Allocates a posted code towards what is still due (nothing is deducted yet).
     *
     * @param Ctx $ctx
     */
    protected function applyCode(Request $request, array $ctx, string $method): Response
    {
        $meta = $ctx['meta'];
        $applied = self::appliedOf($meta);
        $due = PrepaidCodeStore::remaining(PrepaidCodeStore::money($meta['amount'] ?? null), $applied);
        $code = PrepaidCodeStore::normalize($this->getString($request->post('code', '')));
        $currency = $this->getString($meta['currency'] ?? '');

        $error = match (true) {
            $code === ''                       => 'Enter a code.',
            bccomp($due, '0', 2) <= 0          => 'The payment is already fully covered - press Pay.',
            count($applied) >= self::MAX_CODES => 'You can use at most ' . self::MAX_CODES . ' codes on one payment.',
            in_array($code, array_column($applied, 'code'), true) => 'That code is already applied.',
            default                            => null,
        };
        $row = $error === null ? $this->codeStore()->find($code) : null;
        $error ??= PrepaidCodeStore::rejectReason($row, $currency);
        if ($error !== null || $row === null) {
            return $this->renderPage($request, $ctx, $method, ['message' => $error ?? 'That code is not valid.', 'is_error' => true], 422);
        }

        $balance = PrepaidCodeStore::money($row['balance'] ?? null);
        $take = PrepaidCodeStore::allocate($balance, $due);
        $applied[] = [
            'code_id' => $this->getInt($row['id'] ?? 0),
            'code'    => $code,
            'channel' => $this->getString($row['channel'] ?? 'voucher'),
            'amount'  => $take,
            'balance' => $balance,
        ];
        $ctx['meta']['applied'] = $applied;
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        $left = PrepaidCodeStore::remaining(PrepaidCodeStore::money($meta['amount'] ?? null), $applied);
        $message = bccomp($left, '0', 2) > 0
            ? "Code applied: {$currency} {$take}. {$currency} {$left} still due - enter another code."
            : "Code applied: {$currency} {$take}. The payment is fully covered - press Pay.";
        return $this->renderPage($request, $ctx, $method, ['message' => $message]);
    }

    /**
     * @param Ctx $ctx
     */
    protected function removeCode(Request $request, array $ctx, string $method): Response
    {
        $code = PrepaidCodeStore::normalize($this->getString($request->post('code', '')));
        $ctx['meta']['applied'] = PrepaidCodeStore::reallocate(
            PrepaidCodeStore::money($ctx['meta']['amount'] ?? null),
            array_values(array_filter(self::appliedOf($ctx['meta']), static fn(array $a): bool => $a['code'] !== $code))
        );
        $this->saveMeta($ctx['trx'], $ctx['meta']);
        return $this->renderPage($request, $ctx, $method, ['message' => 'Code removed.']);
    }

    /**
     * Deducts the applied codes and completes the payment in one DB transaction.
     *
     * @param Ctx $ctx
     */
    protected function confirmCode(Request $request, array $ctx, string $method): Response
    {
        $applied = self::appliedOf($ctx['meta']);
        $amount = PrepaidCodeStore::money($ctx['meta']['amount'] ?? null);
        if ($applied === [] || bccomp(PrepaidCodeStore::remaining($amount, $applied), '0', 2) > 0) {
            return $this->renderPage($request, $ctx, $method, ['message' => 'Apply codes covering the full amount before paying.', 'is_error' => true], 422);
        }
        $currency = $this->getString($ctx['meta']['currency'] ?? '');
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $ctx['meta']['method'] = $method;
        $this->saveMeta($ctx['trx'], $ctx['meta']);

        return $this->complete($request, $ctx, $method, function () use ($ctx, $mid, $currency, $applied): void {
            $this->codeStore()->redeem($ctx['trx'], $mid, $currency, $applied);
        }, static function (array $meta): array {
            $meta['applied'] = [];
            return $meta;
        });
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<int, Applied>
     */
    protected static function appliedOf(array $meta): array
    {
        $out = [];
        foreach (is_array($meta['applied'] ?? null) ? $meta['applied'] : [] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $out[] = [
                'code_id' => is_numeric($a['code_id'] ?? null) ? (int) $a['code_id'] : 0,
                'code'    => is_string($a['code'] ?? null) ? $a['code'] : '',
                'channel' => is_string($a['channel'] ?? null) ? $a['channel'] : 'voucher',
                'amount'  => PrepaidCodeStore::money($a['amount'] ?? null),
                'balance' => PrepaidCodeStore::money($a['balance'] ?? null),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- completion

    /**
     * Claims the transaction, runs $take (deduct funds) and the core callback in one DB
     * transaction, then redirects to the status page. If funds changed meanwhile, the
     * transaction is reopened and the page re-rendered with $reset applied to the meta.
     *
     * @param Ctx $ctx
     * @param callable(): void $take
     * @param callable(array<string, mixed>): array<string, mixed> $reset
     */
    protected function complete(Request $request, array $ctx, string $method, callable $take, callable $reset): Response
    {
        $trxId = $ctx['trx'];
        $txnId = $this->getInt($ctx['txn']['id'] ?? 0);
        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        if (!$this->claim($txnId, $mid)) {
            return Response::redirect($this->statusUrl($trxId), 303);
        }

        $db = Database::getInstance();
        try {
            $db->transaction(function () use ($take, $mid, $trxId, $method, $ctx): void {
                $take();
                $result = $this->gatewayApi()->handleCallback($mid, self::SLUG, [
                    'trx_id'  => $trxId,
                    'method'  => $method,
                    'session' => $this->getString($ctx['meta']['session'] ?? ''),
                ]);
                if (!$result['success']) {
                    throw new \RuntimeException($this->getString($result['error'] ?? 'Payment was not completed.'));
                }
            });
        } catch (PrepaidBalanceChanged $e) {
            $db->update(
                "UPDATE op_transactions SET status = 'processing' WHERE id = :id AND status = 'callback_processing'",
                ['id' => $txnId]
            );
            $ctx['meta'] = $reset($ctx['meta']);
            $this->saveMeta($trxId, $ctx['meta']);
            return $this->renderPage($request, $ctx, $method, ['message' => $e->getMessage(), 'is_error' => true], 409);
        } catch (\Throwable $e) {
            $this->logger()?->warning(static::metadata()['name'] . ' payment not completed: ' . $e->getMessage(), ['trx_id' => $trxId]);
            $this->failClaimed($txnId, $mid, 'Payment could not be completed.');
        }

        return Response::redirect($this->statusUrl($trxId), 303);
    }

    private function claim(int $txnId, int $mid): bool
    {
        return Database::getInstance()->update(
            "UPDATE op_transactions SET status = 'callback_processing'
             WHERE id = :id AND merchant_id = :mid AND status = 'processing' AND gateway_slug = :gw",
            ['id' => $txnId, 'mid' => $mid, 'gw' => self::SLUG]
        ) > 0;
    }

    private function failClaimed(int $txnId, int $mid, string $reason): void
    {
        $svc = $this->container?->get(TransactionService::class);
        if ($svc instanceof TransactionService) {
            $svc->fail($txnId, $mid, $reason);
            return;
        }
        Database::getInstance()->update(
            "UPDATE op_transactions SET status = 'failed' WHERE id = :id AND status = 'callback_processing'",
            ['id' => $txnId]
        );
    }

    // ---------------------------------------------------------------- rendering + helpers

    /**
     * @param Ctx $ctx
     * @param array<string, mixed> $vars
     */
    protected function render(Request $request, string $template, array $ctx, array $vars = [], int $status = 200): Response
    {
        $twig = $this->container?->get(Environment::class);
        if (!$twig instanceof Environment) {
            return Response::html('Payment page is unavailable.', 500);
        }
        $loader = $twig->getLoader();
        if ($loader instanceof FilesystemLoader && !in_array(self::VIEWS, $loader->getNamespaces(), true)) {
            $loader->addPath($this->pluginDir() . '/views', self::VIEWS);
        }

        $mid = $this->getInt($ctx['txn']['merchant_id'] ?? 0);
        $html = $twig->render('@' . self::VIEWS . '/' . $template . '.twig', $vars + $this->brandVars() + [
            'nonce'      => $this->getString($request->getAttribute('csp_nonce', '')),
            'merchant'   => $this->merchantInfo($mid),
            'trx'        => $ctx['trx'],
            'amount'     => $this->getString($ctx['meta']['amount'] ?? '0.00'),
            'currency'   => $this->getString($ctx['meta']['currency'] ?? ''),
            'token'      => $this->formToken($ctx),
            'expires_in' => max(0, $this->getInt($ctx['meta']['expires_at'] ?? 0) - time()),
            'base'       => rtrim(self::PATH, '/') . '/' . rawurlencode($ctx['trx']),
            'label'      => $this->checkoutLabel($mid),
            'error'      => '',
            'message'    => '',
            'is_error'   => false,
        ]);

        return Response::html($html, $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * Base64 data URI for a file in the plugin's assets folder (the CSP allows img-src data:).
     */
    protected function assetDataUri(string $file): string
    {
        if (isset($this->dataUris[$file])) {
            return $this->dataUris[$file];
        }
        $path = $this->pluginDir() . '/assets/' . basename($file);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'svg'   => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
        return $this->dataUris[$file] = $bytes !== false ? 'data:' . $mime . ';base64,' . base64_encode($bytes) : '';
    }

    private function pluginDir(): string
    {
        return dirname((string) (new \ReflectionClass($this))->getFileName());
    }

    /**
     * @param Ctx $ctx
     */
    protected function formToken(array $ctx): string
    {
        return hash_hmac('sha256', 'form|' . $ctx['trx'] . '|' . $this->getString($ctx['meta']['session'] ?? ''), $ctx['creds']['api_key'] ?? '');
    }

    /**
     * @param Ctx $ctx
     */
    protected function validToken(Request $request, array $ctx): bool
    {
        return hash_equals($this->formToken($ctx), $this->getString($request->post('token', '')));
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{brand: string, last4: string}
     */
    protected function cardOf(array $meta): array
    {
        $card = is_array($meta['card'] ?? null) ? $meta['card'] : [];
        return ['brand' => $this->getString($card['brand'] ?? 'Card'), 'last4' => $this->getString($card['last4'] ?? '')];
    }

    /**
     * @param array<string, mixed> $meta
     */
    protected function saveMeta(string $trxId, array $meta): void
    {
        Database::getInstance()->update(
            "UPDATE op_transactions
             SET metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), :path, CAST(:m AS JSON))
             WHERE trx_id = :trx",
            ['trx' => $trxId, 'path' => '$.' . self::META, 'm' => (string) json_encode($meta)]
        );
    }

    /**
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    protected static function metaOf(array $txn): array
    {
        $meta = is_string($txn['metadata'] ?? null) ? json_decode($txn['metadata'], true) : null;
        $own = is_array($meta) ? ($meta[self::META] ?? null) : null;
        if (!is_array($own)) {
            return [];
        }
        $out = [];
        foreach ($own as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function credentialsFor(int $merchantId): array
    {
        if (isset($this->credentials[$merchantId])) {
            return $this->credentials[$merchantId];
        }
        $creds = [];
        try {
            $configs = $this->container?->get(GatewayConfigRepository::class);
            $encryptor = $this->container?->get(FieldEncryptor::class);
            if ($merchantId > 0 && $configs instanceof GatewayConfigRepository && $encryptor instanceof FieldEncryptor) {
                $enc = $configs->forTenant($merchantId)->findCredentialsBySlug(self::SLUG);
                $decoded = $enc !== null && $enc !== '' ? json_decode($encryptor->decrypt($enc), true) : null;
                if (is_array($decoded)) {
                    foreach ($decoded as $k => $v) {
                        $creds[(string) $k] = $this->getString($v);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger()?->warning(static::metadata()['name'] . ' credentials lookup failed: ' . $e->getMessage());
        }
        return $this->credentials[$merchantId] = $creds;
    }

    /**
     * @return array{name: string, color: string, logo: string}
     */
    private function merchantInfo(int $merchantId): array
    {
        $row = Database::getInstance()->fetchOne('SELECT name, settings FROM op_merchants WHERE id = :id', ['id' => $merchantId]);
        $settings = is_string($row['settings'] ?? null) ? json_decode($row['settings'], true) : null;
        $settings = is_array($settings) ? $settings : [];
        $color = $this->getString($settings['primary_color'] ?? '');
        return [
            'name'  => $this->getString($row['name'] ?? 'Merchant'),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#334155',
            'logo'  => $this->getString($settings['logo'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTransaction(string $trxId): ?array
    {
        if ($trxId === '') {
            return null;
        }
        return Database::getInstance()->fetchOne(
            'SELECT id, merchant_id, customer_id, trx_id, status, gateway_slug, metadata FROM op_transactions WHERE trx_id = :t LIMIT 1',
            ['t' => $trxId]
        );
    }

    private function identityService(): CheckoutIdentityService
    {
        $svc = $this->container?->get(CheckoutIdentityService::class);
        if (!$svc instanceof CheckoutIdentityService) {
            throw new \RuntimeException('Customer verification is unavailable.');
        }
        return $svc;
    }

    private function gatewayApi(): GatewayApiService
    {
        $svc = $this->container?->get(GatewayApiService::class);
        if (!$svc instanceof GatewayApiService) {
            throw new \RuntimeException('Payment service unavailable.');
        }
        return $svc;
    }

    protected function path(string $trxId, string $step): string
    {
        return self::PATH . rawurlencode($trxId) . '/' . $step;
    }

    protected function statusUrl(string $trxId): string
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

    protected function logger(): ?Logger
    {
        $logger = $this->container?->has(Logger::class) ? $this->container->get(Logger::class) : null;
        return $logger instanceof Logger ? $logger : null;
    }
}
