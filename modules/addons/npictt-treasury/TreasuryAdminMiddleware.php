<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;

/**
 * Blocks payment-link and invoice admin saves without a valid catalog treasury code.
 */
final class TreasuryAdminMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, callable $next): Response
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $path = $request->path();
        $isLink = $path === '/admin/payment-links/store'
            || (bool) preg_match('#^/admin/payment-links/\d+/update$#', $path);
        $isInvoice = $path === '/admin/invoices/store'
            || (bool) preg_match('#^/admin/invoices/\d+/update$#', $path);

        if (!$isLink && !$isInvoice) {
            return $next($request);
        }

        $post = $request->post();
        $input = is_array($post) ? $post : [];

        $validator = $this->validator();
        $result = $validator->validate($input, null);
        if (!($result['ok'] ?? false)) {
            $session = $this->container->get(AdminSession::class);
            $message = is_string($result['message'] ?? null) ? $result['message'] : TreasuryValidator::MESSAGE_REQUIRED;
            if ($session instanceof AdminSession) {
                $session->flashError($message);
            }
            if ($isInvoice) {
                $redirect = $path === '/admin/invoices/store'
                    ? '/admin/invoices/create'
                    : ((string) (preg_replace('#/update$#', '', $path) ?: '/admin/invoices'));
            } else {
                $redirect = $path === '/admin/payment-links/store'
                    ? '/admin/payment-links/create'
                    : ((string) (preg_replace('#/update$#', '', $path) ?: '/admin/payment-links'));
            }
            return Response::redirect($redirect);
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

    private function validator(): TreasuryValidator
    {
        $v = $this->container->get(TreasuryValidator::class);
        if (!$v instanceof TreasuryValidator) {
            throw new \RuntimeException('TreasuryValidator unavailable');
        }
        return $v;
    }
}
