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

use APP\publication\Publication;
use DOMElement;
use DOMNode;
use Illuminate\Support\Carbon;
use PKP\citation\Citation;
use PKP\citation\enum\CitationSourceType;
use PKP\citation\enum\CitationType;
use PKP\citation\pid\Arxiv;
use PKP\citation\pid\Doi;
use PKP\citation\pid\Handle;

class ArticleBack extends \DOMDocument
{
    /**
     * create xml back DOMNode
     */
    public function create(Publication $publication): DOMNode
    {
        // create element back
        $backElement = $this->appendChild($this->createElement('back'));

        // consider citations
        $citations = $publication->getData('citations');
        if (!empty($citations)) {
            $refListElement = $backElement->appendChild($this->createElement('ref-list'));
            $i = 1;
            foreach ($citations as $citation) {
                $refElement = $this->createElement('ref');
                $refElement->setAttribute('id', 'R' . $i);
                if ($citation->getData('isStructured')) {
                    $elementCitation = $this->createElement('element-citation');
                    $this->appendStructuredCitations($elementCitation, $citation);
                    $refElement->appendChild($elementCitation);
                } else {
                    $refElement->appendChild($this->createElement('mixed-citation', htmlspecialchars($citation->getRawCitation())));
                }
                $refListElement->appendChild($refElement);
                $i++;
            }
        }

        return $backElement;
    }

    /**
     * Append elements to element-citation element
     */
    protected function appendStructuredCitations(DOMElement $elementCitation, Citation $citation): void
    {
        $jatsPublicationType = $this->getJATSPublicationType($citation);
        if (!empty($jatsPublicationType)) {
            $elementCitation->setAttribute('publication-type', $jatsPublicationType);
        }
        if (!empty($citation->getData('authors'))) {
            $personElement = $this->createElement('person-group');
            $personElement->setAttribute('person-group-type', 'author');
            foreach ($citation->getData('authors') as $author) {
                $familyName = $author['familyName'] ?? null;
                $givenName = $author['givenName'] ?? null;
                if ($familyName || $givenName) {
                    $nameElement = $this->createElement('name');
                    if ($familyName) {
                        $nameElement->appendChild($this->createElement('surname', htmlspecialchars($familyName)));
                    }
                    if ($givenName) {
                        $nameElement->appendChild($this->createElement('given-names', htmlspecialchars($givenName)));
                    }
                    $personElement->appendChild($nameElement);
                }
            }
            $elementCitation->appendChild($personElement);
        }
        if ($date = $citation->getData('date')) {
            $dateParsed = Carbon::parse($date);
            $elementCitation->appendChild($this->createElement('year', $dateParsed->year));
        }
        // Title handling based on citation type
        // s. https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/element-citation.html
        // s. https://jats.nlm.nih.gov/archiving/tag-library/1.4/chapter/tag-cite-details.html
        $this->appendTitleElements($elementCitation, $citation);

        $pubIds = [
            'doi' => $citation->getData('doi'),
            'handle' => $citation->getData('handle'),
            'arxiv' => $citation->getData('arxiv'),
            'urn' => $citation->getData('urn'),
        ];
        foreach ($pubIds as $type => $pubId) {
            if (!empty($pubId)) {
                $pubIdElement = $this->createElement('pub-id', htmlspecialchars($pubId));
                $pubIdElement->setAttribute('pub-id-type', $type);
                $elementCitation->appendChild($pubIdElement);
            }
        }

        if ($url = $citation->getData('url')) {
            $extLinkElement = $this->createElement('ext-link', htmlspecialchars($url));
            $extLinkElement->setAttribute('ext-link-type', 'uri');
            $extLinkElement->setAttribute('xlink:href', $url);
            $elementCitation->appendChild($extLinkElement);
        }
        if ($fPage = $citation->getData('firstPage')) {
            $elementCitation->appendChild($this->createElement('fpage', htmlspecialchars($fPage)));
        }
        if ($lPage = $citation->getData('lastPage')) {
            $elementCitation->appendChild($this->createElement('lpage', htmlspecialchars($lPage)));
        }
        if ($issue = $citation->getData('issue')) {
            $elementCitation->appendChild($this->createElement('issue', htmlspecialchars($issue)));
        }
        if ($volume = $citation->getData('volume')) {
            $elementCitation->appendChild($this->createElement('volume', htmlspecialchars($volume)));
        }
    }

