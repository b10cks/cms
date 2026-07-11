<?php

namespace App\Services\Content\RichText;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Bidirectional converter between the ProseMirror/TipTap JSON documents stored in
 * richtext content fields and an HTML representation suitable for translation
 * interchange (XLIFF/CSV/…).
 *
 * The node/mark vocabulary mirrors the editor configuration in
 * resources/js/components/editor (StarterKit + Table + the custom InternalLink,
 * TextClass and PlaceholderToken extensions). Round-trips preserve structure and
 * marks; unknown nodes degrade gracefully to their text content.
 */
class ProseMirrorHtmlConverter
{
    /** Block-level HTML tags recognised when parsing HTML back into a document. */
    private const BLOCK_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'blockquote', 'pre', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /**
     * Serialize a ProseMirror document (or bare node list) to an HTML string.
     *
     * @param  mixed  $doc
     */
    public function toHtml($doc): string
    {
        if (! \is_array($doc)) {
            return '';
        }

        $content = $doc['content'] ?? (array_is_list($doc) ? $doc : []);

        return \is_array($content) ? $this->renderNodes($content) : '';
    }

    /**
     * Parse an HTML string into a ProseMirror document.
     *
     * @return array{type: string, content: array<int, array<string, mixed>>}
     */
    public function toDoc(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return ['type' => 'doc', 'content' => []];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__pm_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $this->findRoot($dom);

        $content = $root ? $this->parseFlow($root) : [];

        return ['type' => 'doc', 'content' => $content];
    }

