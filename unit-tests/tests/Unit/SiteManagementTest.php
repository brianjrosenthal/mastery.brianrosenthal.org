<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteManagementTest extends TestCase
{
    private UserContext $admin;
    private UserContext $charlie;

    protected function setUp(): void
    {
        test_reset_all();
        $this->admin = test_seed_admin();
        $this->charlie = test_seed_user('charlie@example.com', 'Charlie');
    }

    public function testCreateForUserDerivesSlugAndDefaults(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', "Charlie's Mastery");
        $site = SiteManagement::findById($id);
        $this->assertSame('charlie', $site['slug']);
        $this->assertSame("Charlie's Mastery", $site['title']);
        $this->assertNull($site['domain']);
        $this->assertSame('blue', $site['accent_color']);
        $this->assertSame(1, (int)$site['is_public']);
        $this->assertNotSame('', $site['homepage_markdown']);
        $this->assertSame($id, (int)SiteManagement::findByUserId($this->charlie->id)['id']);
        $this->assertSame($id, (int)SiteManagement::findBySlug('CHARLIE')['id']);
    }

    public function testUserMayCreateTheirOwnSiteButNotAnothers(): void
    {
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        SiteManagement::createForUser($this->charlie, $this->charlie->id, 'Charlie', 'Mine');
        $this->expectException(RuntimeException::class);
        SiteManagement::createForUser($this->charlie, $lilly->id, 'Lilly', 'Not mine');
    }

    public function testSecondSiteForSameUserIsRefused(): void
    {
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'One');
        $this->expectException(RuntimeException::class);
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'Two');
    }

    public function testSlugCollisionsGetASuffixAndReservedHintsFallBack(): void
    {
        $other = test_seed_user('other@example.com', 'Charlie');
        SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $id2 = SiteManagement::createForUser($this->admin, $other->id, 'Charlie', 'B');
        $this->assertSame('charlie-2', SiteManagement::findById($id2)['slug']);

        $third = test_seed_user('admin2@example.com', 'Admin');
        $id3 = SiteManagement::createForUser($this->admin, $third->id, 'admin', 'C');
        $this->assertSame('site-' . $third->id, SiteManagement::findById($id3)['slug']);
    }

    public function testOwnerCanUpdateContentAndAdminCanUpdateRouting(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');

        SiteManagement::updateSiteContent($this->charlie, $id, [
            'title' => 'Charlie Learns', 'tagline' => 'Explained by me', 'homepage_markdown' => '# Hi',
            'accent_color' => 'mint', 'is_public' => false,
        ]);
        $site = SiteManagement::findById($id);
        $this->assertSame('Charlie Learns', $site['title']);
        $this->assertSame('mint', $site['accent_color']);
        $this->assertSame(0, (int)$site['is_public']);

        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie-r', 'https://Mastery.CharlieRosenthal.org/');
        $site = SiteManagement::findById($id);
        $this->assertSame('charlie-r', $site['slug']);
        $this->assertSame('mastery.charlierosenthal.org', $site['domain']);
        $this->assertSame($id, (int)SiteManagement::findByDomain('MASTERY.charlierosenthal.org:443')['id']);
        $this->assertSame(['mastery.charlierosenthal.org'], SiteManagement::listDomains());

        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie-r', '');
        $this->assertNull(SiteManagement::findById($id)['domain']);
    }

    public function testNonOwnerCannotUpdateContent(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $stranger = test_seed_user('stranger@example.com');
        $this->expectException(RuntimeException::class);
        SiteManagement::updateSiteContent($stranger, $id, ['title' => 'Hijacked']);
    }

    public function testOwnerCannotChangeRouting(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $this->expectException(RuntimeException::class);
        SiteManagement::updateSiteRouting($this->charlie, $id, 'new-slug', '');
    }

    public function testRoutingValidation(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        $id2 = SiteManagement::createForUser($this->admin, $lilly->id, 'Lilly', 'B');
        SiteManagement::updateSiteRouting($this->admin, $id2, 'lilly', 'mastery.lillyrosenthal.org');

        foreach ([
            ['admin', ''],                                   // reserved slug
            ['www', ''],                                     // would shadow www.MAIN_HOST
            ['lilly', ''],                                   // taken slug
            ['charlie', 'mastery.lillyrosenthal.org'],       // taken domain
            ['charlie', MAIN_HOST],                          // the main host
            ['charlie', 'charlie.' . MAIN_HOST],             // subdomains are automatic, not custom domains
            ['charlie', 'not a host'],                       // malformed
        ] as [$slug, $domain]) {
            try {
                SiteManagement::updateSiteRouting($this->admin, $id, $slug, $domain);
                $this->fail("expected rejection of slug '$slug' / domain '$domain'");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testListPublicHostsCoversSubdomainsAndCustomDomains(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        $lilly = test_seed_user('lilly@example.com', 'Lilly');
        SiteManagement::createForUser($this->admin, $lilly->id, 'Lilly', 'B');
        SiteManagement::updateSiteRouting($this->admin, $id, 'charlie', 'mastery.charlierosenthal.org');

        $expected = ['mastery.charlierosenthal.org'];
        if (SiteResolver::subdomainHostFor(['slug' => 'x']) !== '') {
            $expected = ['charlie.' . strtolower(MAIN_HOST), 'mastery.charlierosenthal.org', 'lilly.' . strtolower(MAIN_HOST)];
        }
        $this->assertSame($expected, SiteManagement::listPublicHosts());
    }

    public function testContentValidation(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        try {
            SiteManagement::updateSiteContent($this->charlie, $id, ['title' => '  ']);
            $this->fail('empty title should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('title', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        SiteManagement::updateSiteContent($this->charlie, $id, ['accent_color' => 'plaid']);
    }

    public function testNormalizeDomain(): void
    {
        $this->assertSame('mastery.charlierosenthal.org', SiteManagement::normalizeDomain(' HTTPS://Mastery.CharlieRosenthal.org:8443/x '));
        $this->assertSame('localhost', SiteManagement::normalizeDomain('localhost'));
        $this->assertNull(SiteManagement::normalizeDomain(''));
        $this->assertNull(SiteManagement::normalizeDomain('no spaces allowed'));
        $this->assertNull(SiteManagement::normalizeDomain('nodots'));
    }

    public function testWritesAreActivityLogged(): void
    {
        $id = SiteManagement::createForUser($this->admin, $this->charlie->id, 'Charlie', 'A');
        SiteManagement::updateSiteContent($this->charlie, $id, ['title' => 'B']);
        $types = array_column(ActivityLog::list([], 10), 'action_type');
        $this->assertContains('site.create', $types);
        $this->assertContains('site.update', $types);
    }
}
