<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;

/**
 * Trinidad & Tobago 4-tier Treasury Code compliance addon.
 *
 * @phpstan-type TreasuryParts array{head: string, sub_head: string, item: string, sub_item: string, code_full: string}
 */
final class Plugin implements PluginInterface
{
    /** @var TreasuryParts|null Request-scoped treasury from API/admin middleware */
    public static ?array $requestTreasury = null;

    private ?Container $container = null;
    private ?TreasuryValidator $validator = null;
    private ?TreasuryAssignmentRepository $assignments = null;
    private ?TreasuryCodeRepository $codes = null;

    public static function metadata(): array
    {
        return [
            'name' => 'NPICTT Treasury Codes',
            'slug' => 'npictt-treasury',
            'version' => '1.1.0',
            'description' => '4-tier Trinidad Treasury Code compliance for payments, links, and invoices.',
            'author' => 'NPICTT',
            'type' => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [
            Capability::ADDON,
            Capability::DASHBOARD,
            Capability::HOOKS,
            Capability::DB_READ,
            Capability::DB_WRITE,
        ];
    }

    public function register(EventManager $events, Container $container): void
    {
        $this->container = $container;

        $events->addFilter('system.middleware.pipeline', [$this, 'injectMiddleware'], 20);
        $events->addAction('payment.intent.created', [$this, 'onIntentCreated'], 5);
        $events->addFilter('payment.transaction.before_create', [$this, 'onBeforeTransactionCreate'], 5);
        $events->addAction('payment.transaction.created', [$this, 'onTransactionCreated'], 5);
        $events->addAction('payment_link.created', [$this, 'onPaymentLinkSaved'], 5);
        $events->addAction('payment_link.updated', [$this, 'onPaymentLinkSaved'], 5);
        $events->addAction('invoice.created', [$this, 'onInvoiceSaved'], 5);
        $events->addAction('invoice.updated', [$this, 'onInvoiceSaved'], 5);
        $events->addAction('ledger.entry.created', [$this, 'onLedgerEntryCreated'], 5);
        $events->addFilter('gateway.capture.before', [$this, 'onGatewayCaptureBefore'], 5);
        $events->addFilter('admin.template.resolve', [$this, 'onAdminTemplateResolve'], 5);
        $events->addFilter('admin.template.data', [$this, 'onAdminTemplateData'], 5);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
        $db = Database::getInstance();
        $this->ensureSchema($db);
        $this->syncManifestFromDisk($db);
        $this->codes = new TreasuryCodeRepository($db);
        $this->assignments = new TreasuryAssignmentRepository($db);
        $this->validator = new TreasuryValidator($this->codes);

        $container->instance(TreasuryCodeRepository::class, $this->codes);
        $container->instance(TreasuryAssignmentRepository::class, $this->assignments);
        $container->instance(TreasuryValidator::class, $this->validator);
        $this->ensureViewsRegistered();
    }

    /**
     * Keep op_plugins.manifest in sync with disk so admin routes (options before {id}, view)
     * match the current addon after code updates without a reinstall.
     */
    private function syncManifestFromDisk(Database $db): void
    {
        $path = __DIR__ . '/manifest.json';
        if (!is_file($path)) {
            return;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }
        $encoded = json_encode($decoded, JSON_THROW_ON_ERROR);

        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $row = $db->fetchOne('SELECT id, manifest FROM op_plugins WHERE slug = :s LIMIT 1', ['s' => 'npictt-treasury']);
            if ($row === null) {
                return;
            }
            $current = is_string($row['manifest'] ?? null) ? $row['manifest'] : '';
            if ($current === $encoded) {
                return;
            }
            $db->execute(
                'UPDATE op_plugins SET manifest = :m, version = :v, updated_at = CURRENT_TIMESTAMP(6) WHERE id = :id',
                [
                    'm' => $encoded,
                    'v' => is_string($decoded['version'] ?? null) ? $decoded['version'] : '1.0.0',
                    'id' => (int) $row['id'],
                ]
            );
        } catch (\Throwable) {
            // Non-fatal: routes still load from disk manifest via PluginLoader.
        } finally {
            $events?->popOwner();
        }
    }

