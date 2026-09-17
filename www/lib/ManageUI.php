<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserManagement.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/SiteResolver.php';
require_once __DIR__ . '/VideoStorage.php';
require_once __DIR__ . '/ConceptManagement.php';

/**
 * Helpers shared by the /manage/ authoring pages: whose content is being
 * edited, form round-tripping, and the reusable fragments (Markdown editor,
 * resources editor, content tree, video panel).
 */
final class ManageUI {

    /**
     * The user whose content this page manages. Admins may pass ?user_id= to
     * work on someone else's site; everyone else always gets themselves.
     * Exits with 403/404 when the request is not allowed.
     */
    public static function targetUser(?string $requestedUserId): array {
        $me = current_user();
        $requested = (int)($requestedUserId ?? 0);
        if ($requested <= 0 || $requested === (int)$me['id']) {
            return $me;
        }
        if (empty($me['is_admin'])) {
            http_response_code(403);
            die('You can only manage your own site.');
        }
        $user = UserManagement::findById($requested);
        if (!$user) {
            http_response_code(404);
            die('User not found.');
        }
        return $user;
    }

    /** "?user_id=N" when managing someone other than the current user, else ''. */
    public static function userParam(int $userId): string {
        $me = current_user();
        return ((int)$me['id'] === $userId) ? '' : '?user_id=' . $userId;
    }

    public static function dashboardUrl(int $userId): string {
        $p = self::userParam($userId);
        return '/manage/' . $p;
    }

