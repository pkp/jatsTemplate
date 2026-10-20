<?php

/**
 * @file ArticleBack.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS XML article back element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\publication\Publication;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Carbon;
use PKP\citation\Citation;
use PKP\citation\enum\CitationSourceType;
use PKP\citation\enum\CitationType;
use PKP\dataCitation\DataCitation;
use PKP\i18n\LocaleConversion;
use PKP\pid\Arxiv;
use PKP\pid\Doi;
use PKP\pid\Handle;
use PKP\pid\Urn;

class ArticleBack extends DOMDocument
{
    /** pub-id-type values allowed in JATS 1.2. See https://jats.nlm.nih.gov/publishing/tag-library/1.2/attribute/pub-id-type.html */
    protected const PUB_ID_TYPES = [
        'accession', 'archive', 'ark', 'art-access-id', 'arxiv', 'coden', 'doaj', 'doi', 'handle', 'index',
        'isbn', 'manuscript', 'medline', 'mr', 'other', 'pii', 'pmcid', 'pmid', 'publisher-id', 'sici',
        'std-designation', 'zbl',
    ];

    /** The identifier classes of a citation's pub-id types */
    protected const PID_CLASSES = [
        'doi' => Doi::class,
        'handle' => Handle::class,
        'arxiv' => Arxiv::class,
        'urn' => Urn::class,
    ];

    /**
     * Create XML back DOMNode
     */
    public function create(Publication $publication): DOMNode|null
    {
        $backElement = null;

        $citations = $publication->getData('citations');
        $dataCitations = $publication->getData('dataCitations');
        $dataAvailability = array_filter(
            $publication->getData('dataAvailability') ?? [],
            fn (?string $statement) => trim(strip_tags((string) $statement)) !== ''
        );

        if (!empty($dataAvailability) || $citations->isNotEmpty() || $dataCitations->isNotEmpty()) {
            // create element back
            $backElement = $this->appendChild($this->createElement('back'));

            // Data availability statement(s) precede the reference list
            // s. https://jats.nlm.nih.gov/archiving/tag-library/1.2/chapter/tag-data-avail.html
            foreach ($dataAvailability as $locale => $statement) {
                $this->appendDataAvailabilitySection($backElement, $statement, $locale);
            }
        }

        if ($citations->isNotEmpty() || $dataCitations->isNotEmpty()) {
            $refListElement = $backElement->appendChild($this->createElement('ref-list'));
            $i = 1;
            foreach ($citations ?? [] as $citation) {
                $refElement = $this->createElement('ref');
                $refElement->setAttribute('id', 'R' . $i);
                if ($citation->getData('isStructured')) {
                    $elementCitation = $this->createElement('element-citation');
                    $this->appendStructuredCitations($elementCitation, $citation);
                    $refElement->appendChild($elementCitation);
                } else {
                    $this->appendMixedCitation($refElement, $citation);
                }
                $refListElement->appendChild($refElement);
                $i++;
            }

            $i = 1;
            foreach ($dataCitations ?? [] as $dataCitation) {
                $refElement = $this->createElement('ref');
                $refElement->setAttribute('id', 'D' . $i);
                $elementCitation = $this->createElement('element-citation');
                $this->appendDataCitation($elementCitation, $dataCitation);
                $refElement->appendChild($elementCitation);
                $refListElement->appendChild($refElement);
                $i++;
            }
        }

        return $backElement;
    }

    /**
     * Append a data availability <sec> element for a single locale
     */
    protected function appendDataAvailabilitySection(DOMElement $backElement, string $statement, string $locale): void
    {
        $secElement = JatsHelper::htmlToJatsElement(
            $this,
            'sec',
            $statement,
            ['sec-type' => 'data-availability', 'xml:lang' => LocaleConversion::toBcp47($locale)],
            allowParagraphs: true
        );
        if (!$secElement) {
            return;
        }
        $backElement->appendChild($secElement);
        // The section title must precede its paragraphs
        $secElement = $backElement->lastChild;
        $titleElement = $this->createElement('title');
        $titleElement->appendChild($this->createTextNode(__('submission.dataAvailability', [], $locale)));
        $secElement->insertBefore($titleElement, $secElement->firstChild);
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
        $this->appendAuthors($elementCitation, $citation->getData('authors'));
        if ($date = $citation->getData('date')) {
            $dateParsed = Carbon::parse($date);
            $elementCitation->appendChild($this->createElement('year', $dateParsed->year));
        }
        // Title handling based on citation type
        // s. https://jats.nlm.nih.gov/archiving/tag-library/1.2/element/element-citation.html
        // s. https://jats.nlm.nih.gov/archiving/tag-library/1.4/chapter/tag-cite-details.html
        $this->appendTitleElements($elementCitation, $citation);

        $this->appendPubIds($elementCitation, $citation);

        $this->appendUrl($elementCitation, $citation->getData('url'));
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

    /**
     * Get the citation's persistent identifiers, keyed by pub-id-type
     *
     * @return array<string, string>
     */
    protected function getPubIds(Citation $citation): array
    {
        return array_filter([
            'doi' => $citation->getData('doi'),
            'handle' => $citation->getData('handle'),
            'arxiv' => $citation->getData('arxiv'),
            'urn' => $citation->getData('urn'),
        ]);
    }

    /**
     * Create a pub-id element, typed "other" when JATS 1.2 has no matching pub-id-type
     */
    protected function createPubId(string $type, string $pubId): DOMElement
    {
        $pubIdElement = $this->createElement('pub-id', htmlspecialchars($pubId));
        $pubIdElement->setAttribute('pub-id-type', in_array($type, self::PUB_ID_TYPES, true) ? $type : 'other');
        return $pubIdElement;
    }

    /**
     * Append pub-id elements for the citation's persistent identifiers
     */
    protected function appendPubIds(DOMElement $citationElement, Citation $citation): void
    {
        foreach ($this->getPubIds($citation) as $type => $pubId) {
            $citationElement->appendChild($this->createPubId($type, $pubId));
        }
    }

    /**
     * Append a person-group of the authors who have a name
     *
     * @param ?array<array{familyName?: ?string, givenName?: ?string}> $authors
     */
    protected function appendAuthors(DOMElement $citationElement, ?array $authors): void
    {
        $personGroupElement = $this->createElement('person-group');
        $personGroupElement->setAttribute('person-group-type', 'author');
        foreach ($authors ?? [] as $author) {
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
                $personGroupElement->appendChild($nameElement);
            }
        }
        if ($personGroupElement->hasChildNodes()) {
            $citationElement->appendChild($personGroupElement);
        }
    }

    /**
     * Append an ext-link for a URL
     */
    protected function appendUrl(DOMElement $citationElement, ?string $url): void
    {
        if ($url) {
            $extLinkElement = $this->createElement('ext-link', htmlspecialchars($url));
            $extLinkElement->setAttribute('ext-link-type', 'uri');
            $extLinkElement->setAttribute('xlink:href', $url);
            $citationElement->appendChild($extLinkElement);
        }
    }

    /**
     * Append mixed-citation element with HTML converted to JATS, tagging the citation's
     * persistent identifiers where they appear in the text
     */
    protected function appendMixedCitation(DOMElement $refElement, Citation $citation): void
    {
        $refElement->appendChild(
            JatsHelper::htmlToJatsElement($this, 'mixed-citation', $citation->getRawCitationWithLinks())
        );
        // htmlToJatsElement may return a fragment, so take the appended element from the ref
        $mixedCitation = $refElement->lastChild;
        foreach ($this->getPubIds($citation) as $type => $pubId) {
            if (!$this->tagPubId($mixedCitation, $type, $pubId)) {
                $mixedCitation->appendChild($this->createTextNode(' '));
                $mixedCitation->appendChild($this->createPubId($type, $pubId));
            }
        }
    }

    /**
     * Wrap the first occurrence of an identifier in the mixed-citation text in a pub-id element.
     * Only an occurrence written as the identifier counts: at the start of a word or after one
     * of its prefixes, not inside another address such as a publisher's page.
     * A pub-id can't be nested in an ext-link, so a link holding only the identifier's text
     * is replaced by a pub-id carrying the link's address.
     *
     * @return bool Whether the identifier was found and tagged
     */
    protected function tagPubId(DOMElement $mixedCitation, string $type, string $pubId): bool
    {
        $pattern = $this->getPubIdPattern($type, $pubId);
        foreach ((new DOMXPath($this))->query('text() | ext-link/text()', $mixedCitation) as $textNode) {
            $text = $textNode->nodeValue;
            if (!preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $position = $matches[1][1];

            $target = $textNode;
            $pubIdElement = $this->createPubId($type, substr($text, $position, strlen($pubId)));
            if ($textNode->parentNode->nodeName === 'ext-link') {
                if ($textNode->parentNode->childNodes->length !== 1) {
                    continue;
                }
                $target = $textNode->parentNode;
                $pubIdElement->setAttribute('xlink:href', $target->getAttribute('xlink:href'));
            }

            $parent = $target->parentNode;
            foreach ([substr($text, 0, $position), $pubIdElement, substr($text, $position + strlen($pubId))] as $node) {
                if ($node === '') {
                    continue;
                }
                $parent->insertBefore(is_string($node) ? $this->createTextNode($node) : $node, $target);
            }
            $parent->removeChild($target);
            return true;
        }
        return false;
    }

    /**
     * Get a pattern matching an identifier at the start of a word or after one of its prefixes,
     * capturing the identifier
     */
    protected function getPubIdPattern(string $type, string $pubId): string
    {
        // Any letter case, and http as well as https, as written in the text
        $prefixes = array_map(
            fn (string $prefix) => str_starts_with($prefix, 'https://')
                ? 'https?:\/\/' . preg_quote(substr($prefix, strlen('https://')), '/')
                : preg_quote($prefix, '/'),
            isset(self::PID_CLASSES[$type]) ? self::PID_CLASSES[$type]::getPrefixes() : []
        );
        $prefixPattern = $prefixes ? '(?:' . implode('|', $prefixes) . ')?' : '';

        return '/(?:^|(?<=[\s(\[<"\']))' . $prefixPattern . '(' . preg_quote($pubId, '/') . ')/i';
    }

    /**
     * Append elements to element-citation element for a data citation
     *
     * @param DOMElement $elementCitation The element-citation DOM element to append to
     * @param DataCitation $dataCitation The data citation object containing the citation information
     */
    protected function appendDataCitation(DOMElement $elementCitation, DataCitation $dataCitation): void
    {
        $elementCitation->setAttribute('publication-type', 'data');
        if ($relationshipType = $dataCitation->getAttribute('relationshipType')) {
            $elementCitation->setAttribute('specific-use', $relationshipType);
        }

        $this->appendAuthors($elementCitation, $dataCitation->getAttribute('authors'));

        if ($title = $dataCitation->getAttribute('title')) {
            $elementCitation->appendChild($this->createElement('data-title', htmlspecialchars($title)));
        }

        if ($year = $dataCitation->getAttribute('year')) {
            $yearElement = $this->createElement('year', (string) $year);
            $yearElement->setAttribute('iso-8601-date', (string) $year);
            $elementCitation->appendChild($yearElement);
        }

        if ($repository = $dataCitation->getAttribute('repository')) {
            $elementCitation->appendChild($this->createElement('source', htmlspecialchars($repository)));
        }

        if ($identifier = $dataCitation->getAttribute('identifier')) {
            $elementCitation->appendChild($this->createPubId(strtolower($dataCitation->getAttribute('identifierType') ?? ''), $identifier));
        }

        $this->appendUrl($elementCitation, $dataCitation->getAttribute('url'));
    }
}
