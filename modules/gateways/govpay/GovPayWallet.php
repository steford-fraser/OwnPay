<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\Govpay;

use OwnPay\Core\Database;
use OwnPay\Gateway\Demo\PrepaidBalanceChanged;
use OwnPay\Gateway\Demo\PrepaidCodeStore;

/**
 * Simulated GovPay digital wallets, one per verified payer contact and currency.
 *
 * A wallet is opened with the configured opening balance the first time the payer uses it.
 * debit() must run inside the caller's DB transaction so a failed payment rolls it back.
 */
final class GovPayWallet
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{type: string, value: string} $contact
     */
    public static function keyFor(array $contact): string
    {
        return hash('sha256', $contact['type'] . '|' . strtolower($contact['value']));
    }

    /**
     * Whether a wallet with $balance can pay $amount.
     *
     * @param numeric-string $balance
     * @param numeric-string $amount
     */
    public static function canPay(string $balance, string $amount): bool
    {
        return bccomp($balance, $amount, 2) >= 0 && bccomp($amount, '0', 2) > 0;
    }

    /**
     * Returns the payer's wallet, opening it with $openingBalance if it does not exist yet.
     *
     * @param numeric-string $openingBalance
     * @return array{id: int, holder: string, currency: string, balance: numeric-string}
     */
    public function open(string $key, string $holder, string $currency, string $openingBalance): array
    {
        $currency = strtoupper($currency);
        $this->db->execute(
            'INSERT IGNORE INTO op_plugin_govpay_wallets (wallet_key, holder, currency, balance) VALUES (:k, :h, :c, :b)',
            ['k' => $key, 'h' => $holder, 'c' => $currency, 'b' => $openingBalance]
        );
        $row = $this->db->fetchOne(
            'SELECT id, holder, currency, balance FROM op_plugin_govpay_wallets WHERE wallet_key = :k AND currency = :c LIMIT 1',
            ['k' => $key, 'c' => $currency]
        );
        return [
            'id'       => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'holder'   => is_string($row['holder'] ?? null) ? $row['holder'] : $holder,
            'currency' => $currency,
            'balance'  => PrepaidCodeStore::money($row['balance'] ?? null),
        ];
    }

    /**
     * Deducts $amount under a row lock and records it. Must be called inside a DB transaction.
     *
     * @param numeric-string $amount
     * @throws PrepaidBalanceChanged When the balance no longer covers the payment.
     */
    public function debit(int $walletId, string $trxId, int $merchantId, string $amount): void
    {
        $row = $this->db->fetchOne('SELECT balance FROM op_plugin_govpay_wallets WHERE id = :id FOR UPDATE', ['id' => $walletId]);
        if ($row === null || !self::canPay(PrepaidCodeStore::money($row['balance'] ?? null), $amount)) {
            throw new PrepaidBalanceChanged('Your GovPay Wallet balance is too low for this payment.');
        }
        $this->db->update('UPDATE op_plugin_govpay_wallets SET balance = balance - :a WHERE id = :id', ['a' => $amount, 'id' => $walletId]);
        $this->db->insert(
            'INSERT INTO op_plugin_govpay_wallet_ledger (wallet_id, trx_id, merchant_id, amount) VALUES (:w, :t, :m, :a)',
            ['w' => $walletId, 't' => $trxId, 'm' => $merchantId, 'a' => $amount]
        );
    }

    /**
     * @return numeric-string
     */
    public function debitedTotal(string $trxId): string
    {
        return PrepaidCodeStore::money($this->db->fetchColumn(
            'SELECT COALESCE(SUM(amount), 0) FROM op_plugin_govpay_wallet_ledger WHERE trx_id = :t',
            ['t' => $trxId]
        ));
    }

    /**
     * Credits $amount back to the wallet(s) debited for the transaction.
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
                'SELECT id, wallet_id, amount, refunded_amount FROM op_plugin_govpay_wallet_ledger
                 WHERE trx_id = :t ORDER BY id DESC FOR UPDATE',
                ['t' => $trxId]
            );
            foreach ($rows as $r) {
                if (bccomp($left, '0', 2) <= 0) {
                    break;
                }
                $open = bcsub(PrepaidCodeStore::money($r['amount'] ?? null), PrepaidCodeStore::money($r['refunded_amount'] ?? null), 2);
                $credit = PrepaidCodeStore::allocate($open, $left);
                if (bccomp($credit, '0', 2) <= 0) {
                    continue;
                }
                $this->db->update(
                    'UPDATE op_plugin_govpay_wallet_ledger SET refunded_amount = refunded_amount + :a WHERE id = :id',
                    ['a' => $credit, 'id' => $r['id']]
                );
                $this->db->update(
                    'UPDATE op_plugin_govpay_wallets SET balance = balance + :a WHERE id = :id',
                    ['a' => $credit, 'id' => $r['wallet_id']]
                );
                $left = bcsub($left, $credit, 2);
            }
            return $left;
        });
    }
}
