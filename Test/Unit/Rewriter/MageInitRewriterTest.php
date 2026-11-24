<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Test\Unit\Rewriter;

use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yireo\FasterScriptLoader\Rewriter\ComponentFilter;
use Yireo\FasterScriptLoader\Rewriter\MageInitRewriter;

class MageInitRewriterTest extends TestCase
{
    public function testAttributeIsMovedIntoScript(): void
    {
        $html = $this->rewrite('<div id="foo" class="a" data-mage-init=\'{"collapsible":{"active":true}}\'>x</div>');

        $this->assertStringNotContainsString('data-mage-init', $html);
        $this->assertStringContainsString('<div id="foo" class="a" >x</div>', $html);
        $this->assertStringContainsString(
            'fsl("collapsible", fn, {"active":true}, {"id":"foo"}, $)',
            $html
        );
        $this->assertStringNotContainsString('data-fsl-', $html);
    }

    public function testElementWithoutIdGetsOneGeneratedIdForAllComponents(): void
    {
        $html = $this->rewrite('<div data-mage-init=\'{"a":{},"b":{}}\'></div>');

        $this->assertSame(1, preg_match('#<div id="(fsl-[a-f0-9]+-0)"></div>#', $html, $match));
        $this->assertSame(2, substr_count($html, '{"id":"' . $match[1] . '"}'));
    }

    public function testMagentoInitScriptIsMovedIntoScript(): void
    {
        $html = $this->rewrite(
            '<script type="text/x-magento-init">{"#foo":{"Vendor_Module/js/a":{"x":1}},"*":{"b":{}}}</script>'
        );

        $this->assertStringNotContainsString('text/x-magento-init', $html);
        $this->assertStringContainsString('fsl("Vendor_Module/js/a", fn, {"x":1}, {"selector":"#foo"}, $)', $html);
        $this->assertStringContainsString('fsl("b", fn, {}, {"selector":"*"}, $)', $html);
    }

    public function testTextThatOnlyLooksLikeAnAttributeIsIgnored(): void
    {
        $payload = 'x data-mage-init=\'{"Magento_Ui/js/core/app":{"components":{}}}\'';
        $body = '<script>var search = "' . $payload . '";</script>'
            . '<!-- ' . $payload . ' -->'
            . '<textarea>' . $payload . '</textarea>'
            . '<template><div ' . $payload . '></div></template>'
            . '<div title="' . $payload . '"></div>'
            . '<div data-x="<script type=text/x-magento-init>{&quot;*&quot;:{&quot;a&quot;:{}}}</script>"></div>';

        $original = $this->getPage($body);
        $this->assertSame($original, $this->createRewriter()->rewrite($original));
    }

    public function testEntityEncodedPayloadInTextIsNotDecoded(): void
    {
        $original = $this->getPage(
            '<p>data-mage-init=\'{&quot;Magento_Ui/js/core/app&quot;:{}}\'</p>'
            . '<p>data-mage-init=\'{"Magento_Ui/js/core/app":{}}\'</p>'
        );

        $this->assertSame($original, $this->createRewriter()->rewrite($original));
    }

    public function testEntitiesInRealAttributeAreDecodedOnce(): void
    {
        $html = $this->rewrite('<div id="foo" data-mage-init="{&quot;a&quot;:{&quot;b&quot;:&quot;&amp;lt;&quot;}}"></div>');

        $this->assertStringContainsString('fsl("a", fn, {"b":"\u0026lt;"}, {"id":"foo"}, $)', $html);
    }

    public function testUrlLikeComponentStaysUntouched(): void
    {
        $body = '<div id="foo" data-mage-init=\'{"https://evil.example/x.js":{}}\'></div>';
        $original = $this->getPage($body);

        $this->assertSame($original, $this->createRewriter()->rewrite($original));
    }

    public function testConfigurationCannotBreakOutOfScript(): void
    {
        $html = $this->rewrite(
            '<div id="foo" data-mage-init="{&quot;a&quot;:{&quot;b&quot;:&quot;&lt;/script&gt;&lt;!--&lt;script&gt;\'&amp;&quot;}}"></div>'
        );

        $script = substr($html, (int)strpos($html, '<script>'));
        $this->assertSame(1, substr_count(strtolower($script), '</script'));
        $this->assertStringNotContainsString('<!--', $script);
        $this->assertStringContainsString('\u003C/script\u003E\u003C!--\u003Cscript\u003E\u0027\u0026', $script);
    }

