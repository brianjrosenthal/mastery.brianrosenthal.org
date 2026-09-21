<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ApplicationUI.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/MarkdownRenderer.php';
require_once __DIR__ . '/VideoStorage.php';

/**
 * Chrome and reusable fragments for a user's PUBLIC site (what visitors to
 * mastery.charlierosenthal.org see). Distinct from ApplicationUI, which is the
 * authoring/admin chrome. Every fragment is a function returning HTML so the
 * same markup can be reused by pages and AJAX endpoints alike.
 */
final class SiteUI {

    private static function h($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** Inline CSS variables for the site's colour scheme. */
    public static function accentStyle(array $site): string {
        $accent = SiteManagement::ACCENTS[$site['accent_color']] ?? SiteManagement::ACCENTS['blue'];
        return ':root{--accent:' . $accent['color'] . ';--accent-dark:' . $accent['dark']
             . ';--accent-light:' . $accent['light'] . ';--accent-soft:' . $accent['soft'] . ';}';
    }

    /**
     * @param array  $site        sites row
     * @param string $basePath    '' or '/site/{slug}'
     * @param bool   $canEdit     viewer is the owner or an admin
     * @param string $title       page title (without the site name)
     * @param array  $breadcrumbs [['label' => ..., 'url' => ...], ...] excluding the current page
     * @param array  $ownerActions [['label' => ..., 'url' => ..., 'method' => 'get'|'post', 'confirm' => ?, 'danger' => bool, 'disabled' => ?string], ...]
     * @param array  $categories  the site's categories for the nav (public ones)
     */
    public static function headerHtml(array $site, string $basePath, bool $canEdit, string $title, array $breadcrumbs, array $ownerActions, array $categories): void {
        $siteTitle = (string)$site['title'];
        $fullTitle = $title === '' ? $siteTitle : $title . ' · ' . $siteTitle;
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . self::h($fullTitle) . '</title>';
        echo ApplicationUI::cssLink('/site.css');
        echo '<style>' . self::accentStyle($site) . '</style>';
        echo '</head><body class="site">';

        if ($canEdit) {
            echo self::ownerToolbarHtml($site, $ownerActions);
        }

        echo '<header class="site-header"><div class="site-inner">';
        echo '<a class="site-brand" href="' . self::h($basePath . '/') . '">' . self::h($siteTitle) . '</a>';
        if ($categories !== []) {
            echo '<nav class="site-nav" aria-label="Categories">';
            foreach ($categories as $cat) {
                echo '<a href="' . self::h(SiteResolver::urlFor($basePath, (string)$cat['slug'])) . '">' . self::h($cat['name']) . '</a>';
            }
            echo '</nav>';
        }
        echo '</div></header>';

        echo '<div class="site-inner"><main class="site-main">';
        if ($breadcrumbs !== []) {
            echo '<nav class="crumbs" aria-label="Breadcrumb">';
            foreach ($breadcrumbs as $i => $crumb) {
                if ($i > 0) {
                    echo '<span class="crumb-sep" aria-hidden="true">›</span>';
                }
                echo '<a href="' . self::h($crumb['url']) . '">' . self::h($crumb['label']) . '</a>';
            }
            echo '<span class="crumb-sep" aria-hidden="true">›</span><span class="crumb-current">' . self::h($title) . '</span>';
            echo '</nav>';
        }
    }

    /**
     * @param bool    $canEdit  viewer is the owner or an admin
     * @param ?string $editUrl  editor for the current page (shown as "Edit this page")
     */
    public static function footerHtml(array $site, ?array $owner = null, bool $canEdit = false, ?string $editUrl = null): void {
        $by = $owner ? trim((string)($owner['first_name'] ?? '')) : '';
        $here = (string)($_SERVER['REQUEST_URI'] ?? '/');
        echo '</main></div>';
        echo '<footer class="site-footer"><div class="site-inner">';
        echo '<span>' . self::h($site['title']) . ($by !== '' ? ' · made by ' . self::h($by) : '') . '</span>';
        echo '<span class="site-footer-links">';
        if (current_user()) {
            if ($canEdit && $editUrl !== null) {
                echo '<a href="' . self::h($editUrl) . '">Edit this page</a>';
            }
            if ($canEdit) {
                echo '<a href="/manage/?user_id=' . (int)$site['user_id'] . '">Manage</a>';
            }
            echo '<a href="/logout.php">Log out</a>';
        } else {
            // Cookies are per hostname, so the owner may be signed in on the
            // main site yet anonymous here; this is their way in.
            echo '<a href="/login.php?next=' . self::h(urlencode($here)) . '">Log in</a>';
        }
        echo '</span>';
        echo '</div></footer>';
        echo ApplicationUI::jsScript('/main.js');
        echo '</body></html>';
    }

    /** The slim bar an owner/admin sees above their own site. */
    public static function ownerToolbarHtml(array $site, array $actions): string {
        $html = '<div class="owner-bar"><div class="site-inner owner-bar-inner">';
        $html .= '<span class="owner-bar-label">Your site</span>';
        $html .= '<a class="owner-bar-link" href="/manage/?user_id=' . (int)$site['user_id'] . '">Manage</a>';
        foreach ($actions as $a) {
            $label = self::h($a['label']);
            $cls = 'owner-bar-link' . (!empty($a['danger']) ? ' danger' : '');
            if (!empty($a['disabled'])) {
                $html .= '<span class="' . $cls . ' disabled" title="' . self::h($a['disabled']) . '">' . $label . '</span>';
                continue;
            }
            if (($a['method'] ?? 'get') === 'post') {
                $html .= '<form method="post" action="' . self::h($a['url']) . '" class="owner-bar-form">'
                       . '<input type="hidden" name="csrf" value="' . self::h(csrf_token()) . '">';
                foreach ($a['fields'] ?? [] as $name => $value) {
                    $html .= '<input type="hidden" name="' . self::h($name) . '" value="' . self::h($value) . '">';
                }
                $html .= '<button type="submit" class="' . $cls . '"' . (!empty($a['confirm']) ? ' data-confirm="' . self::h($a['confirm']) . '"' : '') . '>' . $label . '</button></form>';
            } else {
                $html .= '<a class="' . $cls . '" href="' . self::h($a['url']) . '">' . $label . '</a>';
            }
        }
        $html .= '<a class="owner-bar-link owner-bar-right" href="/logout.php">Log out</a>';
        $html .= '</div></div>';
        return $html;
    }

    /** Rendered Markdown in the site's prose style, or '' when empty. */
    public static function proseHtml(string $markdown): string {
        $html = MarkdownRenderer::toHtml($markdown);
        return $html === '' ? '' : '<div class="prose">' . $html . '</div>';
    }

    /** Home page: category cards. Hidden-from-public categories are only passed in for owners. */
    public static function categoryCardsHtml(array $categories, string $basePath, bool $canEdit): string {
        if ($categories === []) {
            return '<p class="site-empty">Nothing here yet' . ($canEdit ? ' — add your first category from the bar above.' : '.') . '</p>';
        }
        $html = '<div class="card-grid">';
        foreach ($categories as $cat) {
            $count = (int)$cat['published_count'];
            $url = SiteResolver::urlFor($basePath, (string)$cat['slug']);
            $html .= '<a class="topic-card' . ($count === 0 ? ' is-empty' : '') . '" href="' . self::h($url) . '">';
            $html .= '<span class="topic-card-title">' . self::h($cat['name']) . '</span>';
            $excerpt = MarkdownRenderer::excerpt((string)$cat['description_markdown']);
            if ($excerpt !== '') {
                $html .= '<span class="topic-card-text">' . self::h($excerpt) . '</span>';
            }
            $html .= '<span class="topic-card-meta">' . self::countLabel($count, 'concept')
                   . ((int)$cat['subcategory_count'] > 0 ? ' · ' . self::countLabel((int)$cat['subcategory_count'], 'topic') : '')
                   . ($count === 0 && $canEdit ? ' · <em>not visible to the public yet</em>' : '')
                   . '</span>';
            $html .= '</a>';
        }
        return $html . '</div>';
    }

    /** A subcategory's concepts as a list of rows. */
    public static function conceptListHtml(array $concepts, string $basePath, string $categorySlug, string $subcategorySlug, bool $canEdit): string {
        if ($concepts === []) {
            return '<p class="site-empty">No concepts here yet' . ($canEdit ? ' — add one from the bar above.' : '.') . '</p>';
        }
        $html = '<ol class="concept-list">';
        foreach ($concepts as $c) {
            $url = SiteResolver::urlFor($basePath, $categorySlug, $subcategorySlug, (string)$c['slug']);
            $html .= '<li><a class="concept-row" href="' . self::h($url) . '">';
            $html .= '<span class="concept-row-icon" aria-hidden="true">' . (!empty($c['video_object_key']) ? '&#9654;' : '&#9998;') . '</span>';
            $html .= '<span class="concept-row-body"><span class="concept-row-title">' . self::h($c['title']) . '</span>';
            $excerpt = MarkdownRenderer::excerpt((string)$c['description_markdown'], 110);
            if ($excerpt !== '') {
                $html .= '<span class="concept-row-text">' . self::h($excerpt) . '</span>';
            }
            $html .= '</span>';
            if ($canEdit && empty($c['is_published'])) {
                $html .= '<span class="badge-draft">Draft</span>';
            }
            $html .= '</a></li>';
        }
        return $html . '</ol>';
    }

    /** The concept's video player, or an owner-only note when none is attached. */
    public static function videoPlayerHtml(array $concept, bool $canEdit): string {
        $key = (string)($concept['video_object_key'] ?? '');
        if ($key === '') {
            if (!$canEdit) {
                return '';
            }
            return '<div class="video-missing">No video yet. <a href="/manage/concept_edit.php?id=' . (int)$concept['id'] . '#video">Record or upload one</a> from the editor.</div>';
        }
        $src = VideoStorage::playbackUrlForConcept($concept);
        $type = (string)($concept['video_content_type'] ?? '');
        // autoplay is attempted with sound; main.js falls back to muted playback
        // (with an Unmute button) where the browser blocks audible autoplay.
        return '<div class="video-frame"><video controls playsinline autoplay preload="auto" data-autoplay src="' . self::h($src) . '"'
             . ($type !== '' ? ' type="' . self::h($type) . '"' : '') . '>'
             . 'Your browser cannot play this video. <a href="' . self::h($src) . '">Download it</a> instead.'
             . '</video></div>';
    }

    public static function resourcesHtml(array $resources): string {
        if ($resources === []) {
            return '';
        }
        $html = '<section class="resources"><h2>Resources</h2><ul class="resource-list">';
        foreach ($resources as $r) {
            $host = (string)(parse_url((string)$r['url'], PHP_URL_HOST) ?: '');
            $html .= '<li><a href="' . self::h($r['url']) . '" target="_blank" rel="noopener">' . self::h($r['title']) . '</a>'
                   . ($host !== '' ? ' <span class="resource-host">' . self::h(preg_replace('/^www\./', '', $host)) . '</span>' : '')
                   . '</li>';
        }
        return $html . '</ul></section>';
    }

    /** Previous / next links at the bottom of a concept page. */
    public static function prevNextHtml(?array $prev, ?array $next, string $basePath, string $categorySlug, string $subcategorySlug): string {
        if ($prev === null && $next === null) {
            return '';
        }
        $html = '<nav class="prev-next" aria-label="More in this topic">';
        if ($prev !== null) {
            $html .= '<a class="prev" href="' . self::h(SiteResolver::urlFor($basePath, $categorySlug, $subcategorySlug, (string)$prev['slug'])) . '"><span>Previous</span>' . self::h($prev['title']) . '</a>';
        } else {
            $html .= '<span></span>';
        }
        if ($next !== null) {
            $html .= '<a class="next" href="' . self::h(SiteResolver::urlFor($basePath, $categorySlug, $subcategorySlug, (string)$next['slug'])) . '"><span>Next</span>' . self::h($next['title']) . '</a>';
        }
        return $html . '</nav>';
    }

    public static function countLabel(int $n, string $noun): string {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }

    /** A complete, styled "not found" page (also used for private sites). */
    public static function notFoundPage(?array $site, string $basePath, string $message): void {
        http_response_code(404);
        $title = $site ? (string)$site['title'] : (defined('APP_NAME') ? APP_NAME : 'Kids That Teach');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Not found · ' . self::h($title) . '</title>';
        echo ApplicationUI::cssLink('/site.css');
        if ($site) {
            echo '<style>' . self::accentStyle($site) . '</style>';
        }
        echo '</head><body class="site"><div class="site-inner"><main class="site-main not-found">';
        echo '<p class="not-found-code">404</p><h1>' . self::h($message) . '</h1>';
        echo '<p><a class="button-link" href="' . self::h($site ? $basePath . '/' : '/') . '">' . ($site ? 'Back to ' . self::h($title) : 'Home') . '</a></p>';
        echo '</main></div></body></html>';
    }
}
