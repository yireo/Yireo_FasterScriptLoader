<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Rewriter;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Moves data-mage-init attributes and text/x-magento-init scripts into a single CSP-aware script.
 *
 * Every candidate in the raw HTML gets a random marker first. The DOM parser then decides which candidates
 * are real attributes or scripts. Only those are rewritten in the raw HTML, all other markers are removed again.
 */
class MageInitRewriter
{
    private const JSON_OUTPUT_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private const RAW_TEXT_ELEMENTS = [
        'iframe',
        'noembed',
        'noframes',
        'noscript',
        'plaintext',
        'script',
        'style',
        'template',
        'textarea',
        'title',
        'xmp',
    ];

    private const TRIM_CHARACTERS = " \f\n\r\t\v\x00";

    public function __construct(
        private readonly ComponentFilter $componentFilter,
        private readonly SecureHtmlRenderer $secureHtmlRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function rewrite(string $html): string
    {
        if (stripos($html, 'data-mage-init') === false && stripos($html, 'text/x-magento-init') === false) {
            return $html;
        }

        if (strripos($html, '</body>') === false) {
            return $html;
        }

        $nonce = bin2hex(random_bytes(6));
        $marker = 'data-fsl-' . $nonce . '-';
        $markedHtml = $this->addMarkers($html, $marker);
        if ($markedHtml === null) {
            return $html;
        }

        $document = $this->loadDocument($markedHtml);
        if ($document === null) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        $plans = array_merge(
            $this->getAttributePlans($xpath, $marker, 'fsl-' . $nonce . '-'),
            $this->getScriptPlans($xpath, $marker)
        );

        if (empty($plans)) {
            return $html;
        }

        [$rewrittenHtml, $lines] = $this->applyPlans($markedHtml, $marker, $plans);
        if (empty($lines)) {
            return $html;
        }

        $position = strripos($rewrittenHtml, '</body>');
        if ($position === false) {
            return $html;
        }

        $script = $this->secureHtmlRenderer->renderTag('script', [], implode("\n", $lines), false);

        return substr_replace($rewrittenHtml, $script, $position, 0);
    }

    private function addMarkers(string $html, string $marker): ?string
    {
        $attributeIndex = 0;
        $html = preg_replace_callback(
            '#(?<=\s)data-mage-init(?=\s*=)#i',
            function (array $match) use ($marker, &$attributeIndex): string {
                return $marker . 'a' . $attributeIndex++ . ' ' . $match[0];
            },
            $html
        );

        if ($html === null) {
            return null;
        }

        $scriptIndex = 0;

        return preg_replace_callback(
            '#<script(?=\s[^>]*type\s*=\s*["\']?text/x-magento-init)#i',
            function (array $match) use ($marker, &$scriptIndex): string {
                return $match[0] . ' ' . $marker . 's' . $scriptIndex++;
            },
            $html
        );
    }

    private function loadDocument(string $html): ?DOMDocument
    {
        $document = new DOMDocument();
        $previousSetting = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }

        return $loaded ? $document : null;
    }

    /**
     * @return array<string, array{replacement: string, lines: string[]}>
     */
    private function getAttributePlans(DOMXPath $xpath, string $marker, string $idPrefix): array
    {
        $elements = [];
        foreach ($this->getMarkerAttributes($xpath, $marker, 'a') as [$element, $index]) {
            $objectId = spl_object_id($element);
            $elements[$objectId]['element'] = $element;
            $elements[$objectId]['indexes'][] = $index;
        }

        $plans = [];
        $idCounter = 0;
        foreach ($elements as $item) {
            $element = $item['element'];
            if ($this->isInsideRawTextElement($element)) {
                continue;
            }

            $value = $this->removeMarkers($element->getAttribute('data-mage-init'), $marker);
            $data = $this->decode($value, 'data-mage-init');
            if ($data === null) {
                continue;
            }

            $elementId = $element->getAttribute('id');
            $newId = $elementId === '' ? $idPrefix . $idCounter++ : null;
            $target = ['id' => $newId ?? $elementId];

            $lines = [];
            $remaining = [];
            foreach ($data as $component => $configuration) {
                $line = $this->getLine((string)$component, $configuration, $target);
                if ($line === null) {
                    $remaining[$component] = $configuration;
                    continue;
                }

                $lines[] = $line;
            }

            if (empty($lines)) {
                continue;
            }

            $replacement = [];
            if (!empty($remaining)) {
                $replacement[] = 'data-mage-init="' . $this->escapeAttribute($this->encode((object)$remaining)) . '"';
            }

            if ($newId !== null) {
                $replacement[] = 'id="' . $this->escapeAttribute($newId) . '"';
            }

            $indexes = $item['indexes'];
            sort($indexes);
            $primaryIndex = array_shift($indexes);
            $plans['a' . $primaryIndex] = ['replacement' => implode(' ', $replacement), 'lines' => $lines];
            foreach ($indexes as $duplicateIndex) {
                $plans['a' . $duplicateIndex] = ['replacement' => '', 'lines' => []];
            }
        }

        return $plans;
    }

