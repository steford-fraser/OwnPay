<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Voucherpay;

use OwnPay\Core\Database;

/**
 * Platform-wide prepaid voucher pool: lookup, issuing, redemption and refunds.
 *
 * Balances are only deducted in redeem(), which must run inside the caller's DB transaction
 * so a failed payment rolls the deduction back.
 */
final class VoucherStore
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Database $db) {}

    /**
     * Creates the voucher tables if missing. Runs outside a transaction because MySQL
     * implicitly commits DDL, which breaks the core plugin migrator's transaction wrapper.
     */
    public function ensureSchema(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->execute($statement);
        }
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($code)));
    }

    /**
     * Normalises a DB/user value to a 2-decimal amount; anything non-numeric becomes 0.00.
     *
     * @return numeric-string
     */
    public static function money(mixed $value): string
    {
        $str = is_scalar($value) ? trim((string) $value) : '';
        return is_numeric($str) ? bcadd($str, '0', 2) : '0.00';
    }

    /**
     * Amount a voucher contributes towards what is still due: min(balance, due).
     *
     * @param numeric-string $balance
     * @param numeric-string $due
     * @return numeric-string
     */
    public static function allocate(string $balance, string $due): string
    {
        return bccomp($balance, $due, 2) < 0 ? bcadd($balance, '0', 2) : bcadd($due, '0', 2);
    }

    /**
     * @param numeric-string $total
     * @param array<int, array{amount: numeric-string}> $applied
     * @return numeric-string
     */
    public static function remaining(string $total, array $applied): string
    {
        $left = bcadd($total, '0', 2);
        foreach ($applied as $a) {
            $left = bcsub($left, $a['amount'], 2);
        }
        return bccomp($left, '0', 2) > 0 ? $left : '0.00';
    }

    /**
     * Returns a payer-facing reason the voucher cannot be used, or null when it can.
     *
     * @param array<string, mixed>|null $voucher
     */
    public static function rejectReason(?array $voucher, string $currency, ?int $now = null): ?string
    {
        if ($voucher === null) {
            return 'Voucher not valid.';
        }
        if (($voucher['status'] ?? '') !== 'active') {
            return 'This voucher has been cancelled.';
        }
        $expires = is_string($voucher['expires_at'] ?? null) ? strtotime($voucher['expires_at']) : false;
        if ($expires !== false && $expires < ($now ?? time())) {
            return 'This voucher has expired.';
        }
        $vCurrency = is_string($voucher['currency'] ?? null) ? $voucher['currency'] : '';
        if (strcasecmp($vCurrency, $currency) !== 0) {
            return "This voucher is in {$vCurrency}; this payment is in {$currency}.";
        }
        if (bccomp(self::money($voucher['balance'] ?? null), '0', 2) <= 0) {
            return 'This voucher has no balance left.';
        }
        return null;
    }

    public static function generateCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $part = '';
            for ($i = 0; $i < 4; $i++) {
                $part .= self::CODE_ALPHABET[random_int(0, $max)];
            }
            $groups[] = $part;
        }
        return implode('-', $groups);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM op_plugin_voucherpay_vouchers WHERE code = :c LIMIT 1',
            ['c' => self::normalize($code)]
        );
    }

    /**
     * Creates a voucher, or resets an existing one with the same code to the given state.
     *
     * @param numeric-string $amount
     */
    public function upsert(string $code, string $currency, string $amount, ?string $expiresAt = null): void
    {
        $this->db->execute(
            'INSERT INTO op_plugin_voucherpay_vouchers (code, currency, initial_balance, balance, status, expires_at)
             VALUES (:c, :cur, :a, :b, \'active\', :e)
             ON DUPLICATE KEY UPDATE currency = VALUES(currency), initial_balance = VALUES(initial_balance),
                 balance = VALUES(balance), status = \'active\', expires_at = VALUES(expires_at)',
            ['c' => self::normalize($code), 'cur' => strtoupper($currency), 'a' => $amount, 'b' => $amount, 'e' => $expiresAt]
        );
    }

    /**
     * Issues $count random vouchers and returns their codes.
     *
     * @param numeric-string $amount
     * @return array<int, string>
     */
    public function issue(int $count, string $currency, string $amount, ?string $expiresAt = null): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = self::generateCode();
            if ($this->find($code) !== null) {
                continue;
            }
            $this->upsert($code, $currency, $amount, $expiresAt);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Deducts each applied allocation under a row lock and records the redemptions.
     * Must be called inside a DB transaction.
     *
     * @param array<int, array{voucher_id: int, amount: numeric-string}> $applied
     * @throws VoucherBalanceChanged When a voucher can no longer cover its allocation.
     */
    public function redeem(string $trxId, int $merchantId, string $currency, array $applied): void
    {
        foreach ($applied as $a) {
            $voucher = $this->db->fetchOne(
                'SELECT * FROM op_plugin_voucherpay_vouchers WHERE id = :id FOR UPDATE',
                ['id' => $a['voucher_id']]
            );
            $balance = self::money($voucher['balance'] ?? null);
            if (self::rejectReason($voucher, $currency) !== null || bccomp($balance, $a['amount'], 2) < 0) {
                throw new VoucherBalanceChanged('Voucher balance changed, please re-apply your vouchers.');
            }
            $this->db->update(
                'UPDATE op_plugin_voucherpay_vouchers SET balance = balance - :a WHERE id = :id',
                ['a' => $a['amount'], 'id' => $a['voucher_id']]
            );
            $this->db->insert(
                'INSERT INTO op_plugin_voucherpay_redemptions (voucher_id, trx_id, merchant_id, amount) VALUES (:v, :t, :m, :a)',
                ['v' => $a['voucher_id'], 't' => $trxId, 'm' => $merchantId, 'a' => $a['amount']]
            );
        }
    }

    /**
     * @return numeric-string
     */
    public function redeemedTotal(string $trxId): string
    {
        return self::money($this->db->fetchColumn(
            'SELECT COALESCE(SUM(amount), 0) FROM op_plugin_voucherpay_redemptions WHERE trx_id = :t',
            ['t' => $trxId]
        ));
    }

    /**
     * Credits $amount back to the transaction's vouchers, newest redemption first.
     * Returns the amount that could not be credited (0.00 when fully refunded).
     *
     * @param numeric-string $amount
     * @return numeric-string
     */
    public function refund(string $trxId, string $amount): string
    {
        return $this->db->transaction(function () use ($trxId, $amount): string {
            $left = bcadd($amount, '0', 2);
            $rows = $this->db->fetchAll(
                'SELECT id, voucher_id, amount, refunded_amount FROM op_plugin_voucherpay_redemptions
                 WHERE trx_id = :t ORDER BY id DESC FOR UPDATE',
                ['t' => $trxId]
            );
            foreach ($rows as $r) {
                if (bccomp($left, '0', 2) <= 0) {
                    break;
                }
                $open = bcsub(self::money($r['amount'] ?? null), self::money($r['refunded_amount'] ?? null), 2);
                $credit = self::allocate($open, $left);
                if (bccomp($credit, '0', 2) <= 0) {
                    continue;
                }
                $this->db->update(
                    'UPDATE op_plugin_voucherpay_redemptions SET refunded_amount = refunded_amount + :a WHERE id = :id',
                    ['a' => $credit, 'id' => $r['id']]
                );
                $this->db->update(
                    'UPDATE op_plugin_voucherpay_vouchers SET balance = balance + :a WHERE id = :id',
                    ['a' => $credit, 'id' => $r['voucher_id']]
                );
                $left = bcsub($left, $credit, 2);
            }
            return $left;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->db->fetchAll(
            'SELECT v.*, COUNT(r.id) AS used_count
             FROM op_plugin_voucherpay_vouchers v
             LEFT JOIN op_plugin_voucherpay_redemptions r ON r.voucher_id = v.id
             GROUP BY v.id
             ORDER BY v.created_at DESC, v.id DESC'
        );
    }

    /**
     * Active, non-expired balance totals by currency (whole pool, not page-scoped).
     *
     * @return array<string, string> currency => amount
     */
    public function outstandingBalances(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT currency, COALESCE(SUM(balance), 0) AS total
             FROM op_plugin_voucherpay_vouchers
             WHERE status = 'active'
               AND balance > 0
               AND (expires_at IS NULL OR expires_at > NOW())
             GROUP BY currency
             ORDER BY currency"
        );
        $out = [];
        foreach ($rows as $row) {
            $cur = is_string($row['currency'] ?? null) ? $row['currency'] : '';
            if ($cur === '') {
                continue;
            }
            $out[$cur] = self::money($row['total'] ?? null);
        }
        return $out;
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filterClause(array $filters): array
    {
        $sql = '';
        $params = [];

        $q = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= ' AND (v.code LIKE :q1 OR v.currency LIKE :q2)';
            $params['q1'] = $like;
            $params['q2'] = $like;
        }

        $status = is_string($filters['status'] ?? null) ? trim($filters['status']) : '';
        $sql .= match ($status) {
            'active' => " AND v.status = 'active' AND v.balance > 0 AND (v.expires_at IS NULL OR v.expires_at > NOW())",
            'used' => " AND v.status = 'active' AND v.balance <= 0",
            'expired' => " AND v.status = 'active' AND v.expires_at IS NOT NULL AND v.expires_at <= NOW()",
            'void' => " AND v.status <> 'active'",
            default => '',
        };

        return [$sql, $params];
    }

    /**
     * @param array{q?: string, status?: string} $filters
     */
    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->filterClause($filters);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM op_plugin_voucherpay_vouchers v WHERE 1=1' . $where,
            $params
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{q?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filterClause($filters);
        $params['lim'] = max(1, min($limit, 200));
        $params['off'] = max(0, $offset);
        return $this->db->fetchAll(
            'SELECT v.*, COUNT(r.id) AS used_count
             FROM op_plugin_voucherpay_vouchers v
             LEFT JOIN op_plugin_voucherpay_redemptions r ON r.voucher_id = v.id
             WHERE 1=1' . $where . '
             GROUP BY v.id
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT :lim OFFSET :off',
            $params
        );
    }
}