    /** Ensure @npictt-treasury Twig namespace is registered on the shared Environment. */
    private function ensureViewsRegistered(): void
    {
        if ($this->container === null || !$this->container->has(\Twig\Environment::class)) {
            return;
        }
        $twig = $this->container->get(\Twig\Environment::class);
        $loader = $twig instanceof \Twig\Environment ? $twig->getLoader() : null;
        if ($loader instanceof \Twig\Loader\FilesystemLoader
            && !in_array('npictt-treasury', $loader->getNamespaces(), true)) {
            $loader->addPath(__DIR__ . '/views', 'npictt-treasury');
        }
    }

    /**
     * Creates plugin tables + seed codes. Kept outside PluginMigrator because MySQL
     * implicitly commits DDL and aborts the migrator's transaction wrapper.
     */
    private function ensureSchema(Database $db): void
    {
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `op_plugin_npictt_treasury_codes` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `merchant_id` BIGINT UNSIGNED NULL,
              `head` VARCHAR(2) NOT NULL,
              `sub_head` VARCHAR(2) NOT NULL,
              `item` VARCHAR(3) NOT NULL,
              `sub_item` VARCHAR(2) NOT NULL,
              `code_full` VARCHAR(14) NOT NULL,
              `head_name` VARCHAR(255) NOT NULL DEFAULT '',
              `sub_head_name` VARCHAR(255) NOT NULL DEFAULT '',
              `item_name` VARCHAR(255) NOT NULL DEFAULT '',
              `sub_item_name` VARCHAR(255) NOT NULL DEFAULT '',
              `label` VARCHAR(512) NULL,
              `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
              `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_code_full_merchant` (`merchant_id`, `code_full`),
              KEY `idx_tiers` (`head`, `sub_head`, `item`),
              KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `op_plugin_npictt_treasury_assignments` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `merchant_id` BIGINT UNSIGNED NOT NULL,
              `entity_type` ENUM('payment_link','payment_intent','transaction','ledger_transaction','invoice') NOT NULL,
              `entity_id` BIGINT UNSIGNED NOT NULL,
              `head` VARCHAR(2) NOT NULL,
              `sub_head` VARCHAR(2) NOT NULL,
              `item` VARCHAR(3) NOT NULL,
              `sub_item` VARCHAR(2) NOT NULL,
              `code_full` VARCHAR(14) NOT NULL,
              `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
              `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_entity` (`entity_type`, `entity_id`),
              KEY `idx_tiers` (`head`, `sub_head`, `item`),
              KEY `idx_merchant_code` (`merchant_id`, `code_full`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->migrateCatalogColumns($db);
        $this->seedCatalog($db);
        $this->seedPaymentLinkAssignments($db);
        $this->seedInvoiceAssignments($db);
    }

    /** Add tier name fields and CoA column widths on existing installs. */
    private function migrateCatalogColumns(Database $db): void
    {
        $cols = $db->fetchAll('SHOW COLUMNS FROM op_plugin_npictt_treasury_codes');
        $names = [];
        foreach ($cols as $col) {
            if (isset($col['Field']) && is_string($col['Field'])) {
                $names[$col['Field']] = true;
            }
        }

        $alters = [];
        if (!isset($names['head_name'])) {
            $alters[] = "ADD COLUMN `head_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `code_full`";
        }
        if (!isset($names['sub_head_name'])) {
            $alters[] = "ADD COLUMN `sub_head_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `head_name`";
        }
        if (!isset($names['item_name'])) {
            $alters[] = "ADD COLUMN `item_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `sub_head_name`";
        }
        if (!isset($names['sub_item_name'])) {
            $alters[] = "ADD COLUMN `sub_item_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `item_name`";
        }
        if ($alters !== []) {
            $db->execute('ALTER TABLE `op_plugin_npictt_treasury_codes` ' . implode(', ', $alters));
        }

        // Drop legacy 2/2/2/3 placeholders before shrinking sub_item to VARCHAR(2).
        $legacy = ['26/02/05/002', '26/01/01/001', '43/03/02/010'];
        $repo = new TreasuryCodeRepository($db);
        $repo->deletePlatformByCodes($legacy);
        $db->execute(
            "DELETE FROM op_plugin_npictt_treasury_assignments
             WHERE code_full IN ('26/02/05/002','26/01/01/001','43/03/02/010')
                OR CHAR_LENGTH(sub_item) > 2
                OR CHAR_LENGTH(item) > 3"
        );

        $db->execute(
            'ALTER TABLE `op_plugin_npictt_treasury_codes`
             MODIFY COLUMN `item` VARCHAR(3) NOT NULL,
             MODIFY COLUMN `sub_item` VARCHAR(2) NOT NULL,
             MODIFY COLUMN `code_full` VARCHAR(14) NOT NULL,
             MODIFY COLUMN `label` VARCHAR(512) NULL'
        );
        $db->execute(
            'ALTER TABLE `op_plugin_npictt_treasury_assignments`
             MODIFY COLUMN `item` VARCHAR(3) NOT NULL,
             MODIFY COLUMN `sub_item` VARCHAR(2) NOT NULL,
             MODIFY COLUMN `code_full` VARCHAR(14) NOT NULL'
        );
    }

    /**
     * Idempotent T&T Estimates Chart of Accounts seed (platform-wide).
     * Heads: Appropriation FY 2025. Sub-Items: Estimates CoA Goods and Services.
     * Skips work when the platform catalog already has the expected row count.
     */
    private function seedCatalog(Database $db): void
    {
        $seeds = TreasuryCatalogSeed::catalogRows();
        $expected = count($seeds);
        $countRow = $db->fetchOne(
            'SELECT COUNT(*) AS c FROM op_plugin_npictt_treasury_codes WHERE merchant_id IS NULL'
        );
        $have = (int) ($countRow['c'] ?? 0);
        if ($have >= $expected) {
            return;
        }

        $repo = new TreasuryCodeRepository($db);
        foreach ($seeds as $seed) {
            $repo->upsertPlatform($seed);
        }
    }

    /**
     * Assign Sub-Item 23 (Fees) under each ministry Head to existing payment links.
     * Runs with core owner so we can read op_payment_links.
     */
    private function seedPaymentLinkAssignments(Database $db): void
    {
        $this->seedEntityFeeAssignments($db, 'payment_link', 'op_payment_links', "status = 'active'");
    }

    /**
     * Assign Sub-Item 23 (Fees) to existing open invoices (not cancelled/paid).
     */
    private function seedInvoiceAssignments(Database $db): void
    {
        $this->seedEntityFeeAssignments(
            $db,
            'invoice',
            'op_invoices',
            "status NOT IN ('cancelled','paid')"
        );
    }

    private function seedEntityFeeAssignments(
        Database $db,
        string $entityType,
        string $table,
        string $where,
    ): void {
        $map = TreasuryCatalogSeed::paymentLinkHeadByMerchant();
        if ($map === []) {
            return;
        }
        if (!in_array($table, ['op_payment_links', 'op_invoices'], true)) {
            return;
        }

        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $rows = $db->fetchAll(
                "SELECT id, merchant_id FROM {$table} WHERE {$where} ORDER BY id"
            );
        } finally {
            $events?->popOwner();
        }

        $assignments = new TreasuryAssignmentRepository($db);
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $mid = (int) ($row['merchant_id'] ?? 0);
            if ($id <= 0 || $mid <= 0 || !isset($map[$mid])) {
                continue;
            }
            $codeFull = TreasuryCatalogSeed::feeCollectionCodeForHead($map[$mid]);
            $parts = explode('/', $codeFull);
            if (count($parts) !== 4) {
                continue;
            }
            $assignments->upsert($mid, $entityType, $id, [
                'head' => $parts[0],
                'sub_head' => $parts[1],
                'item' => $parts[2],
                'sub_item' => $parts[3],
                'code_full' => $codeFull,
            ]);
        }
    }

    public function deactivate(Container $container): void {}

    public function uninstall(Container $container): void
    {
        $db = Database::getInstance();
        $db->execute('DROP TABLE IF EXISTS `op_plugin_npictt_treasury_assignments`');
        $db->execute('DROP TABLE IF EXISTS `op_plugin_npictt_treasury_codes`');
    }

    public function fields(): array
    {
        return [];
    }

    /** @param mixed $pipeline */
    public function injectMiddleware(mixed $pipeline): mixed
    {
        if (!is_array($pipeline)) {
            return $pipeline;
        }
        if (isset($pipeline['api']) && is_array($pipeline['api'])) {
            $pipeline['api'][] = TreasuryApiMiddleware::class;
        }
        if (isset($pipeline['admin']) && is_array($pipeline['admin'])) {
            $pipeline['admin'][] = TreasuryAdminMiddleware::class;
        }
        if (isset($pipeline['checkout']) && is_array($pipeline['checkout'])) {
            $pipeline['checkout'][] = TreasuryCheckoutMiddleware::class;
        }
        return $pipeline;
    }

    // --- Admin route handlers (manifest routes point at Plugin methods) ---

    public function adminIndex(Request $request): Response
    {
        return $this->admin()->index($request);
    }

    public function adminCreate(Request $request): Response
    {
        return $this->admin()->create($request);
    }

    public function adminEdit(Request $request): Response
    {
        return $this->admin()->edit($request);
    }

    public function adminView(Request $request): Response
    {
        return $this->admin()->view($request);
    }

    public function adminOptions(Request $request): Response
    {
        return $this->admin()->options($request);
    }

    /** @param array<string, mixed> $intent */
    public function onIntentCreated(array $intent): void
    {
        $treasury = $this->resolveFromGlobalsOrMeta($intent);
        if ($treasury === null) {
            return;
        }
        $id = (int) ($intent['id'] ?? 0);
        $mid = (int) ($intent['merchant_id'] ?? 0);
        if ($id <= 0 || $mid <= 0) {
            return;
        }

        $this->assignments()?->upsert($mid, 'payment_intent', $id, $treasury);
        $this->mergeMetadataIntoCore('op_payment_intents', $id, $treasury);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function onBeforeTransactionCreate(array $data, mixed $merchantId = null): array
    {
        $mid = is_numeric($merchantId) ? (int) $merchantId : (int) ($data['merchant_id'] ?? 0);
        $resolved = $this->resolveForNewTransactionDetailed($data, $mid);

        if ($resolved['treasury'] === null) {
            $data['__reject'] = $resolved['message'] ?? TreasuryValidator::MESSAGE_TRANSACTION;
            return $data;
        }

        $treasury = $resolved['treasury'];
        $meta = $this->decodeMeta($data['metadata'] ?? null);
        $meta = array_merge($meta, $this->validator()?->metadataPayload($treasury) ?? []);
        $data['metadata'] = json_encode($meta);
        $data['_treasury'] = $treasury;

        return $data;
    }

    /** @param array<string, mixed> $txn */
    public function onTransactionCreated(array $txn): void
    {
        $treasury = null;
        if (isset($txn['_treasury']) && is_array($txn['_treasury'])) {
            /** @var TreasuryParts $treasury */
            $treasury = $txn['_treasury'];
        } else {
            $treasury = $this->resolveFromGlobalsOrMeta($txn);
        }
        if ($treasury === null) {
            $meta = $this->decodeMeta($txn['metadata'] ?? null);
            $treasury = $this->validator()?->extract($meta);
        }
        if ($treasury === null) {
            return;
        }

        $id = (int) ($txn['id'] ?? 0);
        $mid = (int) ($txn['merchant_id'] ?? 0);
        if ($id <= 0 || $mid <= 0) {
            return;
        }

        $this->assignments()?->upsert($mid, 'transaction', $id, $treasury);
        $this->mergeMetadataIntoCore('op_transactions', $id, $treasury);
    }

    /** @param array<string, mixed> $link */
    public function onPaymentLinkSaved(array $link): void
    {
        $this->persistEntityFromRequest('payment_link', $link);
    }

    /** @param array<string, mixed> $invoice */
    public function onInvoiceSaved(array $invoice): void
    {
        $this->persistEntityFromRequest('invoice', $invoice);
    }

    /** @param array<string, mixed> $payload */
    public function onLedgerEntryCreated(array $payload): void
    {
        $ledgerTxnId = (int) ($payload['transaction_id'] ?? 0);
        $mid = (int) ($payload['merchant_id'] ?? 0);
        if ($ledgerTxnId <= 0 || $mid <= 0) {
            return;
        }

        // Resolve source payment transaction via ledger journal reference when possible.
        $treasury = null;
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $db = Database::getInstance();
            $journal = $db->fetchOne(
                'SELECT reference_type, reference_id FROM op_ledger_transactions WHERE id = :id LIMIT 1',
                ['id' => $ledgerTxnId]
            );
            if (is_array($journal) && ($journal['reference_type'] ?? '') === 'transaction') {
                $refId = (int) ($journal['reference_id'] ?? 0);
                if ($refId > 0) {
                    $treasury = $this->assignments()?->find('transaction', $refId);
                }
            }
        } finally {
            $events?->popOwner();
        }

        if ($treasury !== null) {
            $this->assignments()?->upsert($mid, 'ledger_transaction', $ledgerTxnId, $treasury);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function onGatewayCaptureBefore(array $params, mixed ...$args): array
    {
        $trxId = is_string($params['trx_id'] ?? null) ? $params['trx_id'] : '';
        if ($trxId === '') {
            return $params;
        }

        $events = $this->events();
        $events?->pushOwner('core');
        try {
            $row = Database::getInstance()->fetchOne(
                'SELECT id, merchant_id, metadata FROM op_transactions WHERE trx_id = :t LIMIT 1',
                ['t' => $trxId]
            );
        } finally {
            $events?->popOwner();
        }

        if (!is_array($row)) {
            return $params;
        }

        $txnId = (int) $row['id'];
        $txnMeta = $this->decodeMeta($row['metadata'] ?? null);
        $treasury = $this->assignments()?->find('transaction', $txnId)
            ?? $this->validator()?->extract($txnMeta);

        if ($treasury === null) {
            $linkId = (int) ($txnMeta['payment_link_id'] ?? 0);
            if ($linkId > 0) {
                $treasury = $this->assignments()?->find('payment_link', $linkId);
            }
        }
        if ($treasury === null) {
            $invoiceId = (int) ($txnMeta['invoice_id'] ?? 0);
            if ($invoiceId > 0) {
                $treasury = $this->assignments()?->find('invoice', $invoiceId);
            }
        }
        if ($treasury === null) {
            return $params;
        }

        $mid = (int) ($row['merchant_id'] ?? $params['merchant_id'] ?? 0);
        if ($mid > 0 && $txnId > 0) {
            $this->assignments()?->upsert($mid, 'transaction', $txnId, $treasury);
            $this->mergeMetadataIntoCore('op_transactions', $txnId, $treasury);
        }

        $meta = is_array($params['metadata'] ?? null) ? $params['metadata'] : [];
        $params['metadata'] = array_merge($meta, $this->validator()?->metadataPayload($treasury) ?? []);
        return $params;
    }

    /**
     * Route core payment-link / invoice edit screens to addon-owned templates
     * (docs-aligned: compliance UI lives in the addon, not the theme).
     */
    public function onAdminTemplateResolve(mixed $template, mixed $data = null): mixed
    {
        if (!is_string($template)) {
            return $template;
        }
        $this->ensureViewsRegistered();
        return match ($template) {
            'admin/payment-links/edit.twig' => '@npictt-treasury/admin/payment-links-edit.twig',
            'admin/payment-links/index.twig' => '@npictt-treasury/admin/payment-links-index.twig',
            'admin/invoices/edit.twig' => '@npictt-treasury/admin/invoices-edit.twig',
            'admin/invoices/index.twig' => '@npictt-treasury/admin/invoices-index.twig',
            'admin/transactions/index.twig' => '@npictt-treasury/admin/transactions-index.twig',
            'admin/transactions/edit.twig' => '@npictt-treasury/admin/transactions-edit.twig',
            default => $template,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function onAdminTemplateData(array $data, mixed $template = null): array
    {
        $this->ensureViewsRegistered();
        $tpl = is_string($template) ? $template : '';
        $isLink = str_contains($tpl, 'payment-links');
        $isInvoice = str_contains($tpl, 'invoices');
        $isTxn = str_contains($tpl, 'transactions');
        $isEdit = str_contains($tpl, 'edit');
        $isIndex = str_contains($tpl, 'index');

        if ($isLink && $isIndex && isset($data['payment_links']) && is_array($data['payment_links'])) {
            $data['payment_links'] = $this->enrichEntityRows($data['payment_links'], 'payment_link');
            return $data;
        }

        if ($isInvoice && $isIndex && isset($data['invoices']) && is_array($data['invoices'])) {
            $data['invoices'] = $this->enrichEntityRows($data['invoices'], 'invoice');
            return $data;
        }

        if ($isTxn && $isIndex && isset($data['transactions']) && is_array($data['transactions'])) {
            $data['transactions'] = $this->enrichTransactionRows($data['transactions']);
            return $data;
        }

        if ($isTxn && $isEdit && isset($data['txn']) && is_array($data['txn'])) {
            $data['txn'] = $this->enrichTransactionDetail($data['txn']);
            return $data;
        }

        if (!$isEdit || (!$isLink && !$isInvoice)) {
            return $data;
        }

        $data['treasury_codes'] = $this->codes()?->listActiveOptions() ?? [];
        $data['treasury_selected'] = [];
        $data['treasury_status'] = 'missing';

        if ($isLink && isset($data['link']) && is_array($data['link'])) {
            $id = (int) ($data['link']['id'] ?? 0);
            if ($id > 0) {
                $data['treasury_selected'] = $this->assignments()?->find('payment_link', $id) ?? [];
            }
        }
        if ($isInvoice && isset($data['invoice']) && is_array($data['invoice'])) {
            $id = (int) ($data['invoice']['id'] ?? 0);
            if ($id > 0) {
                $data['treasury_selected'] = $this->assignments()?->find('invoice', $id) ?? [];
            }
        }
        if (is_array($data['treasury_selected']) && ($data['treasury_selected']['code_full'] ?? '') !== '') {
            $data['treasury_status'] = 'mapped';
        }

        return $data;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichEntityRows(array $rows, string $entityType): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $map = $this->assignments()?->mapForEntities($entityType, $ids) ?? [];
        $enriched = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $assigned = $map[$id] ?? null;
            $row['treasury_status'] = $assigned !== null ? 'mapped' : 'missing';
            $row['treasury_code'] = is_array($assigned) ? (string) ($assigned['code_full'] ?? '') : '';
            $enriched[] = $row;
        }
        return $enriched;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichTransactionRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        $map = $this->assignments()?->mapForEntities('transaction', $ids) ?? [];
        $enriched = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $assigned = $map[$id] ?? null;
            $code = is_array($assigned) ? (string) ($assigned['code_full'] ?? '') : '';
            if ($code === '') {
                $code = $this->treasuryCodeFromMeta($row['metadata'] ?? null);
            }
            $row['treasury_code'] = $code;
            $row['treasury_status'] = $code !== '' ? 'mapped' : 'missing';
            $enriched[] = $row;
        }
        return $enriched;
    }

    /**
     * @param array<string, mixed> $txn
     * @return array<string, mixed>
     */
    private function enrichTransactionDetail(array $txn): array
    {
        $id = (int) ($txn['id'] ?? 0);
        $assigned = $id > 0 ? ($this->assignments()?->find('transaction', $id) ?? null) : null;
        $code = is_array($assigned) ? (string) ($assigned['code_full'] ?? '') : '';
        if ($code === '') {
            $code = $this->treasuryCodeFromMeta($txn['metadata'] ?? null);
        }
        $txn['treasury_code'] = $code;
        $txn['treasury_status'] = $code !== '' ? 'mapped' : 'missing';
        $txn['treasury_label'] = '';
        if ($code !== '') {
            $catalog = $this->codes()?->findByCodeFull($code);
            if (is_array($catalog)) {
                $label = is_string($catalog['label'] ?? null) ? $catalog['label'] : '';
                if ($label === '') {
                    $parts = array_filter([
                        is_string($catalog['head_name'] ?? null) ? $catalog['head_name'] : '',
                        is_string($catalog['sub_head_name'] ?? null) ? $catalog['sub_head_name'] : '',
                        is_string($catalog['item_name'] ?? null) ? $catalog['item_name'] : '',
                        is_string($catalog['sub_item_name'] ?? null) ? $catalog['sub_item_name'] : '',
                    ], static fn (string $p): bool => $p !== '');
                    $label = implode(' · ', $parts);
                }
                $txn['treasury_label'] = $label;
            }
        }
        return $txn;
    }

    private function treasuryCodeFromMeta(mixed $raw): string
    {
        $meta = $this->decodeMeta($raw);
        $code = $meta['treasury_code'] ?? null;
        if (is_string($code) && $code !== '') {
            return $code;
        }
        $nested = is_array($meta['treasury'] ?? null) ? $meta['treasury'] : [];
        $nestedCode = $nested['code_full'] ?? null;
        return is_string($nestedCode) ? $nestedCode : '';
    }

    // --- internals ---

    private function admin(): TreasuryAdminController
    {
        if ($this->container === null || $this->codes === null || $this->validator === null) {
            throw new \RuntimeException('Treasury addon not booted.');
        }
        return new TreasuryAdminController($this->container, $this->codes, $this->validator);
    }

    /** @param array<string, mixed> $entity */
    private function persistEntityFromRequest(string $entityType, array $entity): void
    {
        $id = (int) ($entity['id'] ?? 0);
        $mid = (int) ($entity['merchant_id'] ?? 0);
        if ($id <= 0 || $mid <= 0) {
            return;
        }

        $treasury = null;
        if (isset($_POST) && is_array($_POST)) {
            $treasury = $this->validator()?->extract($_POST);
        }
        if ($treasury === null && is_array(self::$requestTreasury)) {
            $treasury = self::$requestTreasury;
        }
        if ($treasury === null) {
            $treasury = $this->assignments()?->find($entityType, $id);
        }
        if ($treasury === null) {
            return;
        }

        $result = $this->validator()?->validate($treasury, $mid);
        if (!is_array($result) || !($result['ok'] ?? false)) {
            return;
        }
        /** @var TreasuryParts $ok */
        $ok = $result['treasury'];
        $this->assignments()?->upsert($mid, $entityType, $id, $ok);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{treasury: TreasuryParts|null, message: string|null}
     */
    private function resolveForNewTransactionDetailed(array $data, int $merchantId): array
    {
        $meta = $this->decodeMeta($data['metadata'] ?? null);
        $custom = is_array($meta['custom_metadata'] ?? null) ? $meta['custom_metadata'] : [];
        $merged = array_merge($meta, $custom);

        $fromMeta = $this->validator()?->extract($merged);
        if ($fromMeta !== null) {
            $check = $this->validator()?->validate($fromMeta, $merchantId);
            if (is_array($check) && ($check['ok'] ?? false)) {
                return ['treasury' => $check['treasury'], 'message' => null];
            }
            if (is_array($check) && !($check['ok'] ?? false)) {
                $msg = is_string($check['message'] ?? null) ? $check['message'] : TreasuryValidator::MESSAGE_REQUIRED;
                return ['treasury' => null, 'message' => $msg];
            }
        }

        $linkId = (int) ($meta['payment_link_id'] ?? 0);
        if ($linkId > 0) {
            $fromLink = $this->assignments()?->find('payment_link', $linkId);
            if ($fromLink !== null) {
                return ['treasury' => $fromLink, 'message' => null];
            }
            return [
                'treasury' => null,
                'message' => TreasuryValidator::MESSAGE_PAYMENT_LINK
                    . " (Payment link #{$linkId}. Admin → Payment Links → Edit → Treasury Code.)",
            ];
        }

        $invoiceId = (int) ($meta['invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            $fromInv = $this->assignments()?->find('invoice', $invoiceId);
            if ($fromInv !== null) {
                return ['treasury' => $fromInv, 'message' => null];
            }
            return [
                'treasury' => null,
                'message' => TreasuryValidator::MESSAGE_INVOICE
                    . " (Invoice #{$invoiceId}. Admin → Invoices → Edit → Treasury Code.)",
            ];
        }

        $intentId = (int) ($data['payment_intent_id'] ?? 0);
        if ($intentId > 0) {
            $fromIntent = $this->assignments()?->find('payment_intent', $intentId);
            if ($fromIntent !== null) {
                return ['treasury' => $fromIntent, 'message' => null];
            }
            return ['treasury' => null, 'message' => TreasuryValidator::MESSAGE_INTENT];
        }

        $fromGlobals = $this->resolveFromGlobalsOrMeta($data);
        if ($fromGlobals !== null) {
            $check = $this->validator()?->validate($fromGlobals, $merchantId);
            if (is_array($check) && ($check['ok'] ?? false)) {
                return ['treasury' => $check['treasury'], 'message' => null];
            }
        }

        return ['treasury' => null, 'message' => TreasuryValidator::MESSAGE_TRANSACTION];
    }

    /**
     * @param array<string, mixed> $row
     * @return TreasuryParts|null
     */
    private function resolveFromGlobalsOrMeta(array $row): ?array
    {
        if (is_array(self::$requestTreasury) && isset(self::$requestTreasury['code_full'])) {
            return self::$requestTreasury;
        }

        $meta = $this->decodeMeta($row['metadata'] ?? null);
        $custom = is_array($meta['custom_metadata'] ?? null) ? $meta['custom_metadata'] : [];
        return $this->validator()?->extract(array_merge($meta, $custom));
    }

    /**
     * @param TreasuryParts $treasury
     */
    private function mergeMetadataIntoCore(string $table, int $id, array $treasury): void
    {
        if (!in_array($table, ['op_transactions', 'op_payment_intents'], true)) {
            return;
        }
        $payload = $this->validator()?->metadataPayload($treasury);
        if ($payload === null) {
            return;
        }
        $events = $this->events();
        $events?->pushOwner('core');
        try {
            Database::getInstance()->execute(
                "UPDATE {$table}
                 SET metadata = JSON_SET(
                     COALESCE(metadata, JSON_OBJECT()),
                     '$.treasury_code', :code,
                     '$.treasury', CAST(:obj AS JSON)
                 )
                 WHERE id = :id",
                [
                    'id' => $id,
                    'code' => $payload['treasury_code'],
                    'obj' => json_encode($payload['treasury'], JSON_THROW_ON_ERROR),
                ]
            );
        } finally {
            $events?->popOwner();
        }
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

    private function events(): ?EventManager
    {
        if ($this->container === null || !$this->container->has(EventManager::class)) {
            return null;
        }
        $e = $this->container->get(EventManager::class);
        return $e instanceof EventManager ? $e : null;
    }

    private function validator(): ?TreasuryValidator
    {
        return $this->validator;
    }

    private function assignments(): ?TreasuryAssignmentRepository
    {
        return $this->assignments;
    }

    private function codes(): ?TreasuryCodeRepository
    {
        return $this->codes;
    }
}
