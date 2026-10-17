<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Core\Database;

final class TreasuryCodeRepository
{
    public function __construct(private readonly Database $db) {}

    public static function buildLabel(
        string $headName,
        string $subHeadName,
        string $itemName,
        string $subItemName,
    ): string {
        return implode(' / ', [$headName, $subHeadName, $itemName, $subItemName]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(?int $merchantId = null, ?string $status = null): array
    {
        $sql = 'SELECT * FROM op_plugin_npictt_treasury_codes WHERE 1=1';
        $params = [];
        if ($merchantId !== null) {
            $sql .= ' AND (merchant_id IS NULL OR merchant_id = :mid)';
            $params['mid'] = $merchantId;
        }
        if ($status !== null) {
            $sql .= ' AND status = :st';
            $params['st'] = $status;
        }
        $sql .= ' ORDER BY head, sub_head, item, sub_item';
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array{q?: string, status?: string, head?: string} $filters
     * @return array{0: string, 1: array<string, mixed>} SQL WHERE clause (with leading AND…) and bind params
     */
    private function filterClause(array $filters): array
    {
        $sql = '';
        $params = [];

        $status = is_string($filters['status'] ?? null) ? trim($filters['status']) : '';
        if ($status === 'active' || $status === 'inactive') {
            $sql .= ' AND status = :st';
            $params['st'] = $status;
        }

        $head = is_string($filters['head'] ?? null) ? trim($filters['head']) : '';
        if ($head !== '' && preg_match('/^\d{1,2}$/', $head) === 1) {
            $sql .= ' AND head = :head';
            $params['head'] = sprintf('%02d', (int) $head);
        }

        $q = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        if ($q !== '') {
            // Distinct bind names: PDO/MySQL does not allow reusing one named parameter.
            $like = '%' . $q . '%';
            $sql .= ' AND (
                code_full LIKE :q1
                OR head LIKE :q2
                OR sub_head LIKE :q3
                OR item LIKE :q4
                OR sub_item LIKE :q5
                OR head_name LIKE :q6
                OR sub_head_name LIKE :q7
                OR item_name LIKE :q8
                OR sub_item_name LIKE :q9
                OR COALESCE(label, \'\') LIKE :q10
            )';
            foreach (range(1, 10) as $i) {
                $params['q' . $i] = $like;
            }
        }

        return [$sql, $params];
    }

    /**
     * @param array{q?: string, status?: string, head?: string} $filters
     */
    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->filterClause($filters);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM op_plugin_npictt_treasury_codes WHERE 1=1' . $where,
            $params
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{q?: string, status?: string, head?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filterClause($filters);
        $params['lim'] = max(1, min($limit, 200));
        $params['off'] = max(0, $offset);
        return $this->db->fetchAll(
            'SELECT * FROM op_plugin_npictt_treasury_codes WHERE 1=1' . $where
            . ' ORDER BY head, sub_head, item, sub_item
               LIMIT :lim OFFSET :off',
            $params
        );
    }

    /** @return list<array<string, mixed>> */
    public function listActiveOptions(?int $merchantId = null): array
    {
        return $this->listAll($merchantId, 'active');
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM op_plugin_npictt_treasury_codes WHERE id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByCodeFull(string $codeFull, ?int $merchantId = null): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM op_plugin_npictt_treasury_codes
             WHERE code_full = :code AND status = \'active\'
               AND (merchant_id IS NULL OR merchant_id = :mid)
             ORDER BY merchant_id IS NULL ASC
             LIMIT 1',
            ['code' => $codeFull, 'mid' => $merchantId ?? 0]
        );
    }

    public function isActive(string $codeFull, ?int $merchantId = null): bool
    {
        return $this->findByCodeFull($codeFull, $merchantId) !== null;
    }

    /**
     * @param array{
     *   head: string, sub_head: string, item: string, sub_item: string, code_full: string,
     *   head_name: string, sub_head_name: string, item_name: string, sub_item_name: string,
     *   label?: string|null, status?: string, merchant_id?: int|null
     * } $data
     */
    public function create(array $data): int
    {
        $label = $data['label'] ?? self::buildLabel(
            $data['head_name'],
            $data['sub_head_name'],
            $data['item_name'],
            $data['sub_item_name']
        );
        return (int) $this->db->insert(
            'INSERT INTO op_plugin_npictt_treasury_codes
                (merchant_id, head, sub_head, item, sub_item, code_full,
                 head_name, sub_head_name, item_name, sub_item_name, label, status)
             VALUES (:mid, :h, :sh, :i, :si, :cf,
                     :hn, :shn, :in, :sin, :label, :st)',
            [
                'mid' => $data['merchant_id'] ?? null,
                'h' => $data['head'],
                'sh' => $data['sub_head'],
                'i' => $data['item'],
                'si' => $data['sub_item'],
                'cf' => $data['code_full'],
                'hn' => $data['head_name'],
                'shn' => $data['sub_head_name'],
                'in' => $data['item_name'],
                'sin' => $data['sub_item_name'],
                'label' => $label,
                'st' => $data['status'] ?? 'active',
            ]
        );
    }

    /**
     * @param array{
     *   head: string, sub_head: string, item: string, sub_item: string, code_full: string,
     *   head_name: string, sub_head_name: string, item_name: string, sub_item_name: string,
     *   label?: string|null, status?: string, merchant_id?: int|null
     * } $data
     */
    public function update(int $id, array $data): void
    {
        $label = $data['label'] ?? self::buildLabel(
            $data['head_name'],
            $data['sub_head_name'],
            $data['item_name'],
            $data['sub_item_name']
        );
        $this->db->execute(
            'UPDATE op_plugin_npictt_treasury_codes SET
                merchant_id = :mid, head = :h, sub_head = :sh, item = :i, sub_item = :si,
                code_full = :cf,
                head_name = :hn, sub_head_name = :shn, item_name = :in, sub_item_name = :sin,
                label = :label, status = :st
             WHERE id = :id',
            [
                'id' => $id,
                'mid' => $data['merchant_id'] ?? null,
                'h' => $data['head'],
                'sh' => $data['sub_head'],
                'i' => $data['item'],
                'si' => $data['sub_item'],
                'cf' => $data['code_full'],
                'hn' => $data['head_name'],
                'shn' => $data['sub_head_name'],
                'in' => $data['item_name'],
                'sin' => $data['sub_item_name'],
                'label' => $label,
                'st' => $data['status'] ?? 'active',
            ]
        );
    }

    /**
     * Upsert a platform-wide catalog row by code_full (merchant_id IS NULL).
     * Uses select+update because MySQL UNIQUE allows multiple NULL merchant_id values.
     *
     * @param array{
     *   head: string, sub_head: string, item: string, sub_item: string, code_full: string,
     *   head_name: string, sub_head_name: string, item_name: string, sub_item_name: string,
     *   status?: string
     * } $data
     */
    public function upsertPlatform(array $data): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM op_plugin_npictt_treasury_codes
             WHERE merchant_id IS NULL AND code_full = :cf LIMIT 1',
            ['cf' => $data['code_full']]
        );
        $payload = [
            'merchant_id' => null,
            'head' => $data['head'],
            'sub_head' => $data['sub_head'],
            'item' => $data['item'],
            'sub_item' => $data['sub_item'],
            'code_full' => $data['code_full'],
            'head_name' => $data['head_name'],
            'sub_head_name' => $data['sub_head_name'],
            'item_name' => $data['item_name'],
            'sub_item_name' => $data['sub_item_name'],
            'status' => $data['status'] ?? 'active',
        ];
        if ($existing !== null) {
            $this->update((int) $existing['id'], $payload);
            return;
        }
        $this->create($payload);
    }

    /** @param list<string> $codeFulls */
    public function deletePlatformByCodes(array $codeFulls): void
    {
        if ($codeFulls === []) {
            return;
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($codeFulls) as $i => $code) {
            $key = 'c' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $code;
        }
        $this->db->execute(
            'DELETE FROM op_plugin_npictt_treasury_codes
             WHERE merchant_id IS NULL AND code_full IN (' . implode(',', $placeholders) . ')',
            $params
        );
    }

}
