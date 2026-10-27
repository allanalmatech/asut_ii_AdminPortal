-- MySQL 8.0.16+ or MariaDB 10.3+ (including 10.3.39-MariaDB-cll-lve).
-- All tables use InnoDB and CHECK constraints are enforced on these versions.
-- {{prefix}} is replaced by the validated configured table prefix at setup.
CREATE TABLE IF NOT EXISTS {{prefix}}settings (
 setting_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}admins (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 username VARCHAR(64) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK(active IN(0,1)), created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}clients (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 username VARCHAR(64) NOT NULL UNIQUE, display_name VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL,
 hwid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 active TINYINT UNSIGNED NOT NULL CHECK(active IN(0,1)),
 units BIGINT UNSIGNED NOT NULL DEFAULT 0 CHECK(units<=1000000000000),
 expires_at BIGINT UNSIGNED NULL, created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 owner_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 role ENUM('admin','client') NOT NULL, hwid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 csrf CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 INDEX idx_session_owner(owner_id,role), INDEX idx_session_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}devices (
 id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 name VARCHAR(128) NOT NULL, family VARCHAR(200) NOT NULL, image VARCHAR(200) NOT NULL, notes TEXT NOT NULL,
 available TINYINT UNSIGNED NOT NULL CHECK(available IN(0,1)),
 unit_cost INT UNSIGNED NOT NULL DEFAULT 1 CHECK(unit_cost<=1000000),
 enabled TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK(enabled IN(0,1)),
 sort_order INT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
 CHECK(enabled=0 OR(available=1 AND unit_cost>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}operations (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 device_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 gateway VARCHAR(45) CHARACTER SET ascii NOT NULL, hwid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cost INT UNSIGNED NOT NULL CHECK(cost>0),
 state ENUM('authorized','executing','applied','failed','cancelled','expired','refunded') NOT NULL,
 start_nonce CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at BIGINT UNSIGNED NOT NULL, authorized_until BIGINT UNSIGNED NOT NULL,
 started_at BIGINT UNSIGNED NULL, finished_at BIGINT UNSIGNED NULL,
 outcome VARCHAR(16) NULL, message TEXT NOT NULL,
 UNIQUE KEY uq_operation_request(user_id,request_id),
 INDEX idx_operation_user(user_id,created_at), INDEX idx_operation_expiry(state,authorized_until),
 FOREIGN KEY(user_id) REFERENCES {{prefix}}clients(id), FOREIGN KEY(device_id) REFERENCES {{prefix}}devices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}vouchers (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 code_hint VARCHAR(32) NOT NULL, units BIGINT UNSIGNED NOT NULL CHECK(units>0 AND units<=1000000000),
 hwid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK(active IN(0,1)), expires_at BIGINT UNSIGNED NULL,
 used_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, used_at BIGINT UNSIGNED NULL,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 notes VARCHAR(500) NOT NULL DEFAULT '', FOREIGN KEY(used_by) REFERENCES {{prefix}}clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}ledger (
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 admin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 voucher_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 kind ENUM('TOPUP','ADJUSTMENT','RESERVE','REFUND','VOUCHER') NOT NULL,
 delta BIGINT NOT NULL CHECK(delta<>0), balance_after BIGINT UNSIGNED NOT NULL CHECK(balance_after<=1000000000000),
 reason VARCHAR(500) NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_operation_ledger(operation_id,kind), UNIQUE KEY uq_admin_adjustment(admin_id,request_id), UNIQUE KEY uq_voucher_credit(voucher_id),
 INDEX idx_ledger_user(user_id,seq), FOREIGN KEY(user_id) REFERENCES {{prefix}}clients(id),
 FOREIGN KEY(operation_id) REFERENCES {{prefix}}operations(id),
 FOREIGN KEY(voucher_id) REFERENCES {{prefix}}vouchers(id),
 CHECK((kind='RESERVE' AND delta<0) OR(kind IN('TOPUP','REFUND','VOUCHER') AND delta>0) OR kind='ADJUSTMENT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}audit (
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 actor VARCHAR(128) NOT NULL, action VARCHAR(64) NOT NULL, target VARCHAR(100) NOT NULL,
 message TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}auth_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 window_start BIGINT UNSIGNED NOT NULL, attempts INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}notifications (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 recipient_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 title VARCHAR(160) NOT NULL, message TEXT NOT NULL,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NULL,
 active TINYINT UNSIGNED NOT NULL DEFAULT 1 CHECK(active IN(0,1)),
 INDEX idx_notification_recipient(recipient_id,active,created_at),
 FOREIGN KEY(recipient_id) REFERENCES {{prefix}}clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS {{prefix}}notification_reads (
 notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 read_at BIGINT UNSIGNED NOT NULL, PRIMARY KEY(notification_id,user_id),
 FOREIGN KEY(notification_id) REFERENCES {{prefix}}notifications(id),
 FOREIGN KEY(user_id) REFERENCES {{prefix}}clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
