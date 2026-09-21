<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The bookkeeping of moving a concept's video from DreamObjects to R2. The
 * byte transfer itself is injected (it is curl against real endpoints), so
 * these tests prove what is recorded when, and that a concept is never
 * switched over to a copy that is missing or the wrong size.
 */
final class VideoMigrationTest extends TestCase
{
    private UserContext $charlie;
    private int $subcategoryId;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->subcategoryId = test_seed_tree($this->charlie)['subcategory_id'];
    }

    private function dream(): FakeS3Client
    {
        return VideoStorage::storage('dreamobjects');
    }

    private function r2(): FakeS3Client
    {
        return VideoStorage::storage('r2');
    }

    /** A concept whose video sits in DreamObjects, as every pre-R2 upload does. */
    private function legacyConcept(string $title = 'Old', int $size = 4321): array
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => $title]);
        $key = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/mp4');
        $this->dream()->seedObject(VideoStorage::bucket('dreamobjects'), $key, $size, 'video/mp4');
        pdo()->prepare("UPDATE concepts SET video_object_key = ?, video_storage = 'dreamobjects', video_content_type = 'video/mp4', video_size_bytes = ?, video_uploaded_at = NOW() WHERE id = ?")
            ->execute([$key, $size, $id]);
        return ['id' => $id, 'key' => $key];
    }

    /** An answered question whose answer video sits in DreamObjects. */
    private function legacyAnswer(int $conceptId, int $size = 555): array
    {
        $asker = test_seed_user('asker' . $size . '@example.com', 'Asker');
        $id = QuestionManagement::ask($asker, $conceptId, 'Why?');
        $key = VideoStorage::newAnswerObjectKeyFor($this->charlie->id, $id, 'video/webm');
        $this->dream()->seedObject(VideoStorage::bucket('dreamobjects'), $key, $size, 'video/webm');
        pdo()->prepare("UPDATE concept_questions SET video_object_key = ?, video_storage = 'dreamobjects', video_content_type = 'video/webm', video_size_bytes = ?, video_uploaded_at = NOW(), answered_at = NOW() WHERE id = ?")
            ->execute([$key, $size, $id]);
        return ['id' => $id, 'key' => $key];
    }

    /** A transfer that copies the object between the fakes (and counts calls). */
    private function fakeTransfer(int &$calls, ?int $sizeOverride = null): callable
    {
        return function (string $key, string $from, string $to, string $contentType) use (&$calls, $sizeOverride): void {
            $calls++;
            $src = VideoStorage::storage($from)->headObject(VideoStorage::bucket($from), $key);
            VideoStorage::storage($to)->seedObject(VideoStorage::bucket($to), $key, $sizeOverride ?? (int)$src['size'], $contentType);
        };
    }

    public function testPendingListsVideosHeldOutsideTheActiveProvider(): void
    {
        $this->assertSame([], VideoMigration::pending());
        $a = $this->legacyConcept('A', 100);
        $b = $this->legacyConcept('B', 200);
        // A fresh R2 upload is not pending.
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'New']);
        $key = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/webm');
        $this->r2()->seedObject(VideoStorage::bucket('r2'), $key, 7, 'video/webm');
        ConceptManagement::attachVideo($this->charlie, $id, $key, 'video/webm', 7);

        $pending = VideoMigration::pending();
        $this->assertSame([$a['id'], $b['id']], array_column($pending, 'id'));
        $this->assertSame(['dreamobjects', 'dreamobjects'], array_column($pending, 'video_storage'));
        $this->assertSame([100, 200], array_column($pending, 'video_size_bytes'));
        $this->assertSame(['r2' => 1, 'dreamobjects' => 2], VideoMigration::countsByProvider());
        $this->assertSame([$id], array_column(VideoMigration::pending('dreamobjects'), 'id'), 'pending is relative to the destination');
    }

    public function testAnswerVideosArePendingCountedInventoriedAndMigratedToo(): void
    {
        $concept = $this->legacyConcept('Old', 100);
        $answer = $this->legacyAnswer($concept['id'], 555);
        $newConcept = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'New']);
        $freshKey = VideoStorage::newObjectKeyFor($this->charlie->id, $newConcept, 'video/mp4');
        $this->r2()->seedObject(VideoStorage::bucket('r2'), $freshKey, 7, 'video/mp4');
        ConceptManagement::attachVideo($this->charlie, $newConcept, $freshKey, 'video/mp4', 7);

        $pending = VideoMigration::pending();
        $this->assertSame([['concept', $concept['id']], ['question', $answer['id']]], array_map(fn($r) => [$r['kind'], $r['id']], $pending), 'concepts first, then answers');
        $this->assertSame($concept['id'], $pending[1]['concept_id']);
        $this->assertStringContainsString('Answer to question #' . $answer['id'] . ' on "Old"', $pending[1]['title']);
        $this->assertSame(['r2' => 1, 'dreamobjects' => 2], VideoMigration::countsByProvider(), 'answer videos count');
        $this->assertSame([$concept['key'], $answer['key']], VideoMigration::listRecordedObjectKeys('dreamobjects'));
        $this->assertSame([$freshKey], VideoMigration::listRecordedObjectKeys('r2'));
        $this->assertSame([$concept['key'], $freshKey, $answer['key']], VideoMigration::listRecordedObjectKeys(), 'the orphan inventory covers both tables');

        $calls = 0;
        $result = VideoMigration::migratePending($this->charlie, $pending[1], null, $this->fakeTransfer($calls), true);
        $this->assertSame(['question', $answer['id'], 'dreamobjects', 'r2', 555, true, true], [$result['kind'], $result['id'], $result['from'], $result['to'], $result['size'], $result['copied'], $result['source_deleted']]);
        $q = QuestionManagement::findById($answer['id']);
        $this->assertSame('r2', $q['video_storage']);
        $this->assertSame($answer['key'], $q['video_object_key']);
        $this->assertTrue($this->r2()->objectExists(VideoStorage::bucket('r2'), $answer['key']));
        $this->assertFalse($this->dream()->objectExists(VideoStorage::bucket('dreamobjects'), $answer['key']));
        $this->assertSame([['concept', $concept['id']]], array_map(fn($r) => [$r['kind'], $r['id']], VideoMigration::pending()));
        $this->assertContains('question.video_migrate', array_column(pdo()->query('SELECT action_type FROM activity_log')->fetchAll(), 'action_type'));

        $this->assertTrue(VideoMigration::migrateQuestion(null, $answer['id'], 'r2', $this->fakeTransfer($calls))['skipped']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Question #' . ($answer['id'] + 1000) . ' has no video');
        pdo()->prepare('INSERT INTO concept_questions (id, concept_id, asked_by_user_id, question_text) VALUES (?, ?, ?, ?)')->execute([$answer['id'] + 1000, $concept['id'], $this->charlie->id, 'no video']);
        VideoMigration::migrateQuestion(null, $answer['id'] + 1000, 'r2', $this->fakeTransfer($calls));
    }

    public function testMigrateCopiesVerifiesFlipsTheRowAndKeepsTheOriginal(): void
    {
        $c = $this->legacyConcept('Old', 4321);
        $calls = 0;
        $result = VideoMigration::migrateConcept($this->charlie, $c['id'], null, $this->fakeTransfer($calls));

        $this->assertSame(1, $calls);
        $this->assertSame(['dreamobjects', 'r2', 4321, true, false, false, null],
            [$result['from'], $result['to'], $result['size'], $result['copied'], $result['skipped'], $result['source_deleted'], $result['warning']]);
        $row = ConceptManagement::findById($c['id']);
        $this->assertSame('r2', $row['video_storage']);
        $this->assertSame($c['key'], $row['video_object_key'], 'same key in the new bucket');
        $this->assertTrue($this->r2()->objectExists(VideoStorage::bucket('r2'), $c['key']));
        $this->assertTrue($this->dream()->objectExists(VideoStorage::bucket('dreamobjects'), $c['key']), 'original kept unless asked');
        $this->assertSame([], VideoMigration::pending());
        $this->assertStringStartsWith('https://r2-test.example/', VideoStorage::playbackUrlForConcept($row), 'playback now comes from R2');

        $types = array_column(pdo()->query('SELECT action_type FROM activity_log')->fetchAll(), 'action_type');
        $this->assertContains('concept.video_migrate', $types);

        // Running again is a no-op.
        $again = VideoMigration::migrateConcept($this->charlie, $c['id'], null, $this->fakeTransfer($calls));
        $this->assertTrue($again['skipped']);
        $this->assertSame(1, $calls);
    }

    public function testDeleteSourceRemovesTheOriginalAfterTheCopyIsRecorded(): void
    {
        $c = $this->legacyConcept();
        $calls = 0;
        $result = VideoMigration::migrateConcept(null, $c['id'], 'r2', $this->fakeTransfer($calls), true);
        $this->assertTrue($result['source_deleted']);
        $this->assertFalse($this->dream()->objectExists(VideoStorage::bucket('dreamobjects'), $c['key']));
        $this->assertTrue($this->r2()->objectExists(VideoStorage::bucket('r2'), $c['key']));
        $this->assertSame('r2', ConceptManagement::findById($c['id'])['video_storage']);
    }

    public function testFailingToDeleteTheSourceIsAWarningNotAFailure(): void
    {
        $c = $this->legacyConcept();
        $calls = 0;
        $this->dream()->failDeletes = true;
        $result = VideoMigration::migrateConcept(null, $c['id'], 'r2', $this->fakeTransfer($calls), true);
        $this->assertFalse($result['source_deleted']);
        $this->assertStringContainsString('deleting the original', (string)$result['warning']);
        $this->assertSame('r2', ConceptManagement::findById($c['id'])['video_storage'], 'the copy is still recorded');
    }

    public function testAnExistingCopyOfTheRightSizeIsNotTransferredAgain(): void
    {
        // e.g. the bucket was bulk-copied with rclone first.
        $c = $this->legacyConcept('Old', 999);
        $this->r2()->seedObject(VideoStorage::bucket('r2'), $c['key'], 999, 'video/mp4');
        $calls = 0;
        $result = VideoMigration::migrateConcept(null, $c['id'], 'r2', $this->fakeTransfer($calls));
        $this->assertSame(0, $calls);
        $this->assertFalse($result['copied']);
        $this->assertSame('r2', ConceptManagement::findById($c['id'])['video_storage']);
    }

    public function testACopyOfTheWrongSizeIsNeverRecorded(): void
    {
        $c = $this->legacyConcept('Old', 999);
        $calls = 0;
        try {
            VideoMigration::migrateConcept(null, $c['id'], 'r2', $this->fakeTransfer($calls, 998));
            $this->fail('a truncated copy must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('998 bytes but the original is 999', $e->getMessage());
        }
        $this->assertSame('dreamobjects', ConceptManagement::findById($c['id'])['video_storage'], 'row untouched');
        $this->assertSame([$c['id']], array_column(VideoMigration::pending(), 'id'));
    }

    public function testMissingSourceAndVideolessConceptsAreReported(): void
    {
        $c = $this->legacyConcept();
        $this->dream()->deleteObjects(VideoStorage::bucket('dreamobjects'), [$c['key']]);
        $calls = 0;
        try {
            VideoMigration::migrateConcept(null, $c['id'], 'r2', $this->fakeTransfer($calls));
            $this->fail('missing source must be reported');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('missing from DreamHost DreamObjects', $e->getMessage());
        }
        $this->assertSame(0, $calls);

        $noVideo = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'No video']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no video');
        VideoMigration::migrateConcept(null, $noVideo, 'r2', $this->fakeTransfer($calls));
    }

    public function testAVideoReplacedDuringTheCopyIsNotSwitchedOver(): void
    {
        $c = $this->legacyConcept();
        $calls = 0;
        $replaceMidway = function (string $key, string $from, string $to, string $contentType) use (&$calls, $c): void {
            $calls++;
            VideoStorage::storage($to)->seedObject(VideoStorage::bucket($to), $key, 4321, $contentType);
            // Meanwhile the owner uploads a new video to R2.
            $fresh = VideoStorage::newObjectKeyFor($this->charlie->id, $c['id'], 'video/webm');
            $this->r2()->seedObject(VideoStorage::bucket('r2'), $fresh, 5, 'video/webm');
            ConceptManagement::attachVideo($this->charlie, $c['id'], $fresh, 'video/webm', 5);
        };
        try {
            VideoMigration::migrateConcept(null, $c['id'], 'r2', $replaceMidway);
            $this->fail('the row no longer points at the copied key');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed during the copy', $e->getMessage());
        }
        $row = ConceptManagement::findById($c['id']);
        $this->assertSame('r2', $row['video_storage']);
        $this->assertNotSame($c['key'], $row['video_object_key'], 'the fresh upload wins');
    }
}
