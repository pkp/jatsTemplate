<?php

/**
 * @file JatsHelper.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS helper class.
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use DOMDocument;
use DOMNode;

class JatsHelper
{
    /**
     * Convert an HTML string to a JATS element, preserving safe inline markup (italic, bold,
     * underline, sup/sub, links, and optionally paragraphs). Falls back to a plain
     * escaped-text element (with the same attributes) if the converted markup fails to
     * parse as XML.
     *
     * @param array<string, string> $attributes Attributes to set on the created element
     * @param bool $allowParagraphs Preserve source <p> tags, for elements whose content model
     *             requires block-level content rather than direct inline text (e.g. <notes>, <bio>,
     *             <fn>). Content with no source <p> tags is wrapped in one, since these elements'
     *             content models don't allow bare text either.
     */
    public static function htmlToJatsElement(
        DOMDocument $ownerDocument,
        string $tagName,
        string $html,
        array $attributes = [],
        bool $allowParagraphs = false
    ): DOMNode {
        $allowedTags = '<i><em><b><strong><u><a><sup><sub>' . ($allowParagraphs ? '<p>' : '');
        $cleaned = strip_tags($html, $allowedTags);
        // Stored rich-text HTML already has literal special characters entity-encoded (e.g. "&" as
        // "&amp;") - decode before re-escaping, or they'd be double-escaped (e.g. "&amp;amp;").
        $decoded = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaped = htmlspecialchars($decoded, ENT_COMPAT, 'UTF-8');
        $jatsText = self::htmlToJats($escaped);
        if ($allowParagraphs && $jatsText !== '' && !preg_match('/^<p[ >]/', $jatsText)) {
            $jatsText = "<p>$jatsText</p>";
        }
        $innerXml = $jatsText;

        $attributesXml = '';
        foreach ($attributes as $name => $value) {
            $attributesXml .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8') . '"';
        }

        $fragment = $ownerDocument->createDocumentFragment();
        // Suppress warnings from malformed user-provided markup
        if (@$fragment->appendXML("<$tagName$attributesXml>$innerXml</$tagName>")) {
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
     * Convert escaped HTML formatting tags to JATS equivalents
     */
    public static function htmlToJats(string $escapedText): string
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
            '&lt;p&gt;' => '<p>',
            '&lt;/p&gt;' => '</p>',
            '&lt;/a&gt;' => '</ext-link>',
        ];

        $jatsText = str_replace(array_keys($mapping), array_values($mapping), $escapedText);

        // Convert links: &lt;a ... href=&quot;URL&quot; ...&gt; (any attribute order) → <ext-link>
        return preg_replace(
            '/&lt;a\b(?:(?!&gt;).)*?\bhref=(?:&quot;|\')(.*?)(?:&quot;|\')(?:(?!&gt;).)*&gt;/i',
            '<ext-link ext-link-type="uri" xlink:href="$1">',
            $jatsText
        );
    }
}
