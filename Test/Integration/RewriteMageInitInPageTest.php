<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Test\Integration;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Yireo\FasterScriptLoader\Plugin\RewriteMageInitInPage;

/**
 * @magentoAppArea frontend
 */
class RewriteMageInitInPageTest extends TestCase
{
    private const HTML = '<!doctype html><html><body>'
        . '<div id="foo" data-mage-init=\'{"collapsible":{}}\'></div>'
        . '</body></html>';

    public function testPluginRunsBeforeBuiltinPageCache(): void
    {
        $pluginList = Bootstrap::getObjectManager()->get(PluginListInterface::class);
        $afterPlugins = $pluginList->getNext(Page::class, 'renderResult')[DefinitionInterface::LISTENER_AFTER] ?? [];

        $position = array_search('Yireo_FasterScriptLoader::rewriteMageInitInPage', $afterPlugins, true);
        $this->assertIsInt($position);

        $pageCachePosition = array_search('result-builtin-cache', $afterPlugins, true);
        if ($pageCachePosition !== false) {
            $this->assertLessThan($pageCachePosition, $position);
        }
    }

    public function testHtmlResponseIsRewritten(): void
    {
        $response = $this->createResponse(self::HTML, 'text/html; charset=UTF-8');
        $this->callPlugin($response);

        $body = (string)$response->getBody();
        $this->assertStringNotContainsString('data-mage-init', $body);
        $this->assertStringContainsString('fsl("collapsible", fn, {}, {"id":"foo"}, $)', $body);
    }

    public function testJsonResponseIsNotModified(): void
    {
        $body = (string)json_encode(['html' => self::HTML]);
        $response = $this->createResponse($body, 'application/json');
        $this->callPlugin($response);

        $this->assertSame($body, (string)$response->getBody());
    }

    public function testAjaxRequestIsNotModified(): void
    {
        $request = Bootstrap::getObjectManager()->get(HttpRequest::class);
        $request->setParam('isAjax', 1);

        $response = $this->createResponse(self::HTML, 'text/html');
        $this->callPlugin($response);

        $this->assertSame(self::HTML, (string)$response->getBody());
        $request->setParam('isAjax', null);
    }

    public function testRedirectIsNotModified(): void
    {
        $response = $this->createResponse(self::HTML, 'text/html');
        $response->setHttpResponseCode(302);
        $this->callPlugin($response);

        $this->assertSame(self::HTML, (string)$response->getBody());
    }

    private function callPlugin(HttpResponse $response): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $page = $objectManager->get(PageFactory::class)->create();
        $plugin = $objectManager->get(RewriteMageInitInPage::class);
        $plugin->afterRenderResult($page, $page, $response);
    }

    private function createResponse(string $body, string $contentType): HttpResponse
    {
        $response = Bootstrap::getObjectManager()->create(HttpResponse::class);
        $response->setHeader('Content-Type', $contentType, true);
        $response->setBody($body);

        return $response;
    }
}
