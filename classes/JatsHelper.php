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

class JatsHelper
{
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
