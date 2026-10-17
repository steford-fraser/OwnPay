<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Container;
use OwnPay\Controller\Admin\AdminPageTrait;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Admin\AdminSession;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TreasuryAdminController
{
    use AdminPageTrait;

    private const URL = '/admin/treasury-codes';
    private const TWIG_NS = 'npictt-treasury';

    public function __construct(
        Container $c,
        private readonly TreasuryCodeRepository $codes,
        private readonly TreasuryValidator $validator,
    ) {
        $this->c = $c;
        $session = $c->get(AdminSession::class);
        if (!$session instanceof AdminSession) {
            throw new \RuntimeException('Admin session unavailable.');
        }
        $this->session = $session;
    }

    /**
     * Core Twig singleton (config/services.php) does not auto-register addon views;
     * mirror voucher admin and attach our namespace before rendering.
     */
    private function ensureViewsRegistered(): void
    {
        $twig = $this->c->get(Environment::class);
        $loader = $twig instanceof Environment ? $twig->getLoader() : null;
        if ($loader instanceof FilesystemLoader && !in_array(self::TWIG_NS, $loader->getNamespaces(), true)) {
            $loader->addPath(__DIR__ . '/views', self::TWIG_NS);
        }
    }

    public function index(Request $request): Response
    {
        $this->ensureViewsRegistered();
        return $this->renderAdminPage('@npictt-treasury/admin/index.twig', [
            'active_page' => 'treasury-codes',
            'codes' => $this->codes->listAll(),
            'unmapped_invoices' => $this->countUnmappedInvoices(),
        ]);
    }

    /**
     * Open invoices that still need a treasury assignment (payment-link status lives on the links list).
     *
     * @return list<array{id: int, label: string}>
     */
    private function countUnmappedInvoices(): array
    {
        $events = $this->c->has(EventManager::class) ? $this->c->get(EventManager::class) : null;
        if ($events instanceof EventManager) {
            $events->pushOwner('core');
        }
        try {
            $rows = Database::getInstance()->fetchAll(
                "SELECT e.id, e.invoice_number AS label
                 FROM op_invoices e
                 LEFT JOIN op_plugin_npictt_treasury_assignments a
                   ON a.entity_type = 'invoice' AND a.entity_id = e.id
                 WHERE e.status NOT IN ('cancelled','paid') AND a.id IS NULL
                 ORDER BY e.id DESC
                 LIMIT 25"
            );
        } finally {
            if ($events instanceof EventManager) {
                $events->popOwner();
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => is_string($row['label'] ?? null) ? $row['label'] : ('#' . (string) ($row['id'] ?? '')),
            ];
        }
        return $out;
    }

    public function create(Request $request): Response
    {
        if ($request->method() === 'GET') {
            $this->ensureViewsRegistered();
            return $this->renderAdminPage('@npictt-treasury/admin/edit.twig', [
                'active_page' => 'treasury-codes',
                'code' => [],
            ]);
        }

        $parsed = $this->parseForm($request);
        if ($parsed === null) {
            $this->session->flashError('Enter a valid 4-tier treasury code (e.g. 18/02/001/01) with names for each tier.');
            return Response::redirect(self::URL . '/create');
        }

        try {
            $this->codes->create($parsed);
            $this->session->flashSuccess('Treasury code created.');
        } catch (\Throwable $e) {
            $this->session->flashError('Could not save treasury code (duplicate?).');
            return Response::redirect(self::URL . '/create');
        }

        return Response::redirect(self::URL);
    }

    public function edit(Request $request): Response
    {
        $id = (int) $request->param('id');
        $existing = $this->codes->find($id);
        if ($existing === null) {
            $this->session->flashError('Treasury code not found.');
            return Response::redirect(self::URL);
        }

        if ($request->method() === 'GET') {
            $this->ensureViewsRegistered();
            return $this->renderAdminPage('@npictt-treasury/admin/edit.twig', [
                'active_page' => 'treasury-codes',
                'code' => $existing,
            ]);
        }

        $parsed = $this->parseForm($request);
        if ($parsed === null) {
            $this->session->flashError('Enter a valid 4-tier treasury code (e.g. 18/02/001/01) with names for each tier.');
            return Response::redirect(self::URL . '/' . $id . '/edit');
        }

        try {
            $this->codes->update($id, $parsed);
            $this->session->flashSuccess('Treasury code updated.');
        } catch (\Throwable $e) {
            $this->session->flashError('Could not update treasury code.');
            return Response::redirect(self::URL . '/' . $id . '/edit');
        }

        return Response::redirect(self::URL);
    }

    public function view(Request $request): Response
    {
        $id = (int) $request->param('id');
        $existing = $this->codes->find($id);
        if ($existing === null) {
            $this->session->flashError('Treasury code not found.');
            return Response::redirect(self::URL);
        }

        $this->ensureViewsRegistered();
        return $this->renderAdminPage('@npictt-treasury/admin/view.twig', [
            'active_page' => 'treasury-codes',
            'code' => $existing,
        ]);
    }

    public function options(Request $request): Response
    {
        $rows = $this->codes->listActiveOptions();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'code_full' => (string) ($row['code_full'] ?? ''),
                'head' => (string) ($row['head'] ?? ''),
                'sub_head' => (string) ($row['sub_head'] ?? ''),
                'item' => (string) ($row['item'] ?? ''),
                'sub_item' => (string) ($row['sub_item'] ?? ''),
                'head_name' => (string) ($row['head_name'] ?? ''),
                'sub_head_name' => (string) ($row['sub_head_name'] ?? ''),
                'item_name' => (string) ($row['item_name'] ?? ''),
                'sub_item_name' => (string) ($row['sub_item_name'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
            ];
        }
        return Response::json(['success' => true, 'data' => $out]);
    }

    /**
     * @return array{
     *   head: string, sub_head: string, item: string, sub_item: string, code_full: string,
     *   head_name: string, sub_head_name: string, item_name: string, sub_item_name: string,
     *   label: string, status: string, merchant_id: null
     * }|null
     */
    private function parseForm(Request $request): ?array
    {
        $post = $request->post();
        $input = is_array($post) ? $post : [];
        $parts = $this->validator->fromParts($input);
        if ($parts === null) {
            return null;
        }

        $headName = $this->scalarTrim($input['head_name'] ?? null);
        $subHeadName = $this->scalarTrim($input['sub_head_name'] ?? null);
        $itemName = $this->scalarTrim($input['item_name'] ?? null);
        $subItemName = $this->scalarTrim($input['sub_item_name'] ?? null);
        if ($headName === '' || $subHeadName === '' || $itemName === '' || $subItemName === '') {
            return null;
        }

        $status = (($input['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
        $label = TreasuryCodeRepository::buildLabel($headName, $subHeadName, $itemName, $subItemName);

        return [
            'head' => $parts['head'],
            'sub_head' => $parts['sub_head'],
            'item' => $parts['item'],
            'sub_item' => $parts['sub_item'],
            'code_full' => $parts['code_full'],
            'head_name' => $headName,
            'sub_head_name' => $subHeadName,
            'item_name' => $itemName,
            'sub_item_name' => $subItemName,
            'label' => $label,
            'status' => $status,
            'merchant_id' => null,
        ];
    }

    private function scalarTrim(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }
}
