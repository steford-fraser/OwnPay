<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Voucherpay;

use OwnPay\Container;
use OwnPay\Controller\Admin\AdminPageTrait;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Minimal admin page for the platform-wide voucher pool: list balances and issue vouchers.
 */
final class VoucherAdmin
{
    use AdminPageTrait;

    private const URL = '/admin/gateways/vouchers';

    public function __construct(Container $c, private readonly VoucherStore $store)
    {
        $this->c = $c;
        $session = $c->get(AdminSession::class);
        if (!$session instanceof AdminSession) {
            throw new \RuntimeException('Admin session unavailable.');
        }
        $this->session = $session;
    }

    public function index(Request $request): Response
    {
        $twig = $this->c->get(Environment::class);
        $loader = $twig instanceof Environment ? $twig->getLoader() : null;
        if ($loader instanceof FilesystemLoader && !in_array('gateway_voucherpay', $loader->getNamespaces(), true)) {
            $loader->addPath(__DIR__ . '/views', 'gateway_voucherpay');
        }

        $vouchers = $this->store->listAll();
        $outstanding = [];
        foreach ($vouchers as $v) {
            $expires = is_string($v['expires_at'] ?? null) ? strtotime($v['expires_at']) : false;
            if (($v['status'] ?? '') !== 'active' || ($expires !== false && $expires < time())) {
                continue;
            }
            $cur = is_string($v['currency'] ?? null) ? $v['currency'] : '';
            $outstanding[$cur] = bcadd($outstanding[$cur] ?? '0', VoucherStore::money($v['balance'] ?? null), 2);
        }

        return $this->renderAdminPage('@gateway_voucherpay/admin/index.twig', [
            'active_page' => 'vouchers',
            'vouchers'    => $vouchers,
            'outstanding' => $outstanding,
            'can_issue'   => $this->isGlobalBrandView(),
            'now'         => date('Y-m-d H:i:s'),
        ]);
    }

    public function issue(Request $request): Response
    {
        $guard = $this->requireGlobalView(self::URL, 'issue vouchers');
        if ($guard !== null) {
            return $guard;
        }

        $count = (int) (is_scalar($request->post('count')) ? $request->post('count') : 0);
        $amountRaw = is_scalar($request->post('amount')) ? trim((string) $request->post('amount')) : '';
        $currency = strtoupper(is_scalar($request->post('currency')) ? trim((string) $request->post('currency')) : '');
        $expiresRaw = is_scalar($request->post('expires_at')) ? trim((string) $request->post('expires_at')) : '';

        if ($count < 1 || $count > 100) {
            $this->session->flashError('Issue between 1 and 100 vouchers at a time.');
            return Response::redirect(self::URL);
        }
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amountRaw) || !is_numeric($amountRaw) || bccomp($amountRaw, '0', 2) <= 0) {
            $this->session->flashError('Enter a voucher amount greater than zero.');
            return Response::redirect(self::URL);
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->session->flashError('Enter a 3-letter currency code, e.g. TTD.');
            return Response::redirect(self::URL);
        }
        $expiresAt = null;
        if ($expiresRaw !== '') {
            $ts = strtotime($expiresRaw . ' 23:59:59');
            if ($ts === false) {
                $this->session->flashError('Enter a valid expiry date.');
                return Response::redirect(self::URL);
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $codes = $this->store->issue($count, $currency, bcadd($amountRaw, '0', 2), $expiresAt);
        $this->session->flashSuccess('Issued ' . count($codes) . " {$currency} " . bcadd($amountRaw, '0', 2) . ' voucher(s): ' . implode(', ', $codes));
        return Response::redirect(self::URL);
    }
}
