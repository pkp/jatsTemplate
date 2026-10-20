<?php

/**
 * @file ArticleBackTest.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article back element unit tests
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use APP\author\Author;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\plugins\generic\jatsTemplate\classes\ArticleBack;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\affiliation\Affiliation;
use PKP\author\contributorRole\ContributorRole;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\citation\Citation;
use PKP\dataCitation\DataCitation;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;
use PKP\tests\PKPTestCase;

#[CoversClass(ArticleBack::class)]
class ArticleBackTest extends PKPTestCase
{
    use ValidatesAgainstJats;

    private string $xmlFilePath = 'plugins/generic/jatsTemplate/tests/data/';

    /**
     * create article mock instance
     */
    private function createArticleMockInstance(OAIRecord $record)
    {
        return $this->getMockBuilder(Article::class)
            ->setConstructorArgs([$record])
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * create mock OAIRecord object
     */
    private function createOAIRecordMockObject(): OAIRecord
    {
        //create test data
        $journalId = 1;

        // Author
        $author = new Author();
        $author->setGivenName('author-firstname', 'en');
        $author->setFamilyName('author-lastname', 'en');
        $author->setPreferredPublicName('author-preferred-name', 'en');
        $author->setData('contributorType', ContributorType::PERSON->getName());
        $contributorRoleAuthor = new ContributorRole();
        $contributorRoleAuthor->fill([
            'contributor_role_id' => 1,
            'context_id' => $journalId,
            'contributor_role_identifier' => ContributorRoleIdentifier::AUTHOR->getName(),
            'name' => ['en' => 'Author'],
        ]);
        $author->setContributorRoles([$contributorRoleAuthor]);
        $affiliation = new Affiliation();
        $affiliation->setName('author-affiliation', 'en');
        $affiliation->setAuthorId(1);
        $affiliation->setRor('https://ror.org/05ek4tb53');
        $author->setAffiliations([$affiliation]);
        $author->setEmail('someone@example.com');
        $author->setUrl('https://example.com');
        $author->setBiography('<p>Test biography</p>', 'en');
        $author->setCompetingInterests('<p>Competing interests</p>', 'en');

        // Publication
        /** @var Doi|MockObject $publicationDoiObject */
        $publicationDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $publicationDoiObject->setData('doi', 'article-doi');

        /** @var Publication|MockObject $publication */
        $publication = $this->getMockBuilder(Publication::class)
            ->onlyMethods([])
            ->getMock();
        $publication->setData('id', 1);
        $publication->setData('issueId', 96);
        $publication->setData('locale', 'en');
        $publication->setData('pages', 15);
        $publication->setData('type', 'art-type', 'en');
        $publication->setData('title', 'article-title-en', 'en');
        $publication->setData('title', 'article-title-de', 'de');
        $publication->setData('subtitle', 'article-subtitle-en', 'en');
        $publication->setData('subtitle', 'article-subtitle-de', 'de');
        $publication->setData('coverage', ['en' => ['article-coverage-geo', 'article-coverage-chron', 'article-coverage-sample']]);
        $publication->setData('keywords', ['en' => [['name' => 'Professional Development'],['name' => 'Social Transformation']]]);
        $publication->setData('abstract', 'article-abstract', 'en');
        $publication->setData('abstract', 'article-abstract-de', 'de');
        $publication->setData('plainLanguageSummary', 'article-plain-language-summary-en', 'en');
        $publication->setData('plainLanguageSummary', 'article-plain-language-summary-de', 'de');
        $publication->setData('sponsor', 'article-sponsor', 'en');
        $publication->setData('doiObject', $publicationDoiObject);
        $publication->setData('languages', ['en' => ['en']]);
        $publication->setData('copyrightHolder', 'article-copyright');
        $publication->setData('copyrightYear', 'year');
        $publication->setData('authors', collect([$author]));
        $publication->setData('dataCitations', collect());
        $publication->setData('citations', collect());

        /** @var Doi|MockObject $galleyDoiObject */
        $galleyDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $galleyDoiObject->setData('doi', 'galley-doi');

        // Galleys
        /** @var Galley|MockObject $galley */
        $galley = $this->getMockBuilder(Galley::class)
            ->onlyMethods(['getBestGalleyId'])
            ->getMock();
        $galley->expects(self::any())
            ->method('getBestGalleyId')
            ->willReturn(98);
        $galley->setId(98);
        $galley->setData('submissionFileId', 98);
        $galley->setData('doiObject', $galleyDoiObject);

        $galleys = collect([$galley]);

        // Article
        /** @var Submission|MockObject $article */
        $article = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getBestId', 'getCurrentPublication','getGalleys'])
            ->getMock();
        $article->expects($this->any())
            ->method('getBestId')
            ->willReturn(9);
        $article->expects($this->any())
            ->method('getGalleys')
            ->willReturn($galleys);
        $article->setId(9);
        $article->setData('contextId', $journalId);
        $article->setData('locale', 'en');
        $author->setSubmissionId($article->getId());
        $article->expects($this->any())
            ->method('getCurrentPublication')
            ->willReturn($publication);

        // Journal
        /** @var Journal|MockObject $journal */
        $journal = $this->getMockBuilder(Journal::class)
            ->onlyMethods(['getSetting'])
            ->getMock();
        $journal->expects($this->any())
            ->method('getSetting')
            ->willReturnMap([
                ['publisherInstitution', null, 'journal-publisher'],
                ['onlineIssn', null, 'onlineIssn'],
                ['printIssn', null, 'printIssn'],
            ]);
        $journal->setName('journal-title', 'en');
        $journal->setPrimaryLocale('en');
        $journal->setPath('journal-path');
        $journal->setData(Journal::SETTING_ENABLE_DOIS, true);
        $journal->setData('abbreviation', 'publicknowledgeJ Pub Know', 'en');
        $journal->setData('publisherInstitution', 'journal-publisher');
        $journal->setData('onlineIssn', 'onlineIssn');
        $journal->setData('printIssn', 'printIssn');
        $journal->setId($journalId);

        // Section
        $section = new Section();
        $section->setIdentifyType('section-identify-type', 'en');
        $section->setTitle('section-identify-type', 'en');

        /** @var Doi|MockObject $issueDoiObject */
        $issueDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $issueDoiObject->setData('doi', 'issue-doi');

        // Issue
        /** @var Issue|MockObject $issue */
        $issue = $this->getMockBuilder(Issue::class)
            ->onlyMethods(['getIssueIdentification'])
            ->getMock();
        $issue->expects($this->any())
            ->method('getIssueIdentification')
            ->willReturn('issue-identification');
        $issue->setId(96);
        $issue->setDatePublished('2010-11-05');
        $issue->setData('doiObject', $issueDoiObject);
        $issue->setJournalId($journalId);

        //
        // Test
        //

        // OAI record
        $record = new OAIRecord();
        $record->setData('article', $article);
        $record->setData('galleys', $galleys);
        $record->setData('journal', $journal);
        $record->setData('section', $section);
        $record->setData('issue', $issue);

        return $record;
    }
    /**
     * test back element if citations table doesn't have records
     *
     * @throws \DOMException
     */
    public function testCreate()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record = & $OAIRecord;
        $submission = & $record->getData('article');
        $publication = $submission->getCurrentPublication();

        $articleBackElement = new ArticleBack();
        self::assertNull($articleBackElement->create($publication));
    }

    /**
     * A data availability statement alone is enough to produce a back element, with one
     * section per locale and no empty reference list
     */
    public function testDataAvailabilityWithoutCitations(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', [
            'en' => '<p>Data are available at <a href="https://example.com/data">https://example.com/data</a>.</p>',
            'de' => '<p>Daten sind <b>verfügbar</b>.</p>',
            'fr' => '<p></p>',
        ]);

        $articleBack = new ArticleBack();
        $backNode = $articleBack->create($publication);
        self::assertNotNull($backNode);

        $xpath = new DOMXPath($articleBack);
        self::assertCount(0, $xpath->query('/back/ref-list'));

        $sections = $xpath->query('/back/sec[@sec-type="data-availability"]');
        self::assertCount(2, $sections);

        $enSection = $sections->item(0);
        self::assertEquals('en', $enSection->getAttribute('xml:lang'));
        self::assertEquals('title', $enSection->firstChild->nodeName);
        self::assertEquals('Data Availability Statement', $enSection->firstChild->textContent);
        self::assertCount(1, $xpath->query('p', $enSection));
        $link = $xpath->query('p/ext-link', $enSection)->item(0);
        self::assertEquals('https://example.com/data', $link->getAttribute('xlink:href'));

        $deSection = $sections->item(1);
        self::assertEquals('de', $deSection->getAttribute('xml:lang'));
        self::assertEquals('verfügbar', $xpath->query('p/bold', $deSection)->item(0)->textContent);
    }

    /**
     * The data availability section precedes the reference list
     */
    public function testDataAvailabilityPrecedesReferenceList(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', ['en' => '<p>Data are available on request.</p>']);
        $citation = new Citation();
        $citation->setData('rawCitation', 'Author, A. (2020). A cited work.');
        $publication->setData('citations', collect([$citation]));

        $articleBack = new ArticleBack();
        $articleBack->create($publication);

        $children = [];
        foreach ($articleBack->documentElement->childNodes as $child) {
            $children[] = $child->nodeName;
        }
        self::assertEquals(['sec', 'ref-list'], $children);
    }

    /**
     * An unstructured citation's persistent identifiers are tagged where they appear in its
     * mixed-citation, leaving the citation text unchanged, and added after the text otherwise
     */
    public function testMixedCitationPubIds(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $plain = new Citation();
        $plain->setData('isStructured', false);
        $plain->setRawCitation('Author, A. (2020). A <i>cited</i> work. https://doi.org/10.1234/Cited. arXiv:2001.12345. urn:nbn:de:101:1-123');
        $plain->setData('doi', '10.1234/cited');
        $plain->setData('arxiv', '2001.12345');
        $plain->setData('urn', 'urn:nbn:de:101:1-123');
        $linked = new Citation();
        $linked->setData('isStructured', false);
        $linked->setRawCitation('Author, B. (2021). Another work. <a href="https://doi.org/10.1234/linked">https://doi.org/10.1234/linked</a>');
        $linked->setData('doi', '10.1234/linked');
        $notInText = new Citation();
        $notInText->setData('isStructured', false);
        $notInText->setRawCitation('Author, C. (2022). A third work. https://example.com/third.');
        $notInText->setData('doi', '10.1234/found');
        $publisherAddress = new Citation();
        $publisherAddress->setData('isStructured', false);
        $publisherAddress->setRawCitation('Author, D. (2009). A publisher page. https://onlinelibrary.wiley.com/doi/full/10.1002/abc.124. doi:10.1002/abc.124');
        $publisherAddress->setData('doi', '10.1002/abc.124');
        $addressOnly = new Citation();
        $addressOnly->setData('isStructured', false);
        $addressOnly->setRawCitation('Author, E. (2010). A journal page. https://example.com/article/view/10.1234/hotel/pdf');
        $addressOnly->setData('doi', '10.1234/hotel');
        $bare = new Citation();
        $bare->setData('isStructured', false);
        $bare->setRawCitation('Author, F. (2015). A bare DOI. 10.1234/golf');
        $bare->setData('doi', '10.1234/golf');
        $publication->setData('citations', collect([$plain, $linked, $notInText, $publisherAddress, $addressOnly, $bare]));

        $articleBack = new ArticleBack();
        $articleBack->create($publication);

        $xpath = new DOMXPath($articleBack);
        $mixedCitations = $xpath->query('/back/ref-list/ref/mixed-citation');

        $plainCitation = $mixedCitations->item(0);
        self::assertEquals(
            'Author, A. (2020). A cited work. https://doi.org/10.1234/Cited. arXiv:2001.12345. urn:nbn:de:101:1-123',
            $plainCitation->textContent
        );
        self::assertEquals('cited', $xpath->query('italic', $plainCitation)->item(0)->textContent);
        $pubIds = $xpath->query('pub-id', $plainCitation);
        self::assertCount(3, $pubIds);
        self::assertEquals('doi', $pubIds->item(0)->getAttribute('pub-id-type'));
        self::assertEquals('10.1234/Cited', $pubIds->item(0)->textContent);
        self::assertEquals('https://doi.org/10.1234/Cited', $pubIds->item(0)->getAttribute('xlink:href'));
        self::assertEquals('arxiv', $pubIds->item(1)->getAttribute('pub-id-type'));
        self::assertEquals('2001.12345', $pubIds->item(1)->textContent);
        self::assertEquals('other', $pubIds->item(2)->getAttribute('pub-id-type'));
        self::assertEquals('urn:nbn:de:101:1-123', $pubIds->item(2)->textContent);

        $linkedCitation = $mixedCitations->item(1);
        self::assertEquals('Author, B. (2021). Another work. https://doi.org/10.1234/linked', $linkedCitation->textContent);
        self::assertCount(0, $xpath->query('ext-link', $linkedCitation));
        $pubId = $xpath->query('pub-id', $linkedCitation)->item(0);
        self::assertEquals('10.1234/linked', $pubId->textContent);
        self::assertEquals('https://doi.org/10.1234/linked', $pubId->getAttribute('xlink:href'));

        // An identifier not in the text is added after it
        $notInTextCitation = $mixedCitations->item(2);
        self::assertEquals('Author, C. (2022). A third work. https://example.com/third. 10.1234/found', $notInTextCitation->textContent);
        self::assertEquals('https://example.com/third', $xpath->query('ext-link', $notInTextCitation)->item(0)->getAttribute('xlink:href'));
        self::assertEquals('pub-id', $notInTextCitation->lastChild->nodeName);
        self::assertEquals('10.1234/found', $notInTextCitation->lastChild->textContent);

        // A DOI inside a publisher's address is not tagged there
        $publisherAddressCitation = $mixedCitations->item(3);
        $extLink = $xpath->query('ext-link', $publisherAddressCitation)->item(0);
        self::assertEquals('https://onlinelibrary.wiley.com/doi/full/10.1002/abc.124', $extLink->getAttribute('xlink:href'));
        self::assertEquals('https://onlinelibrary.wiley.com/doi/full/10.1002/abc.124', $extLink->textContent);
        $pubIds = $xpath->query('pub-id', $publisherAddressCitation);
        self::assertCount(1, $pubIds);
        self::assertFalse($pubIds->item(0)->hasAttribute('xlink:href'));
        self::assertEquals('doi:', substr($pubIds->item(0)->previousSibling->textContent, -4));

        $addressOnlyCitation = $mixedCitations->item(4);
        self::assertEquals(
            'https://example.com/article/view/10.1234/hotel/pdf',
            $xpath->query('ext-link', $addressOnlyCitation)->item(0)->getAttribute('xlink:href')
        );
        self::assertEquals('Author, E. (2010). A journal page. https://example.com/article/view/10.1234/hotel/pdf 10.1234/hotel', $addressOnlyCitation->textContent);
        self::assertEquals('pub-id', $addressOnlyCitation->lastChild->nodeName);

        $bareCitation = $mixedCitations->item(5);
        self::assertEquals('Author, F. (2015). A bare DOI. 10.1234/golf', $bareCitation->textContent);
        self::assertEquals('10.1234/golf', $xpath->query('pub-id', $bareCitation)->item(0)->textContent);
    }

    /**
     * A data citation's identifier type is used as its pub-id-type when JATS 1.2 has one, and
     * "other" otherwise; authors without a name are left out
     */
    public function testDataCitationPubIdTypes(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $pmid = new DataCitation();
        $pmid->setAttribute('title', 'A dataset');
        $pmid->setAttribute('identifierType', 'PMID');
        $pmid->setAttribute('identifier', '12345678');
        $pmid->setAttribute('authors', [['familyName' => 'Brown', 'givenName' => 'Alice']]);
        $uri = new DataCitation();
        $uri->setAttribute('title', 'Another dataset');
        $uri->setAttribute('identifierType', 'URI');
        $uri->setAttribute('identifier', 'https://example.com/dataset');
        $uri->setAttribute('authors', [['familyName' => '', 'givenName' => '']]);
        $publication->setData('dataCitations', collect([$pmid, $uri]));

        $articleBack = new ArticleBack();
        $articleBack->create($publication);

        $xpath = new DOMXPath($articleBack);
        $citations = $xpath->query('/back/ref-list/ref/element-citation');
        self::assertEquals('pmid', $xpath->query('pub-id', $citations->item(0))->item(0)->getAttribute('pub-id-type'));
        self::assertEquals('Brown', $xpath->query('person-group/name/surname', $citations->item(0))->item(0)->textContent);
        self::assertEquals('other', $xpath->query('pub-id', $citations->item(1))->item(0)->getAttribute('pub-id-type'));
        self::assertCount(0, $xpath->query('person-group', $citations->item(1)));
    }

    /**
     * Test that the back element is valid against the JATS 1.2 DTD
     */
    public function testValidatesAgainstJats12(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', [
            'en' => '<p>Data are available at <a href="https://example.com/data">the repository</a>.</p><p>Code is on request.</p>',
            'de' => 'Daten sind verfügbar.',
        ]);
        $citation = new Citation();
        $citation->setData('rawCitation', 'Author, A. (2020). A cited work. <a href="https://doi.org/10.1234/cited">https://doi.org/10.1234/cited</a>. urn:nbn:de:101:1-123');
        $citation->setData('doi', '10.1234/cited');
        $citation->setData('urn', 'urn:nbn:de:101:1-123');
        $publication->setData('citations', collect([$citation]));

        $articleBack = new ArticleBack();
        $backNode = $articleBack->create($publication);

        // Wrap the back element in a minimal valid article
        $doc = new DOMDocument();
        $doc->loadXML(
            '<article xmlns:xlink="http://www.w3.org/1999/xlink" dtd-version="1.2">'
            . '<front><journal-meta><journal-id>j</journal-id><issn>0000-0000</issn></journal-meta>'
            . '<article-meta><title-group><article-title>Title</article-title></title-group></article-meta></front>'
            . '</article>'
        );
        $doc->documentElement->appendChild($doc->importNode($backNode, true));

        $xpath = new DOMXPath($doc);
        self::assertCount(2, $xpath->query('/article/back/sec[@sec-type="data-availability"]'));
        self::assertCount(2, $xpath->query('/article/back/sec[@xml:lang="en"]/p'));
        self::assertCount(1, $xpath->query('/article/back/sec[@xml:lang="de"]/p'));
        self::assertCount(1, $xpath->query('/article/back/ref-list/ref/mixed-citation/pub-id[@pub-id-type="doi"]'));
        self::assertCount(1, $xpath->query('/article/back/ref-list/ref/mixed-citation/pub-id[@pub-id-type="other"]'));
        $this->assertXmlValidatesAgainstJats12($doc);
    }
}
