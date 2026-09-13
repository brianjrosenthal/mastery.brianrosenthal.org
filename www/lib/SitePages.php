<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/ContentAccess.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/SiteUI.php';
require_once __DIR__ . '/CategoryManagement.php';
require_once __DIR__ . '/SubcategoryManagement.php';
require_once __DIR__ . '/ConceptManagement.php';

/**
 * Renders the four public pages of a user's site (home, category,
 * subcategory, concept) from a resolved site and a path. Called by
 * public_site.php (rewritten pretty URLs) and index.php (custom domain root).
 *
 * Visibility rules: drafts, and categories/subcategories with no published
 * concept, are invisible to the public. The owner (or an admin) sees
 * everything, with Draft badges and an owner toolbar whose actions are
 * specific to the page being viewed.
 */
final class SitePages {

    public static function render(array $resolved, string $path): void {
        $site = $resolved['site'];
        $basePath = (string)$resolved['base_path'];
        if ($site === null) {
            SiteUI::notFoundPage(null, '', 'There is no site here.');
            return;
        }

        $ctx = UserContext::getLoggedInUserContext();
        if ($ctx === null && current_user()) {
            // current_user() may have auto-logged-in from the remember cookie.
            $ctx = UserContext::getLoggedInUserContext();
        }
        $canEdit = ContentAccess::canEdit($ctx, (int)$site['user_id']);

        if (empty($site['is_public']) && !$canEdit) {
            SiteUI::notFoundPage($site, $basePath, 'This site is not public yet.');
            return;
        }

        $slugs = SiteResolver::splitPath($path);
        if ($slugs === null) {
            SiteUI::notFoundPage($site, $basePath, 'That page does not exist.');
            return;
        }
        [$catSlug, $subSlug, $conceptSlug] = $slugs;

        $userId = (int)$site['user_id'];
        $owner = UserManagement::findById($userId);
        $allCategories = CategoryManagement::listForUser($userId);
        $navCategories = array_values(array_filter($allCategories, static fn(array $c): bool => (int)$c['published_count'] > 0));
        $visibleCategories = $canEdit ? $allCategories : $navCategories;

        if ($catSlug === null) {
            self::home($site, $basePath, $canEdit, $owner, $visibleCategories, $navCategories);
            return;
        }

        $category = CategoryManagement::findBySlug($userId, $catSlug);
        if ($category === null || (!$canEdit && !self::categoryIsVisible($category['id'], $allCategories))) {
            SiteUI::notFoundPage($site, $basePath, 'That category does not exist.');
            return;
        }
        // listForCategory() rows carry concept_count/published_count, which the
        // pages and the owner bar need; the slug lookup above does not.
        $allSubcategories = SubcategoryManagement::listForCategory((int)$category['id']);
        $category['subcategory_count'] = count($allSubcategories);
        $subcategories = $canEdit
            ? $allSubcategories
            : array_values(array_filter($allSubcategories, static fn(array $s): bool => (int)$s['published_count'] > 0));

        if ($subSlug === null) {
            self::category($site, $basePath, $canEdit, $owner, $navCategories, $category, $subcategories);
            return;
        }

        $subcategory = null;
        foreach ($allSubcategories as $row) {
            if ($row['slug'] === $subSlug) {
                $subcategory = $row;
                break;
            }
        }
        if ($subcategory === null || (!$canEdit && !self::subcategoryIsVisible($subcategory['id'], $subcategories))) {
            SiteUI::notFoundPage($site, $basePath, 'That topic does not exist.');
            return;
        }
        $concepts = ConceptManagement::listForSubcategory((int)$subcategory['id'], $canEdit);

        if ($conceptSlug === null) {
            self::subcategory($site, $basePath, $canEdit, $owner, $navCategories, $category, $subcategory, $concepts);
            return;
        }

        $concept = ConceptManagement::findBySlug((int)$subcategory['id'], $conceptSlug);
        if ($concept === null || (!$canEdit && empty($concept['is_published']))) {
            SiteUI::notFoundPage($site, $basePath, 'That concept does not exist.');
            return;
        }
        self::concept($site, $basePath, $canEdit, $owner, $navCategories, $category, $subcategory, $concept);
    }

    private static function categoryIsVisible(int|string $id, array $categories): bool {
        foreach ($categories as $c) {
            if ((int)$c['id'] === (int)$id) {
                return (int)$c['published_count'] > 0;
            }
        }
        return false;
    }

