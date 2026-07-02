<?php

namespace App\Services\Icon;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use RuntimeException;

/**
 * Parses an uploaded SVG into the Iconify "body" + viewBox dimensions, sanitizing
 * the markup so the stored icon can be rendered inline without XSS risk.
 */
class IconSvgParser
{
    /**
     * Presentation attributes on the root <svg> element that are inherited by child elements
     * and must be preserved. Following the Iconify convention, these are hoisted into a
     * wrapping <g> so the stored body string remains fully self-contained.
     */
    private const SVG_PRESENTATION_ATTRIBUTES = [
        'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-opacity', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset',
        'color', 'opacity', 'clip-rule',
    ];

    /**
     * SVG elements that are safe to keep (lower-cased for case-insensitive comparison;
     * SVG is case-sensitive so e.g. `linearGradient` is matched as `lineargradient`).
     */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect',
        'defs', 'clippath', 'mask', 'pattern', 'lineargradient', 'radialgradient', 'stop',
        'use', 'symbol', 'title', 'desc', 'text', 'tspan', 'textpath', 'marker',
        'filter', 'fegaussianblur', 'feoffset', 'feblend', 'feflood', 'fecomposite',
        'femerge', 'femergenode', 'fecolormatrix', 'femorphology', 'fedropshadow',
        'fespecularlighting', 'fediffuselighting', 'fepointlight', 'fedistantlight',
        'fespotlight', 'feturbulence', 'fedisplacementmap', 'feimage', 'fetile',
        'animate', 'animatetransform', 'animatemotion', 'set', 'metadata',
    ];

    /** Attributes that reference other resources; only internal `#fragment` refs are kept. */
    private const REFERENCE_ATTRIBUTES = ['href', 'xlink:href', 'src'];

    /**
     * @return array{body: string, width: int, height: int}
     */
    public function parse(string $svg): array
    {
        $svg = trim($svg);

        if ($svg === '') {
            throw new RuntimeException('Empty SVG document.');
        }

        $previous = libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$doc->documentElement) {
            throw new RuntimeException('Invalid SVG document.');
        }

        // Reject DOCTYPE declarations outright — they are the vector for XXE / entity attacks
        // and have no legitimate place in an icon.
        if ($doc->doctype !== null) {
            throw new RuntimeException('SVG must not contain a DOCTYPE declaration.');
        }

        $root = $doc->documentElement;

        if (strtolower($root->localName ?? $root->nodeName) !== 'svg') {
            throw new RuntimeException('Root element is not <svg>.');
        }

        [$width, $height] = $this->resolveDimensions($root);

        $this->sanitizeChildren($root);

        $body = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $body .= $doc->saveXML($child);
        }

        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('SVG has no renderable content.');
        }

        // Hoist inherited presentation attributes from the root <svg> into a <g> wrapper
        // so the stored body is fully self-contained — the Iconify-standard approach.
        $inheritedAttrs = $this->collectPresentationAttributes($root);
        if ($inheritedAttrs !== '') {
            $body = "<g {$inheritedAttrs}>{$body}</g>";
        }

        return [
            'body' => $body,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveDimensions(DOMElement $svg): array
    {
        $viewBox = $svg->getAttribute('viewBox');

        if ($viewBox !== '') {
            $parts = preg_split('/[\s,]+/', trim($viewBox)) ?: [];

            if (count($parts) === 4) {
                $width = (int) round((float) $parts[2]);
                $height = (int) round((float) $parts[3]);

                if ($width > 0 && $height > 0) {
                    return [$width, $height];
                }
            }
        }

        $width = $this->parseLength($svg->getAttribute('width'));
        $height = $this->parseLength($svg->getAttribute('height'));

        if ($width > 0 && $height > 0) {
            return [$width, $height];
        }

        return [24, 24];
    }

    private function parseLength(string $value): int
    {
        if (trim($value) === '') {
            return 0;
        }

        if (preg_match('/^\s*([0-9]*\.?[0-9]+)/', $value, $matches)) {
            return (int) round((float) $matches[1]);
        }

        return 0;
    }

    /**
     * Collects whitelisted presentation attributes from the root <svg> element and returns
     * them as a ready-to-embed attribute string, e.g. `fill="none" stroke-width="2"`.
     */
    private function collectPresentationAttributes(DOMElement $svg): string
    {
        $parts = [];

        foreach (self::SVG_PRESENTATION_ATTRIBUTES as $attr) {
            $value = $svg->getAttribute($attr);
            if ($value !== '') {
                $parts[] = sprintf('%s="%s"', $attr, htmlspecialchars($value, ENT_QUOTES | ENT_XML1));
            }
        }

        return implode(' ', $parts);
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->localName ?? $child->nodeName);

                if (!in_array($name, self::ALLOWED_ELEMENTS, true)) {
                    $node->removeChild($child);
                    continue;
                }

                $this->sanitizeAttributes($child);
                $this->sanitizeChildren($child);
            } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = $attribute->nodeValue ?? '';

            // Event handlers (onload, onclick, …).
            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            // Resource references: keep only internal fragment links (e.g. url(#id), #gradient).
            if (in_array($name, self::REFERENCE_ATTRIBUTES, true)) {
                if (!str_starts_with(ltrim($value), '#')) {
                    $element->removeAttributeNode($attribute);
                }
                continue;
            }

            // Strip any value carrying a script payload (javascript: URIs, CSS expression()).
            $normalized = preg_replace('/\s+/', '', strtolower($value)) ?? '';
            if (str_contains($normalized, 'javascript:') || str_contains($normalized, 'expression(')) {
                $element->removeAttributeNode($attribute);
            }
        }
    }
}
