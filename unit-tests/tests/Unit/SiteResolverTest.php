<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteResolverTest extends TestCase
{
    private const MAIN = 'kidsthatteach.org';

    private array $charlie = ['id' => 7, 'user_id' => 3, 'slug' => 'charlie', 'domain' => 'mastery.charlierosenthal.org', 'title' => 'Charlie'];
    private array $milton = ['id' => 8, 'user_id' => 4, 'slug' => 'milton', 'domain' => null, 'title' => 'Milton'];

    private function bySlug(string $slug): ?array
    {
        return ['charlie' => $this->charlie, 'milton' => $this->milton][$slug] ?? null;
    }

    private function byDomain(string $domain): ?array
    {
        return $domain === 'mastery.charlierosenthal.org' ? $this->charlie : null;
    }

    private function resolve(string $host, string $slug, string $main = self::MAIN): array
    {
        return SiteResolver::resolve($host, $slug, fn(string $s) => $this->bySlug($s), fn(string $d) => $this->byDomain($d), $main);
    }

    public function testPathFormWinsOnAnyHost(): void
    {
        $r = $this->resolve(self::MAIN, 'charlie');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('/site/charlie', $r['base_path']);
        $this->assertFalse($r['is_custom_domain']);

        $r = $this->resolve('localhost:8080', 'Charlie');
        $this->assertSame(7, $r['site']['id'], 'slug lookup is case-insensitive');

        $r = $this->resolve('milton.' . self::MAIN, 'charlie');
        $this->assertSame(7, $r['site']['id'], '?site= beats the hostname');
        $this->assertSame('/site/charlie', $r['base_path']);
    }

    public function testUnknownSlugResolvesToNoSite(): void
    {
        $r = $this->resolve(self::MAIN, 'nobody');
        $this->assertNull($r['site']);
    }

    public function testSubdomainResolvesFromTheSlugWithEmptyBasePath(): void
    {
        $r = $this->resolve('Milton.KidsThatTeach.org:443', '');
        $this->assertSame(8, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);

        $this->assertNull($this->resolve('nobody.' . self::MAIN, '')['site']);
        $this->assertNull($this->resolve('www.' . self::MAIN, '')['site'], 'www is the main site');
        $this->assertNull($this->resolve('a.milton.' . self::MAIN, '')['site'], 'only one label deep');
        $this->assertNull($this->resolve('milton.' . self::MAIN, '', 'localhost')['site'], 'no subdomains without a real main host');
    }

    public function testSubdomainSlugParsing(): void
    {
        $this->assertSame('milton', SiteResolver::subdomainSlug('milton.' . self::MAIN, self::MAIN));
        $this->assertSame('milton-2', SiteResolver::subdomainSlug('MILTON-2.' . self::MAIN . ':8443', self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug(self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('www.' . self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('milton.example.org', self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('evilkidsthatteach.org', self::MAIN), 'suffix must be a whole label');
        $this->assertNull(SiteResolver::subdomainSlug('bad_slug.' . self::MAIN, self::MAIN));
        $this->assertNull(SiteResolver::subdomainSlug('milton.localhost', 'localhost'));
        $this->assertSame('milton.' . self::MAIN, SiteResolver::subdomainHostFor($this->milton, self::MAIN));
        $this->assertSame('', SiteResolver::subdomainHostFor($this->milton, 'localhost'));
    }

    public function testCustomDomainResolvesWithEmptyBasePath(): void
    {
        $r = $this->resolve('Mastery.CharlieRosenthal.org:443', '');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);
    }

    public function testWwwPrefixedCustomDomainResolvesToTheSameSite(): void
    {
        $r = $this->resolve('www.mastery.charlierosenthal.org', '');
        $this->assertSame(7, $r['site']['id']);
        $this->assertTrue($r['is_custom_domain']);
        $this->assertNull($this->resolve('www.' . self::MAIN, '')['site']);
    }

    public function testMainHostAndUnknownHostsResolveToNoSite(): void
    {
        $this->assertNull($this->resolve(self::MAIN, '')['site']);
        $this->assertNull($this->resolve('example.org', '')['site']);
        $this->assertNull($this->resolve('', '')['site']);
    }

    public function testLegacyHostsRedirectToTheSamePathOnTheMainHost(): void
    {
        $legacy = ['mastery.brianrosenthal.org'];
        $this->assertSame('https://kidsthatteach.org/manage/?user_id=3', SiteResolver::legacyRedirectTarget('mastery.brianrosenthal.org', '/manage/?user_id=3', $legacy, self::MAIN));
        $this->assertSame('https://kidsthatteach.org/', SiteResolver::legacyRedirectTarget('WWW.Mastery.BrianRosenthal.org:443', '', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget(self::MAIN, '/', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('mastery.charlierosenthal.org', '/', $legacy, self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('mastery.brianrosenthal.org', '/', [], self::MAIN));
        $this->assertNull(SiteResolver::legacyRedirectTarget('mastery.brianrosenthal.org', '/', $legacy, ''), 'no main host, nowhere to go');
    }

    public function testUrlForBuildsNestedPaths(): void
    {
        $this->assertSame('/site/charlie/', SiteResolver::urlFor('/site/charlie'));
        $this->assertSame('/algebra-ii/', SiteResolver::urlFor('', 'algebra-ii'));
        $this->assertSame('/site/charlie/algebra-ii/sequences/e-x/', SiteResolver::urlFor('/site/charlie', 'algebra-ii', 'sequences', 'e-x'));
        $this->assertSame('/algebra-ii/', SiteResolver::urlFor('', 'algebra-ii', null, 'ignored-without-subcategory'));
    }

    public function testSplitPathAcceptsUpToThreeValidSlugs(): void
    {
        $this->assertSame([null, null, null], SiteResolver::splitPath(''));
        $this->assertSame(['a', null, null], SiteResolver::splitPath('a/'));
        $this->assertSame(['a', 'b', 'c'], SiteResolver::splitPath('/a/b/c/'));
        $this->assertNull(SiteResolver::splitPath('a/b/c/d'));
        $this->assertNull(SiteResolver::splitPath('Bad Slug'));
        $this->assertNull(SiteResolver::splitPath('../etc'));
    }
}
