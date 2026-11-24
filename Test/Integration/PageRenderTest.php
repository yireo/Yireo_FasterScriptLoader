<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Test\Integration;

use Magento\TestFramework\TestCase\AbstractController;

/**
 * @magentoAppArea frontend
 */
class PageRenderTest extends AbstractController
{
    public function testHomepageScriptsAreRenderedThroughLoader(): void
    {
        $this->dispatch('/');
        $body = (string)$this->getResponse()->getBody();

        $this->assertStringContainsString('function fsl(', $body);
        $this->assertStringContainsString('require([', $body);
        $this->assertStringNotContainsString('data-fsl-', $body);
    }
}
