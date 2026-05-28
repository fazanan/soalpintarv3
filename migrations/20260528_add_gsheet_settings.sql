CREATE TABLE IF NOT EXISTS gsheet_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  mapel VARCHAR(128) NOT NULL,
  spreadsheet_url TEXT NOT NULL,
  spreadsheet_id VARCHAR(128) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  auto_sync TINYINT(1) NOT NULL DEFAULT 1,
  include_detail TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gsheet_user_mapel (user_id, mapel),
  INDEX idx_gsheet_user (user_id),
  CONSTRAINT fk_gsheet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
