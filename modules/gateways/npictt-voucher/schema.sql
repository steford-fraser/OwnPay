CREATE TABLE IF NOT EXISTS `op_plugin_voucherpay_vouchers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `initial_balance` DECIMAL(15,2) NOT NULL,
  `balance` DECIMAL(15,2) NOT NULL,
  `status` ENUM('active','void') NOT NULL DEFAULT 'active',
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_plugin_voucherpay_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` BIGINT UNSIGNED NOT NULL,
  `trx_id` VARCHAR(64) NOT NULL,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `refunded_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_trx` (`trx_id`),
  KEY `idx_voucher` (`voucher_id`),
  CONSTRAINT `fk_vp_redemption_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `op_plugin_voucherpay_vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
