<?php

/**
 * @file Article.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\issue\Issue;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\i18n\LocaleConversion;
use PKP\oai\OAIRecord;
use PKP\plugins\Hook;

class Article extends \DOMDocument
{
    public const JATS_PUBLIC_ID = '-//NLM//DTD JATS (Z39.96) Journal Publishing DTD v1.2 20190208//EN';
    public const JATS_SYSTEM_ID = 'http://jats.nlm.nih.gov/publishing/1.2/JATS-journalpublishing1.dtd';
    public const JATS_VERSION = '1.2';

    public function __construct()
    {
        parent::__construct('1.0', 'UTF-8');
    }

    /**
     *
     */
    public function convertOAIToXml(OAIRecord $record, PKPRequest $request): void
    {
        $submission = $record->getData('article'); /** @var Submission $submission */
        $journal = $record->getData('journal'); /** @var Context $journal */
        $section = $record->getData('section'); /** @var Section $section */
        $issue = $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication();

        $this->convertSubmission($submission, $journal, $section, $issue, $publication, $request);
        Hook::run('JatsTemplatePlugin::jats', [$record, $this]);
    }

    /**
     * Convert submission metadata to JATS XML
     */
    public function convertSubmission(
        Submission $submission,
        Context $context,
        Section $section,
        ?Issue $issue = null,
        ?Publication $publication = null,
        ?PKPRequest $request = null
    ): void {
        // Add DTD before the root element
        $impl = new \DOMImplementation();
        $doctype = $impl->createDocumentType(
            'article',
            self::JATS_PUBLIC_ID,
            self::JATS_SYSTEM_ID
        );
        $this->appendChild($doctype);

        $articleElement = $this->appendChild($this->createElement('article'))
            ->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink')->parentNode
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($submission->getData('locale')))->parentNode
            ->setAttribute('xmlns:mml', 'http://www.w3.org/1998/Math/MathML')->parentNode
            ->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance')->parentNode
            ->setAttribute('dtd-version', self::JATS_VERSION)->parentNode;

        $articleFront = new ArticleFront();
        $articleElement->appendChild($this->importNode($articleFront->create($context, $submission, $section, $issue, $request, $this, $publication), true));

        $articleBody = new ArticleBody();
        $articleElement->appendChild($this->importNode($articleBody->create($submission), true));

        if ($publication) {
            $articleBack = new ArticleBack();
            $articleBackNode = $articleBack->create($publication);
            if ($articleBackNode) {
                $articleElement->appendChild($this->importNode($articleBackNode, true));
            }
        }
    }

    /**
     * Map HTML tags in title/subtitle to JATS elements for JATS schema compatibility
     *
     * @param string $htmlTitle The submission title/subtitle which may contain HTML
     */
    public function mapHtmlTagsForTitle(string $htmlTitle): string
    {
        $mappings = [
            '<b>'   => '<bold>',
            '</b>'  => '</bold>',
            '<i>'   => '<italic>',
            '</i>'  => '</italic>',
            '<u>'   => '<underline>',
            '</u>'  => '</underline>',
        ];

        return str_replace(array_keys($mappings), array_values($mappings), $htmlTitle);
    }
}