    /**
     * Append title elements based on citation type
     */
    protected function appendTitleElements(DOMElement $elementCitation, Citation $citation): void
    {
        $type = $citation->getData('type');
        $title = $citation->getData('title');
        $sourceName = $citation->getData('sourceName');

        $bookTypes = [
            CitationType::BOOK->value,
            CitationType::MONOGRAPH->value,
            CitationType::EDITED_BOOK->value,
            CitationType::BOOK_SET->value,
            CitationType::BOOK_TRACK->value,
        ];

        $partTypes = [
            CitationType::BOOK_CHAPTER->value,
            CitationType::BOOK_PART->value,
            CitationType::BOOK_SECTION->value,
        ];

        if (in_array($type, $bookTypes) && $title) {
            $elementCitation->appendChild($this->createElement('source', htmlspecialchars($title)));
        } elseif (in_array($type, $partTypes) && $title) {
            $elementCitation->appendChild($this->createElement('part-title', htmlspecialchars($title)));
            $this->appendSource($elementCitation, $sourceName);
        } elseif ($type === CitationType::DATASET->value && $title) {
            $elementCitation->appendChild($this->createElement('data-title', htmlspecialchars($title)));
            $this->appendSource($elementCitation, $sourceName);
        } elseif (in_array($type, [CitationType::JOURNAL_ARTICLE->value, CitationType::PREPRINT->value]) && $title) {
            $elementCitation->appendChild($this->createElement('article-title', htmlspecialchars($title)));
            $this->appendSource($elementCitation, $sourceName);
        } elseif ($type === CitationType::JOURNAL_ISSUE->value && $title) {
            $elementCitation->appendChild($this->createElement('issue-title', htmlspecialchars($title)));
            $this->appendSource($elementCitation, $sourceName);
        } elseif ($title || $sourceName) {
            $elementCitation->appendChild($this->createElement('source', htmlspecialchars($title ?? $sourceName)));
        }
    }

    /**
     * Append source element if sourceName is provided
     */
    protected function appendSource(DOMElement $elementCitation, ?string $sourceName): void
    {
        if ($sourceName) {
            $elementCitation->appendChild($this->createElement('source', htmlspecialchars($sourceName)));
        }
    }

    /**
     * Get JATS publication_type attribute
     */
    protected function getJATSPublicationType(Citation $citation): ?string
    {
        $specialCases = [
            CitationType::COMPONENT->value => 'component',
            CitationType::DISSERTATION->value => 'dissertation',
            CitationType::ERRATUM->value => 'erratum',
            CitationType::EDITORIAL->value => 'journal',
            CitationType::GRANT->value => 'grant',
            CitationType::LIBGUIDES->value => 'libguides',
            CitationType::PARATEXT->value => 'paratext',
            CitationType::REFERENCE_ENTRY->value => 'reference-entry',
            CitationType::RETRACTION->value => 'retraction',
        ];
        $typeMapping = [
            CitationType::BOOK->value => 'book',
            CitationType::BOOK_CHAPTER->value => 'book',
            CitationType::BOOK_PART->value => 'book',
            CitationType::BOOK_SECTION->value => 'book',
            CitationType::BOOK_SERIES->value => 'book',
            CitationType::BOOK_SET->value => 'book',
            CitationType::BOOK_TRACK->value => 'book',
            CitationType::DATABASE->value => 'data',
            CitationType::DATASET->value => 'data',
            CitationType::EDITED_BOOK->value => 'book',
            CitationType::JOURNAL->value => 'journal',
            CitationType::JOURNAL_ARTICLE->value => 'journal',
            CitationType::JOURNAL_ISSUE->value => 'journal',
            CitationType::JOURNAL_VOLUME->value => 'journal',
            CitationType::LETTER->value => 'letter',
            CitationType::MONOGRAPH->value => 'book',
            CitationType::OTHER->value => 'other',
            CitationType::PEER_REVIEW->value => 'review',
            CitationType::POSTED_CONTENT->value => 'preprint',
            CitationType::PREPRINT->value => 'preprint',
            CitationType::PROCEEDINGS->value => 'conference',
            CitationType::PROCEEDINGS_ARTICLE->value => 'conference',
            CitationType::PROCEEDINGS_SERIES->value => 'conference',
            CitationType::REFERENCE_BOOK->value => 'book',
            CitationType::REPORT->value => 'report',
            CitationType::REPORT_COMPONENT->value => 'report',
            CitationType::REPORT_SERIES->value => 'report',
            CitationType::REVIEW->value => 'review',
            CitationType::STANDARD->value => 'standard',
            CitationType::SUPPLEMENTARY_MATERIALS->value => 'data',
        ];
        $sourceTypeMapping = [
            CitationSourceType::BOOK_SERIES->value => 'book',
            CitationSourceType::CONFERENCE->value => 'conference',
            CitationSourceType::EBOOK_PLATFORM->value => 'book',
            CitationSourceType::JOURNAL->value => 'journal',
            CitationSourceType::REPOSITORY->value => 'repository',
        ];

        $citationType = $citation->getData('type');
        $sourceType = $citation->getData('sourceType');
        return $typeMapping[$citationType] ?? $sourceTypeMapping[$sourceType] ?? $specialCases[$citationType] ?? $citationType;
    }
}
