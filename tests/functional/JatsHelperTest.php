<?php

/**
 * @file JatsHelperTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS helper unit tests
 */

namespace APP\plugins\generic\jatsTemplate\functional;

use APP\plugins\generic\jatsTemplate\classes\JatsHelper;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;

#[CoversClass(JatsHelper::class)]
class JatsHelperTest extends PKPTestCase
{
    /**
     * Render an element via JatsHelper::htmlToJatsElement() and return its serialized XML.
     */
    private function render(string $tagName, string $html, bool $allowParagraphs = false): string
    {
        $doc = new DOMDocument();
        $root = $doc->appendChild($doc->createElement('root'));
        $root->appendChild(JatsHelper::htmlToJatsElement($doc, $tagName, $html, [], $allowParagraphs));
        return $doc->saveXML($root->firstChild);
    }

    /**
     * A "<" that doesn't open a tag, as in a title written through the API with a comparison
     * sign, is kept as escaped text rather than cutting off the rest of the content. A ">"
     * is kept as escaped text too.
     */
    #[DataProvider('comparisonSignProvider')]
    public function testKeepsComparisonSignsAsText(string $html, string $expectedXml): void
    {
        self::assertEquals($expectedXml, $this->render('article-title', $html));
    }

    /**
     * Content holding a stray "<" or ">", with the JATS it is expected to produce.
     */
    public static function comparisonSignProvider(): array
    {
        return [
            'before a digit' => [
                'Effects at p<0.05 in small trials',
                '<article-title>Effects at p&lt;0.05 in small trials</article-title>',
            ],
            'followed by a space' => [
                'a < b',
                '<article-title>a &lt; b</article-title>',
            ],
            'alongside formatting' => [
                'n<30 and <b>bold</b>',
                '<article-title>n&lt;30 and <bold>bold</bold></article-title>',
            ],
            'already escaped' => [
                'x &lt; y',
                '<article-title>x &lt; y</article-title>',
            ],
            'greater-than sign' => [
                'Effects at p>0.05',
                '<article-title>Effects at p&gt;0.05</article-title>',
            ],
            'greater-than sign alongside formatting' => [
                '<b>bold</b> > 3',
                '<article-title><bold>bold</bold> &gt; 3</article-title>',
            ],
            'less-than and greater-than signs together' => [
                'p<0.05 and q>0.1',
                '<article-title>p&lt;0.05 and q&gt;0.1</article-title>',
            ],
        ];
    }

    /**
     * A stray "<" is kept in content that holds paragraphs too.
     */
    public function testKeepsStrayLessThanSignInParagraphs(): void
    {
        self::assertEquals(
            '<notes><p>Significant at p&lt;0.05.</p></notes>',
            $this->render('notes', '<p>Significant at p<0.05.</p>', allowParagraphs: true)
        );
    }

    /**
     * Unsafe markup is removed along with its content, while formatting is kept.
     */
    public function testStripsUnsafeMarkup(): void
    {
        self::assertEquals(
            '<article-title>Title <italic>kept</italic></article-title>',
            $this->render('article-title', 'Title <script>alert(1)</script><i>kept</i>')
        );
    }
}
