<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/QuestionManagement.php';
require_once __DIR__ . '/ConceptManagement.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * The emails around concept questions: the owner hears about a new question,
 * the asker hears when it is first answered. Called by the eval pages after
 * the write has succeeded, never from QuestionManagement, so the management
 * class stays network-free. A failure to send is logged (EmailLog records
 * every attempt; a thrown error lands in the activity log) and never
 * disturbs the page flow.
 */
final class QuestionNotifications {

    /** Tell the concept's owner a question was asked (unless they asked it). */
    public static function questionAsked(int $questionId): void {
        try {
            $c = self::context($questionId);
            if ($c === null) {
                return;
            }
            $owner = $c['owner'];
            $ownerEmail = (string)($owner['email'] ?? '');
            if ($ownerEmail === '' || (int)$owner['id'] === (int)($c['question']['asked_by_user_id'] ?? 0)) {
                return;
            }
            send_question_asked_email(
                $ownerEmail,
                (string)$owner['first_name'],
                self::askerName($c['question']),
                (string)$c['concept']['title'],
                (string)$c['question']['question_text'],
                $c['url']
            );
        } catch (\Throwable $e) {
            self::logFailure('question.notify_failed', $questionId, $e);
        }
    }

    /** Tell the asker their question has an answer (unless they answered it themselves). */
    public static function questionAnswered(int $questionId): void {
        try {
            $c = self::context($questionId);
            if ($c === null || empty($c['question']['asked_by_user_id'])) {
                return;
            }
            $asker = UserManagement::findById((int)$c['question']['asked_by_user_id']);
            $askerEmail = (string)($asker['email'] ?? '');
            if (!$asker || $askerEmail === '' || (int)$asker['id'] === (int)($c['question']['answered_by_user_id'] ?? 0)) {
                return;
            }
            $answerer = !empty($c['question']['answered_by_user_id']) ? UserManagement::findById((int)$c['question']['answered_by_user_id']) : null;
            send_question_answered_email(
                $askerEmail,
                (string)$asker['first_name'],
                (string)(($answerer ?? $c['owner'])['first_name'] ?? 'The teacher'),
                (string)$c['concept']['title'],
                $c['url']
            );
        } catch (\Throwable $e) {
            self::logFailure('question.notify_failed', $questionId, $e);
        }
    }

    /**
     * The question, its concept with ancestors, the concept's owner and the
     * absolute link to the question (on the subdomain when there is one, so
     * a signed-in owner stays signed in).
     */
    private static function context(int $questionId): ?array {
        $question = QuestionManagement::findById($questionId);
        if (!$question) {
            return null;
        }
        $concept = ConceptManagement::findWithAncestors((int)$question['concept_id']);
        if (!$concept) {
            return null;
        }
        $owner = UserManagement::findById((int)$concept['user_id']);
        $site = SiteManagement::findByUserId((int)$concept['user_id']);
        if (!$owner || !$site) {
            return null;
        }
        $url = SiteResolver::absoluteUrlFor($site, (string)$concept['category_slug'], (string)$concept['subcategory_slug'], (string)$concept['slug'], true)
             . '#q' . (int)$question['id'];
        return ['question' => $question, 'concept' => $concept, 'owner' => $owner, 'site' => $site, 'url' => $url];
    }

    private static function askerName(array $question): string {
        $name = trim((string)($question['asker_first_name'] ?? ''));
        return $name !== '' ? $name : 'Someone';
    }

    private static function logFailure(string $action, int $questionId, \Throwable $e): void {
        try {
            ActivityLog::log(UserContext::getLoggedInUserContext(), $action, ['question_id' => $questionId, 'error' => $e->getMessage()]);
        } catch (\Throwable $ignored) {
            // Nothing left to report to.
        }
    }
}
