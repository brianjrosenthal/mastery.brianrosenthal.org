<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VideoStorageTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset_all();
    }

    /** The fake behind the ACTIVE provider (where uploads go). */
    private function storage(): FakeS3Client
    {
        return VideoStorage::storage();
    }

    public function testActiveProviderDefaultsToR2AndRowsRememberTheirProvider(): void
    {
        $this->assertSame('r2', VideoStorage::activeProvider(), 'config.local.php sets VIDEO_STORAGE_PROVIDER to r2 (or leaves the default)');
        $this->assertSame(['r2', 'dreamobjects'], VideoStorage::providers(), 'active provider is listed first');
        $this->assertSame('Cloudflare R2', VideoStorage::providerLabel('r2'));
        $this->assertSame('r2', VideoStorage::providerOf(['video_storage' => 'r2']));
        $this->assertSame('dreamobjects', VideoStorage::providerOf(['video_storage' => 'dreamobjects']));
        $this->assertSame('dreamobjects', VideoStorage::providerOf(['video_storage' => null]), 'rows from before the column existed were uploaded to DreamObjects');
        $this->assertSame('dreamobjects', VideoStorage::providerOf([]));
        $this->expectException(InvalidArgumentException::class);
        VideoStorage::storage('s3');
    }

    public function testEachProviderHasItsOwnClient(): void
    {
        $this->assertSame('https://r2-test.example', VideoStorage::storage('r2')->endpoint());
        $this->assertSame('https://objects-test.dream.io', VideoStorage::storage('dreamobjects')->endpoint());
        $this->assertNotSame(VideoStorage::storage('r2'), VideoStorage::storage('dreamobjects'));
        $this->assertSame(VideoStorage::storage('r2'), VideoStorage::storage(), 'no provider means the active one');
    }

    public function testContentTypeNormalizationAndExtensions(): void
    {
        $this->assertSame('video/webm', VideoStorage::normalizeContentType('video/webm;codecs=vp9,opus'));
        $this->assertSame('video/mp4', VideoStorage::normalizeContentType('VIDEO/MP4'));
        $this->assertSame('video/mp4', VideoStorage::normalizeContentType('video/x-m4v'));
        $this->assertSame('mp4', VideoStorage::extensionFor('video/mp4'));
        $this->assertSame('mov', VideoStorage::extensionFor('video/quicktime'));
        $this->assertSame('webm', VideoStorage::extensionFor('video/webm;codecs=vp8'));
        $this->assertNull(VideoStorage::extensionFor('image/png'));
        $this->assertSame(['video/mp4', 'video/webm', 'video/quicktime'], VideoStorage::allowedContentTypes());
    }

    public function testObjectKeysAreScopedAndUnguessable(): void
    {
        $a = VideoStorage::newObjectKeyFor(3, 17, 'video/mp4');
        $b = VideoStorage::newObjectKeyFor(3, 17, 'video/mp4');
        $this->assertMatchesRegularExpression('#^videos/3/17/[0-9a-f]{32}\.mp4$#', $a);
        $this->assertNotSame($a, $b, 'each upload gets a fresh key');
        $this->assertTrue(VideoStorage::keyBelongsToConcept($a, 17));
        $this->assertFalse(VideoStorage::keyBelongsToConcept($a, 18));
        $this->assertFalse(VideoStorage::keyBelongsToConcept('videos/3/17/../x.mp4', 17));
        $this->expectException(InvalidArgumentException::class);
        VideoStorage::newObjectKeyFor(3, 17, 'application/pdf');
    }

    public function testAnswerKeysLiveInTheirOwnNamespace(): void
    {
        $a = VideoStorage::newAnswerObjectKeyFor(3, 17, 'video/webm');
        $this->assertMatchesRegularExpression('#^videos/3/answers/17/[0-9a-f]{32}\\.webm$#', $a);
        $this->assertNotSame($a, VideoStorage::newAnswerObjectKeyFor(3, 17, 'video/webm'));
        $this->assertTrue(VideoStorage::keyBelongsToAnswer($a, 17));
        $this->assertFalse(VideoStorage::keyBelongsToAnswer($a, 18));
        $this->assertFalse(VideoStorage::keyBelongsToConcept($a, 17), 'an answer video can never be attached to a concept');
        $concept = VideoStorage::newObjectKeyFor(3, 17, 'video/mp4');
        $this->assertFalse(VideoStorage::keyBelongsToAnswer($concept, 17), 'nor a concept video to a question');
        $this->expectException(InvalidArgumentException::class);
        VideoStorage::newAnswerObjectKeyFor(3, 17, 'image/gif');
    }

    public function testPresignUploadSignsOnlyTheHostAndSendsNoAclHeader(): void
    {
        // DreamObjects rejects canned ACLs ("Unsupported value for canned acl
        // 'public-read'"), so the browser must send no x-amz-acl header and
        // the signature must not require one.
        $key = VideoStorage::newObjectKeyFor(3, 17, 'video/webm');
        $grant = VideoStorage::presignUploadFor($key, 'video/webm;codecs=vp9');
        $this->assertSame('r2', $grant['provider']);
        $this->assertStringStartsWith('https://r2-test.example/' . VideoStorage::bucket('r2') . '/videos/3/17/', $grant['url'], 'uploads go to the active provider');
        $this->assertStringContainsString('X-Amz-SignedHeaders=host&', $grant['url']);
        $this->assertStringContainsString('X-Amz-Expires=' . VideoStorage::UPLOAD_URL_TTL, $grant['url']);
        $this->assertSame(['Content-Type' => 'video/webm'], $grant['headers']);
        $this->assertSame([], $this->storage()->calls, 'presigning never touches storage');
    }

    public function testPlaybackUrlIsPresignedAndStableWithinAWindow(): void
    {
        $key = 'videos/3/17/abc.mp4';
        $window = VideoStorage::urlWindowSeconds();
        $t0 = 1774526400 - (1774526400 % $window);
        $a = VideoStorage::playbackUrlFor($key, 'dreamobjects', $t0 + 10);
        $b = VideoStorage::playbackUrlFor($key, 'dreamobjects', $t0 + $window - 1);
        $c = VideoStorage::playbackUrlFor($key, 'dreamobjects', $t0 + $window);

        $this->assertStringStartsWith('https://objects-test.dream.io/' . VideoStorage::bucket('dreamobjects') . '/' . $key . '?', $a, 'played from the provider that holds it');
        $this->assertStringStartsWith('https://r2-test.example/' . VideoStorage::bucket('r2') . '/' . $key . '?', VideoStorage::playbackUrlFor($key, 'r2', $t0));
        $this->assertSame(
            VideoStorage::playbackUrlFor($key, 'r2', $t0),
            VideoStorage::playbackUrlForConcept(['video_object_key' => $key, 'video_storage' => 'r2'], $t0)
        );
        $this->assertStringStartsWith('https://objects-test.dream.io/', VideoStorage::playbackUrlForConcept(['video_object_key' => $key, 'video_storage' => null], $t0));
        $this->assertStringContainsString('X-Amz-Signature=', $a);
        $this->assertSame($a, $b, 'same window => byte-identical URL so the browser can cache the video');
        $this->assertNotSame($a, $c, 'a new window re-signs');
        $this->assertStringContainsString('X-Amz-Expires=' . VideoStorage::urlTtlSeconds(), $a);
        $this->assertGreaterThanOrEqual(2 * $window, VideoStorage::urlTtlSeconds(), 'a URL minted at the start of a window must outlive its end');
        $this->assertLessThanOrEqual(604800, VideoStorage::urlTtlSeconds());
        $this->assertSame([], VideoStorage::storage('dreamobjects')->calls, 'playback URLs are pure computation');
    }

    public function testVerifyUploadedObjectAcceptsGoodObjects(): void
    {
        $this->storage()->seedObject(VideoStorage::bucket(), 'videos/1/1/k.mp4', 1234, 'video/mp4');
        $this->assertSame(['content_type' => 'video/mp4', 'size' => 1234], VideoStorage::verifyUploadedObject('videos/1/1/k.mp4'));
    }

    public function testVerifyUploadedObjectRejectsMissingWrongTypeAndOversize(): void
    {
        try {
            VideoStorage::verifyUploadedObject('videos/1/1/missing.mp4');
            $this->fail('missing object must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not reach storage', $e->getMessage());
        }

        $this->storage()->seedObject(VideoStorage::bucket(), 'videos/1/1/bad.mp4', 10, 'text/html');
        try {
            VideoStorage::verifyUploadedObject('videos/1/1/bad.mp4');
            $this->fail('wrong type must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a supported video', $e->getMessage());
        }
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), 'videos/1/1/bad.mp4'), 'junk is deleted');

        $this->storage()->seedObject(VideoStorage::bucket(), 'videos/1/1/big.mp4', VideoStorage::maxBytes() + 1, 'video/mp4');
        try {
            VideoStorage::verifyUploadedObject('videos/1/1/big.mp4');
            $this->fail('oversize must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('limit', $e->getMessage());
        }
        $this->assertFalse($this->storage()->objectExists(VideoStorage::bucket(), 'videos/1/1/big.mp4'));
    }

    public function testCorsOriginsCoverMainHostDomainsAndLocalDev(): void
    {
        $origins = VideoStorage::corsOrigins('kidsthatteach.org', ['charlie.kidsthatteach.org', 'mastery.charlierosenthal.org', 'MASTERY.lillyrosenthal.org', '', 'mastery.charlierosenthal.org']);
        $this->assertSame([
            'https://kidsthatteach.org',
            'https://charlie.kidsthatteach.org',
            'https://mastery.charlierosenthal.org',
            'https://mastery.lillyrosenthal.org',
            'http://localhost:8080',
        ], $origins);
        $this->assertSame(['https://a.example'], VideoStorage::corsOrigins('a.example', [], false));
    }

    public function testHumanBytes(): void
    {
        $this->assertSame('512 B', VideoStorage::humanBytes(512));
        $this->assertSame('1.5 KB', VideoStorage::humanBytes(1536));
        $this->assertSame('2 GB', VideoStorage::humanBytes(2147483648));
    }
}