    // ---------------------------------------------------------------------
    // Rendering: ProseMirror -> HTML
    // ---------------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $nodes
     */
    private function renderNodes(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {
            if (\is_array($node)) {
                $html .= $this->renderNode($node);
            }
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderNode(array $node): string
    {
        $type = $node['type'] ?? null;
        $content = \is_array($node['content'] ?? null) ? $node['content'] : [];

        return match ($type) {
            'text' => $this->renderText($node),
            'paragraph' => '<p>' . $this->renderNodes($content) . '</p>',
            'heading' => $this->renderHeading($node, $content),
            'bulletList' => '<ul>' . $this->renderNodes($content) . '</ul>',
            'orderedList' => $this->renderOrderedList($node, $content),
            'listItem' => '<li>' . $this->renderNodes($content) . '</li>',
            'blockquote' => '<blockquote>' . $this->renderNodes($content) . '</blockquote>',
            'codeBlock' => $this->renderCodeBlock($node, $content),
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'table' => '<table>' . $this->renderNodes($content) . '</table>',
            'tableRow' => '<tr>' . $this->renderNodes($content) . '</tr>',
            'tableHeader' => $this->renderTableCell('th', $node, $content),
            'tableCell' => $this->renderTableCell('td', $node, $content),
            'placeholderToken' => $this->renderPlaceholderToken($node),
            default => $this->renderNodes($content),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $content
     */
    private function renderHeading(array $node, array $content): string
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));

        return "<h{$level}>" . $this->renderNodes($content) . "</h{$level}>";
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $content
     */
    private function renderOrderedList(array $node, array $content): string
    {
        $start = $node['attrs']['start'] ?? null;
        $startAttr = ($start !== null && (int) $start !== 1) ? ' start="' . (int) $start . '"' : '';

        return "<ol{$startAttr}>" . $this->renderNodes($content) . '</ol>';
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $content
     */
    private function renderCodeBlock(array $node, array $content): string
    {
        $language = $node['attrs']['language'] ?? null;
        $classAttr = $language ? ' class="language-' . $this->escapeAttr((string) $language) . '"' : '';
        $text = '';

        foreach ($content as $child) {
            if (\is_array($child) && ($child['type'] ?? null) === 'text') {
                $text .= (string) ($child['text'] ?? '');
            }
        }

        return "<pre><code{$classAttr}>" . $this->escapeText($text) . '</code></pre>';
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, mixed>  $content
     */
    private function renderTableCell(string $tag, array $node, array $content): string
    {
        $attrs = '';

        foreach (['colspan', 'rowspan'] as $attr) {
            $value = $node['attrs'][$attr] ?? null;
            if ($value !== null && (int) $value !== 1) {
                $attrs .= ' ' . $attr . '="' . (int) $value . '"';
            }
        }

        return "<{$tag}{$attrs}>" . $this->renderNodes($content) . "</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderPlaceholderToken(array $node): string
    {
        $key = (string) ($node['attrs']['key'] ?? '');
        $label = $node['attrs']['label'] ?? null;
        $labelAttr = $label !== null ? ' data-label="' . $this->escapeAttr((string) $label) . '"' : '';

        return '<span data-type="placeholder-token" data-key="' . $this->escapeAttr($key) . '"'
            . $labelAttr . '>' . $this->escapeText('{' . $key . '}') . '</span>';
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function renderText(array $node): string
    {
        $text = $this->escapeText((string) ($node['text'] ?? ''));
        $marks = \is_array($node['marks'] ?? null) ? $node['marks'] : [];

        foreach ($marks as $mark) {
            if (! \is_array($mark)) {
                continue;
            }

            [$open, $close] = $this->renderMark($mark);
            $text = $open . $text . $close;
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array{0: string, 1: string}
     */
    private function renderMark(array $mark): array
    {
        $attrs = \is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];

        return match ($mark['type'] ?? null) {
            'bold' => ['<strong>', '</strong>'],
            'italic' => ['<em>', '</em>'],
            'strike' => ['<s>', '</s>'],
            'underline' => ['<u>', '</u>'],
            'code' => ['<code>', '</code>'],
            'link' => [$this->renderLinkOpen($attrs), '</a>'],
            'internalLink' => [$this->renderInternalLinkOpen($attrs), '</a>'],
            'textClass' => ['<span class="' . $this->escapeAttr((string) ($attrs['class'] ?? '')) . '">', '</span>'],
            default => ['', ''],
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function renderLinkOpen(array $attrs): string
    {
        $html = '<a href="' . $this->escapeAttr((string) ($attrs['href'] ?? '')) . '"';

        foreach (['target', 'rel', 'class', 'title'] as $attr) {
            $value = $attrs[$attr] ?? null;
            if ($value !== null && $value !== '') {
                $html .= ' ' . $attr . '="' . $this->escapeAttr((string) $value) . '"';
            }
        }

        return $html . '>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function renderInternalLinkOpen(array $attrs): string
    {
        $html = '<a data-type="internal"';
        $html .= ' data-content="' . $this->escapeAttr((string) ($attrs['content'] ?? '')) . '"';

        $anchor = $attrs['anchor'] ?? null;
        if ($anchor !== null && $anchor !== '') {
            $html .= ' data-anchor="' . $this->escapeAttr((string) $anchor) . '"';
        }

        return $html . ' href="#">';
    }

    // ---------------------------------------------------------------------
    // Parsing: HTML -> ProseMirror
    // ---------------------------------------------------------------------

    private function findRoot(DOMDocument $dom): ?DOMElement
    {
        $root = $dom->getElementById('__pm_root__');

        if ($root instanceof DOMElement) {
            return $root;
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : $dom->documentElement;
    }

    /**
     * Parse a container's children into a list of block nodes, grouping loose
     * inline content into paragraphs.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFlow(DOMNode $parent): array
    {
        $blocks = [];
        $inline = [];

        $flush = function () use (&$blocks, &$inline): void {
            if ($inline !== []) {
                $blocks[] = ['type' => 'paragraph', 'content' => $inline];
                $inline = [];
            }
        };

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlockTag($child->nodeName)) {
                $flush();
                $block = $this->parseBlock($child);
                if ($block !== null) {
                    $blocks[] = $block;
                }

                continue;
            }

            $inline = [...$inline, ...$this->parseInline($child, [])];
        }

        $flush();

        return $blocks;
    }

    private function isBlockTag(string $tag): bool
    {
        return \in_array(strtolower($tag), self::BLOCK_TAGS, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseBlock(DOMElement $element): ?array
    {
        $tag = strtolower($element->nodeName);

        return match ($tag) {
            'p' => ['type' => 'paragraph', 'content' => $this->parseInlineChildren($element)],
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => [
                'type' => 'heading',
                'attrs' => ['level' => (int) substr($tag, 1)],
                'content' => $this->parseInlineChildren($element),
            ],
            'ul' => ['type' => 'bulletList', 'content' => $this->parseListItems($element)],
            'ol' => $this->parseOrderedList($element),
            'li' => ['type' => 'listItem', 'content' => $this->parseListItemContent($element)],
            'blockquote' => ['type' => 'blockquote', 'content' => $this->parseFlow($element)],
            'pre' => $this->parseCodeBlock($element),
            'hr' => ['type' => 'horizontalRule'],
            'table' => ['type' => 'table', 'content' => $this->parseTableRows($element)],
            'thead', 'tbody' => null,
            'tr' => ['type' => 'tableRow', 'content' => $this->parseTableCells($element)],
            'th' => $this->parseTableCell('tableHeader', $element),
            'td' => $this->parseTableCell('tableCell', $element),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOrderedList(DOMElement $element): array
    {
        $node = ['type' => 'orderedList', 'content' => $this->parseListItems($element)];
        $start = $element->getAttribute('start');

        if ($start !== '' && (int) $start !== 1) {
            $node['attrs'] = ['start' => (int) $start];
        }

        return $node;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseListItems(DOMElement $element): array
    {
        $items = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $items[] = ['type' => 'listItem', 'content' => $this->parseListItemContent($child)];
            }
        }

        return $items;
    }

    /**
     * A list item wraps block content; loose inline text is promoted to a paragraph.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseListItemContent(DOMElement $element): array
    {
        $content = $this->parseFlow($element);

        return $content === [] ? [['type' => 'paragraph', 'content' => []]] : $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCodeBlock(DOMElement $element): array
    {
        $node = ['type' => 'codeBlock'];
        $text = $element->textContent;

        $code = null;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'code') {
                $code = $child;
                break;
            }
        }

        if ($code !== null) {
            $text = $code->textContent;
            $class = $code->getAttribute('class');
            if (preg_match('/language-([\w-]+)/', $class, $matches) === 1) {
                $node['attrs'] = ['language' => $matches[1]];
            }
        }

        if ($text !== '') {
            $node['content'] = [['type' => 'text', 'text' => $text]];
        }

        return $node;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTableRows(DOMElement $element): array
    {
        $rows = [];

        foreach ($element->getElementsByTagName('tr') as $tr) {
            $rows[] = ['type' => 'tableRow', 'content' => $this->parseTableCells($tr)];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTableCells(DOMElement $row): array
    {
        $cells = [];

        foreach ($row->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if ($tag === 'th') {
                $cells[] = $this->parseTableCell('tableHeader', $child);
            } elseif ($tag === 'td') {
                $cells[] = $this->parseTableCell('tableCell', $child);
            }
        }

        return $cells;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTableCell(string $type, DOMElement $element): array
    {
        $node = ['type' => $type];
        $attrs = [];

        foreach (['colspan', 'rowspan'] as $attr) {
            $value = $element->getAttribute($attr);
            if ($value !== '' && (int) $value !== 1) {
                $attrs[$attr] = (int) $value;
            }
        }

        if ($attrs !== []) {
            $node['attrs'] = $attrs;
        }

        $content = $this->parseFlow($element);
        $node['content'] = $content === [] ? [['type' => 'paragraph', 'content' => []]] : $content;

        return $node;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseInlineChildren(DOMElement $element): array
    {
        $nodes = [];

        foreach ($element->childNodes as $child) {
            $nodes = [...$nodes, ...$this->parseInline($child, [])];
        }

        return $nodes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function parseInline(DOMNode $node, array $marks): array
    {
        if ($node instanceof DOMText) {
            $text = $node->textContent;

            if ($text === '') {
                return [];
            }

            $textNode = ['type' => 'text', 'text' => $text];
            if ($marks !== []) {
                $textNode['marks'] = $marks;
            }

            return [$textNode];
        }

        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->nodeName);

        if ($tag === 'br') {
            return [['type' => 'hardBreak']];
        }

        if ($tag === 'span' && $node->getAttribute('data-type') === 'placeholder-token') {
            return [[
                'type' => 'placeholderToken',
                'attrs' => [
                    'key' => $node->getAttribute('data-key'),
                    'label' => $node->getAttribute('data-label'),
                ],
            ]];
        }

        $mark = $this->markFromElement($node);
        $childMarks = $mark !== null ? [...$marks, $mark] : $marks;

        $nodes = [];
        foreach ($node->childNodes as $child) {
            $nodes = [...$nodes, ...$this->parseInline($child, $childMarks)];
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function markFromElement(DOMElement $element): ?array
    {
        $tag = strtolower($element->nodeName);

        return match ($tag) {
            'strong', 'b' => ['type' => 'bold'],
            'em', 'i' => ['type' => 'italic'],
            's', 'strike', 'del' => ['type' => 'strike'],
            'u' => ['type' => 'underline'],
            'code' => ['type' => 'code'],
            'a' => $this->markFromAnchor($element),
            'span' => $element->getAttribute('class') !== ''
                ? ['type' => 'textClass', 'attrs' => ['class' => $element->getAttribute('class')]]
                : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function markFromAnchor(DOMElement $element): array
    {
        if ($element->getAttribute('data-type') === 'internal') {
            return [
                'type' => 'internalLink',
                'attrs' => [
                    'content' => $element->getAttribute('data-content') ?: null,
                    'anchor' => $element->getAttribute('data-anchor') ?: null,
                ],
            ];
        }

        $attrs = ['href' => $element->getAttribute('href')];

        foreach (['target', 'rel', 'class', 'title'] as $attr) {
            $value = $element->getAttribute($attr);
            if ($value !== '') {
                $attrs[$attr] = $value;
            }
        }

        return ['type' => 'link', 'attrs' => $attrs];
    }

    // ---------------------------------------------------------------------

    private function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
