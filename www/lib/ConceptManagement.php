<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/Slugger.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/VideoStorage.php';

/**
 * Concepts ("Derivation of e^x"): the video, description and supporting links
 * a user publishes once they have mastered something. All SQL for the
 * concepts and concept_resources tables lives here.
 */
final class ConceptManagement {

    public const MAX_RESOURCES = 20;

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

    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM concepts WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * A concept with its ancestors' ids, names and slugs (category_*,
     * subcategory_*, user_id) so pages can build breadcrumbs and URLs in one
     * query.
     */
    public static function findWithAncestors(int $id): ?array {
        $st = self::pdo()->prepare(
            'SELECT k.*,
                    s.name AS subcategory_name, s.slug AS subcategory_slug,
                    c.id AS category_id, c.name AS category_name, c.slug AS category_slug,
                    c.user_id
             FROM concepts k
             JOIN subcategories s ON s.id = k.subcategory_id
             JOIN categories c ON c.id = s.category_id
             WHERE k.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function findBySlug(int $subcategoryId, string $slug): ?array {
        $st = self::pdo()->prepare('SELECT * FROM concepts WHERE subcategory_id = ? AND slug = ? LIMIT 1');
        $st->execute([$subcategoryId, $slug]);
        return $st->fetch() ?: null;
    }

    public static function ownerUserIdOf(int $conceptId): ?int {
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

    /** Concepts in a subcategory in display order; drafts only when asked for. */
    public static function listForSubcategory(int $subcategoryId, bool $includeUnpublished): array {
        $sql = 'SELECT * FROM concepts WHERE subcategory_id = ?'
             . ($includeUnpublished ? '' : ' AND is_published = 1')
             . ' ORDER BY sort_order, title';
        $st = self::pdo()->prepare($sql);
        $st->execute([$subcategoryId]);
        return $st->fetchAll();
    }

    /**
     * Previous and next concept (by display order) within the same
     * subcategory, for the concept page's footer navigation.
     * @return array{prev:?array,next:?array}
     */
    public static function neighbors(array $concept, bool $includeUnpublished): array {
        $all = self::listForSubcategory((int)$concept['subcategory_id'], $includeUnpublished);
        $prev = null;
        $next = null;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === (int)$concept['id']) {
                $prev = $all[$i - 1] ?? null;
                $next = $all[$i + 1] ?? null;
                break;
            }
        }
        return ['prev' => $prev, 'next' => $next];
    }

    public static function listResources(int $conceptId): array {
        $st = self::pdo()->prepare('SELECT * FROM concept_resources WHERE concept_id = ? ORDER BY sort_order, id');
        $st->execute([$conceptId]);
        return $st->fetchAll();
    }

    /** Every concept row that has a video, for the storage diagnostics page. */
    public static function listVideoObjectKeys(): array {
        $rows = self::pdo()->query('SELECT video_object_key FROM concepts WHERE video_object_key IS NOT NULL')->fetchAll();
        return array_map(static fn(array $r): string => (string)$r['video_object_key'], $rows);
    }

    public static function slugExists(int $subcategoryId, string $slug, ?int $exceptId = null): bool {
        $st = self::pdo()->prepare(
            'SELECT COUNT(*) FROM concepts WHERE subcategory_id = ? AND slug = ? AND (? IS NULL OR id <> ?)'
        );
        $st->execute([$subcategoryId, $slug, $exceptId, $exceptId]);
        return (int)$st->fetchColumn() > 0;
    }

    // ---- writes -----------------------------------------------------------

    /**
     * @param array{title:string,slug?:string,description_markdown?:string,is_published?:bool,resources?:array} $data
     */
    public static function create(?UserContext $ctx, int $subcategoryId, array $data): int {
        $ownerId = SubcategoryManagement::ownerUserIdOf($subcategoryId);
        if ($ownerId === null) {
            throw new RuntimeException('Subcategory not found.');
        }
        ContentAccess::assertCanEdit($ctx, $ownerId);
        [$title, $slug, $description] = self::validate($subcategoryId, $data, null);
        $resources = self::validateResources($data['resources'] ?? []);
        $publish = !empty($data['is_published']);

        $st = self::pdo()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM concepts WHERE subcategory_id = ?');
        $st->execute([$subcategoryId]);
        $sortOrder = (int)$st->fetchColumn();

        $st = self::pdo()->prepare(
            'INSERT INTO concepts (subcategory_id, slug, title, description_markdown, is_published, published_at, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$subcategoryId, $slug, $title, $description, $publish ? 1 : 0, $publish ? date('Y-m-d H:i:s') : null, $sortOrder]);
        $id = (int)self::pdo()->lastInsertId();
        self::writeResources($id, $resources);
        self::log('concept.create', ['concept_id' => $id, 'subcategory_id' => $subcategoryId, 'title' => $title, 'published' => $publish]);
        return $id;
    }

    public static function update(?UserContext $ctx, int $id, array $data): void {
        $concept = self::findById($id);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        [$title, $slug, $description] = self::validate((int)$concept['subcategory_id'], $data + $concept, $id);
        $sortOrder = array_key_exists('sort_order', $data) ? (int)$data['sort_order'] : (int)$concept['sort_order'];

        $st = self::pdo()->prepare(
            'UPDATE concepts SET title = ?, slug = ?, description_markdown = ?, sort_order = ? WHERE id = ?'
        );
        $st->execute([$title, $slug, $description, $sortOrder, $id]);

        if (array_key_exists('resources', $data)) {
            self::writeResources($id, self::validateResources($data['resources']));
        }
        if (array_key_exists('is_published', $data) && (bool)$data['is_published'] !== (bool)$concept['is_published']) {
            self::setPublished($ctx, $id, (bool)$data['is_published']);
        }
        self::log('concept.update', ['concept_id' => $id, 'title' => $title]);
    }

    public static function setPublished(?UserContext $ctx, int $id, bool $published): void {
        $concept = self::findById($id);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));

        $st = self::pdo()->prepare(
            'UPDATE concepts SET is_published = ?, published_at = CASE WHEN ? = 1 THEN COALESCE(published_at, NOW()) ELSE published_at END
             WHERE id = ?'
        );
        $st->execute([$published ? 1 : 0, $published ? 1 : 0, $id]);
        self::log($published ? 'concept.publish' : 'concept.unpublish', ['concept_id' => $id, 'title' => $concept['title']]);
    }

    /** Replace the supporting links as a set. */
    public static function replaceResources(?UserContext $ctx, int $id, array $rows): void {
        if (!self::findById($id)) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        self::writeResources($id, self::validateResources($rows));
        self::log('concept.resources_replace', ['concept_id' => $id, 'count' => count($rows)]);
    }

    /**
     * Record a video the browser has finished uploading to storage. The caller
     * (concept_video_attach_eval.php) has already verified the object with
     * VideoStorage::verifyUploadedObject(). A previous video is deleted from
     * storage, best effort.
     */
    public static function attachVideo(?UserContext $ctx, int $id, string $objectKey, string $contentType, int $sizeBytes): void {
        $concept = self::findById($id);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        if ($objectKey === '' || !VideoStorage::keyBelongsToConcept($objectKey, $id)) {
            throw new InvalidArgumentException('That video does not belong to this concept.');
        }

        $previous = (string)($concept['video_object_key'] ?? '');

        $st = self::pdo()->prepare(
            'UPDATE concepts SET video_object_key = ?, video_content_type = ?, video_size_bytes = ?, video_uploaded_at = NOW()
             WHERE id = ?'
        );
        $st->execute([$objectKey, $contentType, $sizeBytes, $id]);
        self::log('concept.video_attach', ['concept_id' => $id, 'object_key' => $objectKey, 'size_bytes' => $sizeBytes]);

        if ($previous !== '' && $previous !== $objectKey) {
            try {
                VideoStorage::deleteObject($previous);
            } catch (\Throwable $e) {
                self::log('concept.video_delete_failed', ['concept_id' => $id, 'object_key' => $previous, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Remove the video from the concept and delete it from storage. */
    public static function detachVideo(?UserContext $ctx, int $id): void {
        $concept = self::findById($id);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));
        $key = (string)($concept['video_object_key'] ?? '');
        if ($key === '') {
            return;
        }

        // Storage first: if the delete fails the row still points at a real
        // object and the user can retry; the reverse would leak the object.
        VideoStorage::deleteObject($key);

        $st = self::pdo()->prepare(
            'UPDATE concepts SET video_object_key = NULL, video_content_type = NULL, video_size_bytes = NULL, video_uploaded_at = NULL
             WHERE id = ?'
        );
        $st->execute([$id]);
        self::log('concept.video_detach', ['concept_id' => $id, 'object_key' => $key]);
    }

    /** Delete a concept (and its resources via cascade, and its video from storage). */
    public static function delete(?UserContext $ctx, int $id): void {
        $concept = self::findById($id);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        ContentAccess::assertCanEdit($ctx, (int)self::ownerUserIdOf($id));

        $key = (string)($concept['video_object_key'] ?? '');
        if ($key !== '') {
            VideoStorage::deleteObject($key);
        }

        $st = self::pdo()->prepare('DELETE FROM concepts WHERE id = ?');
        $st->execute([$id]);
        self::log('concept.delete', ['concept_id' => $id, 'title' => $concept['title'], 'object_key' => $key]);
    }

    // ---- validation -------------------------------------------------------

    private static function validate(int $subcategoryId, array $data, ?int $exceptId): array {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }
        if (mb_strlen($title) > 150) {
            throw new InvalidArgumentException('Title must be 150 characters or fewer.');
        }
        $slug = strtolower(trim((string)($data['slug'] ?? '')));
        if ($slug === '') {
            $base = Slugger::fromText($title);
            if ($base === '' || Slugger::isReserved($base)) {
                $base = $base === '' ? 'concept' : $base . '-1';
            }
            $slug = Slugger::makeUnique($base,
                static fn(string $s): bool => self::slugExists($subcategoryId, $s, $exceptId) || Slugger::isReserved($s));
        }
        if (($problem = Slugger::problemWith($slug)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (self::slugExists($subcategoryId, $slug, $exceptId)) {
            throw new InvalidArgumentException('Another concept here already uses the URL name "' . $slug . '".');
        }
        return [$title, $slug, (string)($data['description_markdown'] ?? '')];
    }

    /**
     * Normalize [{title, url}, ...]: rows with neither are dropped, a URL
     * without a title takes the URL as its title, and links must be http(s).
     * @return array<int,array{title:string,url:string}>
     */
    public static function validateResources(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            $url = trim((string)($row['url'] ?? ''));
            if ($title === '' && $url === '') {
                continue;
            }
            if ($url === '') {
                throw new InvalidArgumentException('Resource "' . $title . '" needs a link.');
            }
            if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Resource link "' . $url . '" must start with http:// or https://.');
            }
            if (strlen($url) > 2000) {
                throw new InvalidArgumentException('Resource link is too long.');
            }
            if ($title === '') {
                $title = $url;
            }
            if (mb_strlen($title) > 200) {
                throw new InvalidArgumentException('Resource titles must be 200 characters or fewer.');
            }
            $out[] = ['title' => $title, 'url' => $url];
            if (count($out) > self::MAX_RESOURCES) {
                throw new InvalidArgumentException('A concept can have at most ' . self::MAX_RESOURCES . ' resources.');
            }
        }
        return $out;
    }

    private static function writeResources(int $conceptId, array $resources): void {
        $pdo = self::pdo();
        $st = $pdo->prepare('DELETE FROM concept_resources WHERE concept_id = ?');
        $st->execute([$conceptId]);
        $ins = $pdo->prepare('INSERT INTO concept_resources (concept_id, title, url, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($resources as $i => $r) {
            $ins->execute([$conceptId, $r['title'], $r['url'], $i + 1]);
        }
    }
}