    /**
     * @return array<string, array{replacement: string, lines: string[]}>
     */
    private function getScriptPlans(DOMXPath $xpath, string $marker): array
    {
        $plans = [];
        foreach ($this->getMarkerAttributes($xpath, $marker, 's') as [$script, $index]) {
            if (strtolower($script->nodeName) !== 'script') {
                continue;
            }

            if (strtolower($script->getAttribute('type')) !== 'text/x-magento-init') {
                continue;
            }

            if ($this->isInsideRawTextElement($script)) {
                continue;
            }

            $data = $this->decode($this->removeMarkers($script->textContent, $marker), 'text/x-magento-init');
            if ($data === null) {
                continue;
            }

            $lines = [];
            $remaining = [];
            foreach ($data as $selector => $components) {
                if (!$components instanceof stdClass) {
                    $remaining[$selector] = $components;
                    continue;
                }

                foreach (get_object_vars($components) as $component => $configuration) {
                    $line = $this->getLine((string)$component, $configuration, ['selector' => (string)$selector]);
                    if ($line === null) {
                        $remaining[$selector][$component] = $configuration;
                        continue;
                    }

                    $lines[] = $line;
                }
            }

            if (empty($lines)) {
                continue;
            }

            $remaining = array_map(
                fn (mixed $value): mixed => is_array($value) ? (object)$value : $value,
                $remaining
            );
            $replacement = empty($remaining) ? '' : $this->encode((object)$remaining);
            $plans['s' . $index] = ['replacement' => $replacement, 'lines' => $lines];
        }

        return $plans;
    }

    /**
     * @return array<int, array{0: DOMElement, 1: int}>
     */
    private function getMarkerAttributes(DOMXPath $xpath, string $marker, string $type): array
    {
        $prefix = $marker . $type;
        $nodes = $xpath->query('//@*[starts-with(name(), "' . $prefix . '")]');
        if ($nodes === false) {
            return [];
        }

        $attributes = [];
        foreach ($nodes as $node) {
            $index = substr($node->nodeName, strlen($prefix));
            if (!ctype_digit($index)) {
                continue;
            }

            $element = $node instanceof DOMAttr ? $node->ownerElement : null;
            if (!$element instanceof DOMElement) {
                continue;
            }

            $attributes[] = [$element, (int)$index];
        }

        return $attributes;
    }

    /**
     * @param array<string, array{replacement: string, lines: string[]}> $plans
     * @return array{0: string, 1: string[]}
     */
    private function applyPlans(string $html, string $marker, array $plans): array
    {
        $quotedMarker = preg_quote($marker, '#');
        preg_match_all(
            '#' . $quotedMarker . 'a(\d+) | ' . $quotedMarker . 's(\d+)#',
            $html,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        $output = '';
        $lines = [];
        $cursor = 0;
        foreach ($matches as $match) {
            $offset = $match[0][1];
            if ($offset < $cursor) {
                continue;
            }

            $output .= substr($html, $cursor, $offset - $cursor);
            $cursor = $offset + strlen($match[0][0]);

            $isScript = isset($match[2]) && $match[2][1] >= 0;
            $key = $isScript ? 's' . $match[2][0] : 'a' . $match[1][0];
            if (!isset($plans[$key])) {
                continue;
            }

            $plan = $plans[$key];
            if ($isScript) {
                $scriptPattern = '#\G((?:[^>"\']|"[^"]*"|\'[^\']*\')*>)(.*?)(</script\s*>)#is';
                if (!preg_match($scriptPattern, $html, $script, 0, $cursor)) {
                    continue;
                }

                if ($plan['replacement'] === '') {
                    $output = substr($output, 0, -strlen('<script'));
                } else {
                    $output .= $this->removeMarkers($script[1], $marker) . $plan['replacement'] . $script[3];
                }

                $cursor += strlen($script[0]);
                $lines = array_merge($lines, $plan['lines']);
                continue;
            }

            $attributePattern = '#\Gdata-mage-init\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)#i';
            if (!preg_match($attributePattern, $html, $attribute, 0, $cursor)) {
                continue;
            }

            $output .= $plan['replacement'];
            $cursor += strlen($attribute[0]);
            $lines = array_merge($lines, $plan['lines']);
        }

        $output .= substr($html, $cursor);

        return [$this->removeMarkers($output, $marker), $lines];
    }

    /**
     * @param array<string, string> $target
     */
    private function getLine(string $component, mixed $configuration, array $target): ?string
    {
        if (!$this->componentFilter->isAllowed($component)) {
            return null;
        }

        try {
            return sprintf(
                'require([%1$s, "jquery"], function (fn, $) { fsl(%1$s, fn, %2$s, %3$s, $); });',
                $this->encode($component),
                $this->encode($configuration),
                $this->encode($target)
            );
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function decode(string $json, string $context): ?array
    {
        $json = trim($json, self::TRIM_CHARACTERS);
        if ($json === '') {
            return null;
        }

        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->debug('Yireo_FasterScriptLoader: Skipping invalid ' . $context . ' JSON: '
                . $exception->getMessage());

            return null;
        }

        if (!$data instanceof stdClass) {
            return null;
        }

        return get_object_vars($data);
    }

    private function encode(mixed $data): string
    {
        return json_encode($data, self::JSON_OUTPUT_FLAGS);
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function removeMarkers(string $text, string $marker): string
    {
        $quotedMarker = preg_quote($marker, '#');

        return (string)preg_replace('#' . $quotedMarker . 'a\d+ | ' . $quotedMarker . 's\d+#', '', $text);
    }

    private function isInsideRawTextElement(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->nodeName), self::RAW_TEXT_ELEMENTS, true)) {
                return true;
            }
        }

        return false;
    }
}
