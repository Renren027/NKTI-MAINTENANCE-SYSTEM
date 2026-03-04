-- ============================================================
-- NKTI BIOMED MedTracker — Database Schema
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

CREATE DATABASE IF NOT EXISTS nkti_biomed
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nkti_biomed;

-- ─── USERS ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username         VARCHAR(80)  NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('admin','engineer','viewer') NOT NULL DEFAULT 'viewer',
  assigned_building VARCHAR(120) DEFAULT NULL,
  assigned_section  VARCHAR(80)  DEFAULT NULL,
  is_active        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default admin account  (password: admin123)
INSERT INTO users (username, password_hash, role)
VALUES ('admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

-- ─── EQUIPMENT ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS equipment (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(200) NOT NULL,
  brand            VARCHAR(120) DEFAULT '',
  model            VARCHAR(120) DEFAULT '',
  serial_no        VARCHAR(100) DEFAULT '',
  date_acquired    DATE         DEFAULT NULL,
  section          VARCHAR(80)  DEFAULT '',
  status           ENUM('Active','Maintenance','Inactive','Condemned') NOT NULL DEFAULT 'Active',
  supplier         VARCHAR(150) DEFAULT '',
  area             VARCHAR(100) DEFAULT '',
  category         ENUM(
    'NKTI-INHOUSE',
    'OUTSOURCE-DIRECT CONTRACTING',
    'OUTSOURCE-UNDERWARRANTY',
    'OUTSOURCE-TIEUP'
  ) NOT NULL DEFAULT 'NKTI-INHOUSE',
  building         ENUM(
    'MAIN BUILDING',
    'ANNEX I BUILDING',
    'ANNEX II BUILDING',
    'DIAGNOSTIC BUILDING'
  ) NOT NULL DEFAULT 'MAIN BUILDING',
  wattage          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  hours_per_day    DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  created_by       INT UNSIGNED  DEFAULT NULL,
  updated_by       INT UNSIGNED  DEFAULT NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_building (building),
  INDEX idx_section  (section),
  INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── AUDIT LOG ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED DEFAULT NULL,
  username     VARCHAR(80)  DEFAULT NULL,
  action       VARCHAR(20)  NOT NULL,   -- CREATE, UPDATE, DELETE, LOGIN, LOGOUT
  target_table VARCHAR(50)  DEFAULT NULL,
  target_id    INT UNSIGNED DEFAULT NULL,
  detail       TEXT         DEFAULT NULL,
  ip_address   VARCHAR(45)  DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user   (user_id),
  INDEX idx_action (action),
  INDEX idx_date   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── SESSIONS (server-side, optional extra security) ─────────
CREATE TABLE IF NOT EXISTS user_sessions (
  session_token VARCHAR(64)  PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  ip_address    VARCHAR(45)  DEFAULT NULL,
  user_agent    VARCHAR(255) DEFAULT NULL,
  expires_at    DATETIME     NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