    public function testInvalidJsonIsSkippedWithoutException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            $this->logicalNot($this->stringContains('<div'))
        );

        $original = $this->getPage('<div data-mage-init=\'{invalid\'></div><div id="b" data-mage-init=\'{"a":{}}\'></div>');
        $html = $this->createRewriter($logger)->rewrite($original);

        $this->assertStringContainsString('<div data-mage-init=\'{invalid\'></div>', $html);
        $this->assertStringContainsString('fsl("a", fn, {}, {"id":"b"}, $)', $html);
    }

    public function testSkippedComponentsKeepTheirOriginalMarkup(): void
    {
        $original = $this->getPage(
            '<div id="g" data-mage-init=\'{"mage/gallery/gallery":{}}\'></div>'
            . '<script type="text/x-magento-init">{"*":{"mage/gallery/gallery":{}}}</script>'
        );

        $this->assertSame($original, $this->createRewriter()->rewrite($original));
    }

    public function testSkippedComponentsRemainNextToHandledComponents(): void
    {
        $html = $this->rewrite(
            '<div id="g" data-mage-init=\'{"mage/gallery/gallery":{"a":1},"b":{}}\'></div>'
            . '<script type="text/x-magento-init">{"#x":{"mage/gallery/gallery":{},"c":{}}}</script>'
        );

        $this->assertStringContainsString(
            '<div id="g" data-mage-init="{&quot;mage/gallery/gallery&quot;:{&quot;a&quot;:1}}"></div>',
            $html
        );
        $this->assertStringContainsString(
            '<script type="text/x-magento-init">{"#x":{"mage/gallery/gallery":{}}}</script>',
            $html
        );
        $this->assertStringContainsString('fsl("b"', $html);
        $this->assertStringContainsString('fsl("c"', $html);
        $this->assertStringNotContainsString('fsl("mage/gallery/gallery"', $html);
    }

    public function testScriptTagWithGreaterThanInAttributeStaysIntact(): void
    {
        $html = $this->rewrite(
            '<script type="text/x-magento-init" data-x="a>b">{"*":{"mage/gallery/gallery":{},"c":{}}}</script>'
        );

        $this->assertStringContainsString(
            '<script type="text/x-magento-init" data-x="a>b">{"*":{"mage/gallery/gallery":{}}}</script>',
            $html
        );
    }

    public function testScriptIsAddedBeforeLastBodyTag(): void
    {
        $html = $this->rewrite('<script>var x = "</body>";</script><div id="a" data-mage-init=\'{"a":{}}\'></div>');

        $this->assertStringContainsString('var x = "</body>";', $html);
        $this->assertMatchesRegularExpression('#<script>require\(.*</script></body></html>$#s', $html);
    }

    public function testHtmlWithoutBodyIsNotModified(): void
    {
        $html = '<div id="a" data-mage-init=\'{"a":{}}\'></div>';
        $this->assertSame($html, $this->createRewriter()->rewrite($html));
    }

    public function testUnrelatedMarkupIsNotReformatted(): void
    {
        $body = "<P CLASS=x>unclosed <b>bold<br>\n<svg><path d='M0 0'/></svg>"
            . '<custom-el foo>é &nbsp; &copy;</custom-el>'
            . '<div id="a" data-mage-init=\'{"a":{}}\'></div>';
        $html = $this->rewrite($body);

        $this->assertStringContainsString(
            "<P CLASS=x>unclosed <b>bold<br>\n<svg><path d='M0 0'/></svg><custom-el foo>é &nbsp; &copy;</custom-el>",
            $html
        );
    }

    private function rewrite(string $body): string
    {
        return $this->createRewriter()->rewrite($this->getPage($body));
    }

    private function getPage(string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $body . '</body></html>';
    }

    private function createRewriter(?LoggerInterface $logger = null): MageInitRewriter
    {
        $secureHtmlRenderer = $this->createStub(SecureHtmlRenderer::class);
        $secureHtmlRenderer->method('renderTag')->willReturnCallback(
            fn (string $tagName, array $attributes, ?string $content) => '<script>' . $content . '</script>'
        );

        return new MageInitRewriter(
            new ComponentFilter(['mage/gallery/gallery']),
            $secureHtmlRenderer,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
