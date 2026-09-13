<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VideoStorageTest extends TestCase
{
    protected function setUp(): void
    {
        VideoStorage::storage()->reset();
    }

    private function storage(): FakeDreamObjects
    {
        return VideoStorage::storage();
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

    public function testPresignUploadReturnsUrlAndRequiredHeaders(): void
    {
        $key = VideoStorage::newObjectKeyFor(3, 17, 'video/webm');
        $grant = VideoStorage::presignUploadFor($key, 'video/webm;codecs=vp9');
        $this->assertStringStartsWith('https://objects-test.dream.io/' . VideoStorage::bucket() . '/videos/3/17/', $grant['url']);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host%3Bx-amz-acl', $grant['url']);
        $this->assertStringContainsString('X-Amz-Expires=' . VideoStorage::UPLOAD_URL_TTL, $grant['url']);
        $this->assertSame('public-read', $grant['headers']['x-amz-acl']);
        $this->assertSame('video/webm', $grant['headers']['Content-Type']);
        $this->assertSame([], $this->storage()->calls, 'presigning never touches storage');
    }

    public function testPublicUrl(): void
    {
        $this->assertSame(
            'https://objects-test.dream.io/' . VideoStorage::bucket() . '/videos/3/17/abc.mp4',
            VideoStorage::publicUrlFor('videos/3/17/abc.mp4')
        );
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
        $origins = VideoStorage::corsOrigins('mastery.brianrosenthal.org', ['mastery.charlierosenthal.org', 'MASTERY.lillyrosenthal.org', '', 'mastery.charlierosenthal.org']);
        $this->assertSame([
            'https://mastery.brianrosenthal.org',
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
