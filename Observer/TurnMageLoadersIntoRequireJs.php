<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\File\Resolver as TemplateFileResolver;
use Magento\Framework\View\TemplateEnginePool;

class TurnMageLoadersIntoRequireJs implements ObserverInterface
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var TemplateFileResolver
     */
    private $templateFileResolver;

    /**
     * @var TemplateEnginePool
     */
    private $templateEnginePool;

    /**
     * @var Template
     */
    private $template;

    /**
     * TurnMageLoadersIntoRequireJs constructor.
     * @param SerializerInterface $serializer
     * @param TemplateFileResolver $templateFileResolver
     * @param TemplateEnginePool $templateEnginePool
     * @param Template $template
     */
    public function __construct(
        SerializerInterface $serializer,
        TemplateFileResolver $templateFileResolver,
        TemplateEnginePool $templateEnginePool,
        Template $template
    ) {
        $this->serializer = $serializer;
        $this->templateFileResolver = $templateFileResolver;
        $this->templateEnginePool = $templateEnginePool;
        $this->template = $template;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $response = $observer->getResponse();
        $html = $response->getBody();
        $script = '';

        if (preg_match_all('#([^<]+)data-mage-init=\'([^\']+)\'([^\>]?)#im', $html, $matches)) {
            foreach ($matches[0] as $matchIndex => $match) {
                $data = $this->getDataFromJson($matches[2][$matchIndex], $matches[0][$matchIndex]);
                foreach ($data as $component => $configuration) {
                    if ($this->skipComponent($component)) {
                        continue;
                    }

                    $elementId = $this->getElementId($matches[0][$matchIndex]);
                    $script .= $this->getScript($component, $configuration, $elementId);
                    $replaceTag = trim($matches[1][$matchIndex]) . ' ' . trim(
                            $matches[3][$matchIndex]
                        ) . ' id="' . $elementId . '"';
                    $html = str_replace($matches[0][$matchIndex], $replaceTag, $html);
                }
            }
        }

        if (preg_match_all('#<script type="text/x-magento-init">([\s\S]*?)</script>#im', $html, $matches)) {
            foreach ($matches[0] as $matchIndex => $match) {
                $data = $this->getDataFromJson($matches[1][$matchIndex], $matches[0][$matchIndex]);
                foreach ($data as $elementId => $componentConfiguration) {
                    foreach ($componentConfiguration as $component => $configuration) {
                        if ($this->skipComponent($component)) {
                            continue;
                        }

                        $script .= $this->getScript($component, $configuration, $elementId);
                        $html = str_replace($matches[0][$matchIndex], '', $html);
                    }
                }
            }
        }

        $html = str_replace('</body>', $script . '</body>', $html);
        $response->setBody($html);
    }

    /**
     * @param string $component
     * @param array|string $configuration
     * @param string $elementId
     * @return string
     */
    private function getScript(
        string $component,
        $configuration,
        string $elementId = ''
    ): string {
        $templateFileName = $this->templateFileResolver->getTemplateFileName(
            'componentScript.phtml',
            ['module' => 'Yireo_FasterScriptLoader']
        );

        $data = [
            'element_id' => $elementId,
            'component' => $component,
            'configuration' => $this->serializer->serialize($configuration)
        ];

        $this->template->setData($data);

        $extension = pathinfo($templateFileName, PATHINFO_EXTENSION);
        $templateEngine = $this->templateEnginePool->get($extension);
        $scriptHtml = $templateEngine->render($this->template, $templateFileName);
        //$scriptHtml = str_replace("\n", ' ', $scriptHtml);

        return $scriptHtml;
    }

    /**
     * @param string $component
     * @return bool
     */
    private function skipComponent(string $component): bool
    {
        // @todo: Turn this into an XML layout parameter
        if ($component === 'mage/gallery/gallery') {
            return true;
        }

        if (in_array($component, [
            'Magento_Catalog/js/validate-product',
            'Magento_Swatches/js/swatch-renderer',
            'Magento_Swatches/js/catalog-add-to-cart'
        ])) {
            return true;
        }

        if ($component === 'catalogAddToCart') {
            return true;
        }

        return false;
    }

    /**
     * @return string
     */
    private function getElementId(string $htmlTag): string
    {
        if (preg_match('/\ id=(\'|\")([^\'\"]+)/', $htmlTag, $matches)) {
            return (string)$matches[2];
        }

        static $i = 0;
        return 'fsl' . $i++;
    }

    /**
     * @param string $json
     * @param string $debugValue
     * @return array
     * @throws \Exception
     */
    private function getDataFromJson(string $json, string $debugValue): array
    {
        $json = str_replace("\n", ' ', $json);
        $json = trim($json);
        if (empty($json)) {
            return [];
        }

        try {
            return $this->serializer->unserialize($json);
        } catch (\InvalidArgumentException $exception) {
            $json = html_entity_decode($json);
            try {
                return $this->serializer->unserialize($json);
            } catch(\InvalidArgumentException $exception) {
                throw new \Exception($exception->getMessage() . ': "'.$json.'" - ' . $debugValue);
            }
        }
    }
}
