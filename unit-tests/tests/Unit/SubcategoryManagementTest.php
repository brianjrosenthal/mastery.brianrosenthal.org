<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubcategoryManagementTest extends TestCase
{
    private UserContext $charlie;
    private int $categoryId;

    protected function setUp(): void
    {
        test_reset_all();
        test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
        $this->categoryId = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Algebra II']);
    }

    public function testCreateAndLookups(): void
    {
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Sequences & Series', 'description_markdown' => 'd']);
        $row = SubcategoryManagement::findById($id);
        $this->assertSame('sequences-series', $row['slug']);
        $this->assertSame($this->categoryId, (int)$row['category_id']);
        $this->assertSame($id, (int)SubcategoryManagement::findBySlug($this->categoryId, 'sequences-series')['id']);
        $this->assertSame($this->charlie->id, SubcategoryManagement::ownerUserIdOf($id));
        $this->assertNull(SubcategoryManagement::ownerUserIdOf(9999));
    }

    public function testSlugsAreScopedPerCategory(): void
    {
        $other = CategoryManagement::create($this->charlie, $this->charlie->id, ['name' => 'Geometry']);
        SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Basics']);
        $dup = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Basics']);
        $inOther = SubcategoryManagement::create($this->charlie, $other, ['name' => 'Basics']);
        $this->assertSame('basics-2', SubcategoryManagement::findById($dup)['slug']);
        $this->assertSame('basics', SubcategoryManagement::findById($inOther)['slug']);
    }

    public function testCreateInUnknownCategoryFails(): void
    {
        $this->expectException(RuntimeException::class);
        SubcategoryManagement::create($this->charlie, 9999, ['name' => 'X']);
    }

    public function testOnlyOwnerOrAdminMayWrite(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Mine']);
        $admin = new UserContext(1, true);
        SubcategoryManagement::update($admin, $id, ['name' => 'Admin touched']);
        $this->expectException(RuntimeException::class);
        SubcategoryManagement::update($lilly, $id, ['name' => 'Hijack']);
    }

    public function testListForCategoryCountsPublished(): void
    {
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Topic']);
        $c1 = ConceptManagement::create($this->charlie, $id, ['title' => 'One', 'is_published' => true]);
        ConceptManagement::create($this->charlie, $id, ['title' => 'Two']);
        $list = SubcategoryManagement::listForCategory($this->categoryId);
        $this->assertCount(1, $list);
        $this->assertSame(2, (int)$list[0]['concept_count']);
        $this->assertSame(1, (int)$list[0]['published_count']);
        $this->assertSame($c1, (int)ConceptManagement::listForSubcategory($id, false)[0]['id']);
    }

    public function testDeleteOnlyWhenEmpty(): void
    {
        $id = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Topic']);
        ConceptManagement::create($this->charlie, $id, ['title' => 'Draft']);
        try {
            SubcategoryManagement::delete($this->charlie, $id);
            $this->fail('non-empty subcategory must not be deleted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }
        $empty = SubcategoryManagement::create($this->charlie, $this->categoryId, ['name' => 'Empty']);
        SubcategoryManagement::delete($this->charlie, $empty);
        $this->assertNull(SubcategoryManagement::findById($empty));
    }
}
