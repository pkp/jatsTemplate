<?php

/**
 * @file PeerReviewTest.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS open peer review sub-article unit tests
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use APP\plugins\generic\jatsTemplate\classes\PeerReview;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\facades\Locale;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewer\recommendation\enums\ReviewerRecommendationType;
use PKP\tests\PKPTestCase;

#[CoversClass(PeerReview::class)]
class PeerReviewTest extends PKPTestCase
{
    use ValidatesAgainstJats;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::registerPath('plugins/generic/jatsTemplate/locale');
    }

    /**
     * Build a review in the shape returned by SubmissionPeerReviewResource
     */
    private function createReview(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'reviewerId' => 1,
            'reviewerFullName' => 'Reviewer Name',
            'reviewerGivenName' => 'Reviewer',
            'reviewerFamilyName' => 'Name',
            'reviewerAffiliation' => null,
            'reviewerOrcid' => null,
            'reviewerHasVerifiedOrcid' => false,
            'doi' => null,
            'doiUrl' => null,
            'dateCompleted' => '2026-01-15 10:00:00',
            'isReviewOpen' => true,
            'reviewMethod' => ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN,
            'reviewerRecommendationDisplayText' => 'Accept Submission',
            'reviewerRecommendationId' => 1,
            'reviewerRecommendationTypeId' => ReviewerRecommendationType::APPROVED->value,
            'reviewerRecommendationTypeLabel' => 'Approved',
            'reviewForm' => null,
            'reviewerComments' => [],
            'competingInterestsDeclared' => null,
            'competingInterests' => null,
        ], $overrides);
    }

    /**
     * Build a review round in the shape returned by SubmissionPeerReviewResource
     */
    private function createRound(
        array $reviews,
        string $versionString = 'Version of Record 1.0',
        int $roundNumber = 1,
        ?array $authorResponse = null,
        ?string $publicationDoi = null
    ): array {
        return [
            'roundId' => $roundNumber,
            'roundNumber' => $roundNumber,
            'publication' => [
                'id' => 1,
                'versionString' => $versionString,
                'versionStage' => 'version_of_record',
                'datePublished' => '2026-01-20',
                'doi' => $publicationDoi,
            ],
            'reviews' => $reviews,
            'authorResponse' => $authorResponse,
        ];
    }

    /**
     * Build an author response in the shape returned by ReviewRoundAuthorResponseResource
     */
    private function createAuthorResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'reviewRoundId' => 1,
            // The resource exposes the response as a multilingual field
            'response' => ['en' => '<p>We thank the reviewers for their comments.</p>'],
            'associatedAuthors' => [
                [
                    'id' => 1,
                    'fullName' => 'Author One',
                    'givenName' => 'Author',
                    'familyName' => 'One',
                    'orcid' => 'https://orcid.org/0000-0002-1825-0097',
                    'hasVerifiedOrcid' => true
                ],
            ],
            'submittedByUser' => ['id' => 1, 'fullName' => 'Author One'],
            'isPublic' => true,
            'createdAt' => '2026-04-01 09:00:00',
        ], $overrides);
    }

    /**
     * Build the sub-articles and return them within a queryable document
     */
    private function buildDocument(array $reviewRounds, ?string $locale = 'en'): DOMDocument
    {
        $peerReview = new PeerReview();
        $subArticles = $peerReview->createSubArticles($reviewRounds, 'The original article title', $locale);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $article = $doc->appendChild($doc->createElement('article'));
        $article->setAttribute('dtd-version', '1.2');
        $front = $article->appendChild($doc->createElement('front'));
        $journalMeta = $front->appendChild($doc->createElement('journal-meta'));
        $journalMeta->appendChild($doc->createElement('journal-id', 'test-journal'));
        $journalMeta->appendChild($doc->createElement('issn', '1234-5678'));
        $articleMeta = $front->appendChild($doc->createElement('article-meta'));
        $articleMeta->appendChild($doc->createElement('title-group'))
            ->appendChild($doc->createElement('article-title', 'The original article title'));

        foreach ($subArticles as $subArticle) {
            $article->appendChild($doc->importNode($subArticle, true));
        }

        return $doc;
    }

    private function query(DOMDocument $doc, string $expression): \DOMNodeList
    {
        return (new DOMXPath($doc))->query($expression);
    }

    public function testAnonymousReviewIsNotPublished(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'isReviewOpen' => false,
                    // The reviewer has not agreed to be part of the public record, so
                    // nothing about the review is published, not even anonymized
                    'reviewerFullName' => 'Reviewer Name',
                    'reviewerOrcid' => 'https://orcid.org/0000-0002-1825-0097',
                    'reviewerComments' => ['<p>A confidential review comment.</p>'],
                ]),
            ]),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article'));
        self::assertStringNotContainsString('Reviewer Name', $doc->saveXML());
        self::assertStringNotContainsString('confidential review comment', $doc->saveXML());
    }

    public function testAnonymousReviewsAreExcludedFromRoundsWithOpenReviews(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview(['id' => 1, 'isReviewOpen' => false, 'reviewerFullName' => 'Hidden Reviewer']),
                $this->createReview(['id' => 2, 'reviewerFullName' => 'Open Reviewer']),
            ]),
        ]);

        $reports = $this->query($doc, '//sub-article[@article-type="reviewer-report"]');
        self::assertCount(1, $reports);
        self::assertStringNotContainsString('Hidden Reviewer', $doc->saveXML());

        // Numbering counts published reviews only
        $title = $this->query($doc, '//sub-article/front-stub/title-group/article-title')->item(0);
        self::assertEquals('Peer Review #1 of "The original article title" (Round 1, Version of Record 1.0)', $title->textContent);
    }

    public function testRoundWithoutOpenReviewsDropsItsAuthorResponse(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview(['isReviewOpen' => false])],
                '1.0',
                1,
                $this->createAuthorResponse(['response' => ['en' => '<p>A response to unpublished reviews.</p>']])
            ),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article'));
        self::assertStringNotContainsString('A response to unpublished reviews', $doc->saveXML());
    }

    public function testOpenReviewerIsCreditedWithAReviewerRole(): void
    {
        $doc = $this->buildDocument([$this->createRound([$this->createReview()])]);

        $role = $this->query(
            $doc,
            '//sub-article/front-stub/contrib-group/contrib[@contrib-type="author"]/role[@specific-use="reviewer"]'
        );
        self::assertCount(1, $role);
        self::assertEquals('Reviewer', $role->item(0)->textContent);
    }

    public function testOpenReviewerIsIdentified(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'reviewerOrcid' => 'https://orcid.org/0000-0002-1825-0097',
                    'reviewerHasVerifiedOrcid' => true,
                ]),
            ]),
        ]);

        $name = $this->query($doc, '//sub-article/front-stub/contrib-group/contrib/name-alternatives/name');
        self::assertCount(1, $name);
        self::assertEquals('Name', $name->item(0)->getElementsByTagName('surname')->item(0)->textContent);
        self::assertEquals('Reviewer', $name->item(0)->getElementsByTagName('given-names')->item(0)->textContent);
        // No display name is recorded for a reviewer who has not chosen one
        self::assertCount(0, $this->query($doc, '//sub-article//string-name'));

        $contribId = $this->query($doc, '//sub-article/front-stub/contrib-group/contrib/contrib-id[@contrib-id-type="orcid"]');
        self::assertCount(1, $contribId);
        self::assertEquals('https://orcid.org/0000-0002-1825-0097', $contribId->item(0)->textContent);
        self::assertEquals('true', $contribId->item(0)->getAttribute('authenticated'));

        self::assertCount(0, $this->query($doc, '//sub-article//anonymous'));
    }

    /**
     * With no name parts at all there is nothing to structure, so the full name stands alone
     */
    public function testContributorWithoutNamePartsKeepsStringName(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview(['reviewerFullName' => 'Unparsed Name', 'reviewerGivenName' => null, 'reviewerFamilyName' => null])],
                '1.0',
                1,
                $this->createAuthorResponse([
                    'associatedAuthors' => [['id' => 1, 'fullName' => 'Solo', 'givenName' => '', 'familyName' => '']],
                ])
            ),
        ]);

        $stringNames = $this->query($doc, '//sub-article//contrib/string-name');
        self::assertCount(2, $stringNames);
        self::assertEquals('Unparsed Name', $stringNames->item(0)->textContent);
        self::assertEquals('Solo', $stringNames->item(1)->textContent);
        self::assertCount(0, $this->query($doc, '//sub-article//name-alternatives'));

        $this->assertXmlValidatesAgainstJats12($doc);
    }

    /**
     * A chosen display name is kept alongside the structured name rather than replacing it,
     * matching how ArticleFront records article contributors
     */
    public function testPreferredPublicNameIsKeptAlongsideTheStructuredName(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewerPreferredPublicName' => 'R. N. Pseudonym'])]),
        ]);

        $alternatives = $this->query($doc, '//sub-article//contrib/name-alternatives');
        self::assertCount(1, $alternatives);

        $display = $this->query($doc, '//sub-article//name-alternatives/string-name[@specific-use="display"]');
        self::assertCount(1, $display);
        self::assertEquals('R. N. Pseudonym', $display->item(0)->textContent);

        $name = $this->query($doc, '//sub-article//name-alternatives/name[@specific-use="primary"]');
        self::assertCount(1, $name);
        self::assertEquals('western', $name->item(0)->getAttribute('name-style'));
        self::assertEquals('Name', $name->item(0)->getElementsByTagName('surname')->item(0)->textContent);

        $this->assertXmlValidatesAgainstJats12($doc);
    }

    /**
     * A contributor recorded under a single name is marked given-only rather than having
     * that name treated as a surname
     */
    public function testContributorWithOnlyAGivenNameIsMarkedGivenOnly(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewerGivenName' => 'Mononym', 'reviewerFamilyName' => null])]),
        ]);

        $name = $this->query($doc, '//sub-article//name-alternatives/name');
        self::assertCount(1, $name);
        self::assertEquals('given-only', $name->item(0)->getAttribute('name-style'));
        self::assertCount(0, $this->query($doc, '//sub-article//name/surname'));
        self::assertEquals('Mononym', $this->query($doc, '//sub-article//name/given-names')->item(0)->textContent);

        $this->assertXmlValidatesAgainstJats12($doc);
    }

    /**
     * The role names a part in the review process, so it stays fixed while the surrounding
     * generated text follows the document language
     */
    public function testRoleLabelsAreNotLocalized(): void
    {
        $doc = $this->buildDocument(
            [$this->createRound([$this->createReview()], '1.0', 1, $this->createAuthorResponse())],
            'fr'
        );

        self::assertEquals('Reviewer', $this->query($doc, '//role[@specific-use="reviewer"]')->item(0)->textContent);
        self::assertEquals('Author', $this->query($doc, '//role[@specific-use="author"]')->item(0)->textContent);
    }

    public function testUnverifiedOrcidIsNotAuthenticated(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'reviewerOrcid' => 'https://orcid.org/0000-0002-1825-0097',
                    'reviewerHasVerifiedOrcid' => false,
                ]),
            ]),
        ]);

        $contribId = $this->query($doc, '//sub-article//contrib-id[@contrib-id-type="orcid"]');
        self::assertEquals('false', $contribId->item(0)->getAttribute('authenticated'));
    }

    public function testOpenReviewWithoutNameFallsBackToAnonymous(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview(['reviewerFullName' => null]),
            ]),
        ]);

        self::assertCount(1, $this->query($doc, '//sub-article//anonymous'));
        self::assertCount(0, $this->query($doc, '//sub-article//string-name'));
    }

    public function testOpenReviewerAffiliationIsIncluded(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'reviewerAffiliation' => 'University of Somewhere',
                ]),
            ]),
        ]);

        $aff = $this->query($doc, '//sub-article/front-stub/contrib-group/contrib/aff');
        self::assertCount(1, $aff);
        self::assertEquals('University of Somewhere', $aff->item(0)->textContent);
    }

    public function testReviewDateIsIncluded(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview(['dateCompleted' => '2026-03-09 14:30:00']),
            ]),
        ]);

        $pubDate = $this->query($doc, '//sub-article/front-stub/pub-date[@date-type="pub"]');
        self::assertCount(1, $pubDate);
        self::assertEquals('2026-03-09', $pubDate->item(0)->getAttribute('iso-8601-date'));
        self::assertEquals('09', $this->query($doc, '//sub-article/front-stub/pub-date/day')->item(0)->textContent);
        self::assertEquals('03', $this->query($doc, '//sub-article/front-stub/pub-date/month')->item(0)->textContent);
        self::assertEquals('2026', $this->query($doc, '//sub-article/front-stub/pub-date/year')->item(0)->textContent);
    }

    public function testReviewWithoutDateOmitsPubDate(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview(['dateCompleted' => null]),
            ]),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article//pub-date'));
    }

    public function testOpenReviewCompetingInterestsAreDisclosed(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'competingInterestsDeclared' => true,
                    'competingInterests' => '<p>I co-authored a paper with the author.</p>',
                ]),
            ]),
        ]);

        $footnote = $this->query($doc, '//sub-article/front-stub/author-notes/fn[@fn-type="coi-statement"]');
        self::assertCount(1, $footnote);
        self::assertEquals('I co-authored a paper with the author.', $footnote->item(0)->textContent);
    }

    public function testOpenReviewWithNoCompetingInterests(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'competingInterestsDeclared' => true,
                    'competingInterests' => '',
                ]),
            ]),
        ]);

        $footnote = $this->query($doc, '//sub-article/front-stub/author-notes/fn[@fn-type="coi-statement"]');
        self::assertCount(1, $footnote);
        self::assertEquals('No competing interests were disclosed.', $footnote->item(0)->textContent);
    }

    public function testUndeclaredCompetingInterestsAreOmitted(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerFullName' => 'Reviewer Name',
                    'competingInterestsDeclared' => false,
                ]),
            ]),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article//author-notes'));
    }

    public function testAuthorResponseBecomesAuthorCommentSubArticle(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview()],
                '1.0',
                1,
                $this->createAuthorResponse([
                    'response' => ['en' => '<p>We thank the reviewers and have revised section 3.</p>'],
                ])
            ),
        ]);

        $authorComment = $this->query($doc, '//sub-article[@article-type="author-comment"]');
        self::assertCount(1, $authorComment);

        // It follows the reviewer report it responds to
        $subArticles = $this->query($doc, '//article/sub-article');
        self::assertEquals('reviewer-report', $subArticles->item(0)->getAttribute('article-type'));
        self::assertEquals('author-comment', $subArticles->item(1)->getAttribute('article-type'));

        self::assertEquals(
            'We thank the reviewers and have revised section 3.',
            $this->query($doc, '//sub-article[@article-type="author-comment"]/body/p')->item(0)->textContent
        );

        $author = $this->query(
            $doc,
            '//sub-article[@article-type="author-comment"]/front-stub/contrib-group/contrib/name-alternatives/name'
        );
        self::assertCount(1, $author);
        self::assertEquals('One', $author->item(0)->getElementsByTagName('surname')->item(0)->textContent);
        self::assertEquals('Author', $author->item(0)->getElementsByTagName('given-names')->item(0)->textContent);

        // Every contributor carries the JATS4R-required role
        self::assertCount(
            1,
            $this->query($doc, '//sub-article[@article-type="author-comment"]//contrib/role[@specific-use="author"]')
        );

        $pubDate = $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub/pub-date/year');
        self::assertEquals('2026', $pubDate->item(0)->textContent);
    }

    public function testAuthorResponseLinksToReviewerReportsWithDois(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [
                    $this->createReview(['id' => 1, 'doi' => '10.1234/review.1']),
                    $this->createReview(['id' => 2, 'doi' => null]),
                    $this->createReview(['id' => 3, 'doi' => '10.1234/review.3']),
                ],
                'Version of Record 1.0',
                1,
                $this->createAuthorResponse()
            ),
        ]);

        // The response links only to the reports that carry a DOI
        $related = $this->query(
            $doc,
            '//sub-article[@article-type="author-comment"]/front-stub/related-object[@document-type="reviewer-report"]'
        );
        self::assertCount(2, $related);

        $dois = [$related->item(0)->getAttribute('document-id'), $related->item(1)->getAttribute('document-id')];
        self::assertEqualsCanonicalizing(['10.1234/review.1', '10.1234/review.3'], $dois);
        self::assertEquals('doi', $related->item(0)->getAttribute('document-id-type'));
    }

    public function testReviewerReportLinksToTheReviewedArticleVersion(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview()], 'Version of Record 1.0', 1, null, '10.5555/article.v1'),
        ]);

        $related = $this->query(
            $doc,
            '//sub-article[@article-type="reviewer-report"]/front-stub/related-object[@document-type="peer-reviewed-article"]'
        );
        self::assertCount(1, $related);
        self::assertEquals('10.5555/article.v1', $related->item(0)->getAttribute('document-id'));
        self::assertEquals('doi', $related->item(0)->getAttribute('document-id-type'));
    }

    public function testReviewerReportWithoutAReviewedDoiOmitsTheArticleLink(): void
    {
        $doc = $this->buildDocument([$this->createRound([$this->createReview()])]);

        self::assertCount(
            0,
            $this->query($doc, '//sub-article[@article-type="reviewer-report"]//related-object[@document-type="peer-reviewed-article"]')
        );
    }

    public function testAuthorResponseBodyUsesTheDocumentLocale(): void
    {
        $doc = $this->buildDocument(
            [
                $this->createRound(
                    [$this->createReview()],
                    'Version of Record 1.0',
                    1,
                    $this->createAuthorResponse(['response' => [
                        'en' => '<p>English response.</p>',
                        'fr' => '<p>Réponse française.</p>',
                    ]])
                ),
            ],
            'fr'
        );

        // The multilingual response is resolved to the document locale, not cast to "Array"
        $body = $this->query($doc, '//sub-article[@article-type="author-comment"]/body/p')->item(0)->textContent;
        self::assertEquals('Réponse française.', $body);
        self::assertStringNotContainsString('Array', $doc->saveXML());
        self::assertStringNotContainsString('English response', $doc->saveXML());
    }

    public function testAuthorResponseFallsBackToFirstLocaleInOrder(): void
    {
        // The document locale (fr) is absent, so the fallback picks the first by locale
        // order (de before en) rather than by arbitrary array order
        $doc = $this->buildDocument(
            [
                $this->createRound(
                    [$this->createReview()],
                    'Version of Record 1.0',
                    1,
                    $this->createAuthorResponse(['response' => [
                        'en' => '<p>English response.</p>',
                        'de' => '<p>Deutsche Antwort.</p>',
                    ]])
                ),
            ],
            'fr'
        );

        $body = $this->query($doc, '//sub-article[@article-type="author-comment"]/body/p')->item(0)->textContent;
        self::assertEquals('Deutsche Antwort.', $body);
    }

    public function testNonPublicAuthorResponseIsOmitted(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview()],
                '1.0',
                1,
                $this->createAuthorResponse([
                    'isPublic' => false,
                    'response' => ['en' => '<p>This response must not be exported.</p>'],
                ])
            ),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article[@article-type="author-comment"]'));
        self::assertStringNotContainsString('must not be exported', $doc->saveXML());
    }

    public function testEmptyAuthorResponseIsOmitted(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview()], '1.0', 1, $this->createAuthorResponse(['response' => ['en' => '   ']])),
        ]);

        self::assertCount(0, $this->query($doc, '//sub-article[@article-type="author-comment"]'));
    }

    public function testAuthorResponseWithoutAuthorsFallsBackToAnonymous(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview()],
                '1.0',
                1,
                $this->createAuthorResponse(['associatedAuthors' => []])
            ),
        ]);

        // JATS4R requires a <contrib> with a <role>, so an unnamed author is still recorded
        $contrib = $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub/contrib-group/contrib[@contrib-type="author"]');
        self::assertCount(1, $contrib);
        self::assertCount(1, $this->query($doc, '//sub-article[@article-type="author-comment"]//contrib/anonymous'));
        self::assertCount(0, $this->query($doc, '//sub-article[@article-type="author-comment"]//contrib/string-name'));
        self::assertCount(1, $this->query($doc, '//sub-article[@article-type="author-comment"]//contrib/role[@specific-use="author"]'));
    }

    public function testReviewDoiIsIncluded(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview([
                'id' => 7,
                'doi' => '10.1234/review.7',
                'doiUrl' => 'https://doi.org/10.1234/review.7',
            ])]),
        ]);

        $articleId = $this->query($doc, '//sub-article/front-stub/article-id[@pub-id-type="doi"]');
        self::assertCount(1, $articleId);
        self::assertEquals('10.1234/review.7', $articleId->item(0)->textContent);
    }

    public function testReviewWithoutDoiOmitsArticleId(): void
    {
        $doc = $this->buildDocument([$this->createRound([$this->createReview()])]);

        self::assertCount(0, $this->query($doc, '//sub-article/front-stub/article-id'));
    }

    public function testReviewsAreNumberedSequentiallyAcrossRounds(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['id' => 1]), $this->createReview(['id' => 2])]),
            $this->createRound([$this->createReview(['id' => 3])], 'Version of Record 1.1', 2),
        ]);

        $titles = $this->query($doc, '//sub-article/front-stub/title-group/article-title');
        self::assertCount(3, $titles);

        // Numbering continues across rounds, and each title carries its own round and version
        self::assertEquals(
            'Peer Review #1 of "The original article title" (Round 1, Version of Record 1.0)',
            $titles->item(0)->textContent
        );
        self::assertEquals(
            'Peer Review #2 of "The original article title" (Round 1, Version of Record 1.0)',
            $titles->item(1)->textContent
        );
        self::assertEquals(
            'Peer Review #3 of "The original article title" (Round 2, Version of Record 1.1)',
            $titles->item(2)->textContent
        );
    }

    public function testSubArticlesCarryUniqueIdAnchors(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview(['id' => 1]), $this->createReview(['id' => 2])],
                'Version of Record 1.0',
                1,
                $this->createAuthorResponse(['response' => ['en' => '<p>Revised.</p>']])
            ),
            $this->createRound(
                [$this->createReview(['id' => 3])],
                'Version of Record 1.1',
                2,
                $this->createAuthorResponse(['response' => ['en' => '<p>Revised again.</p>']])
            ),
        ]);

        $ids = [];
        foreach ($this->query($doc, '//article/sub-article') as $subArticle) {
            $ids[] = $subArticle->getAttribute('id');
        }

        // Reports and responses are numbered independently and continue across rounds
        self::assertSame(['rr1', 'rr2', 'ar1', 'rr3', 'ar2'], $ids);
    }

    public function testAuthorResponseTitlesAreDistinguishedByRound(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['id' => 1])], 'Version of Record 1.0', 1, $this->createAuthorResponse()),
            $this->createRound([$this->createReview(['id' => 2])], 'Version of Record 1.0', 2, $this->createAuthorResponse()),
        ]);

        $titles = $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub/title-group/article-title');
        self::assertCount(2, $titles);

        // The round keeps the titles apart even when both rounds reviewed the same version
        self::assertEquals('Author Response to Peer Review of "The original article title" (Round 1, Version of Record 1.0)', $titles->item(0)->textContent);
        self::assertEquals('Author Response to Peer Review of "The original article title" (Round 2, Version of Record 1.0)', $titles->item(1)->textContent);
    }

    public function testReviewFormQuestionsBecomeSections(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewForm' => [
                        'id' => 1,
                        'title' => 'Review form',
                        'description' => 'A review form',
                        'questions' => [
                            ['question' => 'Is the methodology sound?', 'responses' => ['Yes, it is well designed.']],
                            ['question' => 'Select all that apply', 'responses' => ['Originality', 'Clarity']],
                        ],
                    ],
                ]),
            ]),
        ]);

        $sections = $this->query($doc, '//sub-article/body/sec');
        self::assertCount(2, $sections);

        self::assertEquals(
            'Is the methodology sound?',
            $this->query($doc, '//sub-article/body/sec[1]/title')->item(0)->textContent
        );
        self::assertEquals(
            'Yes, it is well designed.',
            $this->query($doc, '//sub-article/body/sec[1]/p')->item(0)->textContent
        );

        $secondResponses = $this->query($doc, '//sub-article/body/sec[2]/p');
        self::assertCount(2, $secondResponses);
        self::assertEquals('Originality', $secondResponses->item(0)->textContent);
        self::assertEquals('Clarity', $secondResponses->item(1)->textContent);
    }

    public function testReviewerCommentsBecomeParagraphs(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerComments' => ['<p>First comment.</p>', 'Second comment.'],
                ]),
            ]),
        ]);

        $paragraphs = $this->query($doc, '//sub-article/body/p');
        self::assertCount(2, $paragraphs);
        self::assertEquals('First comment.', $paragraphs->item(0)->textContent);
        self::assertEquals('Second comment.', $paragraphs->item(1)->textContent);
    }

    public function testCommentHtmlIsConvertedToJats(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([
                $this->createReview([
                    'reviewerComments' => ['A <b>bold</b> and <i>italic</i> comment with a <script>alert(1)</script> tag.'],
                ]),
            ]),
        ]);

        $xml = $doc->saveXML();
        self::assertCount(1, $this->query($doc, '//sub-article/body/p/bold'));
        self::assertCount(1, $this->query($doc, '//sub-article/body/p/italic'));
        // Unsupported markup is stripped, leaving only its harmless text content
        self::assertStringNotContainsString('<script>', $xml);
        self::assertCount(0, $this->query($doc, '//sub-article//script'));
    }

    /**
     * A soft line break survives as a JATS break, in whichever form the editor wrote it
     */
    public function testLineBreaksAreConvertedToJatsBreaks(): void
    {
        $doc = $this->buildDocument([
            $this->createRound(
                [$this->createReview(['reviewerComments' => ['First line.<br>Second line.<br />Third line.']])],
                '1.0',
                1,
                $this->createAuthorResponse([
                    'response' => ['en' => '<p>Our reply.<br/>On two lines.</p>'],
                ])
            ),
        ]);

        $reportParagraphs = $this->query($doc, '//sub-article[@article-type="reviewer-report"]/body/p');
        self::assertCount(3, $reportParagraphs);
        self::assertEquals('First line.', $reportParagraphs->item(0)->textContent);
        self::assertEquals('Second line.', $reportParagraphs->item(1)->textContent);
        self::assertEquals('Third line.', $reportParagraphs->item(2)->textContent);

        $responseParagraphs = $this->query($doc, '//sub-article[@article-type="author-comment"]/body/p');
        self::assertCount(2, $responseParagraphs);
        self::assertEquals('Our reply.', $responseParagraphs->item(0)->textContent);
        self::assertEquals('On two lines.', $responseParagraphs->item(1)->textContent);

        $this->assertXmlValidatesAgainstJats12($doc);
    }

    /**
     * A break against a paragraph edge would otherwise leave an empty paragraph behind
     */
    public function testLeadingAndTrailingLineBreaksLeaveNoEmptyParagraph(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewerComments' => ['<br>Only line.<br>']])]),
        ]);

        $paragraphs = $this->query($doc, '//sub-article/body/p');
        self::assertCount(1, $paragraphs);
        self::assertEquals('Only line.', $paragraphs->item(0)->textContent);
    }

    /**
     * A break already sitting on a paragraph boundary must not double up the tags, which
     * would leave content malformed enough to fall back to flattened plain text
     */
    public function testLineBreakBetweenParagraphsIsAbsorbed(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewerComments' => ['<p>First.</p><br><p>Second.</p>']])]),
        ]);

        $paragraphs = $this->query($doc, '//sub-article/body/p');
        self::assertCount(2, $paragraphs);
        self::assertEquals('First.', $paragraphs->item(0)->textContent);
        self::assertEquals('Second.', $paragraphs->item(1)->textContent);
    }

    /**
     * Reach the plain-text reduction create() applies to the stored HTML title
     */
    private static function reduceToPlainText(string $html): string
    {
        return (new class () extends PeerReview {
            public static function reduce(string $html): string
            {
                return self::toPlainText($html);
            }
        })::reduce($html);
    }

    /**
     * The title stored for a publication is HTML, so create() reduces it to the characters
     * behind it before the sub-article titles are built from it
     */
    public function testStoredHtmlTitleIsReducedToItsCharacters(): void
    {
        self::assertEquals(
            'Connectivity in the & Aquifer between Springs > < and Barton Springs',
            self::reduceToPlainText('Connectivity in the &amp; Aquifer between Springs &gt; &lt; and Barton Springs')
        );
    }

    /**
     * Titles, questions and content are stored as HTML, so their special characters arrive
     * already escaped and must not be escaped a second time on the way into the XML
     */
    public function testHtmlSourcedTextIsNotDoubleEscaped(): void
    {
        $peerReview = new PeerReview();
        $subArticles = $peerReview->createSubArticles(
            [
                $this->createRound([
                    $this->createReview([
                        'reviewForm' => [
                            'id' => 1,
                            'title' => 'Review form',
                            'description' => 'A review form',
                            'questions' => [
                                [
                                    'question' => '<p>Are Smith &amp; Jones&#39; &lt;methods&gt; sound?</p>',
                                    'responses' => ['<p>Yes, Smith &amp; Jones are &lt;rigorous&gt;.</p>'],
                                ],
                            ],
                        ],
                    ]),
                ]),
            ],
            // create() reduces the stored HTML title to plain text before this point
            self::reduceToPlainText('Connectivity in the &amp; Aquifer between Springs &gt; &lt; and Barton Springs')
        );

        $doc = new DOMDocument('1.0', 'UTF-8');
        $article = $doc->appendChild($doc->createElement('article'));
        foreach ($subArticles as $subArticle) {
            $article->appendChild($doc->importNode($subArticle, true));
        }

        // The characters survive as themselves, escaped exactly once in the serialized XML
        self::assertStringContainsString(
            'Connectivity in the & Aquifer between Springs > < and Barton Springs',
            $this->query($doc, '//sub-article/front-stub/title-group/article-title')->item(0)->textContent
        );
        self::assertStringNotContainsString('&amp;amp;', $doc->saveXML());
        self::assertStringNotContainsString('&amp;lt;', $doc->saveXML());

        self::assertEquals(
            "Are Smith & Jones' <methods> sound?",
            $this->query($doc, '//sub-article/body/sec/title')->item(0)->textContent
        );
        self::assertEquals(
            'Yes, Smith & Jones are <rigorous>.',
            $this->query($doc, '//sub-article/body/sec/p')->item(0)->textContent
        );
    }

    public function testReviewWithoutContentOmitsBody(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewForm' => null, 'reviewerComments' => []])]),
        ]);

        self::assertCount(1, $this->query($doc, '//sub-article/front-stub'));
        self::assertCount(0, $this->query($doc, '//sub-article/body'));
    }

    /**
     * @return array<string, array{?int, ?string}>
     */
    public static function recommendationProvider(): array
    {
        return [
            'approved' => [ReviewerRecommendationType::APPROVED->value, 'accept'],
            'not approved' => [ReviewerRecommendationType::NOT_APPROVED->value, 'reject'],
            'revisions requested' => [ReviewerRecommendationType::REVISIONS_REQUESTED->value, 'revision'],
            // No JATS4R equivalent, so no recommendation is recorded
            'with comments' => [ReviewerRecommendationType::WITH_COMMENTS->value, null],
            // A recommendation a journal defined itself may carry no machine-readable type
            'no type' => [null, null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recommendationProvider')]
    public function testRecommendationIsMappedToJats4r(?int $typeId, ?string $expected): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['reviewerRecommendationTypeId' => $typeId])]),
        ]);

        $metaValues = $this->query(
            $doc,
            '//sub-article/front-stub/custom-meta-group/custom-meta[meta-name="peer-review-recommendation"]/meta-value'
        );

        if ($expected === null) {
            self::assertCount(0, $metaValues);
            return;
        }

        self::assertCount(1, $metaValues);
        self::assertEquals($expected, $metaValues->item(0)->textContent);
    }

    public function testPeerReviewTypeIsMappedToJats4r(): void
    {
        $doc = $this->buildDocument([$this->createRound([$this->createReview()])]);

        $metaValues = $this->query(
            $doc,
            '//sub-article/front-stub/custom-meta-group/custom-meta[meta-name="PeerReviewType"]/meta-value'
        );

        self::assertCount(1, $metaValues);
        self::assertEquals('all-identities-visible', $metaValues->item(0)->textContent);
    }

    /**
     * A review method with no JATS4R equivalent leaves out the type without affecting
     * the rest of the custom metadata
     */
    public function testUnmappedReviewMethodOmitsPeerReviewType(): void
    {
        $doc = $this->buildDocument([$this->createRound([$this->createReview(['reviewMethod' => null])])]);

        self::assertCount(0, $this->query($doc, '//custom-meta[meta-name="PeerReviewType"]'));
        self::assertCount(1, $this->query($doc, '//custom-meta[meta-name="peer-review-recommendation"]'));
    }

    public function testRevisionRoundFollowsTheRoundNumber(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview(['id' => 1])], '1.0', 1),
            $this->createRound([$this->createReview(['id' => 2])], '1.1', 2, $this->createAuthorResponse()),
        ]);

        $path = '/custom-meta-group/custom-meta[meta-name="peer-review-revision-round"]/meta-value';
        $reports = $this->query($doc, '//sub-article[@article-type="reviewer-report"]/front-stub' . $path);

        self::assertCount(2, $reports);
        self::assertEquals('1', $reports->item(0)->textContent);
        self::assertEquals('2', $reports->item(1)->textContent);

        // The response is tagged with the round it replies to, not the first round
        $responses = $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub' . $path);
        self::assertCount(1, $responses);
        self::assertEquals('2', $responses->item(0)->textContent);
    }

    /**
     * The response describes neither the review type nor a recommendation
     */
    public function testAuthorResponseCarriesOnlyTheRevisionRound(): void
    {
        $doc = $this->buildDocument([
            $this->createRound([$this->createReview()], '1.0', 1, $this->createAuthorResponse()),
        ]);

        $metaNames = $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub/custom-meta-group/custom-meta/meta-name');

        $names = [];
        foreach ($metaNames as $metaName) {
            $names[] = $metaName->textContent;
        }
        self::assertEquals(['peer-review-revision-round'], $names);
    }

    public function testNoRoundsProduceNoSubArticles(): void
    {
        $doc = $this->buildDocument([]);
        self::assertCount(0, $this->query($doc, '//sub-article'));
    }

    /**
     * Test that sub-articles are valid against the JATS 1.2 DTD.
     */
    public function testValidatesAgainstJats12(): void
    {
        $doc = $this->buildDocument(
            [
                $this->createRound([
                    $this->createReview([
                        'id' => 1,
                        'doi' => '10.1234/review.1',
                        'reviewerFullName' => 'Reviewer Name',
                        'reviewerOrcid' => 'https://orcid.org/0000-0002-1825-0097',
                        'reviewerHasVerifiedOrcid' => true,
                        'reviewerComments' => ['<p>A <b>thorough</b> review comment.</p>'],
                        'competingInterestsDeclared' => true,
                        'competingInterests' => '<p>I co-authored a paper with the author.</p>',
                    ]),
                    $this->createReview([
                        'id' => 2,
                        'reviewerRecommendationTypeId' => ReviewerRecommendationType::REVISIONS_REQUESTED->value,
                        'reviewForm' => [
                            'id' => 1,
                            'title' => 'Review form',
                            'description' => 'A review form',
                            'questions' => [
                                ['question' => 'Is the methodology sound?', 'responses' => ['Yes.']],
                            ],
                        ],
                    ]),
                ], '1.0', 1, $this->createAuthorResponse([
                    'response' => ['en' => '<p>We thank the reviewers and have <b>revised</b> the manuscript.</p>'],
                ]), '10.5555/article.v1'),
            ]
        );

        // The reviewed-article link (one per report) and the reply-to-report link both validate in place
        self::assertCount(2, $this->query($doc, '//sub-article[@article-type="reviewer-report"]/front-stub/related-object[@document-type="peer-reviewed-article"]'));
        self::assertCount(1, $this->query($doc, '//sub-article[@article-type="author-comment"]/front-stub/related-object[@document-type="reviewer-report"]'));
        $this->assertXmlValidatesAgainstJats12($doc);
    }
}
