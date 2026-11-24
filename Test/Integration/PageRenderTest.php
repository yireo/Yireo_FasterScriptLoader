<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Test\Integration;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * @magentoAppArea frontend
 */
class PageRenderTest extends AbstractController
{
    public function testHomepageScriptsAreRenderedThroughLoader(): void
    {
        $this->dispatch('/');
        $response = $this->getResponse();
        $this->assertInstanceOf(HttpResponse::class, $response);
        $body = (string)$response->getBody();

        $this->assertStringContainsString('function fsl(', $body);
        $this->assertStringContainsString('require([', $body);
        $this->assertStringNotContainsString('data-fsl-', $body);
    }
}
