-- Run this once against your Hostinger MySQL database (hPanel -> Databases ->
-- phpMyAdmin -> Import, or paste into the SQL tab).

CREATE TABLE IF NOT EXISTS oipulse_latest (
    id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oipulse_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ts DATETIME NOT NULL,
    trading_day DATE NOT NULL,
    spot DECIMAL(10,2) NOT NULL,
    pcr DECIMAL(6,3) NOT NULL,
    KEY idx_trading_day (trading_day),
    KEY idx_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
