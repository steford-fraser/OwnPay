<?php
$pdo = new PDO('mysql:host=lerd-mysql;dbname=ownpay', 'root', 'lerd', [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$one = fn(string $sql) => $pdo->query($sql)->fetch();
$state = json_decode((string) file_get_contents(__DIR__ . '/npictt-demo-state.json'), true);
$txIn = implode(',', array_map('intval', $state['transactions']));
$out = ['brands' => []];
foreach (['mowi', 'motca', 'moe'] as $slug) {
    $mid = (int) $one("SELECT id FROM op_merchants WHERE slug = '{$slug}'")['id'];
    $out['brands'][$slug] = [
        'id'         => $mid,
        'link'       => $one("SELECT id, slug FROM op_payment_links WHERE merchant_id = {$mid} AND amount IS NOT NULL ORDER BY id LIMIT 1"),
        'var_link'   => $one("SELECT id, slug FROM op_payment_links WHERE merchant_id = {$mid} AND amount IS NULL ORDER BY id DESC LIMIT 1"),
        'completed'  => $one("SELECT id, trx_id FROM op_transactions WHERE merchant_id = {$mid} AND id IN ({$txIn}) AND status = 'completed' AND method = 'link' ORDER BY created_at DESC LIMIT 1"),
        'failed'     => $one("SELECT id, trx_id FROM op_transactions WHERE merchant_id = {$mid} AND id IN ({$txIn}) AND status = 'failed' ORDER BY created_at DESC LIMIT 1"),
        'pending'    => $one("SELECT id, trx_id FROM op_transactions WHERE merchant_id = {$mid} AND id IN ({$txIn}) AND status = 'pending' ORDER BY created_at DESC LIMIT 1"),
        'invoice'    => $one("SELECT id, token FROM op_invoices WHERE merchant_id = {$mid} AND status = 'sent' ORDER BY id LIMIT 1"),
        'customer'   => $one("SELECT customer_id AS id, COUNT(*) c FROM op_transactions WHERE merchant_id = {$mid} AND id IN ({$txIn}) GROUP BY customer_id ORDER BY c DESC LIMIT 1"),
    ];
}
$out['staff_id'] = (int) $one("SELECT id FROM op_merchant_users WHERE email = 'finance@mowi.gov.tt'")['id'];
echo json_encode($out, JSON_PRETTY_PRINT);
