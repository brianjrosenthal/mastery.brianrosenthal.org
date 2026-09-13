<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ConceptManagementTest extends TestCase
{
    private UserContext $charlie;
    private int $subcategoryId;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $ids = test_seed_tree($this->charlie);
        $this->subcategoryId = $ids['subcategory_id'];
        // The seed tree already holds one concept; tests add their own.
    }

    private function storage(): FakeDreamObjects
    {
        return VideoStorage::storage();
    }

    public function testCreateWithResourcesAndPublishNow(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, [
            'title' => 'Sum of a geometric series',
            'description_markdown' => 'S = a/(1-r)',
            'is_published' => true,
            'resources' => [
                ['title' => 'Khan Academy', 'url' => 'https://www.khanacademy.org/x'],
                ['title' => '', 'url' => 'https://example.com/untitled'],
                ['title' => '', 'url' => ''],   // blank row is ignored
            ],
        ]);
        $c = ConceptManagement::findWithAncestors($id);
        $this->assertSame('sum-of-a-geometric-series', $c['slug']);
        $this->assertSame(1, (int)$c['is_published']);
        $this->assertNotNull($c['published_at']);
        $this->assertSame('Algebra II', $c['category_name']);
        $this->assertSame('sequences-and-series', $c['subcategory_slug']);
        $this->assertSame($this->charlie->id, (int)$c['user_id']);

        $res = ConceptManagement::listResources($id);
        $this->assertCount(2, $res);
        $this->assertSame('Khan Academy', $res[0]['title']);
        $this->assertSame('https://example.com/untitled', $res[1]['title'], 'untitled links use the URL as title');
    }

    public function testResourceValidation(): void
    {
        try {
            ConceptManagement::validateResources([['title' => 'Bad', 'url' => 'ftp://x']]);
            $this->fail('non-http link must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('http', $e->getMessage());
        }
        try {
            ConceptManagement::validateResources([['title' => 'No link', 'url' => '']]);
            $this->fail('title without link must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('link', $e->getMessage());
        }
        $this->assertSame([], ConceptManagement::validateResources(['garbage', null]));
    }

    public function testUpdateReplacesResourcesAndTogglesPublish(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'T', 'resources' => [['title' => 'A', 'url' => 'https://a.example']]]);
        ConceptManagement::update($this->charlie, $id, ['title' => 'T2', 'resources' => [['title' => 'B', 'url' => 'https://b.example']], 'is_published' => true]);
        $c = ConceptManagement::findById($id);
        $this->assertSame('T2', $c['title']);
        $this->assertSame(1, (int)$c['is_published']);
        $this->assertSame(['B'], array_column(ConceptManagement::listResources($id), 'title'));

        // Update without a resources key leaves them alone.
        ConceptManagement::update($this->charlie, $id, ['title' => 'T3']);
        $this->assertCount(1, ConceptManagement::listResources($id));
    }

    public function testPublishKeepsFirstPublishedAt(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'P']);
        ConceptManagement::setPublished($this->charlie, $id, true);
        $first = ConceptManagement::findById($id)['published_at'];
        $this->assertNotNull($first);
        ConceptManagement::setPublished($this->charlie, $id, false);
        $this->assertSame(0, (int)ConceptManagement::findById($id)['is_published']);
        ConceptManagement::setPublished($this->charlie, $id, true);
        $this->assertSame($first, ConceptManagement::findById($id)['published_at']);
    }

    public function testListForSubcategoryHidesDraftsUnlessAsked(): void
    {
        $pub = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'Public', 'is_published' => true]);
        $this->assertSame([$pub], array_map('intval', array_column(ConceptManagement::listForSubcategory($this->subcategoryId, false), 'id')));
        $this->assertCount(2, ConceptManagement::listForSubcategory($this->subcategoryId, true));
    }

    public function testNeighborsFollowDisplayOrder(): void
    {
        $a = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'A', 'is_published' => true]);
        $b = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'B', 'is_published' => true]);
        $c = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'C', 'is_published' => true]);
        $n = ConceptManagement::neighbors(ConceptManagement::findById($b), false);
        $this->assertSame($a, (int)$n['prev']['id']);
        $this->assertSame($c, (int)$n['next']['id']);
        $n = ConceptManagement::neighbors(ConceptManagement::findById($a), false);
        $this->assertNull($n['prev']);
    }

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'Mine']);
        ConceptManagement::update(new UserContext(1, true), $id, ['title' => 'Admin touched']);
        foreach (['update', 'setPublished', 'delete'] as $method) {
            try {
                match ($method) {
                    'update' => ConceptManagement::update($lilly, $id, ['title' => 'x']),
                    'setPublished' => ConceptManagement::setPublished($lilly, $id, true),
                    'delete' => ConceptManagement::delete($lilly, $id),
                };
                $this->fail("$method must be refused for another user");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('own content', $e->getMessage());
            }
        }
        $this->expectException(RuntimeException::class);
        ConceptManagement::create($lilly, $this->subcategoryId, ['title' => 'Planted']);
    }

    // --- video -------------------------------------------------------------

    public function testAttachVideoRecordsAndReplacesPreviousObject(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'V']);
        $bucket = VideoStorage::bucket();

        $key1 = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/mp4');
        $this->storage()->seedObject($bucket, $key1, 5000, 'video/mp4');
        ConceptManagement::attachVideo($this->charlie, $id, $key1, 'video/mp4', 5000);
        $c = ConceptManagement::findById($id);
        $this->assertSame($key1, $c['video_object_key']);
        $this->assertSame(5000, (int)$c['video_size_bytes']);
        $this->assertNotNull($c['video_uploaded_at']);

        $key2 = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/webm');
        $this->storage()->seedObject($bucket, $key2, 7000, 'video/webm');
        ConceptManagement::attachVideo($this->charlie, $id, $key2, 'video/webm', 7000);
        $this->assertSame($key2, ConceptManagement::findById($id)['video_object_key']);
        $this->assertFalse($this->storage()->objectExists($bucket, $key1), 'previous object is deleted');
        $this->assertTrue($this->storage()->objectExists($bucket, $key2));
        $this->assertSame([$key2], ConceptManagement::listVideoObjectKeys());
    }

    public function testAttachRefusesKeysForOtherConcepts(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'V']);
        $foreign = VideoStorage::newObjectKeyFor($this->charlie->id, $id + 1, 'video/mp4');
        $this->expectException(InvalidArgumentException::class);
        ConceptManagement::attachVideo($this->charlie, $id, $foreign, 'video/mp4', 10);
    }

    public function testDetachDeletesObjectAndClearsColumns(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'V']);
        $key = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/mp4');
        $this->storage()->seedObject(VideoStorage::bucket(), $key, 10, 'video/mp4');
        ConceptManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 10);

        ConceptManagement::detachVideo($this->charlie, $id);
        $c = ConceptManagement::findById($id);
        $this->assertNull($c['video_object_key']);
        $this->assertNull($c['video_size_bytes']);
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $key));
        ConceptManagement::detachVideo($this->charlie, $id); // no video: no-op
    }

    public function testDetachKeepsRowWhenStorageDeleteFails(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'V']);
        $key = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/mp4');
        $this->storage()->seedObject(VideoStorage::bucket(), $key, 10, 'video/mp4');
        ConceptManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 10);
        $this->storage()->failDeletes = true;
        try {
            ConceptManagement::detachVideo($this->charlie, $id);
            $this->fail('storage failure must surface');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated', $e->getMessage());
        }
        $this->assertSame($key, ConceptManagement::findById($id)['video_object_key'], 'row still points at the object so the user can retry');
    }

    public function testDeleteRemovesConceptResourcesAndVideo(): void
    {
        $id = ConceptManagement::create($this->charlie, $this->subcategoryId, ['title' => 'Gone', 'resources' => [['title' => 'r', 'url' => 'https://r.example']]]);
        $key = VideoStorage::newObjectKeyFor($this->charlie->id, $id, 'video/mp4');
        $this->storage()->seedObject(VideoStorage::bucket(), $key, 10, 'video/mp4');
        ConceptManagement::attachVideo($this->charlie, $id, $key, 'video/mp4', 10);

        ConceptManagement::delete($this->charlie, $id);
        $this->assertNull(ConceptManagement::findById($id));
        $this->assertSame([], ConceptManagement::listResources($id));
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), $key));
        $types = array_column(ActivityLog::list([], 20), 'action_type');
        $this->assertContains('concept.delete', $types);
        $this->assertContains('concept.video_attach', $types);
    }
}
