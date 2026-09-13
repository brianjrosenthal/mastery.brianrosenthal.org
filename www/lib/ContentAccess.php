<?php
declare(strict_types=1);

require_once __DIR__ . '/UserContext.php';

/**
 * The one authorization rule for content (categories, subcategories,
 * concepts): the owner of the tree or an app admin may change it. Shared by
 * the three management classes so the rule cannot drift between them.
 */
final class ContentAccess {

    public static function canEdit(?UserContext $ctx, int $ownerUserId): bool {
        return $ctx !== null && ($ctx->admin || $ctx->id === $ownerUserId);
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
