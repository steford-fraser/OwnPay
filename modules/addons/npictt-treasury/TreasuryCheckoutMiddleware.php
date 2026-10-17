<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\Logger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Blocks checkout for payment links / invoices / intents that lack a treasury assignment.
 * End users see a generic NPICTT-styled page; technical detail is logged for admins.
 */
final class TreasuryCheckoutMiddleware
{
    public function __construct(private readonly Container $container) {}

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->path();

        if (preg_match('#^/pay/([^/]+)(?:/submit)?$#', $path, $m) === 1) {
            return $this->guardPaymentLink($request, $next, rawurldecode($m[1]));
        }

        if (preg_match('#^/invoice/([^/]+)$#', $path, $m) === 1) {
            return $this->guardInvoice($request, $next, rawurldecode($m[1]));
        }

        if (preg_match('#^/checkout/intent/([^/]+)(?:/pay|/express)?$#', $path, $m) === 1
            && in_array($request->method(), ['GET', 'POST'], true)) {
            return $this->guardIntent($request, $next, rawurldecode($m[1]));
        }

        if (preg_match('#^/checkout/([^/]+)(?:/pay|/express)?$#', $path, $m) === 1
            && !str_starts_with($m[1], 'intent')
            && in_array($request->method(), ['GET', 'POST'], true)) {
            return $this->guardCheckoutTxn($request, $next, rawurldecode($m[1]));
        }