    private static function subcategoryIsVisible(int|string $id, array $subcategories): bool {
        foreach ($subcategories as $s) {
            if ((int)$s['id'] === (int)$id) {
                return (int)$s['published_count'] > 0;
            }
        }
        return false;
    }

    private static function nextParam(string $url): string {
        return '&next=' . urlencode($url);
    }

    // ---- pages ------------------------------------------------------------

    private static function home(array $site, string $basePath, bool $canEdit, ?array $owner, array $categories, array $navCategories): void {
        $here = SiteResolver::urlFor($basePath);
        $actions = $canEdit ? [
            ['label' => 'Edit homepage', 'url' => '/manage/site_settings.php?site_id=' . (int)$site['id'] . self::nextParam($here)],
            ['label' => '+ Category', 'url' => '/manage/category_add.php?user_id=' . (int)$site['user_id'] . self::nextParam($here)],
        ] : [];
        SiteUI::headerHtml($site, $basePath, $canEdit, '', [], $actions, $navCategories);

        echo '<section class="hero">';
        echo '<h1 class="hero-title">' . htmlspecialchars((string)$site['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
        if (trim((string)$site['tagline']) !== '') {
            echo '<p class="hero-tagline">' . htmlspecialchars((string)$site['tagline'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '</section>';
        echo SiteUI::proseHtml((string)$site['homepage_markdown']);
        echo '<h2 class="section-title">Categories</h2>';
        echo SiteUI::categoryCardsHtml($categories, $basePath, $canEdit);

        SiteUI::footerHtml($site, $owner, $canEdit, $canEdit ? $actions[0]['url'] : null);
    }

    private static function category(array $site, string $basePath, bool $canEdit, ?array $owner, array $navCategories, array $category, array $subcategories): void {
        $here = SiteResolver::urlFor($basePath, (string)$category['slug']);
        $home = SiteResolver::urlFor($basePath);
        $actions = $canEdit ? [
            ['label' => 'Edit', 'url' => '/manage/category_edit.php?id=' . (int)$category['id'] . self::nextParam($here)],
            ['label' => '+ Subcategory', 'url' => '/manage/subcategory_add.php?category_id=' . (int)$category['id'] . self::nextParam($here)],
            (int)$category['subcategory_count'] === 0
                ? ['label' => 'Delete', 'method' => 'post', 'url' => '/manage/category_delete_eval.php', 'danger' => true,
                   'fields' => ['id' => (int)$category['id'], 'next' => $home],
                   'confirm' => 'Delete the category "' . $category['name'] . '"?']
                : ['label' => 'Delete', 'danger' => true, 'disabled' => 'Only an empty category can be deleted.'],
        ] : [];
        SiteUI::headerHtml($site, $basePath, $canEdit, (string)$category['name'], [['label' => 'Home', 'url' => $home]], $actions, $navCategories);

        echo '<h1 class="page-title">' . htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        echo SiteUI::proseHtml((string)$category['description_markdown']);

        if ($subcategories === []) {
            echo '<p class="site-empty">No topics here yet' . ($canEdit ? ' — add a subcategory from the bar above.' : '.') . '</p>';
        }
        foreach ($subcategories as $sub) {
            $subUrl = SiteResolver::urlFor($basePath, (string)$category['slug'], (string)$sub['slug']);
            $concepts = ConceptManagement::listForSubcategory((int)$sub['id'], $canEdit);
            echo '<section class="topic-section">';
            echo '<h2 class="topic-heading"><a href="' . htmlspecialchars($subUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string)$sub['name'], ENT_QUOTES, 'UTF-8') . '</a>'
               . '<span class="topic-count">' . SiteUI::countLabel((int)$sub['published_count'], 'concept') . '</span></h2>';
            $excerpt = MarkdownRenderer::excerpt((string)$sub['description_markdown'], 200);
            if ($excerpt !== '') {
                echo '<p class="topic-blurb">' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            echo SiteUI::conceptListHtml($concepts, $basePath, (string)$category['slug'], (string)$sub['slug'], $canEdit);
            echo '</section>';
        }

        SiteUI::footerHtml($site, $owner, $canEdit, $canEdit ? $actions[0]['url'] : null);
    }

    private static function subcategory(array $site, string $basePath, bool $canEdit, ?array $owner, array $navCategories, array $category, array $subcategory, array $concepts): void {
        $here = SiteResolver::urlFor($basePath, (string)$category['slug'], (string)$subcategory['slug']);
        $catUrl = SiteResolver::urlFor($basePath, (string)$category['slug']);
        $actions = $canEdit ? [
            ['label' => 'Edit', 'url' => '/manage/subcategory_edit.php?id=' . (int)$subcategory['id'] . self::nextParam($here)],
            ['label' => '+ Concept', 'url' => '/manage/concept_add.php?subcategory_id=' . (int)$subcategory['id'] . self::nextParam($here)],
            (int)$subcategory['concept_count'] === 0
                ? ['label' => 'Delete', 'method' => 'post', 'url' => '/manage/subcategory_delete_eval.php', 'danger' => true,
                   'fields' => ['id' => (int)$subcategory['id'], 'next' => $catUrl],
                   'confirm' => 'Delete the subcategory "' . $subcategory['name'] . '"?']
                : ['label' => 'Delete', 'danger' => true, 'disabled' => 'Only an empty subcategory can be deleted.'],
        ] : [];
        SiteUI::headerHtml($site, $basePath, $canEdit, (string)$subcategory['name'], [
            ['label' => 'Home', 'url' => SiteResolver::urlFor($basePath)],
            ['label' => (string)$category['name'], 'url' => $catUrl],
        ], $actions, $navCategories);

        echo '<p class="eyebrow">' . htmlspecialchars((string)$category['name'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<h1 class="page-title">' . htmlspecialchars((string)$subcategory['name'], ENT_QUOTES, 'UTF-8') . '</h1>';
        echo SiteUI::proseHtml((string)$subcategory['description_markdown']);
        echo '<h2 class="section-title">Concepts</h2>';
        echo SiteUI::conceptListHtml($concepts, $basePath, (string)$category['slug'], (string)$subcategory['slug'], $canEdit);

        SiteUI::footerHtml($site, $owner, $canEdit, $canEdit ? $actions[0]['url'] : null);
    }

    private static function concept(array $site, string $basePath, bool $canEdit, ?array $owner, array $navCategories, array $category, array $subcategory, array $concept): void {
        $subUrl = SiteResolver::urlFor($basePath, (string)$category['slug'], (string)$subcategory['slug']);
        $here = SiteResolver::urlFor($basePath, (string)$category['slug'], (string)$subcategory['slug'], (string)$concept['slug']);
        $published = !empty($concept['is_published']);
        $actions = $canEdit ? [
            ['label' => 'Edit', 'url' => '/manage/concept_edit.php?id=' . (int)$concept['id'] . self::nextParam($here)],
            ['label' => $published ? 'Unpublish' : 'Publish', 'method' => 'post', 'url' => '/manage/concept_publish_eval.php',
             'fields' => ['id' => (int)$concept['id'], 'publish' => $published ? 0 : 1, 'next' => $here]],
            ['label' => 'Delete', 'method' => 'post', 'url' => '/manage/concept_delete_eval.php', 'danger' => true,
             'fields' => ['id' => (int)$concept['id'], 'next' => $subUrl],
             'confirm' => 'Delete the concept "' . $concept['title'] . '" and its video? This cannot be undone.'],
        ] : [];
        SiteUI::headerHtml($site, $basePath, $canEdit, (string)$concept['title'], [
            ['label' => 'Home', 'url' => SiteResolver::urlFor($basePath)],
            ['label' => (string)$category['name'], 'url' => SiteResolver::urlFor($basePath, (string)$category['slug'])],
            ['label' => (string)$subcategory['name'], 'url' => $subUrl],
        ], $actions, $navCategories);

        echo '<article class="concept">';
        echo '<p class="eyebrow">' . htmlspecialchars((string)$subcategory['name'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<h1 class="page-title">' . htmlspecialchars((string)$concept['title'], ENT_QUOTES, 'UTF-8');
        if (!$published) {
            echo ' <span class="badge-draft">Draft</span>';
        }
        echo '</h1>';
        echo SiteUI::videoPlayerHtml($concept, $canEdit);
        echo SiteUI::proseHtml((string)$concept['description_markdown']);
        echo SiteUI::resourcesHtml(ConceptManagement::listResources((int)$concept['id']));
        echo '</article>';

        $neighbors = ConceptManagement::neighbors($concept, $canEdit);
        echo SiteUI::prevNextHtml($neighbors['prev'], $neighbors['next'], $basePath, (string)$category['slug'], (string)$subcategory['slug']);

        SiteUI::footerHtml($site, $owner, $canEdit, $canEdit ? $actions[0]['url'] : null);
    }
}
