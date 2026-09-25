<?php

/**
 * @file ArticleBack.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article back element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use DOMElement;
use PKP\i18n\LocaleConversion;

class ArticleBack extends \DOMDocument
{
    /**
     * Create the xml back DOMNode, or null when the publication has no back matter,
     * so that no empty <back> element is added to the article
     * @param $publication
     */
    public function create($publication): ?\DOMNode
    {
        // create element back
        $backElement = $this->appendChild($this->createElement('back'));

        // Data availability statement(s) precede the reference list
        // s. https://jats.nlm.nih.gov/archiving/tag-library/1.2/chapter/tag-data-avail.html
        $dataAvailability = array_filter(
            $publication->getData('dataAvailability') ?? [],
            fn (?string $statement) => trim(strip_tags((string) $statement)) !== ''
        );
        foreach ($dataAvailability as $locale => $statement) {
            $this->appendDataAvailabilitySection($backElement, $statement, $locale);
        }

        $citations = $publication->getData('citations');
        if ($citations?->isNotEmpty()) {
            // create element ref-list
            $refListElement = $backElement->appendChild($this->createElement('ref-list'));
            $i = 1;
            foreach ($citations as $citation) {
                // create element ref
                $refListElement
                    ->appendChild($this->createElement('ref'))
                    ->setAttribute('id', 'R' . $i)
                    ->parentNode
                    ->appendChild($this->createElement('mixed-citation', htmlspecialchars($citation->getRawCitation())));
                $i++;
            }
        }

        if (!$backElement->hasChildNodes()) {
            $this->removeChild($backElement);
            return null;
        }

        return $backElement;
    }

    /**
     * Append a data availability <sec> element for a single locale
     */
    protected function appendDataAvailabilitySection(DOMElement $backElement, string $statement, string $locale): void
    {
        $secNode = JatsHelper::htmlToJatsElement(
            $this,
            'sec',
            $statement,
            ['sec-type' => 'data-availability', 'xml:lang' => LocaleConversion::toBcp47($locale)],
            allowParagraphs: true
        );
        if (!$secNode) {
            return;
        }
        $backElement->appendChild($secNode);
        // The section title must precede its paragraphs
        $secElement = $backElement->lastChild;
        $titleElement = $this->createElement('title');
        $titleElement->appendChild($this->createTextNode(__('submission.dataAvailability', [], $locale)));
        $secElement->insertBefore($titleElement, $secElement->firstChild);
    }
}