    /** Admin-only dropdown to jump between users' sites. */
    public static function siteSwitcherHtml(int $currentUserId): string {
        $me = current_user();
        if (empty($me['is_admin'])) {
            return '';
        }
        $users = UserManagement::listUsers();
        $html = '<form method="get" action="/manage/" class="site-switcher" data-auto-submit>'
              . '<label class="inline"><span class="small">Managing:</span> <select name="user_id">';
        foreach ($users as $u) {
            $name = trim((string)$u['first_name'] . ' ' . (string)$u['last_name']);
            $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === $currentUserId ? ' selected' : '') . '>'
                   . h($name) . '</option>';
        }
        $html .= '</select></label></form>';
        return $html;
    }

    // ---- form round-tripping ---------------------------------------------

    /**
     * Keep a failed form's data (which may include long Markdown) in the
     * session for the redirect back, instead of the query string.
     */
    public static function stashForm(string $key, array $data, string $error): void {
        $_SESSION['form_' . $key] = ['data' => $data, 'err' => $error];
    }

    /** @return array{data:array,err:?string} */
    public static function takeForm(string $key): array {
        $stash = $_SESSION['form_' . $key] ?? null;
        unset($_SESSION['form_' . $key]);
        if (!is_array($stash)) {
            return ['data' => [], 'err' => null];
        }
        return ['data' => (array)($stash['data'] ?? []), 'err' => (string)($stash['err'] ?? '') ?: null];
    }

    /** The validated in-context return URL from ?next= / POST next, or $default. */
    public static function nextOr(string $default): string {
        $next = validate_relative_next_path($_POST['next'] ?? $_GET['next'] ?? '');
        return $next !== '' ? $next : $default;
    }

    public static function nextInputHtml(): string {
        $next = validate_relative_next_path($_GET['next'] ?? '');
        return $next !== '' ? '<input type="hidden" name="next" value="' . h($next) . '">' : '';
    }

    /** "&next=..." to forward the current next= to another page's link. */
    public static function nextParam(): string {
        $next = validate_relative_next_path($_GET['next'] ?? '');
        return $next !== '' ? '&next=' . urlencode($next) : '';
    }

    // ---- fragments --------------------------------------------------------

    public static function markdownFieldHtml(string $name, string $label, string $value, string $hint = ''): string {
        $id = 'md_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        return '<div class="md-field"><label for="' . h($id) . '">' . h($label)
             . ($hint !== '' ? ' <span class="hint">' . h($hint) . '</span>' : '') . '</label>'
             . '<textarea class="markdown" id="' . h($id) . '" name="' . h($name) . '" rows="10" data-markdown-field>' . h($value) . '</textarea>'
             . '<div class="md-toolbar"><button type="button" class="button small" data-md-preview-for="' . h($id) . '">Preview</button>'
             . '<span class="small">Markdown: **bold**, *italics*, # headings, - lists, [links](https://…)</span></div>'
             . '<div class="md-preview prose" id="' . h($id) . '_preview" aria-live="polite"></div></div>';
    }

    /** Resource rows (title + URL) with add/remove; manage.js wires the buttons. */
    public static function resourcesEditorHtml(array $resources): string {
        $rows = array_values($resources);
        if ($rows === []) {
            $rows[] = ['title' => '', 'url' => ''];
        }
        $html = '<div class="resource-rows" id="resource-rows">';
        foreach ($rows as $i => $r) {
            $html .= self::resourceRowHtml($i, (string)($r['title'] ?? ''), (string)($r['url'] ?? ''));
        }
        $html .= '</div>';
        $html .= '<template id="resource-row-template">' . self::resourceRowHtml(9999, '', '') . '</template>';
        $html .= '<div class="actions" style="margin-top:8px"><button type="button" class="button small" id="resource-add">+ Add link</button></div>';
        return $html;
    }

    private static function resourceRowHtml(int $i, string $title, string $url): string {
        return '<div class="resource-row">'
             . '<input type="text" name="resources[' . $i . '][title]" value="' . h($title) . '" placeholder="Title (e.g. Khan Academy lesson)" maxlength="200">'
             . '<input type="url" name="resources[' . $i . '][url]" value="' . h($url) . '" placeholder="https://…" maxlength="2000">'
             . '<button type="button" class="remove" title="Remove" aria-label="Remove link">&times;</button>'
             . '</div>';
    }

    /**
     * The dashboard tree: categories → subcategories → concepts, each with
     * links to its public page and its editor.
     */
    public static function treeHtml(array $tree, array $site, int $userId): string {
        $base = SiteResolver::basePathFor($site);
        $dash = self::dashboardUrl($userId);
        $csrf = h(csrf_token());
        if ($tree === []) {
            return '<p class="muted">No categories yet. Start with something you are learning, like "Algebra II".</p>'
                 . '<p><a class="button primary" href="/manage/category_add.php?user_id=' . $userId . '">Add your first category</a></p>';
        }
        $html = '<ul class="tree">';
        foreach ($tree as $cat) {
            $catUrl = SiteResolver::urlFor($base, (string)$cat['slug']);
            $html .= '<li><div class="tree-node level-1">'
                   . '<a class="title" href="' . h($catUrl) . '">' . h($cat['name']) . '</a>'
                   . '<span class="small">' . (int)$cat['published_count'] . '/' . (int)$cat['concept_count'] . ' published</span>'
                   . '<span class="tools">'
                   . '<a href="/manage/category_edit.php?id=' . (int)$cat['id'] . '">Edit</a>'
                   . '<a href="/manage/subcategory_add.php?category_id=' . (int)$cat['id'] . '">+ Subcategory</a>'
                   . ((int)$cat['subcategory_count'] === 0
                        ? '<form method="post" action="/manage/category_delete_eval.php" style="display:inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . (int)$cat['id'] . '"><input type="hidden" name="next" value="' . h($dash) . '"><button type="submit" class="danger" data-confirm="Delete the category &quot;' . h($cat['name']) . '&quot;?">Delete</button></form>'
                        : '')
                   . '</span></div>';
            foreach ($cat['subcategories'] as $sub) {
                $subUrl = SiteResolver::urlFor($base, (string)$cat['slug'], (string)$sub['slug']);
                $html .= '<div class="tree-node level-2">'
                       . '<a class="title" href="' . h($subUrl) . '">' . h($sub['name']) . '</a>'
                       . '<span class="small">' . (int)$sub['published_count'] . '/' . (int)$sub['concept_count'] . ' published</span>'
                       . '<span class="tools">'
                       . '<a href="/manage/subcategory_edit.php?id=' . (int)$sub['id'] . '">Edit</a>'
                       . '<a href="/manage/concept_add.php?subcategory_id=' . (int)$sub['id'] . '">+ Concept</a>'
                       . ((int)$sub['concept_count'] === 0
                            ? '<form method="post" action="/manage/subcategory_delete_eval.php" style="display:inline"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="id" value="' . (int)$sub['id'] . '"><input type="hidden" name="next" value="' . h($dash) . '"><button type="submit" class="danger" data-confirm="Delete the subcategory &quot;' . h($sub['name']) . '&quot;?">Delete</button></form>'
                            : '')
                       . '</span></div>';
                foreach ($sub['concepts'] as $c) {
                    $cUrl = SiteResolver::urlFor($base, (string)$cat['slug'], (string)$sub['slug'], (string)$c['slug']);
                    $html .= '<div class="tree-node level-3">'
                           . '<a class="title" href="' . h($cUrl) . '">' . h($c['title']) . '</a>'
                           . (!empty($c['is_published']) ? '<span class="badge published">Published</span>' : '<span class="badge draft">Draft</span>')
                           . (!empty($c['video_object_key']) ? '<span class="badge video">&#9654; Video</span>' : '<span class="badge novideo">No video</span>')
                           . '<span class="tools"><a href="/manage/concept_edit.php?id=' . (int)$c['id'] . '">Edit</a></span>'
                           . '</div>';
                }
                if ($sub['concepts'] === []) {
                    $html .= '<div class="tree-add level-3"><a href="/manage/concept_add.php?subcategory_id=' . (int)$sub['id'] . '">+ Add the first concept</a></div>';
                }
            }
            if ($cat['subcategories'] === []) {
                $html .= '<div class="tree-add level-2"><a href="/manage/subcategory_add.php?category_id=' . (int)$cat['id'] . '">+ Add the first subcategory</a></div>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        $html .= '<div class="tree-add level-1"><a class="button small" href="/manage/category_add.php?user_id=' . $userId . '">+ Add category</a></div>';
        return $html;
    }

    /**
     * The video panel on the concept editor. Returned as a fragment so
     * concept_video_attach_eval.php can hand back the refreshed panel after an
     * upload without a page reload.
     */
    public static function videoPanelHtml(array $concept): string {
        $id = (int)$concept['id'];
        $key = (string)($concept['video_object_key'] ?? '');
        $html = '<div class="video-panel" id="video-panel" data-concept-id="' . $id . '"'
              . ' data-max-bytes="' . VideoStorage::maxBytes() . '"'
              . ' data-configured="' . (VideoStorage::isConfigured() ? '1' : '0') . '">';
        $html .= '<h3 id="video">Video</h3>';

        if ($key !== '') {
            $src = VideoStorage::playbackUrlForConcept($concept);
            $html .= '<div class="video-current">'
                   . '<video controls playsinline preload="metadata" src="' . h($src) . '"></video>'
                   . '<p class="small">' . h(VideoStorage::humanBytes((int)$concept['video_size_bytes'])) . ' · '
                   . h((string)$concept['video_content_type']) . ' · ' . h(VideoStorage::providerLabel(VideoStorage::providerOf($concept))) . ' · uploaded '
                   . h(date('M j, Y g:i A', strtotime((string)$concept['video_uploaded_at']))) . '</p>'
                   . '<form method="post" action="/manage/concept_video_remove_eval.php" class="actions">'
                   . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
                   . '<input type="hidden" name="id" value="' . $id . '">'
                   . '<button type="submit" class="button small danger" data-confirm="Remove this video? It will be deleted from storage.">Remove video</button>'
                   . '<span class="small">Or replace it below.</span>'
                   . '</form></div>';
        }

        if (!VideoStorage::isConfigured()) {
            $html .= '<p class="notice">Video storage is not configured yet, so uploads are disabled. An admin can set it up under Admin → Video Storage.</p>';
            return $html . '</div>';
        }

        $html .= '<div class="tabs" role="tablist">'
               . '<button type="button" class="tab active" data-tab="upload" role="tab">Upload a file</button>'
               . '<button type="button" class="tab" data-tab="record" role="tab">Record in the browser</button>'
               . '</div>';
        $html .= '<div data-tab-panel="upload">'
               . '<div class="dropzone" id="video-dropzone">'
               . '<p><strong>Drop a video here</strong> or <label style="display:inline;font-weight:700;color:var(--color-primary);cursor:pointer">choose a file<input type="file" id="video-file" accept="video/mp4,video/webm,video/quicktime,.mp4,.mov,.webm,.m4v" style="display:none"></label></p>'
               . '<p class="small">MP4, MOV or WebM up to ' . h(VideoStorage::humanBytes(VideoStorage::maxBytes())) . '. Phone recordings work fine.</p>'
               . '</div></div>';
        $html .= '<div data-tab-panel="record" class="hidden">'
               . '<video id="rec-preview" playsinline muted autoplay></video>'
               . '<div class="rec-controls">'
               . '<button type="button" class="button" id="rec-start">Start camera</button>'
               . '<button type="button" class="button primary hidden" id="rec-record">&#9679; Record</button>'
               . '<button type="button" class="button danger hidden" id="rec-stop">&#9632; Stop</button>'
               . '<span class="rec-timer hidden" id="rec-timer"><span class="rec-dot"></span>00:00</span>'
               . '</div>'
               . '<div class="rec-controls hidden" id="rec-review">'
               . '<button type="button" class="button primary" id="rec-use">Use this recording</button>'
               . '<button type="button" class="button" id="rec-again">Record again</button>'
               . '</div>'
               . '<p class="small" id="rec-help">Your browser will ask for camera and microphone permission. Recording stays on this device until you click "Use this recording".</p>'
               . '</div>';
        $html .= '<div class="upload-progress hidden" id="upload-progress"><span></span></div>'
               . '<div class="upload-status" id="upload-status" aria-live="polite"></div>';
        return $html . '</div>';
    }
}
