<?php

/**
 * @file JatsHelper.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Convert user-entered HTML into JATS markup.
 *
 * Content passes through a fixed pipeline: unsafe markup is removed, the HTML is reduced to the
 * tags JATS can carry, entities are decoded and the text re-escaped, the remaining tags are
 * mapped to their JATS equivalents, and block-level content is normalized into the paragraphs
 * and lists its element's content model requires. Content that still fails to parse as XML
 * falls back to plain text.
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use DOMDocument;
use DOMElement;
use DOMNode;
use PKP\core\PKPString;

class JatsHelper
{
    /**
     * Convert an HTML string to a JATS element, preserving safe inline markup (italic, bold,
     * underline, sup/sub, links, and optionally paragraphs). Falls back to a plain
     * escaped-text element (with the same attributes) if the converted markup fails to
     * parse as XML. Returns null when block-level content is required and the source holds
     * nothing but blank paragraphs: an empty element would not be valid JATS.
     *
     * @param array<string, string> $attributes Attributes to set on the created element
     * @param bool $allowParagraphs Preserve source <p> tags and lists, for elements whose content
     *             model requires block-level content rather than direct inline text (e.g. <notes>,
     *             <bio>, <fn>). The content is normalized into <p> elements, since these elements'
     *             content models don't allow bare text either, an <ol> or <ul> becomes a JATS
     *             <list> carried in a paragraph, and a <br> ends one paragraph and starts the next.
     *             Where only inline content is allowed, a <br> becomes a space.
     */
    public static function htmlToJatsElement(
        DOMDocument $ownerDocument,
        string $tagName,
        string $html,
        array $attributes = [],
        bool $allowParagraphs = false
    ): ?DOMNode {
        $innerXml = self::htmlToJatsContent($html, $allowParagraphs);
        if ($allowParagraphs && $innerXml === '') {
            return null;
        }

        $attributesXml = '';
        foreach ($attributes as $name => $value) {
            $attributesXml .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8') . '"';
        }

        $fragment = $ownerDocument->createDocumentFragment();
        // Suppress warnings from malformed user-provided markup
        if (@$fragment->appendXML("<{$tagName}{$attributesXml}>{$innerXml}</{$tagName}>")) {
            return $fragment;
        }

        // Fallback if XML parsing fails - createElement handles escaping automatically. strip_tags()
        // here (with no allowed tags) always removes any source <p>, so wrap unconditionally when
        // $allowParagraphs requires block-level content.
        $fallbackText = htmlspecialchars(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_COMPAT, 'UTF-8');
        $fallbackNode = $allowParagraphs
            ? $ownerDocument->createElement($tagName)
            : $ownerDocument->createElement($tagName, $fallbackText);
        if ($allowParagraphs) {
            $fallbackNode->appendChild($ownerDocument->createElement('p', $fallbackText));
        }
        foreach ($attributes as $name => $value) {
            $fallbackNode->setAttribute($name, $value);
        }
        return $fallbackNode;
    }

    /**
     * Convert an HTML string to JATS XML that goes inside an element: unsafe markup removed, inline
     * markup converted, and for block-level content the paragraphs, lists and breaks normalized.
     *
     * @param bool $allowParagraphs Whether the target element holds block-level content; see htmlToJatsElement()
     */
    public static function htmlToJatsContent(string $html, bool $allowParagraphs = false): string
    {
        $html = PKPString::stripUnsafeHtml($html);
        // <li> is kept in both modes: as a list item where block content is allowed, and as a
        // separator between items where it is not
        $allowedTags = '<i><em><b><strong><u><a><sup><sub><br><li>' . ($allowParagraphs ? '<p><ol><ul>' : '');
        $cleaned = strip_tags($html, $allowedTags);
        // Stored rich-text HTML already has literal special characters entity-encoded (e.g. "&" as
        // "&amp;") - decode before re-escaping, or they'd be double-escaped (e.g. "&amp;amp;").
        $decoded = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaped = htmlspecialchars($decoded, ENT_COMPAT, 'UTF-8');
        // JATS has no in-paragraph line break: in block content a break ends the paragraph, which
        // normalizeParagraphs() carries out on the <break/> marker; inline content gets a space.
        // Attribute values are escaped too at this point, so a tag's attributes are skipped up to
        // its closing "&gt;" rather than up to the next "&".
        $escaped = preg_replace('/&lt;br\b(?:(?!&gt;).)*&gt;/is', $allowParagraphs ? '<break/>' : ' ', $escaped);
        if (!$allowParagraphs) {
            // Where a list cannot be built, use a separator
            $escaped = preg_replace('/\s*&lt;\/li&gt;\s*&lt;li\b(?:(?!&gt;).)*&gt;\s*/is', '; ', $escaped);
            $escaped = preg_replace('/\s*&lt;\/?li\b(?:(?!&gt;).)*&gt;\s*/is', ' ', $escaped);
            $escaped = trim(preg_replace('/ {2,}/', ' ', $escaped));
        }
        $jatsText = self::convertEscapedTags($escaped);

        return $allowParagraphs ? self::normalizeParagraphs($jatsText) : $jatsText;
    }

    /**
     * Whether an HTML string holds anything once converted to block-level content.
     */
    public static function hasBlockContent(string $html): bool
    {
        return self::htmlToJatsContent($html, allowParagraphs: true) !== '';
    }

    /**
     * Convert the escaped HTML tags in text to their JATS equivalents, leaving the text itself escaped.
     */
    protected static function convertEscapedTags(string $escapedText): string
    {
        $mapping = [
            '&lt;i&gt;' => '<italic>',
            '&lt;/i&gt;' => '</italic>',
            '&lt;em&gt;' => '<italic>',
            '&lt;/em&gt;' => '</italic>',
            '&lt;b&gt;' => '<bold>',
            '&lt;/b&gt;' => '</bold>',
            '&lt;strong&gt;' => '<bold>',
            '&lt;/strong&gt;' => '</bold>',
            '&lt;u&gt;' => '<underline>',
            '&lt;/u&gt;' => '</underline>',
            '&lt;sup&gt;' => '<sup>',
            '&lt;/sup&gt;' => '</sup>',
            '&lt;sub&gt;' => '<sub>',
            '&lt;/sub&gt;' => '</sub>',
            '&lt;/p&gt;' => '</p>',
            '&lt;/ol&gt;' => '</list>',
            '&lt;/ul&gt;' => '</list>',
            '&lt;/li&gt;' => '</list-item>',
        ];

        $jatsText = str_replace(array_keys($mapping), array_values($mapping), $escapedText);

        // A rich-text editor may leave attributes on a paragraph; JATS <p> has no equivalent, so drop them.
        $jatsText = preg_replace('/&lt;p\b(?:(?!&gt;).)*&gt;/is', '<p>', $jatsText);

        // Convert lists: <ol> or <ul> → JATS <list> of <list-item>
        $jatsText = preg_replace('/&lt;ol\b(?:(?!&gt;).)*&gt;/is', '<list list-type="order">', $jatsText);
        $jatsText = preg_replace('/&lt;ul\b(?:(?!&gt;).)*&gt;/is', '<list list-type="bullet">', $jatsText);
        $jatsText = preg_replace('/&lt;li\b(?:(?!&gt;).)*&gt;/is', '<list-item>', $jatsText);

        // Convert links: &lt;a ... href=&quot;URL&quot; ...&gt; (any attribute order) → <ext-link>,
        // or <email> for a mailto link, holding the address rather than the link text.
        // A link left without an href is reduced to its text, and a stray tag is dropped.
        $jatsText = preg_replace_callback(
            '/&lt;a\b((?:(?!&gt;).)*)&gt;(.*?)&lt;\/a&gt;/is',
            function (array $matches): string {
                if (preg_match('/\bhref=(?:&quot;|\')(.*?)(?:&quot;|\')/i', $matches[1], $href)) {
                    if (preg_match('/^mailto:(.+)$/i', $href[1], $mailto)) {
                        return '<email>' . $mailto[1] . '</email>';
                    }
                    return '<ext-link ext-link-type="uri" xlink:href="' . $href[1] . '">' . $matches[2] . '</ext-link>';
                }
                return $matches[2];
            },
            $jatsText
        );
        return preg_replace('/&lt;\/?a\b(?:(?!&gt;).)*&gt;/is', '', $jatsText);
    }

    /**
     * Normalize converted content into the sequence of <p> elements required by an element whose
     * content model is block-level. Content lying outside a source <p> - text preceding the first
     * one, or between two of them - becomes a paragraph of its own rather than being swallowed
     * into a wrapper around them, and a nested source <p> is flattened: JATS allows neither bare
     * text nor a <p> inside a <p>.
     *
     * A <list> is carried inside a paragraph, the one place every block-level content model lets one stand.
     * Inside a list item the item's text becomes a paragraph, and a nested list follows it directly, as the
     * <list-item> content model requires. Empty items and empty lists are dropped.
     *
     * A <br> ends the paragraph it is in and starts the next one. One inside an inline element becomes a space.
     *
     * The content is normalized as a DOM tree; if it cannot be parsed it is split on paragraph tags instead.
     */
    public static function normalizeParagraphs(string $jatsText): string
    {
        // The xlink prefix is deliberately left undeclared: declared, libxml would bind the
        // link attributes to the namespace and redeclare it on every element they are moved
        // to, while the rest of the document carries them as plain "xlink:href" attributes
        // under the declaration on <article>. Undeclared, they parse as plain attributes.
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $parsed = $document->loadXML('<root>' . $jatsText . '</root>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$parsed) {
            return self::splitParagraphs($jatsText);
        }

        $blocks = self::toBlocks($document->documentElement);

        $xml = '';
        foreach ($blocks as $block) {
            $xml .= $document->saveXML($block);
        }
        return $xml;
    }

    /**
     * Split content on paragraph tags, wrapping each run in a paragraph of its own.
     */
    protected static function splitParagraphs(string $jatsText): string
    {
        $paragraphs = [];
        // Only paragraph tags recognized by convertEscapedTags() are unescaped at this point, so splitting
        // on them cannot break on a literal "<p>" the author wrote, which is still escaped.
        $jatsText = str_replace('<break/>', '</p><p>', $jatsText);
        foreach (preg_split('/<\/?p>/', $jatsText) as $fragment) {
            $fragment = self::trimSpace($fragment);
            if ($fragment !== '') {
                $paragraphs[] = '<p>' . $fragment . '</p>';
            }
        }
        return implode('', $paragraphs);
    }

    /**
     * The block-level sequence a container's children make: every run of inline content
     * becomes a paragraph, a paragraph is flattened around any paragraph nested in it, and a
     * list is normalized and carried in a paragraph of its own.
     *
     * @param bool $listsStandAlone Whether a list may be a block by itself, as it may inside a
     *  list item, rather than needing a paragraph around it
     *
     * @return DOMElement[]
     */
    protected static function toBlocks(DOMElement $container, bool $listsStandAlone = false): array
    {
        $document = $container->ownerDocument;
        $blocks = [];
        $paragraph = null;

        foreach (iterator_to_array($container->childNodes) as $child) {
            switch ($child instanceof DOMElement ? $child->tagName : null) {
                case 'p':
                    $paragraph = null;
                    array_push($blocks, ...self::toBlocks($child, listsStandAlone: false));
                    break;
                case 'list':
                    $list = self::normalizeList($child);
                    if (!$list) {
                        break;
                    }
                    if ($listsStandAlone) {
                        $paragraph = null;
                        $blocks[] = $list;
                    } else {
                        $paragraph ??= $blocks[] = $document->createElement('p');
                        $paragraph->appendChild($list);
                    }
                    break;
                case 'list-item':
                    // An item outside any list has lost its list to malformed markup: keep its content
                    $paragraph = null;
                    array_push($blocks, ...self::toBlocks($child, $listsStandAlone));
                    break;
                case 'break':
                    // A line break ends the paragraph; the next inline content starts a new one
                    $paragraph = null;
                    break;
                default:
                    // Inline content: text and formatting, gathered into the current paragraph. A break
                    // inside a formatting element cannot split the paragraph, so it becomes a space, and
                    // a paragraph or list nested in one is unwrapped, since a block cannot sit inline.
                    if ($child instanceof DOMElement) {
                        self::unwrapNestedBlocks($child);
                    }
                    $paragraph ??= $blocks[] = $document->createElement('p');
                    $paragraph->appendChild($child);
            }
        }

        foreach ($blocks as $block) {
            self::trimParagraph($block);
        }

        return array_values(array_filter($blocks, fn (DOMElement $block) => self::hasContent($block)));
    }

    /**
     * Replace every block element nested in an inline element with its own content, a paragraph
     * or list item set off by a space, and every break with a space.
     */
    protected static function unwrapNestedBlocks(DOMElement $inline): void
    {
        $document = $inline->ownerDocument;
        foreach (['break', 'p', 'list-item', 'list'] as $tagName) {
            foreach (iterator_to_array($inline->getElementsByTagName($tagName)) as $block) {
                $previous = $block->previousSibling;
                if (
                    $tagName !== 'list' &&
                    !($previous?->nodeType === XML_TEXT_NODE && preg_match('/\s$/', $previous->nodeValue))
                ) {
                    $block->parentNode->insertBefore($document->createTextNode(' '), $block);
                }
                while ($block->firstChild) {
                    $block->parentNode->insertBefore($block->firstChild, $block);
                }
                $block->parentNode->removeChild($block);
            }
        }
    }

    /**
     * Normalize a list: each item becomes a paragraph of its own content followed by any
     * nested list, an item with nothing in it is dropped, and so is a list left without items.
     */
    protected static function normalizeList(DOMElement $list): ?DOMElement
    {
        foreach (iterator_to_array($list->childNodes) as $child) {
            if (!($child instanceof DOMElement && $child->tagName === 'list-item')) {
                // A list may hold only items; stray content is folded into a new item
                if ($child instanceof DOMElement || trim($child->textContent) !== '') {
                    $item = $list->insertBefore($list->ownerDocument->createElement('list-item'), $child);
                    $item->appendChild($child);
                    $child = $item;
                } else {
                    $list->removeChild($child);
                    continue;
                }
            }
            $blocks = self::toBlocks($child, listsStandAlone: true);
            while ($child->firstChild) {
                $child->removeChild($child->firstChild);
            }
            foreach ($blocks as $block) {
                $child->appendChild($block);
            }
            if (!$child->hasChildNodes()) {
                $list->removeChild($child);
            }
        }

        return $list->hasChildNodes() ? $list : null;
    }

    /**
     * Trim the whitespace at either end of a paragraph, non-breaking spaces included.
     */
    protected static function trimParagraph(DOMElement $block): void
    {
        if ($block->tagName !== 'p') {
            return;
        }
        // Dropping an element can leave adjacent text nodes; merge them first, or only the
        // last of them would be trimmed
        $block->normalize();
        if ($block->firstChild?->nodeType === XML_TEXT_NODE) {
            $block->firstChild->nodeValue = preg_replace('/^[\s\x{00A0}]+/u', '', $block->firstChild->nodeValue);
        }
        if ($block->lastChild?->nodeType === XML_TEXT_NODE) {
            $block->lastChild->nodeValue = preg_replace('/[\s\x{00A0}]+$/u', '', $block->lastChild->nodeValue);
        }
    }

    /**
     * Whether a block holds elements or text.
     */
    protected static function hasContent(DOMElement $block): bool
    {
        foreach ($block->childNodes as $node) {
            if ($node instanceof DOMElement || self::trimSpace($node->nodeValue) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Trim whitespace at either end of a string, counting the non-breaking space an editor
     * writes into a blank line as whitespace too.
     */
    protected static function trimSpace(string $text): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $text);
    }
}
