<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/SiteManagement.php';
require_once __DIR__ . '/SiteResolver.php';

/**
 * Page chrome for the authoring/admin side of the app (everything except the
 * public sites, which SiteUI renders): the top bar with brand, nav, the Admin
 * dropdown and the profile menu, and the <main> wrapper.
 */
class ApplicationUI {

    /** The site whose colour scheme this page should wear (see useSiteTheme). */
    private static ?array $themeSite = null;

    /**
     * Make the authoring chrome wear a site's colour scheme. Pages that manage
     * a specific site call this; otherwise headerHtml() falls back to the site
     * of the current hostname, then the signed-in user's own site.
     */
    public static function useSiteTheme(?array $site): void {
        self::$themeSite = $site;
    }

    /** Inline CSS variables overriding the app's blue with a site's scheme, or ''. */
    public static function siteThemeStyle(?array $site): string {
        if ($site === null) {
            return '';
        }
        $accent = SiteManagement::ACCENTS[$site['accent_color']] ?? null;
        if ($accent === null || $site['accent_color'] === 'blue') {
            return '';
        }
        return '<style>:root{--color-primary:' . $accent['color'] . ';--color-primary-dark:' . $accent['dark']
             . ';--color-primary-light:' . $accent['light'] . ';--color-primary-lighter:' . $accent['light']
             . ';--color-primary-soft:' . $accent['soft'] . ';}</style>';
    }

    /**
     * Generate a cache-busted URL for a static resource
     */
    public static function staticResourceUrl(string $path): string {
        $filePath = __DIR__ . '/../' . ltrim($path, '/');
        $version = @filemtime($filePath);
        if (!$version) {
            $version = date('Ymd');
        }
        return $path . '?v=' . $version;
    }

    /**
     * Generate a complete CSS link tag with cache-busting
     */
    public static function cssLink(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate a complete JS script tag with cache-busting
     */
    public static function jsScript(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    /**
     * Page shell: top bar with the brand, main nav, admin menu and profile
     * menu; content renders inside <main>.
     */
    public static function headerHtml(string $title): void {
        $u = current_user();
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $siteTitle = Settings::siteTitle();

        $mySite = $u ? SiteManagement::findByUserId((int)$u['id']) : null;
        $themeSite = self::$themeSite;
        if ($themeSite === null) {
            $resolved = SiteResolver::resolveFromRequest();
            $themeSite = ($resolved['site'] !== null && $resolved['is_custom_domain']) ? $resolved['site'] : $mySite;
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . h($title) . ' - ' . h($siteTitle) . '</title>';
        echo self::cssLink('/styles.css');
        echo self::cssLink('/video-panel.css');
        echo self::siteThemeStyle($themeSite);
        echo '</head><body>';

        if ($u) {
            $navItems = [
                ['path' => '/manage/', 'label' => 'Manage', 'prefixes' => ['/manage/']],
            ];
            if ($mySite) {
                $navItems[] = ['path' => SiteResolver::publicHomeUrl($mySite), 'label' => 'My Site', 'prefixes' => ['/public_site.php']];
            }

            echo '<header class="topbar">';
            echo '<a class="brand" href="/manage/"><span class="brand-mark" aria-hidden="true">&#127891;</span> ' . h($siteTitle) . '</a>';

            echo '<nav class="topnav">';
            foreach ($navItems as $item) {
                $active = false;
                foreach ($item['prefixes'] as $prefix) {
                    if (strpos($script, $prefix) === 0) { $active = true; break; }
                }
                echo '<a href="' . h($item['path']) . '"' . ($active ? ' class="active"' : '') . '>' . h($item['label']) . '</a>';
            }

            if (!empty($u['is_admin'])) {
                $inAdmin = strpos($script, '/admin/') === 0;
                $adminItems = [
                    ['path' => '/admin/users.php', 'label' => 'Users'],
                    ['path' => '/admin/sites.php', 'label' => 'Sites'],
                    ['path' => '/admin/settings.php', 'label' => 'Settings'],
                    ['path' => '/admin/video_storage.php', 'label' => 'Video Storage'],
                    ['path' => '/admin/migrations.php', 'label' => 'Migrations'],
                    ['path' => '/admin/activity_log.php', 'label' => 'Activity Log'],
                    ['path' => '/admin/email_log.php', 'label' => 'Email Log'],
                ];
                $adminLinks = '';
                foreach ($adminItems as $item) {
                    $active = $inAdmin && basename(parse_url($item['path'], PHP_URL_PATH)) === basename($script);
                    $adminLinks .= '<a href="' . h($item['path']) . '" role="menuitem"' . ($active ? ' class="active"' : '') . '>' . h($item['label']) . '</a>';
                }
                echo '<span class="menu-wrap">'
                   . '<button type="button" id="adminToggle" class="topnav-toggle' . ($inAdmin ? ' active' : '') . '" aria-expanded="false" aria-controls="adminMenu">Admin &#9662;</button>'
                   . '<span id="adminMenu" class="popup-menu hidden" role="menu" aria-hidden="true">' . $adminLinks . '</span>'
                   . '</span>';
            }
            echo '</nav>';

            echo '<div class="topbar-right">';
            $initials = strtoupper((string)substr((string)($u['first_name'] ?? ''), 0, 1) . (string)substr((string)($u['last_name'] ?? ''), 0, 1));
            $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
            echo '<span class="menu-wrap">'
               . '<button type="button" id="profileToggle" class="avatar" aria-expanded="false" aria-controls="profileMenu" title="' . h($name) . '" aria-label="Account menu for ' . h($name) . '">' . h($initials) . '</button>'
               . '<span id="profileMenu" class="popup-menu popup-menu-right hidden" role="menu" aria-hidden="true">'
               .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
               .   '<a href="/logout.php" role="menuitem">Logout</a>'
               . '</span>'
               . '</span>';
            echo '</div>';
            echo '</header>';

            echo '<div class="content"><main>';
        } else {
            echo '<header class="topbar"><a class="brand" href="/login.php"><span class="brand-mark" aria-hidden="true">&#127891;</span> ' . h($siteTitle) . '</a></header>';
            echo '<div class="content"><main>';
        }
    }

    public static function footerHtml(): void {
        echo '</main></div>' . self::jsScript('/main.js') . '</body></html>';
    }
}
