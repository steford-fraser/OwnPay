<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\NpicttTreasury;

/**
 * Platform catalog derived from Trinidad & Tobago Estimates Chart of Accounts
 * (expenditure) and Heads of Expenditure in the Appropriation (FY 2025) Act.
 *
 * Sources:
 * - Appropriation (Financial Year 2025) Bill / Act — Heads of Expenditure
 * - Estimates of Recurrent Expenditure — Chart of Accounts (Sub-Heads / Sub-Items)
 * - Comptroller of Accounts Accounting Manual — Head / Sub-Head / Item / Sub-Item form
 *
 * Full vote combinations across every Item in every ministry are not published as a
 * single flat file; this seed covers every Head with the standard General Administration
 * (Item 001) Goods-and-Services and Personnel lines used for fee classification.
 *
 * @phpstan-type SeedRow array{
 *   head: string, sub_head: string, item: string, sub_item: string, code_full: string,
 *   head_name: string, sub_head_name: string, item_name: string, sub_item_name: string
 * }
 */
final class TreasuryCatalogSeed
{
    /**
     * Heads of Expenditure (FY 2025 Appropriation Schedule).
     *
     * @return array<string, string> head => official name
     */
    public static function heads(): array
    {
        return [
            '01' => 'President',
            '02' => 'Auditor General',
            '03' => 'Judiciary',
            '04' => 'Industrial Court',
            '05' => 'Parliament',
            '06' => 'Service Commissions',
            '07' => 'Statutory Authorities Service Commission',
            '08' => 'Elections and Boundaries Commission',
            '09' => 'Tax Appeal Board',
            '11' => 'Registration, Recognition and Certification Board',
            '12' => 'Public Service Appeal Board',
            '13' => 'Office of the Prime Minister',
            '15' => 'Tobago House of Assembly',
            '16' => 'Central Administrative Services, Tobago',
            '17' => 'Personnel Department',
            '18' => 'Ministry of Finance',
            '20' => 'Pensions and Gratuities',
            '22' => 'Ministry of National Security',
            '23' => 'Office of the Attorney General and Ministry of Legal Affairs',
            '26' => 'Ministry of Education',
            '28' => 'Ministry of Health',
            '30' => 'Ministry of Labour',
            '31' => 'Ministry of Public Administration',
            '37' => 'Integrity Commission',
            '38' => 'Environmental Commission',
            '39' => 'Ministry of Public Utilities',
            '40' => 'Ministry of Energy and Energy Industries',
            '42' => 'Ministry of Rural Development and Local Government',
            '43' => 'Ministry of Works and Transport',
            '48' => 'Ministry of Trade and Industry',
            '61' => 'Ministry of Housing and Urban Development',
            '64' => 'Trinidad and Tobago Police Service',
            '65' => 'Ministry of Foreign and CARICOM Affairs',
            '67' => 'Ministry of Planning and Development',
            '75' => 'Equal Opportunity Tribunal',
            '77' => 'Ministry of Agriculture, Land and Fisheries',
            '78' => 'Ministry of Social Development and Family Services',
            '79' => 'Ministry of Sport and Community Development',
            '80' => 'Ministry of Tourism, Culture and the Arts',
            '81' => 'Ministry of Youth Development and National Service',
            '82' => 'Ministry of Digital Transformation',
        ];
    }

    /**
     * Standard Sub-Items under Sub-Head 02 — Goods and Services (Estimates CoA).
     * Numbers match the published Chart of Accounts classification.
     *
     * @return array<string, string> sub_item => name
     */
    public static function goodsAndServicesSubItems(): array
    {
        return [
            '01' => 'Travelling and Subsistence',
            '02' => 'Overseas Travel Facilities',
            '03' => 'Uniforms',
            '04' => 'Electricity',
            '05' => 'Telephones',
            '06' => 'Water and Sewerage Rates',
            '07' => 'House Rates',
            '08' => 'Rent / Lease - Office Accommodation and Storage',
            '09' => 'Rent / Lease - Vehicles and Equipment',
            '10' => 'Office Stationery and Supplies',
            '11' => 'Books and Periodicals',
            '12' => 'Materials and Supplies',
            '13' => 'Maintenance of Vehicles',
            '15' => 'Repairs and Maintenance - Equipment',
            '16' => 'Contract Employment',
            '17' => 'Training',
            '19' => 'Official Entertainment',
            '21' => 'Repairs and Maintenance - Buildings',
            '22' => 'Short-term Employment',
            '23' => 'Fees',
            '24' => 'Refunds and Rebates',
            '27' => 'Official Overseas Travel',
            '28' => 'Other Contracted Services',
            '37' => 'Janitorial Services',
            '43' => 'Security Services',
            '57' => 'Postage',
            '58' => 'Medical Expenses',
            '62' => 'Promotions, Publicity and Printing',
            '96' => 'Fuel and Lubricants',
        ];
    }

    /**
     * Build the full platform seed set: every Head × General Administration lines.
     *
     * @return list<SeedRow>
     */
    public static function catalogRows(): array
    {
        $rows = [];
        foreach (self::heads() as $headKey => $headName) {
            // PHP casts numeric-string array keys to int; force 2-digit Head codes.
            $head = sprintf('%02d', (int) $headKey);
            $rows[] = self::row(
                $head,
                '01',
                '001',
                '01',
                $headName,
                'Personnel Expenditure',
                'General Administration',
                'Salaries and Cost of Living Allowance'
            );
            foreach (self::goodsAndServicesSubItems() as $subItemKey => $subItemName) {
                $subItem = sprintf('%02d', (int) $subItemKey);
                $rows[] = self::row(
                    $head,
                    '02',
                    '001',
                    $subItem,
                    $headName,
                    'Goods and Services',
                    'General Administration',
                    $subItemName
                );
            }
        }
        return $rows;
    }

    /**
     * Map OwnPay merchant_id → expenditure Head for payment-link fee collection.
     * Fee receipts are classified to Sub-Item 23 (Fees) under Goods and Services.
     *
     * @return array<int, string>
     */
    public static function paymentLinkHeadByMerchant(): array
    {
        return [
            // NPICTT platform brand — Digital Transformation / Finance support codes available;
            // fee links for ministries use ministry heads below.
            1 => '82',
            // Ministry of Works and Infrastructure → Head 43 Works and Transport (Appropriation)
            3 => '43',
            // Ministry of Transport and Civil Aviation → Head 43 (Transport Division vote)
            4 => '43',
            // Ministry of Education → Head 26
            5 => '26',
        ];
    }

    /** Preferred vote for public fee / permit / exam payment links. */
    public static function feeCollectionCodeForHead(string $head): string
    {
        return sprintf('%s/02/001/23', $head);
    }

    /**
     * @return SeedRow
     */
    private static function row(
        string $head,
        string $subHead,
        string $item,
        string $subItem,
        string $headName,
        string $subHeadName,
        string $itemName,
        string $subItemName,
    ): array {
        return [
            'head' => $head,
            'sub_head' => $subHead,
            'item' => $item,
            'sub_item' => $subItem,
            'code_full' => "{$head}/{$subHead}/{$item}/{$subItem}",
            'head_name' => $headName,
            'sub_head_name' => $subHeadName,
            'item_name' => $itemName,
            'sub_item_name' => $subItemName,
        ];
    }
}
