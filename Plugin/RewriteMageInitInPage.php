<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Plugin;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\View\Result\Page;
use Yireo\FasterScriptLoader\Rewriter\MageInitRewriter;

class RewriteMageInitInPage
{
    public function __construct(
        private readonly MageInitRewriter $mageInitRewriter,
        private readonly RequestInterface $request
    ) {
    }

    public function afterRenderResult(Page $subject, mixed $result, HttpResponseInterface $response): mixed
    {
        if (!$response instanceof HttpResponse) {
            return $result;
        }

        if ($this->request instanceof HttpRequest && $this->request->isAjax()) {
            return $result;
        }

        if (!$this->isHtmlResponse($response)) {
            return $result;
        }

        $body = (string)$response->getBody();
        if (stripos($body, '</body>') === false) {
            return $result;
        }

        $response->setBody($this->mageInitRewriter->rewrite($body));

        return $result;
    }

    private function isHtmlResponse(HttpResponse $response): bool
    {
        $statusCode = (int)$response->getHttpResponseCode();
        if ($statusCode >= 300 && $statusCode < 400) {
            return false;
        }

        $contentType = $response->getHeader('Content-Type');
        if (!$contentType) {
            return true;
        }

        return stripos((string)$contentType->getFieldValue(), 'text/html') === 0;
    }
}
