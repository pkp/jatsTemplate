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
    private function render(string $tagName, string $html, array $attributes = [], bool $allowParagraphs = false): string
    {
        $doc = new DOMDocument();
        $root = $doc->appendChild($doc->createElement('root'));
        $element = JatsHelper::htmlToJatsElement($doc, $tagName, $html, $attributes, $allowParagraphs);
        if ($element) {
            $root->appendChild($element);
        }
        return $doc->saveXML($root);
    }

    /**
     * Stored rich-text HTML already has literal special characters entity-encoded - the
     * conversion must not re-escape them (e.g. "&amp;" becoming "&amp;amp;").
     */
    public function testDoesNotDoubleEscapeStoredEntities()
    {
        self::assertSame(
            '<root><article-title>Cats &amp; <bold>Dogs</bold></article-title></root>',
            $this->render('article-title', 'Cats &amp; <b>Dogs</b>')
        );
    }

    /**
     * An "&" inside a link's query string must remain correctly, singly escaped once converted
     * to a xlink:href attribute.
     */
    public function testPreservesEscapedAmpersandInLinkHref()
    {
        self::assertSame(
            '<root><bio xml:lang="en"><p>See <ext-link ext-link-type="uri" xlink:href="https://example.com/?a=1&amp;b=2">this link</ext-link></p></bio></root>',
            $this->render('bio', '<p>See <a href="https://example.com/?a=1&amp;b=2">this link</a></p>', ['xml:lang' => 'en'], allowParagraphs: true)
        );
    }

    /**
     * Elements requiring block-level content (allowParagraphs: true) must not contain bare text -
     * content with no source <p> tags is auto-wrapped in one.
     */
    public function testWrapsUnwrappedContentInParagraphWhenParagraphsAllowed()
    {
        self::assertSame(
            '<root><fn fn-type="coi-statement" id="x"><p>Plain competing interest text, no markup.</p></fn></root>',
            $this->render('fn', 'Plain competing interest text, no markup.', ['fn-type' => 'coi-statement', 'id' => 'x'], allowParagraphs: true)
        );
    }

    /**
     * Content that already has a source <p> must not be wrapped again (avoiding invalid <p><p>...</p></p>).
     */
    public function testDoesNotDoubleWrapContentThatAlreadyHasAParagraph()
    {
        self::assertSame(
            '<root><bio xml:lang="en"><p>Already a paragraph.</p></bio></root>',
            $this->render('bio', '<p>Already a paragraph.</p>', ['xml:lang' => 'en'], allowParagraphs: true)
        );
    }

    /**
     * Multiple source paragraphs are preserved as separate <p> elements, not merged into one.
     */
    public function testPreservesMultipleParagraphsSeparately()
    {
        self::assertSame(
            '<root><notes notes-type="update-notice"><p>First.</p><p>Second.</p></notes></root>',
            $this->render('notes', '<p>First.</p><p>Second.</p>', ['notes-type' => 'update-notice'], allowParagraphs: true)
        );
    }

    /**
     * Content preceding the first source <p> - a heading run, say - becomes a paragraph of its
     * own. Wrapping the whole string instead would nest the source paragraphs inside it, which
     * will fail DTD validation.
     */
    public function testWrapsContentPrecedingTheFirstParagraphSeparately()
    {
        self::assertSame(
            '<root><notes notes-type="update-notice"><p><bold>Amendments from Version 1</bold></p><p>The manuscript was revised.</p></notes></root>',
            $this->render('notes', "<b>Amendments from Version 1</b>\n<p>The manuscript was revised.</p>", ['notes-type' => 'update-notice'], allowParagraphs: true)
        );
    }

    /**
     * Whitespace around the source paragraphs is not included in content.
     */
    public function testIgnoresWhitespaceAroundParagraphs()
    {
        self::assertSame(
            '<root><bio xml:lang="en"><p>First.</p><p>Second.</p></bio></root>',
            $this->render('bio', "\n<p>First.</p>\n<p>Second.</p>\n", ['xml:lang' => 'en'], allowParagraphs: true)
        );
    }

    /**
     * A <p> nested in the source is flattened, since JATS does not allow one inside another.
     */
    public function testFlattensNestedSourceParagraphs()
    {
        self::assertSame(
            '<root><bio xml:lang="en"><p>Outer.</p><p>Inner.</p></bio></root>',
            $this->render('bio', '<p>Outer. <p>Inner.</p></p>', ['xml:lang' => 'en'], allowParagraphs: true)
        );
    }

    /**
     * An HTML list becomes a JATS list, carried in a paragraph: the item text is a paragraph
     * of its own and a nested list follows it inside the item.
     */
    public function testConvertsListsWhenParagraphsAllowed()
    {
        $html = '<p>Five priorities emerged, with <b>one</b> above all:</p>'
            . '<ol><li>Ethics and trust;</li><li>Telemedicine, <i>if</i> safe<ul><li>and reliable</li></ul></li></ol>'
            . '<p>Closing.</p>';

        self::assertSame(
            '<root><abstract>'
            . '<p>Five priorities emerged, with <bold>one</bold> above all:</p>'
            . '<p><list list-type="order">'
            . '<list-item><p>Ethics and trust;</p></list-item>'
            . '<list-item><p>Telemedicine, <italic>if</italic> safe</p><list list-type="bullet"><list-item><p>and reliable</p></list-item></list></list-item>'
            . '</list></p>'
            . '<p>Closing.</p>'
            . '</abstract></root>',
            $this->render('abstract', $html, [], allowParagraphs: true)
        );
    }

    /**
     * Paragraphs inside an item are kept as its blocks, an empty item is dropped, and the
     * attributes an editor leaves on list tags are dropped with the tags.
     */
    public function testNormalizesListItems()
    {
        $html = '<ul class="x"><li style="a"><p>A paragraph item.</p><p>With a second paragraph.</p></li><li></li><li>  </li></ul>';

        self::assertSame(
            '<root><notes><p><list list-type="bullet">'
            . '<list-item><p>A paragraph item.</p><p>With a second paragraph.</p></list-item>'
            . '</list></p></notes></root>',
            $this->render('notes', $html, [], allowParagraphs: true)
        );
    }

    /**
     * A list with nothing in it leaves nothing behind, not an empty paragraph.
     */
    public function testDropsEmptyLists()
    {
        self::assertSame(
            '<root><notes><p>Before.</p><p>After.</p></notes></root>',
            $this->render('notes', '<p>Before.</p><ul><li></li></ul><p>After.</p>', [], allowParagraphs: true)
        );
    }

    /**
     * Where only inline content is allowed, a list cannot be built, so its items are run
     * together with a separator rather than losing the boundary between them.
     */
    public function testJoinsListItemsWhenParagraphsNotAllowed()
    {
        self::assertSame(
            '<root><funding-statement>Funded by: Grant A; Grant B; and <bold>Grant C</bold> with thanks.</funding-statement></root>',
            $this->render('funding-statement', 'Funded by:<ul><li>Grant A</li><li> Grant B</li><li>and <b>Grant C</b></li></ul>with thanks.')
        );
    }

    /**
     * A blank line an editor writes as a paragraph holding a non-breaking space is dropped like
     * any other empty paragraph, and a non-breaking space at a paragraph's edge is trimmed.
     */
    public function testDropsParagraphsHoldingOnlyNonBreakingSpace()
    {
        self::assertSame(
            '<root><notes><p>First.</p><p>Second.</p></notes></root>',
            $this->render('notes', '<p>First.</p><p>&nbsp;</p><p>&nbsp;Second.&nbsp;</p><p> </p>', [], allowParagraphs: true)
        );
    }

    /**
     * JATS has no in-paragraph line break, so in block content a <br> ends the paragraph and
     * starts the next, whether or not the author wrapped the text in a paragraph.
     */
    public function testBreakEndsTheParagraphWhenParagraphsAllowed()
    {
        self::assertSame(
            '<root><notes><p>Line one.</p><p>Line two.</p><p>Line three.</p></notes></root>',
            $this->render('notes', '<p>Line one.<br>Line two.</p>Line three.', [], allowParagraphs: true)
        );
    }

    /**
     * Breaks at the edges of a paragraph, or several in a row, leave no empty paragraph behind.
     */
    public function testBreaksLeaveNoEmptyParagraphs()
    {
        self::assertSame(
            '<root><notes><p>One.</p><p>Two.</p></notes></root>',
            $this->render('notes', '<br><p>One.<br/><br />Two.<br></p>', [], allowParagraphs: true)
        );
    }

    /**
     * A break inside a formatting element cannot split the paragraph, so it becomes a space;
     * one inside a list item splits the item's text into two paragraphs. A list that follows
     * inline text with no paragraph boundary between them stays in that text's paragraph.
     */
    public function testBreaksInsideInlineMarkupAndListItems()
    {
        self::assertSame(
            '<root><notes>'
            . '<p><bold>Bold one two</bold><list list-type="bullet"><list-item><p>Item start</p><p>item end</p></list-item></list></p>'
            . '</notes></root>',
            $this->render('notes', '<b>Bold one<br>two</b><ul><li>Item start<br>item end</li></ul>', [], allowParagraphs: true)
        );
    }

    /**
     * Where only inline content is allowed, a break becomes a space rather than vanishing and
     * running the words together.
     */
    public function testBreakBecomesASpaceWhenParagraphsNotAllowed()
    {
        self::assertSame(
            '<root><article-title>Title line one line two</article-title></root>',
            $this->render('article-title', 'Title line one<br>line two')
        );
    }

    /**
     * A rich-text editor may leave attributes on a paragraph; the element is kept and they are
     * dropped, rather than the whole tag surviving as literal text.
     */
    public function testConvertsParagraphsCarryingAttributes()
    {
        self::assertSame(
            '<root><notes notes-type="update-notice"><p>First.</p><p>Second.</p></notes></root>',
            $this->render('notes', '<p class="lead" dir="ltr">First.</p><p>Second.</p>', ['notes-type' => 'update-notice'], allowParagraphs: true)
        );
    }

    /**
     * Elements whose content model disallows <p> (e.g. mixed-citation, funding-statement) must
     * have any source <p> tags stripped, not preserved or auto-wrapped, with the runs of text
     * the paragraphs held kept apart.
     */
    public function testStripsParagraphsWhenNotAllowed()
    {
        self::assertSame(
            '<root><mixed-citation>Some citation text.</mixed-citation></root>',
            $this->render('mixed-citation', '<p>Some citation text.</p>')
        );
        self::assertSame(
            '<root><funding-statement>Grant A. Grant B.</funding-statement></root>',
            $this->render('funding-statement', '<p>Grant A.</p><p>Grant B.</p>')
        );
    }

    /**
     * Markup that fails to parse as XML falls back to a plain-escaped-text element, still
     * wrapped in a <p> when the target element requires block-level content. The sanitizer
     * repairs unclosed tags, so the fallback is reached by escaped text that decodes to markup.
     */
    public function testFallbackWrapsInParagraphWhenParagraphsAllowed()
    {
        self::assertSame(
            '<root><fn fn-type="coi-statement" id="x"><p>Broken &lt;b&gt;markup &amp; &lt;i&gt;nested wrong</p></fn></root>',
            $this->render('fn', 'Broken &lt;b&gt;markup &amp; &lt;i&gt;nested wrong', ['fn-type' => 'coi-statement', 'id' => 'x'], allowParagraphs: true)
        );
    }

    /**
     * The fallback path must not wrap in a <p> when the target element's content model doesn't allow one.
     */
    public function testFallbackDoesNotWrapWhenParagraphsNotAllowed()
    {
        self::assertSame(
            '<root><mixed-citation>Broken &lt;b&gt;markup &amp; &lt;i&gt;nested wrong</mixed-citation></root>',
            $this->render('mixed-citation', 'Broken &lt;b&gt;markup &amp; &lt;i&gt;nested wrong')
        );
    }

    /**
     * A list item carrying an attribute is still recognized when the items are joined inline,
     * even though its quoted attribute value is escaped by then.
     */
    public function testJoinsListItemsCarryingAttributesWhenParagraphsNotAllowed()
    {
        self::assertSame(
            '<root><funding-statement>Funded: A; <bold>B</bold></funding-statement></root>',
            $this->render('funding-statement', 'Funded:<ul><li class="x">A</li><li class="y"><b>B</b></li></ul>')
        );
    }

    /**
     * A link whose URL scheme the sanitizer rejects loses its href; it must then be reduced to
     * its text rather than leaving the markup unbalanced and forcing the plain-text fallback.
     */
    public function testReducesLinkWithoutHrefToItsText()
    {
        self::assertSame(
            '<root><bio><p>See the DOI and <italic>italic</italic>.</p><p>Second <bold>para</bold>.</p></bio></root>',
            $this->render('bio', '<p>See <a href="doi:10.1000/xyz">the DOI</a> and <i>italic</i>.</p><p>Second <b>para</b>.</p>', [], allowParagraphs: true)
        );
    }

    /**
     * Block-level content that normalizes to nothing yields no element at all, since an empty
     * <fn>, <notes> or <bio> would not be valid JATS.
     */
    public function testReturnsNoElementWhenBlockContentIsBlank()
    {
        self::assertSame('<root/>', $this->render('fn', '<p>&nbsp;</p>', ['fn-type' => 'coi-statement'], allowParagraphs: true));
        self::assertSame('<root/>', $this->render('notes', " \n ", [], allowParagraphs: true));
        self::assertFalse(JatsHelper::hasBlockContent('<p>&nbsp;</p><p> </p>'));
        self::assertTrue(JatsHelper::hasBlockContent('<p>&nbsp;x</p>'));
    }

    /**
     * A paragraph or list nested inside inline markup is unwrapped, since JATS allows a block
     * neither inside an inline element nor inside a paragraph. The sanitizer already removes
     * such nesting from HTML, so the normalizer is exercised directly.
     */
    public function testUnwrapsBlocksNestedInInlineMarkup()
    {
        self::assertSame(
            '<p><bold>bold x</bold></p>',
            JatsHelper::normalizeParagraphs('<bold>bold<list list-type="bullet"><list-item>x</list-item></list></bold>')
        );
        self::assertSame(
            '<p><bold>bold inner</bold></p>',
            JatsHelper::normalizeParagraphs('<bold>bold <p>inner</p></bold>')
        );
    }

    /**
     * Unsafe markup is removed for every field, not only those holding block-level content.
     */
    public function testStripsUnsafeMarkupInInlineContent()
    {
        self::assertSame(
            '<root><funding-statement>See here xy</funding-statement></root>',
            $this->render('funding-statement', 'See <a href="javascript:alert(1)">here</a> x<script>alert(1)</script>y')
        );
    }

    /**
     * A line break carrying an attribute is still a line break.
     */
    public function testBreakCarryingAttributes()
    {
        self::assertSame(
            '<root><article-title>a b</article-title></root>',
            $this->render('article-title', 'a<br class="x">b')
        );
        self::assertSame(
            '<root><notes><p>a</p><p>b</p></notes></root>',
            $this->render('notes', '<p>a<br class="x"/>b</p>', [], allowParagraphs: true)
        );
    }

    /**
     * A mailto link becomes an <email> holding the address; the link text is not kept.
     */
    public function testMailtoLinkBecomesEmail()
    {
        self::assertSame(
            '<root><p>Write to <email>editor@example.com</email> or see <ext-link ext-link-type="uri" xlink:href="https://example.com/">the site</ext-link>.</p></root>',
            $this->render('p', 'Write to <a href="mailto:editor@example.com">the editor</a> or see <a href="https://example.com/">the site</a>.')
        );
    }

    /**
     * Only the address of a mailto link is kept: its query string is dropped and percent-encoding decoded.
     */
    public function testMailtoLinkKeepsOnlyTheDecodedAddress()
    {
        self::assertSame(
            '<root><p><email>a b@example.org</email> <email>x&amp;y@example.org</email></p></root>',
            $this->render('p', '<a href="mailto:a%20b@example.org?subject=Hi&amp;body=z">me</a> <a href="mailto:x%26y@example.org">me</a>')
        );
    }

    /**
     * A paragraph holding no text, however deeply nested its inline markup, is dropped, and
     * so is a link left without text.
     */
    public function testDropsParagraphsWithoutTextInsideInlineMarkup()
    {
        self::assertSame(
            '<root><notes><p>Real</p></notes></root>',
            $this->render('notes', '<p><b><i> </i></b></p><p>&nbsp;<b>&nbsp;</b></p><p><a href="https://example.com"></a></p><p>Real</p>', [], allowParagraphs: true)
        );
    }

    /**
     * Abstract HTML as the rich text editor stores it, and the JATS it converts to, covering
     * the edges of list handling: nesting, text around nested lists, and the empty items and
     * blank lines the editor leaves behind.
     */
    #[DataProvider('abstractHtmlProvider')]
    public function testConvertsAbstractHtml(string $html, string $expectedXml)
    {
        self::assertSame('<root>' . $expectedXml . '</root>', $this->render('abstract', $html, [], allowParagraphs: true));
    }

    public static function abstractHtmlProvider(): array
    {
        return [
            'plain text is wrapped in a paragraph' => [
                'article-abstract',
                '<abstract><p>article-abstract</p></abstract>',
            ],
            'inline markup stays within its paragraph' => [
                '<p>Intro <em>italic</em> and <strong>bold</strong>.</p>',
                '<abstract><p>Intro <italic>italic</italic> and <bold>bold</bold>.</p></abstract>',
            ],
            'stray inline content between blocks is wrapped in a paragraph' => [
                '<p>First</p>Stray <em>text</em>',
                '<abstract><p>First</p><p>Stray <italic>text</italic></p></abstract>',
            ],
            'line break ends the paragraph' => [
                '<p>Line one<br>Line two</p>',
                '<abstract><p>Line one</p><p>Line two</p></abstract>',
            ],
            'unordered list becomes a bullet list wrapped in a paragraph' => [
                '<p>Intro</p><ul><li>One</li><li>Two</li></ul>',
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>One</p></list-item><list-item><p>Two</p></list-item></list></p></abstract>',
            ],
            'ordered list becomes an order list' => [
                '<ol><li>First</li><li>Second</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>First</p></list-item><list-item><p>Second</p></list-item></list></p></abstract>',
            ],
            'editor whitespace between block elements is ignored' => [
                "<p>Intro</p>\n<ul>\n<li>Parent\n<ul>\n<li>Child</li>\n</ul>\n</li>\n<li>Two</li>\n</ul>\n<p>Outro</p>",
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>Parent</p><list list-type="bullet"><list-item><p>Child</p></list-item></list></list-item><list-item><p>Two</p></list-item></list></p><p>Outro</p></abstract>',
            ],
            'nested list follows the paragraph of its list item' => [
                '<ol><li>Parent<ul><li>Child one</li><li>Child two</li></ul></li><li>Sibling</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>Parent</p><list list-type="bullet"><list-item><p>Child one</p></list-item><list-item><p>Child two</p></list-item></list></list-item><list-item><p>Sibling</p></list-item></list></p></abstract>',
            ],
            'lists nest to any depth' => [
                '<ul><li>L1<ol><li>L2<ul><li>L3</li></ul></li></ol></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>L1</p><list list-type="order"><list-item><p>L2</p><list list-type="bullet"><list-item><p>L3</p></list-item></list></list-item></list></list-item></list></p></abstract>',
            ],
            'text after a nested list gets its own paragraph' => [
                '<ul><li>Before<ul><li>Nested</li></ul>After</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Before</p><list list-type="bullet"><list-item><p>Nested</p></list-item></list><p>After</p></list-item></list></p></abstract>',
            ],
            'list item holding only a nested list has no paragraph of its own' => [
                '<ul><li><ul><li>Child only</li></ul></li><li>Sibling</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><list list-type="bullet"><list-item><p>Child only</p></list-item></list></list-item><list-item><p>Sibling</p></list-item></list></p></abstract>',
            ],
            'sibling nested lists in one list item keep their order and type' => [
                '<ul><li>Parent<ul><li>Bullet child</li></ul><ol><li>Numbered child</li></ol></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Parent</p><list list-type="bullet"><list-item><p>Bullet child</p></list-item></list><list list-type="order"><list-item><p>Numbered child</p></list-item></list></list-item></list></p></abstract>',
            ],
            'text between nested lists in one list item gets its own paragraph' => [
                '<ol><li>Start<ul><li>First nested</li></ul>Middle text<ul><li>Second nested</li></ul>End</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>Start</p><list list-type="bullet"><list-item><p>First nested</p></list-item></list><p>Middle text</p><list list-type="bullet"><list-item><p>Second nested</p></list-item></list><p>End</p></list-item></list></p></abstract>',
            ],
            'nested list without any non-empty item is dropped from its list item' => [
                "<ul>\n<li>Parent\n<ul>\n<li>\u{00A0}</li>\n</ul>\n</li>\n</ul>",
                '<abstract><p><list list-type="bullet"><list-item><p>Parent</p></list-item></list></p></abstract>',
            ],
            'paragraph inside a list item is not wrapped again' => [
                '<ul><li><p>Pasted paragraph</p></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Pasted paragraph</p></list-item></list></p></abstract>',
            ],
            'inline markup inside a list item is preserved' => [
                '<ul><li>H<sub>2</sub>O and E=mc<sup>2</sup> in <strong>bold</strong></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>H<sub>2</sub>O and E=mc<sup>2</sup> in <bold>bold</bold></p></list-item></list></p></abstract>',
            ],
            'links become ext-link and email' => [
                '<ul><li>See <a href="https://example.com/?a=1&amp;b=2">site</a> or <a href="mailto:editor@example.com">email</a></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>See <ext-link ext-link-type="uri" xlink:href="https://example.com/?a=1&amp;b=2">site</ext-link> or <email>editor@example.com</email></p></list-item></list></p></abstract>',
            ],
            'empty list items and empty lists are dropped' => [
                '<p>Intro</p><ul><li></li><li><br></li><li>Kept</li></ul><ol><li> </li></ol>',
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>Kept</p></list-item></list></p></abstract>',
            ],
            'list items holding only a non-breaking space, as the editor stores empty items, are dropped' => [
                "<ul>\n<li>\u{00A0}</li>\n<li>Kept</li>\n</ul>\n<ol>\n<li>\u{00A0}</li>\n</ol>",
                '<abstract><p><list list-type="bullet"><list-item><p>Kept</p></list-item></list></p></abstract>',
            ],
            'paragraph holding only a non-breaking space, as the editor stores blank lines, is dropped' => [
                "<p>Intro</p>\n<p>\u{00A0}</p>\n<p>Outro</p>",
                '<abstract><p>Intro</p><p>Outro</p></abstract>',
            ],
            // The sanitizer repairs this invalid HTML by moving the list into the preceding item
            'list directly inside a list is nested in the preceding list item' => [
                '<ul><li>A</li><ul><li>A1</li></ul><li>B</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>A</p><list list-type="bullet"><list-item><p>A1</p></list-item></list></list-item><list-item><p>B</p></list-item></list></p></abstract>',
            ],
            'entities are not double escaped' => [
                '<p>Cats &amp; dogs</p><ul><li>x &lt; y</li></ul>',
                '<abstract><p>Cats &amp; dogs</p><p><list list-type="bullet"><list-item><p>x &lt; y</p></list-item></list></p></abstract>',
            ],
        ];
    }
}
