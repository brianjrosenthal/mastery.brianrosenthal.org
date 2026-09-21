<?php
declare(strict_types=1);

require_once __DIR__ . '/UserContext.php';

/**
 * The authorization rules for content (categories, subcategories, concepts,
 * questions): the owner of the tree or an app admin may change it, and any
 * signed-in user may ask a question. Shared by the management classes so the
 * rules cannot drift between them.
 */
final class ContentAccess {

    public static function canEdit(?UserContext $ctx, int $ownerUserId): bool {
        return $ctx !== null && ($ctx->admin || $ctx->id === $ownerUserId);
    }

    public static function canAsk(?UserContext $ctx): bool {
        return $ctx !== null;
    }

    public static function assertCanAsk(?UserContext $ctx): void {
        if (!self::canAsk($ctx)) {
            throw new RuntimeException('Please sign in to ask a question.');
        }
    }

    public static function assertCanEdit(?UserContext $ctx, int $ownerUserId): void {
        if (!$ctx) {
            throw new RuntimeException('Login required');
        }
        if (!self::canEdit($ctx, $ownerUserId)) {
            throw new RuntimeException('You can only change your own content.');
        }
    }
}
