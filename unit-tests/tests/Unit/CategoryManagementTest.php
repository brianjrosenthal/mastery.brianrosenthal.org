<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CategoryManagementTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
    }

    public function testCreateGeneratesSlugAndSortOrder(): void
    {
        $a = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra II', 'description_markdown' => '# Hi']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Chess Openings']);
        $rowA = CategoryManagement::findById($a);
        $rowB = CategoryManagement::findById($b);
        $this->assertSame('algebra-ii', $rowA['slug']);
        $this->assertSame('chess-openings', $rowB['slug']);
        $this->assertSame(1, (int)$rowA['sort_order']);
        $this->assertSame(2, (int)$rowB['sort_order']);
        $this->assertSame('# Hi', $rowA['description_markdown']);
        $this->assertSame($a, (int)CategoryManagement::findBySlug($this->charlie->id, 'algebra-ii')['id']);
    }

    public function testDuplicateNamesGetDistinctSlugsAndExplicitDuplicateSlugIsRefused(): void
    {
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $this->assertSame('algebra-2', CategoryManagement::findById($b)['slug']);

        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Other', 'slug' => 'algebra']);
    }

    public function testSlugsAreScopedPerUser(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra']);
        $id = CategoryManagement::create($lilly, $lilly->id, ['name' => 'Algebra']);
        $this->assertSame('algebra', CategoryManagement::findById($id)['slug']);
    }

    public function testReservedSlugIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved');
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'X', 'slug' => 'admin']);
    }

    public function testReservedNameGetsSafeGeneratedSlug(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Admin']);
        $slug = CategoryManagement::findById($id)['slug'];
        $this->assertFalse(Slugger::isReserved($slug));
        $this->assertTrue(Slugger::isValid($slug));
    }

    public function testNameIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => '  ']);
    }

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = CategoryManagement::create($this->admin, $this->charlie->id, ['name' => 'By admin']);
        CategoryManagement::update($this->admin, $id, ['name' => 'Admin edit']);
        CategoryManagement::update($this->charlie, $id, ['name' => 'Owner edit']);
        $this->assertSame('Owner edit', CategoryManagement::findById($id)['name']);

        try {
            CategoryManagement::update($lilly, $id, ['name' => 'Hijack']);
            $this->fail('another user must not edit');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('own content', $e->getMessage());
        }
        try {
            CategoryManagement::create($lilly, $this->charlie->id, ['name' => 'Planted']);
            $this->fail('another user must not create in someone else\'s tree');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->expectException(RuntimeException::class);
        CategoryManagement::delete(null, $id);
    }

    public function testUpdateChangesFieldsAndKeepsSlugUniqueness(): void
    {
        $a = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'A']);
        $b = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'B']);
        CategoryManagement::update($this->charlie, $b, ['name' => 'B renamed', 'slug' => 'b-new', 'sort_order' => 0, 'description_markdown' => 'x']);
        $row = CategoryManagement::findById($b);
        $this->assertSame('B renamed', $row['name']);
        $this->assertSame('b-new', $row['slug']);
        $this->assertSame(0, (int)$row['sort_order']);
        $this->assertSame('x', $row['description_markdown']);
        // Updating with its own slug is fine; taking another's is not.
        CategoryManagement::update($this->charlie, $b, ['slug' => 'b-new']);
        $this->expectException(InvalidArgumentException::class);
        CategoryManagement::update($this->charlie, $b, ['slug' => 'a']);
    }

    public function testDeleteOnlyWhenEmpty(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Full']);
        SubcategoryManagement::create($this->charlie, $id, ['name' => 'Inside']);
        try {
            CategoryManagement::delete($this->charlie, $id);
            $this->fail('non-empty category must not be deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        $empty = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Empty']);
        CategoryManagement::delete($this->charlie, $empty);
        $this->assertNull(CategoryManagement::findById($empty));
    }

    public function testListForUserCountsAndTreeForUser(): void
    {
        $ids = test_seed_tree($this->charlie);
        $sub2 = SubcategoryManagement::create($this->charlie, $ids['category_id'], ['name' => 'Empty topic']);
        ConceptManagement::setPublished($this->charlie, $ids['concept_id'], true);
        ConceptManagement::create($this->charlie, $ids['subcategory_id'], ['title' => 'Draft one']);

        $list = CategoryManagement::listForUser($this->charlie->id);
        $this->assertCount(1, $list);
        $this->assertSame(2, (int)$list[0]['subcategory_count']);
        $this->assertSame(2, (int)$list[0]['concept_count']);
        $this->assertSame(1, (int)$list[0]['published_count']);

        $tree = CategoryManagement::treeForUser($this->charlie->id);
        $this->assertCount(2, $tree[0]['subcategories']);
        $this->assertCount(2, $tree[0]['subcategories'][0]['concepts']);
        $this->assertCount(0, $tree[0]['subcategories'][1]['concepts']);
        $this->assertSame($this->charlie->id, CategoryManagement::ownerUserIdOf($ids['category_id']));
        $this->assertNull(CategoryManagement::ownerUserIdOf(9999));
    }

    public function testWritesAreActivityLogged(): void
    {
        $id = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Logged']);
        CategoryManagement::update($this->charlie, $id, ['name' => 'Logged 2']);
        CategoryManagement::delete($this->charlie, $id);
        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('category.create', $types);
        $this->assertContains('category.update', $types);
        $this->assertContains('category.delete', $types);
    }
}
