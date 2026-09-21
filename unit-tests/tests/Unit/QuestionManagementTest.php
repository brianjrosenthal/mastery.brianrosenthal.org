<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Questions under a concept and the owner's answers: who may ask, who sees
 * what, how "answered" is derived from text and video, and that answer videos
 * are cleaned out of storage on every path that drops them.
 */
final class QuestionManagementTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;   // owns the concept
    private UserContext $lilly;     // asks questions
    private UserContext $stranger;  // signed in, unrelated
    private int $conceptId;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->lilly = test_seed_user('lilly@example.com', 'Lilly');
        $this->stranger = test_seed_user('stranger@example.com', 'Stranger');
        $this->conceptId = test_seed_tree($this->charlie)['concept_id'];
    }

    private function storage(): FakeS3Client
    {
        return VideoStorage::storage();
    }

    /** An uploaded answer video, as the browser leaves it before attach. */
    private function seedAnswerUpload(int $questionId, string $type = 'video/mp4', int $size = 1000): string
    {
        $key = VideoStorage::newAnswerObjectKeyFor($this->charlie->id, $questionId, $type);
        $this->storage()->seedObject(VideoStorage::bucket(), $key, $size, $type);
        return $key;
    }

    public function testAnySignedInUserMayAskAndTheQuestionStartsUnanswered(): void
    {
        $id = QuestionManagement::ask($this->lilly, $this->conceptId, "  Why is e special?\nReally.  ");
        $q = QuestionManagement::findById($id);
        $this->assertSame("Why is e special?\nReally.", $q['question_text'], 'trimmed, newlines kept');
        $this->assertSame($this->lilly->id, (int)$q['asked_by_user_id']);
        $this->assertSame('Lilly', $q['asker_first_name']);
        $this->assertNull($q['answered_at']);
        $this->assertSame($this->charlie->id, QuestionManagement::conceptOwnerUserIdOf($id));

        // The owner and an admin may ask on it too; nobody signed out may.
        QuestionManagement::ask($this->charlie, $this->conceptId, 'Note to self');
        QuestionManagement::ask($this->admin, $this->conceptId, 'Admin asks');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sign in');
        QuestionManagement::ask(null, $this->conceptId, 'Anonymous');
    }

    public function testAskValidation(): void
    {
        try {
            QuestionManagement::ask($this->lilly, $this->conceptId, "  \n ");
            $this->fail('empty question must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('write your question', $e->getMessage());
        }
        try {
            QuestionManagement::ask($this->lilly, $this->conceptId, str_repeat('x', QuestionManagement::MAX_QUESTION_CHARS + 1));
            $this->fail('over-long question must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('characters or fewer', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Concept not found');
        QuestionManagement::ask($this->lilly, 999999, 'Where?');
    }

    public function testVisibilityUntilAndAfterAnswering(): void
    {
        $mine = test_seed_question($this->lilly, $this->conceptId, 'Mine');
        $theirs = test_seed_question($this->stranger, $this->conceptId, 'Theirs');

        $ids = fn(?UserContext $viewer, bool $canEdit) => array_map('intval', array_column(QuestionManagement::listForConcept($this->conceptId, $viewer, $canEdit), 'id'));
        $this->assertSame([], $ids(null, false), 'anonymous sees nothing unanswered');
        $this->assertSame([$mine], $ids($this->lilly, false), 'an asker sees only their own unanswered question');
        $this->assertSame([$theirs], $ids($this->stranger, false));
        $this->assertSame([$mine, $theirs], $ids($this->charlie, true), 'the owner sees everything');
        $this->assertSame([$mine, $theirs], $ids($this->admin, true));

        QuestionManagement::answer($this->charlie, $mine, 'Because.');
        $this->assertSame([$mine], $ids(null, false), 'answered questions are public');
        $this->assertSame([$mine], $ids($this->stranger, false) === [$mine, $theirs] ? [$mine] : array_values(array_intersect($ids($this->stranger, false), [$mine])));
        $this->assertSame([$mine, $theirs], $ids($this->stranger, false), 'answered plus their own');
    }

    public function testAnswerTextMarksAnsweredOnceAndCanBeEdited(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        $this->assertTrue(QuestionManagement::answer($this->charlie, $id, '**Because** e is its own derivative.'), 'first answer is "newly answered"');
        $q = QuestionManagement::findById($id);
        $this->assertNotNull($q['answered_at']);
        $this->assertSame($this->charlie->id, (int)$q['answered_by_user_id']);
        $first = $q['answered_at'];

        $this->assertFalse(QuestionManagement::answer($this->admin, $id, 'Edited by an admin.'), 'editing is not a new answer');
        $q = QuestionManagement::findById($id);
        $this->assertSame('Edited by an admin.', $q['answer_markdown']);
        $this->assertSame($first, $q['answered_at'], 'answered_at is kept from the first answer');
        $this->assertSame($this->admin->id, (int)$q['answered_by_user_id']);
    }

    public function testEmptyAnswerTextIsRefusedUnlessThereIsAVideo(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        try {
            QuestionManagement::answer($this->charlie, $id, '   ');
            $this->fail('nothing to answer with');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Write an answer or add a video', $e->getMessage());
        }
        $this->assertNull(QuestionManagement::findById($id)['answered_at']);

        $key = $this->seedAnswerUpload($id);
        QuestionManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 1000);
        QuestionManagement::answer($this->charlie, $id, '');
        $this->assertNull(QuestionManagement::findById($id)['answer_markdown'], 'a video-only answer stores no text');
        $this->assertNotNull(QuestionManagement::findById($id)['answered_at']);

        $this->expectException(InvalidArgumentException::class);
        QuestionManagement::answer($this->charlie, $id, str_repeat('y', QuestionManagement::MAX_ANSWER_CHARS + 1));
    }

    public function testAttachingAVideoAnswersAndReplacesThePreviousObject(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        $first = $this->seedAnswerUpload($id, 'video/webm', 500);
        $this->assertTrue(QuestionManagement::attachVideo($this->charlie, $id, $first, 'video/webm', 500));
        $q = QuestionManagement::findById($id);
        $this->assertSame([$first, 'r2', 'video/webm', 500], [$q['video_object_key'], $q['video_storage'], $q['video_content_type'], (int)$q['video_size_bytes']]);
        $this->assertNotNull($q['answered_at']);
        $this->assertStringStartsWith('https://r2-test.example/', VideoStorage::playbackUrlForConcept($q), 'the shared column names make playback work as for concepts');

        $second = $this->seedAnswerUpload($id, 'video/mp4', 700);
        $this->assertFalse(QuestionManagement::attachVideo($this->charlie, $id, $second, 'video/mp4', 700), 'replacing is not a new answer');
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $first), 'the previous object is deleted');
        $this->assertTrue($this->storage()->objectExists(VideoStorage::bucket(), $second));

        // A key from another question, or a concept-shaped key, is refused.
        $other = test_seed_question($this->lilly, $this->conceptId, 'Other');
        foreach ([
            VideoStorage::newAnswerObjectKeyFor($this->charlie->id, $other, 'video/mp4'),
            VideoStorage::newObjectKeyFor($this->charlie->id, $this->conceptId, 'video/mp4'),
            '',
        ] as $bad) {
            try {
                QuestionManagement::attachVideo($this->charlie, $id, $bad, 'video/mp4', 1);
                $this->fail('key "' . $bad . '" must be refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('does not belong', $e->getMessage());
            }
        }
    }

    public function testDetachingTheOnlyAnswerUnanswersTheQuestion(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        $key = $this->seedAnswerUpload($id);
        QuestionManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 1000);
        QuestionManagement::detachVideo($this->charlie, $id);
        $q = QuestionManagement::findById($id);
        $this->assertNull($q['video_object_key']);
        $this->assertNull($q['answered_at'], 'no text and no video: back to waiting');
        $this->assertNull($q['answered_by_user_id']);
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $key));
        QuestionManagement::detachVideo($this->charlie, $id); // no video: a no-op

        // With text, removing the video keeps it answered, and answering again is not "new".
        $key = $this->seedAnswerUpload($id);
        QuestionManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 1000);
        QuestionManagement::answer($this->charlie, $id, 'Text too.');
        QuestionManagement::detachVideo($this->charlie, $id);
        $this->assertNotNull(QuestionManagement::findById($id)['answered_at']);

        // A storage failure leaves the row pointing at the object.
        $key = $this->seedAnswerUpload($id);
        QuestionManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 1000);
        $this->storage()->failDeletes = true;
        try {
            QuestionManagement::detachVideo($this->charlie, $id);
            $this->fail('storage failure must surface');
        } catch (RuntimeException $e) {
            $this->assertSame($key, QuestionManagement::findById($id)['video_object_key']);
        }
    }

    public function testOnlyOwnerOrAdminMayAnswerAttachDetachOrDeleteOthersQuestions(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        $key = $this->seedAnswerUpload($id);
        foreach (['answer', 'attachVideo', 'detachVideo'] as $method) {
            foreach ([$this->lilly, $this->stranger, null] as $who) {
                try {
                    match ($method) {
                        'answer'      => QuestionManagement::answer($who, $id, 'Hijack'),
                        'attachVideo' => QuestionManagement::attachVideo($who, $id, $key, 'video/mp4', 1000),
                        'detachVideo' => QuestionManagement::detachVideo($who, $id),
                    };
                    $this->fail("$method must be refused for " . ($who ? 'user ' . $who->id : 'anonymous'));
                } catch (RuntimeException $e) {
                    $this->assertThat($e->getMessage(), $this->logicalOr(
                        $this->stringContains('own content'), $this->stringContains('Login required')));
                }
            }
        }
        $this->assertNull(QuestionManagement::findById($id)['answered_at']);

        // Deleting: the stranger never, the asker only while unanswered, the owner/admin always.
        try {
            QuestionManagement::delete($this->stranger, $id);
            $this->fail('a stranger cannot delete');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('own unanswered', $e->getMessage());
        }
        QuestionManagement::delete($this->lilly, $id);
        $this->assertNull(QuestionManagement::findById($id));

        $answered = test_seed_question($this->lilly, $this->conceptId);
        QuestionManagement::answer($this->charlie, $answered, 'Done.');
        try {
            QuestionManagement::delete($this->lilly, $answered);
            $this->fail('an answered question is the owner\'s to keep');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('own unanswered', $e->getMessage());
        }
        QuestionManagement::delete($this->admin, $answered);
        $this->assertNull(QuestionManagement::findById($answered));

        $this->expectException(RuntimeException::class);
        QuestionManagement::delete(null, test_seed_question($this->lilly, $this->conceptId));
    }

    public function testDeletingAQuestionOrItsConceptRemovesAnswerVideosFromStorage(): void
    {
        $a = test_seed_question($this->lilly, $this->conceptId, 'A');
        $b = test_seed_question($this->stranger, $this->conceptId, 'B');
        $keyA = $this->seedAnswerUpload($a);
        $keyB = $this->seedAnswerUpload($b);
        QuestionManagement::attachVideo($this->charlie, $a, $keyA, 'video/mp4', 1000);
        QuestionManagement::attachVideo($this->charlie, $b, $keyB, 'video/mp4', 1000);
        $this->assertSame([$keyA, $keyB], QuestionManagement::listVideoObjectKeys());
        $this->assertSame([$keyA, $keyB], QuestionManagement::listVideoObjectKeys('r2'));
        $this->assertSame([], QuestionManagement::listVideoObjectKeys('dreamobjects'));

        QuestionManagement::delete($this->charlie, $a);
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $keyA));
        $this->assertTrue($this->storage()->objectExists(VideoStorage::bucket(), $keyB));

        ConceptManagement::delete($this->charlie, $this->conceptId);
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $keyB), 'answer videos go with the concept');
        $this->assertNull(QuestionManagement::findById($b), 'rows cascade');
        $this->assertSame([], QuestionManagement::listVideoObjectKeys());
    }

    public function testDeletingTheAskerKeepsTheQuestionAsAFormerMember(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        QuestionManagement::answer($this->charlie, $id, 'Sure.');
        UserManagement::deleteUser($this->admin, $this->lilly->id);
        $q = QuestionManagement::findById($id);
        $this->assertNull($q['asked_by_user_id']);
        $this->assertNull($q['asker_first_name']);
        $this->assertSame('Sure.', $q['answer_markdown']);
    }

    public function testUnansweredInboxForOwner(): void
    {
        $this->assertSame([], QuestionManagement::listUnansweredForOwner($this->charlie->id));
        $a = test_seed_question($this->lilly, $this->conceptId, 'First');
        $b = test_seed_question($this->stranger, $this->conceptId, 'Second');
        QuestionManagement::answer($this->charlie, $b, 'Done.');
        $rows = QuestionManagement::listUnansweredForOwner($this->charlie->id);
        $this->assertSame([$a], array_map('intval', array_column($rows, 'id')));
        $this->assertSame('Derivation of e^x', $rows[0]['concept_title']);
        $this->assertSame(['algebra-ii', 'sequences-and-series', 'derivation-of-e-x'],
            [$rows[0]['category_slug'], $rows[0]['subcategory_slug'], $rows[0]['concept_slug']]);
        $this->assertSame('Lilly', $rows[0]['asker_first_name']);
        $this->assertSame([], QuestionManagement::listUnansweredForOwner($this->lilly->id), 'only the owner\'s concepts');
    }

    public function testWritesAreActivityLogged(): void
    {
        $id = test_seed_question($this->lilly, $this->conceptId);
        QuestionManagement::answer($this->charlie, $id, 'A');
        $key = $this->seedAnswerUpload($id);
        QuestionManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 1000);
        QuestionManagement::detachVideo($this->charlie, $id);
        QuestionManagement::delete($this->charlie, $id);
        $types = array_column(ActivityLog::list([], 20), 'action_type');
        foreach (['question.ask', 'question.answer', 'question.video_attach', 'question.video_detach', 'question.delete'] as $t) {
            $this->assertContains($t, $types);
        }
    }
}
