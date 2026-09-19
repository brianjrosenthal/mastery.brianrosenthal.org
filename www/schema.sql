-- Kids That Teach (kidsthatteach.org) application schema
-- Create the database, then load this file. This file always represents the
-- complete current schema; migrations in db_migrations/ exist only to upgrade
-- older production installations.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ===== Users =====
-- Email is the login identifier. An empty password_hash means the user cannot
-- sign in yet (admin-created accounts gain a password via the emailed
-- activation link).
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(255) DEFAULT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'App administrator',
  email_verify_token VARCHAR(64) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  password_reset_token_hash CHAR(64) DEFAULT NULL,
  password_reset_expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_users_email_verify_token ON users(email_verify_token);
CREATE INDEX idx_users_pwreset_expires ON users(password_reset_expires_at);

-- ===== Settings key-value table =====
CREATE TABLE settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_name VARCHAR(191) NOT NULL UNIQUE,
  value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (key_name, value) VALUES
  ('site_title', 'Kids That Teach'),
  ('timezone', 'America/New_York'),
  ('site_base_url', 'https://kidsthatteach.org')
ON DUPLICATE KEY UPDATE value=VALUES(value);

-- ===== Activity Log =====
-- Every write action and login is recorded here (see docs/php-guidelines.md).
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action_type VARCHAR(64) NOT NULL,
  json_metadata LONGTEXT NULL,
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_al_created_at ON activity_log(created_at);
CREATE INDEX idx_al_user_id ON activity_log(user_id);
CREATE INDEX idx_al_action_type ON activity_log(action_type);

-- ===== Email Log =====
CREATE TABLE emails_sent (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) DEFAULT NULL,
  cc_email VARCHAR(255) DEFAULT NULL,
  subject VARCHAR(500) NOT NULL,
  body_html LONGTEXT NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT DEFAULT NULL,
  CONSTRAINT fk_emails_sent_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE INDEX idx_emails_sent_created_at ON emails_sent(created_at);
CREATE INDEX idx_emails_sent_user_id ON emails_sent(sent_by_user_id);
CREATE INDEX idx_emails_sent_to_email ON emails_sent(to_email);
CREATE INDEX idx_emails_sent_success ON emails_sent(success);

-- ===== Schema migrations =====
-- Which db_migrations/*.sql files have been applied (by Admin -> Migrations or
-- db_migrations/migrate.sh). A fresh install loads this file instead, so the
-- migrations that this file already contains are recorded as applied below.
CREATE TABLE schema_migrations (
  filename VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO schema_migrations (filename) VALUES
  ('001_initial_schema.sql'),
  ('002_concept_video_storage.sql'),
  ('003_rebrand_kidsthatteach.sql');

-- ===== Sites =====
-- One public site per user: its branding and homepage, the slug that serves
-- it at {slug}.kidsthatteach.org and at /site/{slug}/ on the main host, and
-- the optional custom domain (e.g. mastery.charlierosenthal.org) that serves
-- it at /. Admins set slug and domain; the owner edits everything else.
CREATE TABLE sites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  slug VARCHAR(50) NOT NULL UNIQUE,
  domain VARCHAR(255) DEFAULT NULL UNIQUE COMMENT 'Lowercase hostname, no scheme or port; NULL = path-only',
  title VARCHAR(150) NOT NULL,
  tagline VARCHAR(255) NOT NULL DEFAULT '',
  homepage_markdown LONGTEXT NOT NULL,
  accent_color VARCHAR(20) NOT NULL DEFAULT 'blue' COMMENT 'Palette key, see SiteUI::ACCENTS',
  is_public TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = only the owner/admin can view the site',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ===== Categories =====
-- Top level of a user's content tree, e.g. "Algebra II". Slugs are unique per
-- user and form the first URL segment. ON DELETE RESTRICT on users: a user
-- with content cannot be deleted until their categories are removed.
CREATE TABLE categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description_markdown LONGTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_user_slug (user_id, slug),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_categories_user_sort ON categories(user_id, sort_order);

-- ===== Subcategories =====
-- e.g. "Sequences and Series" inside "Algebra II". A subcategory with concepts
-- cannot be deleted (RESTRICT from concepts), matching the "delete only when
-- empty" rule in the spec.
CREATE TABLE subcategories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  slug VARCHAR(80) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description_markdown LONGTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_subcategories_category_slug (category_id, slug),
  CONSTRAINT fk_subcategories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_subcategories_category_sort ON subcategories(category_id, sort_order);

-- ===== Concepts =====
-- The unit of mastery, e.g. "Derivation of e^x": a video, a Markdown
-- description and supporting links. Only published concepts appear on the
-- public site. The video itself lives in object storage (Cloudflare R2 for
-- new uploads; DreamHost DreamObjects for videos not yet migrated); the row
-- stores just the provider, the object key
-- (videos/{user_id}/{concept_id}/{random}.{ext}) and what the server verified
-- about it after the upload.
CREATE TABLE concepts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subcategory_id INT NOT NULL,
  slug VARCHAR(80) NOT NULL,
  title VARCHAR(150) NOT NULL,
  description_markdown LONGTEXT NOT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME DEFAULT NULL,
  video_object_key VARCHAR(255) DEFAULT NULL,
  video_storage VARCHAR(20) DEFAULT NULL COMMENT 'Provider holding video_object_key: r2 or dreamobjects; NULL when no video',
  video_content_type VARCHAR(100) DEFAULT NULL,
  video_size_bytes BIGINT UNSIGNED DEFAULT NULL,
  video_uploaded_at DATETIME DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_concepts_subcategory_slug (subcategory_id, slug),
  CONSTRAINT fk_concepts_subcategory FOREIGN KEY (subcategory_id) REFERENCES subcategories(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE INDEX idx_concepts_subcategory_sort ON concepts(subcategory_id, sort_order);
CREATE INDEX idx_concepts_published ON concepts(subcategory_id, is_published);

-- ===== Concept resources =====
-- Supporting links shown under a concept's video. Replaced as a set whenever
-- the concept is saved, so they cascade with the concept.
CREATE TABLE concept_resources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  concept_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  url VARCHAR(2000) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_concept_resources_concept FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_concept_resources_concept ON concept_resources(concept_id, sort_order);

-- Seed admin so a fresh install can be signed into immediately: email
-- "brian.rosenthal@gmail.com", password "mastery". Change the password after
-- first login. Regenerate the hash with:
--   php -r "echo password_hash('mastery', PASSWORD_DEFAULT);"
INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
VALUES ('Brian', 'Rosenthal', 'brian.rosenthal@gmail.com', '$2y$12$2vNhJHwkqpxVshbZbr1HWuEzPHJyja7tufn3jUk1q3H.GPslBTpSy', 1, NOW());
