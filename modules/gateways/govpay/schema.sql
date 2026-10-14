CREATE TABLE IF NOT EXISTS `op_plugin_govpay_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `channel` ENUM('voucher','sms','qr') NOT NULL DEFAULT 'voucher',
  `currency` CHAR(3) NOT NULL,
  `initial_balance` DECIMAL(15,2) NOT NULL,
  `balance` DECIMAL(15,2) NOT NULL,
  `status` ENUM('active','void') NOT NULL DEFAULT 'active',
  `expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_plugin_govpay_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` BIGINT UNSIGNED NOT NULL,
  `trx_id` VARCHAR(64) NOT NULL,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `refunded_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_trx` (`trx_id`),
  KEY `idx_code` (`code_id`),
  CONSTRAINT `fk_gp_redemption_code` FOREIGN KEY (`code_id`) REFERENCES `op_plugin_govpay_codes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_plugin_govpay_wallets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_key` CHAR(64) NOT NULL,
  `holder` VARCHAR(190) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `balance` DECIMAL(15,2) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wallet_currency` (`wallet_key`, `currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_plugin_govpay_wallet_ledger` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wallet_id` BIGINT UNSIGNED NOT NULL,
  `trx_id` VARCHAR(64) NOT NULL,
  `merchant_id` BIGINT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `refunded_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_trx` (`trx_id`),
  KEY `idx_wallet` (`wallet_id`),
  CONSTRAINT `fk_gp_ledger_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `op_plugin_govpay_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
