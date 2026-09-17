<?php
declare(strict_types=1);

// Test bootstrap: load the app, then point every pdo() call at a dedicated
// test database (recreated from schema.sql on every run) so tests never touch
// the real database. Video storage is replaced by an in-memory fake.

require_once __DIR__ . '/../../www/config.php';
require_once __DIR__ . '/../../www/lib/UserManagement.php';
require_once __DIR__ . '/../../www/lib/ActivityLog.php';
require_once __DIR__ . '/../../www/lib/Slugger.php';
require_once __DIR__ . '/../../www/lib/MarkdownRenderer.php';
require_once __DIR__ . '/../../www/lib/SiteManagement.php';
require_once __DIR__ . '/../../www/lib/SiteResolver.php';
require_once __DIR__ . '/../../www/lib/CategoryManagement.php';
require_once __DIR__ . '/../../www/lib/SubcategoryManagement.php';
require_once __DIR__ . '/../../www/lib/ConceptManagement.php';
require_once __DIR__ . '/../../www/lib/S3Client.php';
require_once __DIR__ . '/../../www/lib/VideoStorage.php';
require_once __DIR__ . '/../../www/lib/VideoMigration.php';
require_once __DIR__ . '/../../www/lib/MigrationRunner.php';
require_once __DIR__ . '/Support/FakeS3Client.php';

const TEST_DB_NAME = 'mastery_brianrosenthal_test';

$server = new PDO(
    'mysql:host=' . DB_HOST . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
$server->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$testPdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$testPdo->exec((string)file_get_contents(__DIR__ . '/../../www/schema.sql'));

set_pdo_for_testing($testPdo);

// Storage: never touch the network from tests. One fake per provider, with
// distinct endpoints so a URL shows which provider it addresses.
VideoStorage::storage('r2', new FakeS3Client('https://r2-test.example', 'auto'));
VideoStorage::storage('dreamobjects', new FakeS3Client('https://objects-test.dream.io', 'us-east-1'));

// Helper for tests: wipe all domain tables back to a clean slate.
function test_reset_all(): void {
    $pdo = pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'activity_log', 'emails_sent',
        'concept_resources', 'concepts', 'subcategories', 'categories', 'sites',
        'users',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (VideoStorage::providers() as $provider) {
        VideoStorage::storage($provider)->reset();
    }
}

// Helper for tests: seed a verified admin and return their UserContext.
function test_seed_admin(string $email = 'admin@example.com'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES ('Admin', 'User', ?, 'hash', 1, NOW())"
    );
    $st->execute([$email]);
    $ctx = new UserContext((int)pdo()->lastInsertId(), true);
    UserContext::set($ctx);
    return $ctx;
}

// Helper for tests: seed a verified non-admin and return their UserContext.
function test_seed_user(string $email = 'user@example.com', string $firstName = 'Regular'): UserContext {
    $st = pdo()->prepare(
        "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verified_at)
         VALUES (?, 'User', ?, 'hash', 0, NOW())"
    );
    $st->execute([$firstName, $email]);
    return new UserContext((int)pdo()->lastInsertId(), false);
}

// Helper for tests: a site plus one category, subcategory and concept for a user.
// Returns ['site_id', 'category_id', 'subcategory_id', 'concept_id'].
function test_seed_tree(UserContext $owner, string $slugHint = 'charlie'): array {
    $siteId = SiteManagement::createForUser($owner, $owner->id, $slugHint, ucfirst($slugHint) . "'s Mastery");
    $categoryId = CategoryManagement::create($owner, $owner->id, ['name' => 'Algebra II']);
    $subcategoryId = SubcategoryManagement::create($owner, $categoryId, ['name' => 'Sequences and Series']);
    $conceptId = ConceptManagement::create($owner, $subcategoryId, ['title' => 'Derivation of e^x']);
    return ['site_id' => $siteId, 'category_id' => $categoryId, 'subcategory_id' => $subcategoryId, 'concept_id' => $conceptId];
}
