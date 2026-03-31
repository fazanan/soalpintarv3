-- Gurupintar/SoalPintar - Database bootstrap
-- Jalankan file ini di MySQL/MariaDB untuk mengaktifkan aplikasi secara lokal
-- Termasuk pembuatan tabel inti dan 1 user admin awal

CREATE DATABASE IF NOT EXISTS soalpintar
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE soalpintar;

-- =========================
-- Tabel pengguna
-- =========================
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'user',
  access_quiz TINYINT(1) NOT NULL DEFAULT 1,
  access_rekap_nilai TINYINT(1) NOT NULL DEFAULT 1,
  limitpaket INT NOT NULL DEFAULT 300,
  limitgambar INT NOT NULL DEFAULT 5,
  token_input BIGINT UNSIGNED NOT NULL DEFAULT 0,
  token_output BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Tabel audit log (opsional, dipakai untuk proxy/API)
-- =========================
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  level ENUM('error','warn','info') NOT NULL DEFAULT 'error',
  category VARCHAR(64) NOT NULL,
  message TEXT NOT NULL,
  http_status INT NULL,
  endpoint VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  context LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_level_created (level, created_at),
  INDEX idx_category_created (category, created_at),
  INDEX idx_user_created (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Tabel model/keys (opsional, untuk OpenAI proxy)
-- =========================
CREATE TABLE IF NOT EXISTS api_models (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(64) NOT NULL,
  modality ENUM('chat','image','audio','embedding','other') NOT NULL DEFAULT 'chat',
  model VARCHAR(128) NOT NULL,
  display_name VARCHAR(128) NULL,
  endpoint_url VARCHAR(512) NOT NULL,
  token_input_price DECIMAL(16,8) NOT NULL DEFAULT 0.0,
  token_output_price DECIMAL(16,8) NOT NULL DEFAULT 0.0,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  currency_rate_to_idr DECIMAL(16,8) NOT NULL DEFAULT 1.0,
  unit VARCHAR(32) NOT NULL DEFAULT 'per_1k_tokens',
  max_input_tokens INT UNSIGNED NULL,
  max_output_tokens INT UNSIGNED NULL,
  supports_json_mode TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider_model_modality (provider, model, modality),
  INDEX idx_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(64) NOT NULL,
  api_key TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_provider_active (provider, is_active),
  CONSTRAINT fk_api_keys_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Tabel simpan snapshot soal (opsional)
-- =========================
CREATE TABLE IF NOT EXISTS soal_user (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  jenjang VARCHAR(32) NULL,
  kelas VARCHAR(32) NULL,
  mata_pelajaran VARCHAR(128) NULL,
  question_count INT NOT NULL DEFAULT 0,
  token_input BIGINT UNSIGNED NOT NULL DEFAULT 0,
  token_output BIGINT UNSIGNED NOT NULL DEFAULT 0,
  model VARCHAR(128) NULL,
  token_input_price DECIMAL(16,8) NOT NULL DEFAULT 0.0,
  token_output_price DECIMAL(16,8) NOT NULL DEFAULT 0.0,
  currency VARCHAR(8) NOT NULL DEFAULT 'USD',
  currency_rate_to_idr DECIMAL(16,8) NOT NULL DEFAULT 1.0,
  snapshot LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_created (user_id, created_at),
  CONSTRAINT fk_soal_user_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Tabel publish quiz & hasil
-- =========================
CREATE TABLE IF NOT EXISTS published_quizzes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  slug VARCHAR(128) NOT NULL UNIQUE,
  mapel VARCHAR(128) NOT NULL,
  kelas VARCHAR(32) NULL,
  total_soal INT NOT NULL DEFAULT 0,
  payload_public LONGTEXT NOT NULL,
  answer_key LONGTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  expire_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_active_created (is_active, created_at),
  CONSTRAINT fk_published_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS published_quiz_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  published_id INT UNSIGNED NOT NULL,
  absen INT UNSIGNED NOT NULL,
  nama VARCHAR(120) NULL,
  score INT NOT NULL DEFAULT 0,
  total INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pub_absen (published_id, absen),
  INDEX idx_pub_created (published_id, created_at),
  CONSTRAINT fk_published_results FOREIGN KEY (published_id) REFERENCES published_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scalev_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unique_id VARCHAR(64) NOT NULL,
  event VARCHAR(128) NOT NULL,
  order_id VARCHAR(64) NULL,
  email VARCHAR(160) NULL,
  payment_status VARCHAR(32) NULL,
  created_user_id INT UNSIGNED NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_unique_id (unique_id),
  INDEX idx_event_received (event, received_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- =========================
-- Pengaturan limit & biaya kredit fitur
-- =========================
CREATE TABLE IF NOT EXISTS app_settings (
  k VARCHAR(64) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_costs (
  feature VARCHAR(64) PRIMARY KEY,
  cost INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (k, v) VALUES ('initial_limit', '300')
  ON DUPLICATE KEY UPDATE v=VALUES(v);

INSERT INTO feature_costs (feature, cost) VALUES
  ('publish_quiz', 3),
  ('modul_ajar',   3),
  ('buat_soal',    2),
  ('rekap_nilai',  0)
ON DUPLICATE KEY UPDATE cost=VALUES(cost);

-- =========================
-- Admin default
-- Username: admin
-- Password: password  (disarankan langsung ganti)
-- =========================
INSERT INTO users (username, password, role, limitpaket, limitgambar)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/5J7FBcgv6Y5Cy', 'admin', 300, 5)
ON DUPLICATE KEY UPDATE role='admin';
