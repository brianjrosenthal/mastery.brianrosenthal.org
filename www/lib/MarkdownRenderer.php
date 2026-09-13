<?php
declare(strict_types=1);

require_once __DIR__ . '/Parsedown.php';

/**
 * Renders user-authored Markdown (site homepages, category/subcategory/concept
 * descriptions) to HTML. Safe mode is always on: raw HTML in the source is
 * escaped rather than emitted, so authors cannot inject scripts into their
 * own or (via an admin) anyone else's public site.
 */
final class MarkdownRenderer {

    public static function toHtml(string $markdown): string {
        if (trim($markdown) === '') {
            return '';
        }
        $pd = new Parsedown();
        $pd->setSafeMode(true);      // escape raw HTML -> no XSS
        $pd->setBreaksEnabled(true); // single newlines -> <br>, as kids expect
        return $pd->text($markdown);
    }

    /** Plain-text teaser of a Markdown document for cards: first $maxChars, no markup. */
    public static function excerpt(string $markdown, int $maxChars = 140): string {
        $text = trim(html_entity_decode(strip_tags(self::toHtml($markdown)), ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $cut = mb_substr($text, 0, $maxChars);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $maxChars * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, ' ,.;:') . '…';
    }
}
