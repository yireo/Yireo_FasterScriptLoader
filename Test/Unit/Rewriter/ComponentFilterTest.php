<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Test\Unit\Rewriter;

use PHPUnit\Framework\TestCase;
use Yireo\FasterScriptLoader\Rewriter\ComponentFilter;

class ComponentFilterTest extends TestCase
{
    public function testRegularComponentsAreAllowed(): void
    {
        $componentFilter = new ComponentFilter();
        foreach (['collapsible', 'Magento_Ui/js/core/app', 'mage/dropdown', 'Vendor_Module/js/foo.bar', 'a-b_c'] as $component) {
            $this->assertTrue($componentFilter->isAllowed($component), $component);
        }
    }

    public function testUrlLikeComponentsAreRejected(): void
    {
        $componentFilter = new ComponentFilter();
        $components = [
            'https://evil.example/x.js',
            '//evil.example/x',
            '/absolute/path',
            'Vendor_Module/js/file.js',
            'Vendor_Module/js/file.JS',
            'text!Vendor_Module/template.html',
            'Vendor_Module/../../evil',
            'Vendor_Module/./foo',
            'data:text/javascript,alert(1)',
            'javascript:alert(1)',
            '',
            "foo\nbar",
            "foo\n",
            'foo bar',
            'foo/',
        ];

        foreach ($components as $component) {
            $this->assertFalse($componentFilter->isAllowed($component), $component);
        }
    }

    public function testSkippedComponentsAreRejected(): void
    {
        $componentFilter = new ComponentFilter(['mage/gallery/gallery']);
        $this->assertFalse($componentFilter->isAllowed('mage/gallery/gallery'));
        $this->assertTrue($componentFilter->isAllowed('mage/gallery/other'));
    }
}
