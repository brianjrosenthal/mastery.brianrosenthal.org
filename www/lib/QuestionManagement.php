<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/VideoStorage.php';

/**
 * Questions visitors ask under a concept, and the owner's answers. One row per
 * question; the answer (Markdown text and/or a video in object storage) lives
 * on the same row, so a question has at most one answer. A question counts as
 * answered (answered_at set) as soon as it has answer text or a video, and
 * reverts to unanswered if both are removed.
 *
 * Visibility: answered questions are public (subject to the site's
 * questions_public setting, applied by the page); unanswered ones are shown
 * only to the asker, the concept's owner and admins.
 *
 * All SQL for concept_questions lives here. Deliberately does not require
 * ConceptManagement (which requires this class to clean up answer videos when
 * a concept is deleted); the owner lookup is its own join.
 */
final class QuestionManagement {

    public const MAX_QUESTION_CHARS = 2000;
    public const MAX_ANSWER_CHARS = 20000;

    private static function pdo(): PDO {
        return pdo();
    }

    private static function log(string $action, array $meta): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, $meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    // ---- lookups ----------------------------------------------------------

    /** The question plus asker_first_name (NULL when the asker was deleted). */
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare(
            'SELECT q.*, u.first_name AS asker_first_name, u.email AS asker_email
             FROM concept_questions q
             LEFT JOIN users u ON u.id = q.asked_by_user_id
             WHERE q.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** The user who owns the concept the question was asked under. */
    public static function conceptOwnerUserIdOf(int $questionId): ?int {
        $st = self::pdo()->prepare(
            'SELECT c.user_id FROM concept_questions q
             JOIN concepts k ON k.id = q.concept_id
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             WHERE q.id = ?'
        );
        $st->execute([$questionId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    private static function ownerUserIdOfConcept(int $conceptId): ?int {
        $st = self::pdo()->prepare(
            'SELECT c.user_id FROM concepts k
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             WHERE k.id = ?'
        );
        $st->execute([$conceptId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    /**
     * The questions a viewer may see under a concept, oldest first: every
     * answered one, plus (for the owner/admin) all unanswered ones, plus (for
     * anyone signed in) their own unanswered ones. Each row carries
     * asker_first_name.
     */
    public static function listForConcept(int $conceptId, ?UserContext $viewer, bool $canEdit): array {
        $st = self::pdo()->prepare(
            'SELECT q.*, u.first_name AS asker_first_name
             FROM concept_questions q
             LEFT JOIN users u ON u.id = q.asked_by_user_id
             WHERE q.concept_id = ?
               AND (q.answered_at IS NOT NULL OR ? = 1 OR q.asked_by_user_id = ?)
             ORDER BY q.created_at, q.id'
        );
        $st->execute([$conceptId, $canEdit ? 1 : 0, $viewer->id ?? 0]);
        return $st->fetchAll();
    }

    /**
     * Unanswered questions across everything a user owns, oldest first, with
     * the concept's title and the slugs needed to link to it.
     */
    public static function listUnansweredForOwner(int $ownerUserId): array {
        $st = self::pdo()->prepare(
            'SELECT q.id, q.concept_id, q.question_text, q.created_at, u.first_name AS asker_first_name,
                    k.title AS concept_title, k.slug AS concept_slug,
                    s.slug AS subcategory_slug, c.slug AS category_slug
             FROM concept_questions q
             JOIN concepts k ON k.id = q.concept_id
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             LEFT JOIN users u ON u.id = q.asked_by_user_id
             WHERE c.user_id = ? AND q.answered_at IS NULL
             ORDER BY q.created_at, q.id'
        );
        $st->execute([$ownerUserId]);
        return $st->fetchAll();
    }

    /**
     * Every answer video object key, for the storage diagnostics page — all
     * of them, or only those held by one provider.
     * @return string[]
     */
    public static function listVideoObjectKeys(?string $provider = null): array {
        $rows = self::pdo()->query('SELECT video_object_key, video_storage FROM concept_questions WHERE video_object_key IS NOT NULL')->fetchAll();
        $keys = [];
        foreach ($rows as $r) {
            if ($provider === null || VideoStorage::providerOf($r) === $provider) {
                $keys[] = (string)$r['video_object_key'];
            }
        }
        return $keys;
    }

    /** The owner/admin may delete any question; the asker only their own unanswered one. */
    public static function canDelete(?UserContext $ctx, array $question, int $ownerUserId): bool {
        if (ContentAccess::canEdit($ctx, $ownerUserId)) {
            return true;
        }
        return $ctx !== null
            && $question['asked_by_user_id'] !== null
            && (int)$question['asked_by_user_id'] === $ctx->id
            && $question['answered_at'] === null;
    }

    // ---- writes -----------------------------------------------------------

    /** Post a question under a concept. Any signed-in user may ask. */
    public static function ask(?UserContext $ctx, int $conceptId, string $text): int {
        ContentAccess::assertCanAsk($ctx);
        if (self::ownerUserIdOfConcept($conceptId) === null) {
            throw new RuntimeException('Concept not found.');
        }
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Please write your question.');
        }
        if (mb_strlen($text) > self::MAX_QUESTION_CHARS) {
            throw new InvalidArgumentException('Questions must be ' . self::MAX_QUESTION_CHARS . ' characters or fewer.');
        }
        $st = self::pdo()->prepare('INSERT INTO concept_questions (concept_id, asked_by_user_id, question_text) VALUES (?, ?, ?)');
        $st->execute([$conceptId, $ctx->id, $text]);
        $id = (int)self::pdo()->lastInsertId();
        self::log('question.ask', ['question_id' => $id, 'concept_id' => $conceptId]);
        return $id;
    }

    /**
     * Save the answer text (Markdown). Empty text is allowed only when the
     * answer has a video. Returns true when this made the question answered
     * for the first time (the caller notifies the asker).
     */
    public static function answer(?UserContext $ctx, int $id, string $markdown): bool {
        $q = self::findById($id);
        if (!$q) {
            throw new RuntimeException('Question not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::conceptOwnerUserIdOf($id));
        $markdown = trim($markdown);
        if ($markdown === '' && empty($q['video_object_key'])) {
            throw new InvalidArgumentException('Write an answer or add a video.');
        }
        if (mb_strlen($markdown) > self::MAX_ANSWER_CHARS) {
            throw new InvalidArgumentException('Answers must be ' . self::MAX_ANSWER_CHARS . ' characters or fewer.');
        }
        $st = self::pdo()->prepare('UPDATE concept_questions SET answer_markdown = ?, answered_by_user_id = ? WHERE id = ?');
        $st->execute([$markdown === '' ? null : $markdown, $ctx->id, $id]);
        $newly = self::refreshAnsweredState($id);
        self::log('question.answer', ['question_id' => $id, 'concept_id' => (int)$q['concept_id'], 'newly_answered' => $newly]);
        return $newly;
    }

    /**
     * Record an answer video the browser has finished uploading to the active
     * provider (already verified with VideoStorage::verifyUploadedObject()).
     * A previous video is deleted from whichever provider held it, best
     * effort. Returns true when this made the question answered for the
     * first time.
     */
    public static function attachVideo(?UserContext $ctx, int $id, string $objectKey, string $contentType, int $sizeBytes): bool {
        $q = self::findById($id);
        if (!$q) {
            throw new RuntimeException('Question not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::conceptOwnerUserIdOf($id));
        if ($objectKey === '' || !VideoStorage::keyBelongsToAnswer($objectKey, $id)) {
            throw new InvalidArgumentException('That video does not belong to this question.');
        }

        $previous = (string)($q['video_object_key'] ?? '');
        $previousProvider = VideoStorage::providerOf($q);
        $provider = VideoStorage::activeProvider();

        $st = self::pdo()->prepare(
            'UPDATE concept_questions
             SET video_object_key = ?, video_storage = ?, video_content_type = ?, video_size_bytes = ?, video_uploaded_at = NOW(), answered_by_user_id = ?
             WHERE id = ?'
        );
        $st->execute([$objectKey, $provider, $contentType, $sizeBytes, $ctx->id, $id]);
        $newly = self::refreshAnsweredState($id);
        self::log('question.video_attach', ['question_id' => $id, 'object_key' => $objectKey, 'provider' => $provider, 'size_bytes' => $sizeBytes]);

        if ($previous !== '' && ($previous !== $objectKey || $previousProvider !== $provider)) {
            try {
                VideoStorage::deleteObject($previous, $previousProvider);
            } catch (\Throwable $e) {
                self::log('question.video_delete_failed', ['question_id' => $id, 'object_key' => $previous, 'provider' => $previousProvider, 'error' => $e->getMessage()]);
            }
        }
        return $newly;
    }

    /** Remove the answer video and delete it from storage. */
    public static function detachVideo(?UserContext $ctx, int $id): void {
        $q = self::findById($id);
        if (!$q) {
            throw new RuntimeException('Question not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::conceptOwnerUserIdOf($id));
        $key = (string)($q['video_object_key'] ?? '');
        if ($key === '') {
            return;
        }

        // Storage first, as ConceptManagement::detachVideo: a failed delete
        // leaves the row pointing at a real object so the owner can retry.
        $provider = VideoStorage::providerOf($q);
        VideoStorage::deleteObject($key, $provider);

        $st = self::pdo()->prepare(
            'UPDATE concept_questions
             SET video_object_key = NULL, video_storage = NULL, video_content_type = NULL, video_size_bytes = NULL, video_uploaded_at = NULL
             WHERE id = ?'
        );
        $st->execute([$id]);
        self::refreshAnsweredState($id);
        self::log('question.video_detach', ['question_id' => $id, 'object_key' => $key, 'provider' => $provider]);
    }

    /** Delete a question (and its answer video from storage). */
    public static function delete(?UserContext $ctx, int $id): void {
        $q = self::findById($id);
        if (!$q) {
            throw new RuntimeException('Question not found.');
        }
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if (!self::canDelete($ctx, $q, (int)self::conceptOwnerUserIdOf($id))) {
            throw new RuntimeException('You can only delete your own unanswered questions.');
        }

        $key = (string)($q['video_object_key'] ?? '');
        if ($key !== '') {
            VideoStorage::deleteObject($key, VideoStorage::providerOf($q));
        }
        $st = self::pdo()->prepare('DELETE FROM concept_questions WHERE id = ?');
        $st->execute([$id]);
        self::log('question.delete', ['question_id' => $id, 'concept_id' => (int)$q['concept_id'], 'object_key' => $key]);
    }

    /**
     * Delete every answer video under a concept from storage, for
     * ConceptManagement::delete(); the rows themselves cascade with the
     * concept. Strict: a storage failure aborts so nothing is leaked.
     */
    public static function deleteVideoObjectsForConcept(int $conceptId): void {
        $st = self::pdo()->prepare('SELECT id, video_object_key, video_storage FROM concept_questions WHERE concept_id = ? AND video_object_key IS NOT NULL');
        $st->execute([$conceptId]);
        foreach ($st->fetchAll() as $row) {
            VideoStorage::deleteObject((string)$row['video_object_key'], VideoStorage::providerOf($row));
        }
    }

    /**
     * Derive answered_at from what the answer contains: set it (once) while
     * there is text or a video, clear it when there is neither. Returns true
     * when the question just became answered.
     */
    private static function refreshAnsweredState(int $id): bool {
        $st = self::pdo()->prepare('SELECT answered_at, answer_markdown, video_object_key FROM concept_questions WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        $hasAnswer = (string)($row['answer_markdown'] ?? '') !== '' || (string)($row['video_object_key'] ?? '') !== '';
        $wasAnswered = $row['answered_at'] !== null;
        if ($hasAnswer && !$wasAnswered) {
            self::pdo()->prepare('UPDATE concept_questions SET answered_at = NOW() WHERE id = ?')->execute([$id]);
            return true;
        }
        if (!$hasAnswer && $wasAnswered) {
            self::pdo()->prepare('UPDATE concept_questions SET answered_at = NULL, answered_by_user_id = NULL WHERE id = ?')->execute([$id]);
        }
        return false;
    }
}
