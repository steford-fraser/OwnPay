<?php
declare(strict_types=1);

namespace OwnPay\Gateway\Demo;

use OwnPay\Core\Database;

/**
 * A pool of prepaid codes (cash vouchers, SMS codes, QR codes) owned by one demo gateway plugin:
 * lookup, issuing, split-tender allocation, redemption and refunds.
 *
 * Each plugin passes its own table prefix and ships the tables in its schema.sql:
 * `{prefix}_codes` (id, code, channel, currency, initial_balance, balance, status, expires_at)
 * and `{prefix}_redemptions` (id, code_id, trx_id, merchant_id, amount, refunded_amount).
 *
 * Balances are only deducted in redeem(), which must run inside the caller's DB transaction
 * so a failed payment rolls the deduction back.
 *
 * @phpstan-type Applied array{code_id: int, code: string, channel: string, amount: numeric-string, balance: numeric-string}
 */
final class PrepaidCodeStore
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CHANNELS = ['voucher', 'sms', 'qr'];

    private readonly string $codes;
    private readonly string $redemptions;

    public function __construct(private readonly Database $db, string $tablePrefix)
    {
        if (!preg_match('/^op_plugin_[a-z0-9_]+$/', $tablePrefix)) {
            throw new \InvalidArgumentException('Prepaid code tables must use an op_plugin_ prefix.');
        }
        $this->codes = $tablePrefix . '_codes';
        $this->redemptions = $tablePrefix . '_redemptions';
    }

    /**
     * Runs a plugin's schema.sql. Runs outside a transaction because MySQL implicitly commits
     * DDL, which breaks the core plugin migrator's transaction wrapper.
     */
    public static function ensureSchema(Database $db, string $schemaFile): void
    {
        $sql = (string) file_get_contents($schemaFile);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $db->execute($statement);
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
     * Amount a code contributes towards what is still due: min(balance, due).
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
     * Recomputes allocations in order after a code is removed, so earlier codes absorb as much
     * of the total as their balance allows.
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
            $a['amount'] = self::allocate($a['balance'], $due);
            $due = bcsub($due, $a['amount'], 2);
            $out[] = $a;
        }
        return $out;
    }

    /**
     * Returns a payer-facing reason the code cannot be used, or null when it can.
     *
     * @param array<string, mixed>|null $code
     */
    public static function rejectReason(?array $code, string $currency, ?int $now = null): ?string
    {
        if ($code === null) {
            return 'That code is not valid.';
        }
        if (($code['status'] ?? '') !== 'active') {
            return 'This code has been cancelled.';
        }
        $expires = is_string($code['expires_at'] ?? null) ? strtotime($code['expires_at']) : false;
        if ($expires !== false && $expires < ($now ?? time())) {
            return 'This code has expired.';
        }
        $cCurrency = is_string($code['currency'] ?? null) ? $code['currency'] : '';
        if (strcasecmp($cCurrency, $currency) !== 0) {
            return "This code is in {$cCurrency}; this payment is in {$currency}.";
        }
        if (bccomp(self::money($code['balance'] ?? null), '0', 2) <= 0) {
            return 'This code has no balance left.';
        }
        return null;
    }

    public static function generateCode(string $prefix = ''): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $groups = $prefix !== '' ? [strtoupper($prefix)] : [];
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
            "SELECT * FROM {$this->codes} WHERE code = :c LIMIT 1",
            ['c' => self::normalize($code)]
        );
    }

    /**
     * Creates a code, or resets an existing one with the same code to the given state.
     *
     * @param numeric-string $amount
     */
    public function upsert(string $code, string $currency, string $amount, ?string $expiresAt = null, string $channel = 'voucher'): void
    {
        $this->db->execute(
            "INSERT INTO {$this->codes} (code, channel, currency, initial_balance, balance, status, expires_at)
             VALUES (:c, :ch, :cur, :a, :b, 'active', :e)
             ON DUPLICATE KEY UPDATE channel = VALUES(channel), currency = VALUES(currency),
                 initial_balance = VALUES(initial_balance), balance = VALUES(balance),
                 status = 'active', expires_at = VALUES(expires_at)",
            [
                'c' => self::normalize($code), 'ch' => self::channel($channel), 'cur' => strtoupper($currency),
                'a' => $amount, 'b' => $amount, 'e' => $expiresAt,
            ]
        );
    }

    /**
     * Issues $count random codes and returns them.
     *
     * @param numeric-string $amount
     * @return array<int, string>
     */
    public function issue(int $count, string $currency, string $amount, string $channel = 'voucher', string $prefix = '', ?string $expiresAt = null): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = self::generateCode($prefix);
            if ($this->find($code) !== null) {
                continue;
            }
            $this->upsert($code, $currency, $amount, $expiresAt, $channel);
            $codes[] = $code;
        }
        return $codes;
    }

    /**
     * Deducts each applied allocation under a row lock and records the redemptions.
     * Must be called inside a DB transaction.
     *
     * @param array<int, array{code_id: int, amount: numeric-string}> $applied
     * @throws PrepaidBalanceChanged When a code can no longer cover its allocation.
     */
    public function redeem(string $trxId, int $merchantId, string $currency, array $applied): void
    {
        foreach ($applied as $a) {
            $code = $this->db->fetchOne(
                "SELECT * FROM {$this->codes} WHERE id = :id FOR UPDATE",
                ['id' => $a['code_id']]
            );
            $balance = self::money($code['balance'] ?? null);
            if (self::rejectReason($code, $currency) !== null || bccomp($balance, $a['amount'], 2) < 0) {
                throw new PrepaidBalanceChanged('A code balance changed, please re-apply your codes.');
            }
            $this->db->update(
                "UPDATE {$this->codes} SET balance = balance - :a WHERE id = :id",
                ['a' => $a['amount'], 'id' => $a['code_id']]
            );
            $this->db->insert(
                "INSERT INTO {$this->redemptions} (code_id, trx_id, merchant_id, amount) VALUES (:c, :t, :m, :a)",
                ['c' => $a['code_id'], 't' => $trxId, 'm' => $merchantId, 'a' => $a['amount']]
            );
        }
    }

    /**
     * @return numeric-string
     */
    public function redeemedTotal(string $trxId): string
    {
        return self::money($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM {$this->redemptions} WHERE trx_id = :t",
            ['t' => $trxId]
        ));
    }

    /**
     * Credits $amount back to the transaction's codes, newest redemption first.
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
                "SELECT id, code_id, amount, refunded_amount FROM {$this->redemptions}
                 WHERE trx_id = :t ORDER BY id DESC FOR UPDATE",
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
                    "UPDATE {$this->redemptions} SET refunded_amount = refunded_amount + :a WHERE id = :id",
                    ['a' => $credit, 'id' => $r['id']]
                );
                $this->db->update(
                    "UPDATE {$this->codes} SET balance = balance + :a WHERE id = :id",
                    ['a' => $credit, 'id' => $r['code_id']]
                );
                $left = bcsub($left, $credit, 2);
            }
            return $left;
        });
    }

    private static function channel(string $channel): string
    {
        return in_array($channel, self::CHANNELS, true) ? $channel : 'voucher';
    }
}