        return $next($request);
    }

    private function guardPaymentLink(Request $request, callable $next, string $slug): Response
    {
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $link = Database::getInstance()->fetchOne(
                'SELECT id, title FROM op_payment_links WHERE slug = :s AND status = \'active\' LIMIT 1',
                ['s' => $slug]
            );
        } finally {
            $events?->popOwner();
        }

        if (!is_array($link)) {
            return $next($request);
        }

        $id = (int) ($link['id'] ?? 0);
        if ($id > 0 && $this->assignments()->find('payment_link', $id) === null) {
            $title = is_string($link['title'] ?? null) ? $link['title'] : $slug;
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_PAYMENT_LINK
                . " (Link: {$title} #{$id}. Admin → Payment Links → Edit → Treasury Code.)"
            );
        }

        return $next($request);
    }

    private function guardInvoice(Request $request, callable $next, string $token): Response
    {
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $invoice = Database::getInstance()->fetchOne(
                'SELECT id, invoice_number FROM op_invoices WHERE token = :t LIMIT 1',
                ['t' => $token]
            );
        } finally {
            $events?->popOwner();
        }

        if (!is_array($invoice)) {
            return $next($request);
        }

        $id = (int) ($invoice['id'] ?? 0);
        if ($id > 0 && $this->assignments()->find('invoice', $id) === null) {
            $num = is_string($invoice['invoice_number'] ?? null) ? $invoice['invoice_number'] : (string) $id;
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_INVOICE
                . " (Invoice: {$num}. Admin → Invoices → Edit → Treasury Code.)"
            );
        }

        return $next($request);
    }

    private function guardIntent(Request $request, callable $next, string $token): Response
    {
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $intent = Database::getInstance()->fetchOne(
                'SELECT id, metadata FROM op_payment_intents WHERE token = :t LIMIT 1',
                ['t' => $token]
            );
        } finally {
            $events?->popOwner();
        }

        if (!is_array($intent)) {
            return $next($request);
        }

        $id = (int) ($intent['id'] ?? 0);
        $validator = $this->validator();
        $meta = $this->decodeMeta($intent['metadata'] ?? null);
        $custom = is_array($meta['custom_metadata'] ?? null) ? $meta['custom_metadata'] : [];
        $fromMeta = $validator->extract(array_merge($meta, $custom));
        $fromAssign = $id > 0 ? $this->assignments()->find('payment_intent', $id) : null;

        if ($fromMeta === null && $fromAssign === null) {
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_INTENT
            );
        }

        return $next($request);
    }

    private function guardCheckoutTxn(Request $request, callable $next, string $trxId): Response
    {
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $txn = Database::getInstance()->fetchOne(
                'SELECT id, metadata, payment_intent_id FROM op_transactions WHERE trx_id = :t LIMIT 1',
                ['t' => $trxId]
            );
        } finally {
            $events?->popOwner();
        }

        if (!is_array($txn)) {
            return $next($request);
        }

        $txnId = (int) ($txn['id'] ?? 0);
        if ($txnId > 0 && $this->assignments()->find('transaction', $txnId) !== null) {
            return $next($request);
        }

        $meta = $this->decodeMeta($txn['metadata'] ?? null);
        $validator = $this->validator();
        if ($validator->extract($meta) !== null) {
            return $next($request);
        }

        $linkId = (int) ($meta['payment_link_id'] ?? 0);
        if ($linkId > 0) {
            if ($this->assignments()->find('payment_link', $linkId) !== null) {
                return $next($request);
            }
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_PAYMENT_LINK
                . " (Payment link #{$linkId}. Admin → Payment Links → Edit → Treasury Code.)"
            );
        }

        $invoiceId = (int) ($meta['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            if ($this->assignments()->find('invoice', $invoiceId) !== null) {
                return $next($request);
            }
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_INVOICE
                . " (Invoice #{$invoiceId}. Admin → Invoices → Edit → Treasury Code.)"
            );
        }

        $intentId = (int) ($txn['payment_intent_id'] ?? 0);
        if ($intentId > 0 && $this->assignments()->find('payment_intent', $intentId) === null) {
            return $this->blocked(
                $request,
                TreasuryValidator::ERR_UNMAPPED,
                TreasuryValidator::MESSAGE_INTENT
            );
        }

        return $next($request);
    }

    private function blocked(Request $request, string $code, string $adminMessage): Response
    {
        $this->logAdminDetail($code, $adminMessage, $request->path());

        // Browser/checkout clients: never expose treasury configuration details.
        $public = TreasuryValidator::MESSAGE_PUBLIC;

        if ($request->isAjax() || str_starts_with($request->path(), '/api/')) {
            return Response::json([
                'success' => false,
                'error' => $public,
            ], 422);
        }

        return Response::html($this->renderUnavailablePage(), 422);
    }

    private function logAdminDetail(string $code, string $message, string $path): void
    {
        if (!$this->container->has(Logger::class)) {
            return;
        }
        $logger = $this->container->get(Logger::class);
        if ($logger instanceof Logger) {
            $logger->warning('Treasury checkout blocked', [
                'code' => $code,
                'message' => $message,
                'path' => $path,
            ]);
        }
    }

    private function renderUnavailablePage(): string
    {
        try {
            if ($this->container->has(Environment::class)) {
                $twig = $this->container->get(Environment::class);
                if ($twig instanceof Environment) {
                    $loader = $twig->getLoader();
                    if ($loader instanceof FilesystemLoader
                        && !in_array('npictt-treasury', $loader->getNamespaces(), true)) {
                        $loader->addPath(__DIR__ . '/views', 'npictt-treasury');
                    }
                    // Prefer public brand name (theme/settings), not the generic OwnPay product name.
                    $appName = 'NPICTT Pay';
                    try {
                        $events = $this->events();
                        $events?->pushOwner('core');
                        $brand = Database::getInstance()->fetchOne(
                            "SELECT value FROM op_system_settings
                             WHERE (group_name = 'branding' AND key_name = 'site_name')
                                OR (group_name = 'general' AND key_name = 'app_name')
                             AND value IS NOT NULL AND TRIM(value) != ''
                             ORDER BY CASE
                               WHEN group_name = 'branding' AND key_name = 'site_name' THEN 0
                               ELSE 1
                             END
                             LIMIT 1"
                        );
                        if (is_array($brand) && is_string($brand['value'] ?? null) && trim($brand['value']) !== '') {
                            $appName = trim($brand['value']);
                        }
                    } catch (\Throwable) {
                        // keep default
                    } finally {
                        $this->events()?->popOwner();
                    }
                    return $twig->render('@npictt-treasury/checkout/unavailable.twig', [
                        'app_name' => $appName,
                    ]);
                }
            }
        } catch (\Throwable) {
            // Fall through to inline HTML
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Payment unavailable</title></head><body style="font-family:Helvetica,Arial,sans-serif;padding:2rem;color:#222">'
            . '<h1 style="color:#0003B1">This payment is unavailable</h1>'
            . '<p style="color:#5f6470">' . htmlspecialchars(TreasuryValidator::MESSAGE_PUBLIC, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<p><a href="/" style="color:#0003B1">Back to home</a></p></body></html>';
    }

    /** @return array<string, mixed> */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function assignments(): TreasuryAssignmentRepository
    {
        $a = $this->container->get(TreasuryAssignmentRepository::class);
        if (!$a instanceof TreasuryAssignmentRepository) {
            throw new \RuntimeException('TreasuryAssignmentRepository unavailable');
        }
        return $a;
    }

    private function validator(): TreasuryValidator
    {
        $v = $this->container->get(TreasuryValidator::class);
        if (!$v instanceof TreasuryValidator) {
            throw new \RuntimeException('TreasuryValidator unavailable');
        }
        return $v;
    }

    private function events(): ?EventManager
    {
        if (!$this->container->has(EventManager::class)) {
            return null;
        }
        $e = $this->container->get(EventManager::class);
        return $e instanceof EventManager ? $e : null;
    }
}
