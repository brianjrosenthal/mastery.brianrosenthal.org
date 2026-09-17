-- 002: record which storage provider holds each concept's video, for the move
-- from DreamHost DreamObjects to Cloudflare R2. Every video that exists when
-- this runs was uploaded to DreamObjects, so existing rows are backfilled to
-- 'dreamobjects'; new uploads record the active provider (config
-- VIDEO_STORAGE_PROVIDER, normally 'r2'). Safe to run more than once.
SET @video_storage_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'concepts' AND COLUMN_NAME = 'video_storage'
);
SET @video_storage_ddl := IF(@video_storage_exists = 0,
  'ALTER TABLE concepts ADD COLUMN video_storage VARCHAR(20) DEFAULT NULL COMMENT ''Provider holding video_object_key: r2 or dreamobjects; NULL when no video'' AFTER video_object_key',
  'SELECT ''concepts.video_storage already exists''');
PREPARE video_storage_stmt FROM @video_storage_ddl;
EXECUTE video_storage_stmt;
DEALLOCATE PREPARE video_storage_stmt;

UPDATE concepts SET video_storage = 'dreamobjects'
WHERE video_object_key IS NOT NULL AND video_storage IS NULL;
