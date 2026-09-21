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
        $citation->setData('rawCitation', 'Author, A. (2020). A cited work.');
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
        $this->assertXmlValidatesAgainstJats12($doc);
    }
}
