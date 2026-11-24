<?php declare(strict_types=1);

namespace Yireo\FasterScriptLoader\Rewriter;

class ComponentFilter
{
    private const COMPONENT_PATTERN = '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-.]+)*$#D';

    /**
     * @param string[] $skippedComponents
     */
    public function __construct(
        private readonly array $skippedComponents = []
    ) {
    }

    public function isAllowed(string $component): bool
    {
        if (in_array($component, $this->skippedComponents, true)) {
            return false;
        }

        if (!preg_match(self::COMPONENT_PATTERN, $component)) {
            return false;
        }

        if (preg_match('#\.js$#iD', $component)) {
            return false;
        }

        foreach (explode('/', $component) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
