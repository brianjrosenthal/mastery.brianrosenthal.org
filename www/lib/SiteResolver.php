<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SiteManagement.php';

/**
 * Decides which user's public site a request is for, and builds that site's
 * public URLs. Two ways in:
 *
 *   - path form  : mastery.brianrosenthal.org/site/{slug}/...   (any host)
 *   - custom host: mastery.charlierosenthal.org/...             (sites.domain)
 *
 * The result is a "resolved site" array used by every public page:
 *   ['site' => row|null, 'base_path' => '' | '/site/{slug}', 'is_custom_domain' => bool]
 *
 * resolve() takes the lookups as callables so the routing rules are unit
 * testable without a database.
 */
final class SiteResolver {

    public static function mainHost(): string {
        return defined('MAIN_HOST') ? strtolower((string)MAIN_HOST) : '';
    }

    /** "Mastery.CharlieRosenthal.org:8080" -> "mastery.charlierosenthal.org" */
    public static function normalizeHost(string $host): string {
        $h = strtolower(trim($host));
        return explode(':', $h, 2)[0];
    }

    /**
     * @param callable(string):?array $findBySlug
     * @param callable(string):?array $findByDomain
     * @return array{site:?array,base_path:string,is_custom_domain:bool}
     */
    public static function resolve(string $host, string $slugFromPath, callable $findBySlug, callable $findByDomain): array {
        $none = ['site' => null, 'base_path' => '', 'is_custom_domain' => false];

        $slug = strtolower(trim($slugFromPath));
        if ($slug !== '') {
            $site = $findBySlug($slug);
            if ($site === null) {
                return $none;
            }
            return ['site' => $site, 'base_path' => '/site/' . $site['slug'], 'is_custom_domain' => false];
        }

        $h = self::normalizeHost($host);
        if ($h === '' || $h === self::mainHost() || $h === 'www.' . self::mainHost()) {
            return $none;
        }
        // DreamHost's "Add WWW" option can serve the site as www.<domain>;
        // treat that as the same site.
        $site = $findByDomain($h);
        if ($site === null && strpos($h, 'www.') === 0) {
            $site = $findByDomain(substr($h, 4));
        }
        if ($site === null) {
            return $none;
        }
        return ['site' => $site, 'base_path' => '', 'is_custom_domain' => true];
    }

    /** Resolve from the live request: ?site= (set by the rewrite) or the Host header. */
    public static function resolveFromRequest(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = self::resolve(
            (string)($_SERVER['HTTP_HOST'] ?? ''),
            (string)($_GET['site'] ?? ''),
            [SiteManagement::class, 'findBySlug'],
            [SiteManagement::class, 'findByDomain']
        );
        return $cached;
    }

    /** True when the current request's host is the site's own domain. */
    public static function requestIsOnDomainOf(array $site): bool {
        $domain = (string)($site['domain'] ?? '');
        $h = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $domain !== '' && ($h === $domain || $h === 'www.' . $domain);
    }

    /** True when the request's hostname is the main (admin) site or unknown. */
    public static function requestIsOnMainHost(): bool {
        $h = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        $main = self::mainHost();
        return $main === '' || $h === $main || $h === 'www.' . $main || $h === 'localhost' || $h === '127.0.0.1';
    }

    /**
     * Base path for a site as seen from the current request: '' when we are on
     * its custom domain, else the /site/{slug} path form (which works on every
     * host, including localhost).
     */
    public static function basePathFor(array $site): string {
        return self::requestIsOnDomainOf($site) ? '' : '/site/' . $site['slug'];
    }

    /** The site's homepage as a root-relative URL usable from the current host. */
    public static function publicHomeUrl(array $site): string {
        return self::basePathFor($site) . '/';
    }

    /** The site's canonical absolute homepage (custom domain when set). */
    public static function canonicalHomeUrl(array $site): string {
        $domain = (string)($site['domain'] ?? '');
        if ($domain !== '') {
            return 'https://' . $domain . '/';
        }
        require_once __DIR__ . '/../settings.php';
        return Settings::siteBaseUrl() . '/site/' . $site['slug'] . '/';
    }

    /**
     * Public URL for a node of the tree, relative to the site's base path.
     * Pass slugs, not rows: urlFor($base, 'algebra-ii', 'sequences', 'e-x').
     */
    public static function urlFor(string $basePath, ?string $categorySlug = null, ?string $subcategorySlug = null, ?string $conceptSlug = null): string {
        $url = $basePath . '/';
        if ($categorySlug !== null && $categorySlug !== '') {
            $url .= rawurlencode($categorySlug) . '/';
            if ($subcategorySlug !== null && $subcategorySlug !== '') {
                $url .= rawurlencode($subcategorySlug) . '/';
                if ($conceptSlug !== null && $conceptSlug !== '') {
                    $url .= rawurlencode($conceptSlug) . '/';
                }
            }
        }
        return $url;
    }

    /**
     * Split the rewritten ?path= ("algebra-ii/sequences/e-x/") into up to three
     * slugs. Returns null when there are more segments than the tree has levels
     * or a segment is not a valid slug, so the router can 404.
     *
     * @return ?array{0:?string,1:?string,2:?string}
     */
    public static function splitPath(string $path): ?array {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
        if (count($segments) > 3) {
            return null;
        }
        foreach ($segments as $s) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $s) !== 1) {
                return null;
            }
        }
        return [$segments[0] ?? null, $segments[1] ?? null, $segments[2] ?? null];
    }
}
