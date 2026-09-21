-- 004: questions and answers on concept pages. Signed-in visitors ask
-- questions under a concept; the owner answers with Markdown text and/or a
-- video (same video_* columns as concepts, key namespace
-- videos/{user}/answers/{question}/...). sites.questions_public lets a site
-- hide the Q&A section from visitors who are not signed in (default: shown).
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS concept_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  concept_id INT NOT NULL,
  asked_by_user_id INT DEFAULT NULL,
  question_text TEXT NOT NULL,
  answer_markdown LONGTEXT DEFAULT NULL,
  answered_at DATETIME DEFAULT NULL,
  answered_by_user_id INT DEFAULT NULL,
  video_object_key VARCHAR(255) DEFAULT NULL,
  video_storage VARCHAR(20) DEFAULT NULL COMMENT 'Provider holding video_object_key: r2 or dreamobjects; NULL when no video',
  video_content_type VARCHAR(100) DEFAULT NULL,
  video_size_bytes BIGINT UNSIGNED DEFAULT NULL,
  video_uploaded_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_concept_questions_concept_created (concept_id, created_at),
  KEY idx_concept_questions_answered (answered_at),
  CONSTRAINT fk_concept_questions_concept FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE CASCADE,
  CONSTRAINT fk_concept_questions_asker FOREIGN KEY (asked_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_concept_questions_answerer FOREIGN KEY (answered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

SET @questions_public_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = 'questions_public'
);
SET @questions_public_ddl := IF(@questions_public_exists = 0,
  'ALTER TABLE sites ADD COLUMN questions_public TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = visitors who are not signed in do not see concept questions'' AFTER is_public',
  'SELECT ''sites.questions_public already exists''');
PREPARE questions_public_stmt FROM @questions_public_ddl;
EXECUTE questions_public_stmt;
DEALLOCATE PREPARE questions_public_stmt;
