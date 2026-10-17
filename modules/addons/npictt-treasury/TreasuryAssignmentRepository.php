<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

use OwnPay\Core\Database;

/**
 * @phpstan-type TreasuryParts array{head: string, sub_head: string, item: string, sub_item: string, code_full: string}
 */
final class TreasuryAssignmentRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param TreasuryParts $treasury
     */
    public function upsert(int $merchantId, string $entityType, int $entityId, array $treasury): void
    {
        $this->db->execute(
            'INSERT INTO op_plugin_npictt_treasury_assignments
                (merchant_id, entity_type, entity_id, head, sub_head, item, sub_item, code_full)
             VALUES (:mid, :et, :eid, :h, :sh, :i, :si, :cf)
             ON DUPLICATE KEY UPDATE
                merchant_id = VALUES(merchant_id),
                head = VALUES(head),
                sub_head = VALUES(sub_head),
                item = VALUES(item),
                sub_item = VALUES(sub_item),
                code_full = VALUES(code_full)',
            [
                'mid' => $merchantId,
                'et' => $entityType,
                'eid' => $entityId,
                'h' => $treasury['head'],
                'sh' => $treasury['sub_head'],
                'i' => $treasury['item'],
                'si' => $treasury['sub_item'],
                'cf' => $treasury['code_full'],
            ]
        );
    }

    /** @return TreasuryParts|null */
    public function find(string $entityType, int $entityId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT head, sub_head, item, sub_item, code_full
             FROM op_plugin_npictt_treasury_assignments
             WHERE entity_type = :et AND entity_id = :eid LIMIT 1',
            ['et' => $entityType, 'eid' => $entityId]
        );
        if ($row === null) {
            return null;
        }
        return [
            'head' => (string) $row['head'],
            'sub_head' => (string) $row['sub_head'],
            'item' => (string) $row['item'],
            'sub_item' => (string) $row['sub_item'],
            'code_full' => (string) $row['code_full'],
        ];
    }

    /**
     * Batch lookup assignments keyed by entity_id.
     *
     * @param list<int> $entityIds
     * @return array<int, TreasuryParts>
     */
    public function mapForEntities(string $entityType, array $entityIds): array
    {
        $ids = [];
        foreach ($entityIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = ['et' => $entityType];
        $i = 0;
        foreach ($ids as $id) {
            $key = 'id' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
            $i++;
        }

        $rows = $this->db->fetchAll(
            'SELECT entity_id, head, sub_head, item, sub_item, code_full
             FROM op_plugin_npictt_treasury_assignments
             WHERE entity_type = :et AND entity_id IN (' . implode(',', $placeholders) . ')',
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            $eid = (int) ($row['entity_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }
            $map[$eid] = [
                'head' => (string) $row['head'],
                'sub_head' => (string) $row['sub_head'],
                'item' => (string) $row['item'],
                'sub_item' => (string) $row['sub_item'],
                'code_full' => (string) $row['code_full'],
            ];
        }
        return $map;
    }

    /**
     * Transaction assignment entity_ids for a catalog code (plugin table only).
     *
     * @return list<int>
     */
    public function transactionIdsForCode(string $codeFull): array
    {
        $rows = $this->db->fetchAll(
            'SELECT entity_id FROM op_plugin_npictt_treasury_assignments
             WHERE entity_type = \'transaction\' AND code_full = :cf
             ORDER BY id DESC',
            ['cf' => $codeFull]
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['entity_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
