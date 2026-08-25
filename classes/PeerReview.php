<?php

/**
 * @file classes/PeerReview.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS open peer review sub-articles, following the JATS4R
 *   guidelines for peer review materials (https://jats4r.niso.org/peer-review-materials/).
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use DOMNode;
use PKP\API\v1\peerReviews\resources\SubmissionPeerReviewResource;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewer\recommendation\enums\ReviewerRecommendationType;

class PeerReview extends DOMDocument
{
    /**
     * Map OJS reviewer recommendation types to JATS4R peer-review-recommendation values.
     *
     * @see https://jats4r.niso.org/peer-review-materials/
     */
    protected const RECOMMENDATION_MAP = [
        ReviewerRecommendationType::APPROVED->value => 'accept',
        ReviewerRecommendationType::NOT_APPROVED->value => 'reject',
        ReviewerRecommendationType::REVISIONS_REQUESTED->value => 'revision',
    ];

    /**
     * Map OJS review methods to JATS4R PeerReviewType values, which follow STM's
     * Standard Taxonomy for Peer Review. JATS4R's triple-anonymized has no OJS equivalent.
     *
     * @see https://jats4r.niso.org/peer-review-materials/
     */
    protected const PEER_REVIEW_TYPE_MAP = [
        ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN => 'all-identities-visible',
        ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS => 'single-anonymized',
        ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS => 'double-anonymized',
    ];

    /**
     * Contributor role labels. These name a part in the review process rather than describing
     * the article, so they are fixed rather than following the document language.
     */
    protected const ROLE_REVIEWER = 'Reviewer';
    protected const ROLE_AUTHOR = 'Author';

    /**
     * Create a sub-article DOMNode for each publicly visible peer review of a submission.
     *
     * Reviews are gathered through the shared peer review resource, so only reviews that are
     * accepted, editor-confirmed and publicly visible, and that belong to a published
     * version, are included.
     *
     * @return array<DOMNode>
     */
    public function create(Submission $submission, Publication $publication): array
    {
        $peerReviewData = (new SubmissionPeerReviewResource($submission))->resolve();
        $reviewRounds = $peerReviewData['reviewRounds'] ?? [];
        if (empty($reviewRounds)) {
            return [];
        }

        $locale = $submission->getData('locale');
        $articleTitle = self::toPlainText($publication->getLocalizedTitle($locale, 'html'));

        return $this->createSubArticles($reviewRounds, $articleTitle, $locale);
    }

    /**
     * Create the sub-article DOMNodes for already resolved public peer review data.
     *
     * Only open reviews are published: the reviewer of an anonymous review has not agreed
     * to be part of the public record, so the review is left out of the document entirely
     * rather than included without an identified reviewer.
     *
     * @param array $reviewRounds Review round data, as returned by SubmissionPeerReviewResource
     * @param ?string $locale Locale for generated text, defaulting to the current locale
     *
     * @return array<DOMNode>
     */
    public function createSubArticles(
        array $reviewRounds,
        string $articleTitle,
        ?string $locale = null
    ): array {
        $subArticles = [];
        $reviewNumber = 1;
        $responseNumber = 1;
        foreach ($reviewRounds as $round) {
            $openReviews = array_filter(
                $round['reviews'] ?? [],
                fn (array $review) => $review['isReviewOpen'] ?? false
            );

            // Without a published review, the round's author response has nothing to respond to
            if (empty($openReviews)) {
                continue;
            }

            foreach ($openReviews as $review) {
                if ($subArticle = $this->createSubArticle($review, $round, $reviewNumber, $articleTitle, $locale)) {
                    $subArticles[] = $subArticle;
                    $reviewNumber++;
                }
            }

            // The authors' response to the round's reviews follows the reviewer reports
            if ($authorComment = $this->createAuthorComment($round, $openReviews, $articleTitle, $responseNumber, $locale)) {
                $subArticles[] = $authorComment;
                $responseNumber++;
            }
        }

        return $subArticles;
    }

    /**
     * Create a single reviewer-report sub-article DOMNode.
     *
     * Returns null for a review that is not open: an anonymous reviewer has not agreed to be
     * part of the public record, so no report is emitted for them. Callers already filter to
     * open reviews; this is the same rule enforced at the point the report is built.
     */
    protected function createSubArticle(
        array $review,
        array $round,
        int $reviewNumber,
        string $articleTitle,
        ?string $locale = null
    ): ?DOMNode {
        if (!($review['isReviewOpen'] ?? false)) {
            return null;
        }

        $subArticle = $this->createElement('sub-article');
        $subArticle->setAttribute('article-type', 'reviewer-report');
        $subArticle->setAttribute('id', 'rr' . $reviewNumber);
        $frontStub = $subArticle->appendChild($this->createElement('front-stub'));

        // Review DOI
        if ($doi = $review['doi'] ?? null) {
            $frontStub->appendChild($this->createElement('article-id', htmlspecialchars($doi)))
                ->setAttribute('pub-id-type', 'doi');
        }

        // Title, e.g. Peer Review #1 of "The original article title" (Round 1, Version of Record 1.0)
        $title = $this->createTitle('plugins.generic.jatsTemplate.reviewerReport.title', $round, [
            'reviewNumber' => $reviewNumber,
            'articleTitle' => $articleTitle,
        ], $locale);
        $frontStub->appendChild($this->createElement('title-group'))
            ->appendChild($this->createElement('article-title', htmlspecialchars($title)));

        // Reviewer contributor
        $frontStub->appendChild($this->createContribGroup($review));

        // Reviewer competing interests
        if ($authorNotes = $this->createAuthorNotes($review, $locale)) {
            $frontStub->appendChild($authorNotes);
        }

        // Date the review was completed
        if (($dateCompleted = $review['dateCompleted'] ?? null) && ($pubDate = $this->createPubDate($dateCompleted))) {
            $frontStub->appendChild($pubDate);
        }

        // Link to the reviewed article version
        if ($reviewedDoi = $round['publication']['doi'] ?? null) {
            $this->appendRelatedObject($frontStub, $reviewedDoi, 'peer-reviewed-article');
        }

        // A review method or recommendation with no machine-readable type cannot be used as a map key
        $reviewMethod = $review['reviewMethod'] ?? null;
        $recommendationType = $review['reviewerRecommendationTypeId'] ?? null;
        $this->appendCustomMetaGroup($frontStub, [
            'PeerReviewType' => $reviewMethod === null
                ? null
                : (self::PEER_REVIEW_TYPE_MAP[$reviewMethod] ?? null),
            'peer-review-revision-round' => $this->getRevisionRound($round),
            'peer-review-recommendation' => $recommendationType === null
                ? null
                : (self::RECOMMENDATION_MAP[$recommendationType] ?? null),
        ]);

        // Review content
        if ($body = $this->createBody($review)) {
            $subArticle->appendChild($body);
        }

        return $subArticle;
    }

    /**
     * Build a sub-article title for a review round.
     *
     * @param array $params Title parameters other than the round number and version
     */
    protected function createTitle(string $key, array $round, array $params, ?string $locale = null): string
    {
        $params['roundNumber'] = $round['roundNumber'] ?? '';
        $params['versionString'] = (string) ($round['publication']['versionString'] ?? '');

        return __($key, $params, $locale);
    }

    /**
     * Create the contrib-group element identifying the reviewer.
     *
     * Only open reviews reach this point, so the reviewer is normally named; the
     * <anonymous> fallback covers the edge case of an open review with no name.
     */
    protected function createContribGroup(array $review): DOMElement
    {
        $contribGroup = $this->createElement('contrib-group');
        $contrib = $contribGroup->appendChild($this->createElement('contrib'));
        $contrib->setAttribute('contrib-type', 'author');

        if (!empty($review['reviewerFullName'])) {
            if (!empty($review['reviewerOrcid'])) {
                $contrib->appendChild($this->createElement('contrib-id', htmlspecialchars($review['reviewerOrcid'])))
                    ->setAttribute('contrib-id-type', 'orcid')->parentNode
                    ->setAttribute('authenticated', ($review['reviewerHasVerifiedOrcid'] ?? false) ? 'true' : 'false');
            }
            $this->appendName(
                $contrib,
                $review['reviewerFullName'],
                $review['reviewerPreferredPublicName'] ?? null,
                $review['reviewerGivenName'] ?? null,
                $review['reviewerFamilyName'] ?? null
            );

            if (!empty($review['reviewerAffiliation'])) {
                $contrib->appendChild($this->createElement('aff', htmlspecialchars($review['reviewerAffiliation'])));
            }
        } else {
            $contrib->appendChild($this->createElement('anonymous'));
        }

        $this->appendRole($contrib, self::ROLE_REVIEWER, 'reviewer');

        return $contribGroup;
    }

    /**
     * Append a contributor's name, following the same structure ArticleFront uses for article
     * contributors: a chosen display name is kept alongside the structured name, not instead
     * of it, and a contributor recorded under a single name is marked given-only.
     */
    protected function appendName(
        DOMElement $contrib,
        string $fullName,
        ?string $preferredPublicName,
        ?string $givenName,
        ?string $familyName
    ): void {
        // Without either part there is nothing to structure, so the full name stands alone
        if (($givenName ?? '') === '' && ($familyName ?? '') === '') {
            $contrib->appendChild($this->createElement('string-name', htmlspecialchars($fullName)));
            return;
        }

        $nameAlternatives = $contrib->appendChild($this->createElement('name-alternatives'));

        if (($preferredPublicName ?? '') !== '') {
            $nameAlternatives->appendChild($this->createElement('string-name', htmlspecialchars($preferredPublicName)))
                ->setAttribute('specific-use', 'display');
        }

        $name = $nameAlternatives->appendChild($this->createElement('name'));
        $name->setAttribute('name-style', ($familyName ?? '') !== '' ? 'western' : 'given-only');
        $name->setAttribute('specific-use', 'primary');

        if (($familyName ?? '') !== '') {
            $name->appendChild($this->createElement('surname', htmlspecialchars($familyName)));
        }
        if (($givenName ?? '') !== '') {
            $name->appendChild($this->createElement('given-names', htmlspecialchars($givenName)));
        }
    }

    /**
     * Append the JATS4R-required <role> element to a contributor.
     *
     * Both the @specific-use token and the label are fixed: the label names the contributor's
     * part in the review process rather than describing the article, and receiving archives
     * display it as-is, so it does not follow the document language.
     */
    protected function appendRole(DOMElement $contrib, string $label, string $specificUse): void
    {
        $contrib->appendChild($this->createElement('role', $label))
            ->setAttribute('specific-use', $specificUse);
    }

    /**
     * Append a <related-object> linking to another peer review material.
     *
     * @param string $documentType A JATS4R related-object document-type, e.g. reviewer-report
     *   (a report the response addresses) or peer-reviewed-article (the reviewed version).
     */
    protected function appendRelatedObject(DOMElement $parent, string $doi, string $documentType): void
    {
        $relatedObject = $parent->appendChild($this->createElement('related-object'));
        $relatedObject->setAttribute('document-id', $doi);
        $relatedObject->setAttribute('document-id-type', 'doi');
        $relatedObject->setAttribute('document-type', $documentType);
    }

    /**
     * Append the JATS4R custom metadata describing a peer review material.
     *
     * @param array<string, ?string> $metas Meta names mapped to their values; a name with
     *   no value is left out, and a group with no values at all is not created.
     */
    protected function appendCustomMetaGroup(DOMElement $frontStub, array $metas): void
    {
        $metas = array_filter($metas, fn (?string $value) => ($value ?? '') !== '');
        if (!$metas) {
            return;
        }

        $customMetaGroup = $frontStub->appendChild($this->createElement('custom-meta-group'));
        foreach ($metas as $name => $value) {
            $customMeta = $customMetaGroup->appendChild($this->createElement('custom-meta'));
            $customMeta->appendChild($this->createElement('meta-name', $name));
            $customMeta->appendChild($this->createElement('meta-value', $value));
        }
    }

    /**
     * Get a round's revision round number, counting the first round of public review as 1.
     *
     * JATS4R leaves it to the publisher whether the initial submission is round 0 or 1, so
     * this follows the round numbering used in the sub-article titles.
     */
    protected function getRevisionRound(array $round): ?string
    {
        return isset($round['roundNumber']) ? (string) $round['roundNumber'] : null;
    }

    /**
     * Create a pub-date element for the date the review was completed.
     *
     * Returns null when the date cannot be parsed.
     */
    protected function createPubDate(string $date): ?DOMElement
    {
        if (($timestamp = strtotime($date)) === false) {
            return null;
        }

        $pubDate = $this->createElement('pub-date');
        $pubDate->setAttribute('date-type', 'pub');
        $pubDate->setAttribute('publication-format', 'electronic');
        $pubDate->setAttribute('iso-8601-date', date('Y-m-d', $timestamp));
        $pubDate->appendChild($this->createElement('day', date('d', $timestamp)));
        $pubDate->appendChild($this->createElement('month', date('m', $timestamp)));
        $pubDate->appendChild($this->createElement('year', date('Y', $timestamp)));

        return $pubDate;
    }

    /**
     * Create an author-comment sub-article DOMNode for a round's public author response.
     *
     * Returns null when the round has no author response, when its reviews are not all
     * publicly visible, or when the response has no content.
     *
     * @param array $reviews The round's published reviews, so the response can link to the
     *   reviewer reports it addresses
     * @param int $responseNumber Sequential number across rounds, for the sub-article anchor
     */
    protected function createAuthorComment(
        array $round,
        array $reviews,
        string $articleTitle,
        int $responseNumber,
        ?string $locale = null
    ): ?DOMNode {
        $authorResponse = $round['authorResponse'] ?? null;
        if (!$authorResponse || !($authorResponse['isPublic'] ?? false)) {
            return null;
        }

        // The response is a multilingual field; use the document locale, falling back to the
        // first translation in locale order (sorted so the fallback is deterministic).
        $translations = array_filter((array) ($authorResponse['response'] ?? []));
        ksort($translations);
        $responseText = (string) ($translations[$locale] ?? (reset($translations) ?: ''));
        if (trim(strip_tags($responseText)) === '') {
            return null;
        }

        $subArticle = $this->createElement('sub-article');
        $subArticle->setAttribute('article-type', 'author-comment');
        $subArticle->setAttribute('id', 'ar' . $responseNumber);

        $frontStub = $subArticle->appendChild($this->createElement('front-stub'));

        $title = $this->createTitle('plugins.generic.jatsTemplate.authorResponse.title', $round, [
            'articleTitle' => $articleTitle,
        ], $locale);
        $frontStub->appendChild($this->createElement('title-group'))
            ->appendChild($this->createElement('article-title', htmlspecialchars($title)));

        $frontStub->appendChild($this->createAuthorContribGroup($authorResponse['associatedAuthors'] ?? []));

        if (($createdAt = $authorResponse['createdAt'] ?? null) && ($pubDate = $this->createPubDate($createdAt))) {
            $frontStub->appendChild($pubDate);
        }

        // Link to each reviewer report the response addresses (only reports with a DOI can be linked).
        foreach ($reviews as $review) {
            if ($doi = $review['doi'] ?? null) {
                $this->appendRelatedObject($frontStub, $doi, 'reviewer-report');
            }
        }

        // The response belongs to the same round as the reports it addresses. It carries no
        // PeerReviewType or recommendation: both describe the review, not the reply to it.
        $this->appendCustomMetaGroup($frontStub, [
            'peer-review-revision-round' => $this->getRevisionRound($round),
        ]);

        $body = $subArticle->appendChild($this->createElement('body'));
        $this->appendSanitizedContent($body, $responseText);

        return $subArticle;
    }

    /**
     * Create the contrib-group element identifying the authors of a response.
     *
     * Named authors are listed when available; a response with none still gets a single
     * anonymous author, so the JATS4R-required <contrib> and <role> are always present.
     */
    protected function createAuthorContribGroup(array $authors): DOMElement
    {
        $contribGroup = $this->createElement('contrib-group');

        foreach ($authors as $author) {
            if (empty($author['fullName'])) {
                continue;
            }

            $contrib = $contribGroup->appendChild($this->createElement('contrib'));
            $contrib->setAttribute('contrib-type', 'author');

            if (!empty($author['orcid'])) {
                $contrib->appendChild($this->createElement('contrib-id', htmlspecialchars($author['orcid'])))
                    ->setAttribute('contrib-id-type', 'orcid')->parentNode
                    ->setAttribute('authenticated', ($author['hasVerifiedOrcid'] ?? false) ? 'true' : 'false');
            }
            $this->appendName(
                $contrib,
                $author['fullName'],
                $author['preferredPublicName'] ?? null,
                $author['givenName'] ?? null,
                $author['familyName'] ?? null
            );
            $this->appendRole($contrib, self::ROLE_AUTHOR, 'author');
        }

        if (!$contribGroup->hasChildNodes()) {
            $contrib = $contribGroup->appendChild($this->createElement('contrib'));
            $contrib->setAttribute('contrib-type', 'author');
            $contrib->appendChild($this->createElement('anonymous'));
            $this->appendRole($contrib, self::ROLE_AUTHOR, 'author');
        }

        return $contribGroup;
    }

    /**
     * Create the author-notes element holding the reviewer's competing interests statement.
     *
     * Returns null when the reviewer did not answer the competing interests question.
     */
    protected function createAuthorNotes(array $review, ?string $locale = null): ?DOMElement
    {
        if (!($review['competingInterestsDeclared'] ?? false)) {
            return null;
        }

        $statement = (string) ($review['competingInterests'] ?? '');
        if (trim(strip_tags($statement)) === '') {
            $statement = __('reviewer.submission.competingInterests.declaredNone', [], $locale);
        }

        $authorNotes = $this->createElement('author-notes');
        $footnote = $authorNotes->appendChild($this->createElement('fn'));
        $footnote->setAttribute('fn-type', 'coi-statement');
        $this->appendSanitizedContent($footnote, $statement);

        return $authorNotes;
    }

    /**
     * Create the body element holding the publicly visible review content.
     *
     * Content is taken either from a review form's questions and responses or from
     * the reviewer's free-form comments. Returns null when there is no public content.
     */
    protected function createBody(array $review): ?DOMElement
    {
        $body = $this->createElement('body');
        $hasContent = false;

        $questions = $review['reviewForm']['questions'] ?? null;
        if (!empty($questions)) {
            foreach ($questions as $question) {
                $sec = $body->appendChild($this->createElement('sec'));
                // The question is stored as rich text, so its markup is dropped for the title
                $sec->appendChild($this->createElement('title', htmlspecialchars(self::toPlainText($question['question'] ?? ''))));
                foreach ($question['responses'] ?? [] as $response) {
                    $this->appendSanitizedContent($sec, (string) $response);
                }
                $hasContent = true;
            }
        } else {
            foreach ($review['reviewerComments'] ?? [] as $comment) {
                $this->appendSanitizedContent($body, (string) $comment);
                $hasContent = true;
            }
        }

        return $hasContent ? $body : null;
    }

    /**
     * Reduce HTML-sourced text to the characters behind it.
     *
     * Titles, questions and review content are stored as HTML, so their special characters
     * arrive already escaped. Decoding them here lets the caller escape exactly once when
     * building the XML: escaping twice is what turns an "&" into "&amp;amp;" in the output.
     */
    protected static function toPlainText(?string $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Append user-provided HTML content to a parent element as JATS paragraph markup.
     */
    protected function appendSanitizedContent(DOMElement $parent, string $html): void
    {
        // Keep only safe formatting tags supported by JATS
        // <br> is included for later processing.
        $allowedTags = '<i><em><b><strong><u><a><sup><sub><p><br>';
        $cleaned = strip_tags($html, $allowedTags);
        // Decode before re-escaping so an already-escaped character is not escaped twice.
        // Anything decoding to markup other than the allowed tags stays escaped below, and so
        // survives as the literal text the author wrote.
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaped = htmlspecialchars($cleaned, ENT_COMPAT, 'UTF-8');
        $converted = JatsHelper::htmlToJats($escaped);

        // Ensure the content is wrapped in at least one block-level paragraph. Line breaks are
        // still escaped at this point, so they cannot be mistaken for an existing paragraph.
        if (!str_contains($converted, '<p>')) {
            $converted = "<p>{$converted}</p>";
        }

        // JATS 1.2 has no in-paragraph line break, so a soft break ends the paragraph and
        // starts the next one. A break that already sat on a paragraph boundary would double
        // up the tags, and one against an edge would leave an empty paragraph.
        $converted = preg_replace('/&lt;br\s*\/?&gt;/i', '</p><p>', $converted);
        $converted = str_replace(['</p></p>', '<p><p>', '<p></p>'], ['</p>', '<p>', ''], $converted);

        $fragment = $this->createDocumentFragment();
        // Suppress warnings from malformed user-provided content
        if (@$fragment->appendXML($converted)) {
            $parent->appendChild($fragment);
        } else {
            // Fallback if XML parsing fails - the content is reduced to its plain text
            $parent->appendChild($this->createElement('p', htmlspecialchars(self::toPlainText($html), ENT_COMPAT, 'UTF-8')));
        }
    }
}
