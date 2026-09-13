<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testRendersBasicMarkdown(): void
    {
        $html = MarkdownRenderer::toHtml("# Title\n\nSome **bold** text and a [link](https://example.com).");
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.com">link</a>', $html);
    }

    public function testEscapesRawHtmlSoAuthorsCannotInjectScripts(): void
    {
        $html = MarkdownRenderer::toHtml('Hello <script>alert(1)</script> [x](javascript:alert(1))');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function testSingleNewlinesBecomeLineBreaks(): void
    {
        $this->assertStringContainsString('<br />', MarkdownRenderer::toHtml("line one\nline two"));
    }

    public function testEmptyInputRendersNothing(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml("   \n  "));
    }

    public function testExcerptStripsMarkupAndTruncatesOnWordBoundary(): void
    {
        $md = "## Heading\n\nThis is **a fairly long** description that keeps going and going so that it needs to be cut somewhere sensible.";
        $excerpt = MarkdownRenderer::excerpt($md, 60);
        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringNotContainsString('**', $excerpt);
        $this->assertLessThanOrEqual(61, mb_strlen($excerpt));
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertSame('Short.', MarkdownRenderer::excerpt('Short.', 60));
    }
}
