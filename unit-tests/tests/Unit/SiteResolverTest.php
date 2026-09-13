<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteResolverTest extends TestCase
{
    private array $charlie = ['id' => 7, 'user_id' => 3, 'slug' => 'charlie', 'domain' => 'mastery.charlierosenthal.org', 'title' => 'Charlie'];

    private function bySlug(string $slug): ?array
    {
        return $slug === 'charlie' ? $this->charlie : null;
    }

    private function byDomain(string $domain): ?array
    {
        return $domain === 'mastery.charlierosenthal.org' ? $this->charlie : null;
    }

    private function resolve(string $host, string $slug): array
    {
        return SiteResolver::resolve($host, $slug, fn(string $s) => $this->bySlug($s), fn(string $d) => $this->byDomain($d));
    }

    public function testPathFormWinsOnAnyHost(): void
    {
        $r = $this->resolve('mastery.brianrosenthal.org', 'charlie');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('/site/charlie', $r['base_path']);
        $this->assertFalse($r['is_custom_domain']);

        $r = $this->resolve('localhost:8080', 'Charlie');
        $this->assertSame(7, $r['site']['id'], 'slug lookup is case-insensitive');
    }

    public function testUnknownSlugResolvesToNoSite(): void
    {
        $r = $this->resolve('mastery.brianrosenthal.org', 'nobody');
        $this->assertNull($r['site']);
    }

    public function testCustomDomainResolvesWithEmptyBasePath(): void
    {
        $r = $this->resolve('Mastery.CharlieRosenthal.org:443', '');
        $this->assertSame(7, $r['site']['id']);
        $this->assertSame('', $r['base_path']);
        $this->assertTrue($r['is_custom_domain']);
    }

    public function testMainHostAndUnknownHostsResolveToNoSite(): void
    {
        $this->assertNull($this->resolve(MAIN_HOST, '')['site']);
        $this->assertNull($this->resolve('example.org', '')['site']);
        $this->assertNull($this->resolve('', '')['site']);
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
