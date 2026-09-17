<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testFreshSchemaRecordsInitialMigrationAsApplied(): void
    {
        $this->assertTrue(MigrationRunner::trackingTableExists());
        $status = MigrationRunner::status();
        $byName = array_column($status, null, 'filename');
        $this->assertArrayHasKey('001_initial_schema.sql', $byName);
        $this->assertTrue($byName['001_initial_schema.sql']['applied']);
        $this->assertSame([], MigrationRunner::pendingFilenames(), 'a fresh install has nothing pending');
    }

    public function testFilenamesAreNaturallySortedAndOnlyNumberedSqlFilesCount(): void
    {
        $files = MigrationRunner::allFilenames();
        $this->assertContains('001_initial_schema.sql', $files);
        $this->assertNotContains('README.md', $files);
        $this->assertNotContains('migrate.sh', $files);
        $sorted = $files;
        natsort($sorted);
        $this->assertSame(array_values($sorted), $files);
    }

    public function testSchemaRecordsEveryMigrationAsAlreadyApplied(): void
    {
        // schema.sql is the complete current structure, so a fresh install must
        // mark every db_migrations file as done or Admin -> Migrations would
        // offer to re-run them.
        $schema = (string)file_get_contents(dirname(__DIR__, 3) . '/www/schema.sql');
        foreach (MigrationRunner::allFilenames() as $file) {
            $this->assertStringContainsString("('" . $file . "')", $schema, "$file must be listed in schema.sql's schema_migrations insert");
        }
        $byName = array_column(MigrationRunner::status(), null, 'filename');
        $this->assertTrue($byName['002_concept_video_storage.sql']['applied']);
    }

    public function testVideoStorageMigrationAddsTheColumnBackfillsAndIsIdempotent(): void
    {
        test_reset_all();
        $admin = test_seed_admin();
        $ids = test_seed_tree($admin);
        $pdo = pdo();
        // Pretend this database predates the column: drop it, leaving a video row.
        $pdo->exec("UPDATE concepts SET video_object_key = 'videos/1/1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.mp4' WHERE id = " . (int)$ids['concept_id']);
        $pdo->exec('ALTER TABLE concepts DROP COLUMN video_storage');

        $run = new ReflectionMethod(MigrationRunner::class, 'runFile');
        $run->invoke(null, '002_concept_video_storage.sql');
        $rows = $pdo->query('SELECT id, video_storage FROM concepts ORDER BY id')->fetchAll();
        $this->assertSame('dreamobjects', $rows[0]['video_storage'], 'existing videos were all uploaded to DreamObjects');

        $noVideo = ConceptManagement::create($admin, $ids['subcategory_id'], ['title' => 'No video']);
        $run->invoke(null, '002_concept_video_storage.sql'); // second run: column exists, must not fail
        $this->assertNull($pdo->query('SELECT video_storage FROM concepts WHERE id = ' . (int)$noVideo)->fetchColumn(), 'rows without a video stay NULL');
        $this->assertSame('dreamobjects', $pdo->query('SELECT video_storage FROM concepts WHERE id = ' . (int)$ids['concept_id'])->fetchColumn());
    }

    public function testApplyRequiresAdminAndIgnoresUnknownFiles(): void
    {
        $user = new UserContext(999, false);
        try {
            MigrationRunner::apply(['001_initial_schema.sql'], $user);
            $this->fail('non-admins must not apply migrations');
        } catch (RuntimeException $e) {
            $this->assertSame('Admins only', $e->getMessage());
        }
        $result = MigrationRunner::apply(['../../etc/passwd', '999_nope.sql', '001_initial_schema.sql'], new UserContext(1, true));
        $this->assertSame([], $result['applied']);
        $this->assertSame(['001_initial_schema.sql'], $result['skipped']);
        $this->assertNull($result['failed']);
    }
}
