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

namespace APP\plugins\generic\jatsTemplate\functional;

use PKP\doi\Doi;
use APP\issue\Issue;
use APP\author\Author;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;
use APP\journal\Journal;
use APP\section\Section;
use PKP\tests\PKPTestCase;
use APP\submission\Submission;
use APP\publication\Publication;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\CoversClass;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\plugins\generic\jatsTemplate\classes\ArticleBack;
use PKP\affiliation\Affiliation;
use PKP\citation\Citation;
use DOMXPath;

#[CoversClass(ArticleBack::class)]
class ArticleBackTest extends PKPTestCase
{
    private string $xmlFilePath = 'plugins/generic/jatsTemplate/tests/data/';

    /**
     * create article mock instance
     * @throws \DOMException
     */
    private function createArticleMockInstance(OAIRecord $record)
    {
        $articleMock = $this->getMockBuilder(Article::class)
            ->setConstructorArgs([$record])
            ->onlyMethods([])
            ->getMock();

        return $articleMock;
    }

    /**
     * create mock OAIRecord object
     * @return OAIRecord
     */
    private function createOAIRecordMockObject(): OAIRecord
    {
        //create test data
        $journalId = 1;

        // Author
        $author = new Author();
        $author->setGivenName('author-firstname', 'en');
        $author->setFamilyName('author-lastname', 'en');
        $affiliation = new Affiliation();
        $affiliation->setName('author-affiliation', 'en');
        $affiliation->setAuthorId(1);
        $author->setAffiliations([$affiliation]);
        $author->setEmail('someone@example.com');

        // Publication
        /** @var Doi|MockObject */
        $publicationDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $publicationDoiObject->setData('doi', 'article-doi');

        /** @var Publication|MockObject */
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
        $publication->setData('coverage', ['en' => ['article-coverage-geo', 'article-coverage-chron', 'article-coverage-sample']]);
        $publication->setData('abstract', 'article-abstract', 'en');
        $publication->setData('sponsor', 'article-sponsor', 'en');
        $publication->setData('doiObject', $publicationDoiObject);
        $publication->setData('languages', ['en' => ['en']]);
        $publication->setData('copyrightHolder', 'article-copyright');
        $publication->setData('copyrightYear', 'year');
        $publication->setData('authors', collect([$author]));

        /** @var Doi|MockObject */
        $galleyDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $galleyDoiObject->setData('doi', 'galley-doi');

        // Galleys
        /** @var Galley|MockObject */
        $galley = $this->getMockBuilder(Galley::class)
            ->onlyMethods(['getFileType', 'getBestGalleyId'])
            ->getMock();
        $galley->expects(self::any())
            ->method('getFileType')
            ->willReturn('galley-filetype');
        $galley->expects(self::any())
            ->method('getBestGalleyId')
            ->willReturn(98);
        $galley->setId(98);
        $galley->setData('submissionFileId', 98);
        $galley->setData('doiObject', $galleyDoiObject);

        $galleys = collect([$galley]);

        // Article
        /** @var Submission|MockObject */
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
        /** @var Journal|MockObject */
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

        /** @var Doi|MockObject */
        $issueDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $issueDoiObject->setData('doi', 'issue-doi');

        // Issue
        /** @var Issue|MockObject */
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
     * @throws \DOMException
     */
    public function testCreate()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article');
        $publication = $submission->getCurrentPublication();

        $articleBackElement = new ArticleBack();
        self::assertNull($articleBackElement->create($publication));
    }

    /**
     * No back element is created when every data availability statement is blank once
     * converted and there are no citations
     */
    public function testNoBackElementWhenAllStatementsAreBlank(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', [
            'en' => '<p>&nbsp;</p>',
            'fr' => '<p><br></p>',
        ]);

        $articleBack = new ArticleBack();
        self::assertNull($articleBack->create($publication));
        self::assertNull($articleBack->documentElement);
    }

    /**
     * A data availability statement alone produces one section per non-empty locale and
     * no empty reference list
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
        $articleBack->create($publication);

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
     * A statement that passes the empty-text check but holds no block content (e.g. only a
     * non-breaking space) is skipped rather than failing the export
     */
    public function testDataAvailabilityWithOnlyBlankBlockContentIsSkipped(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', [
            'en' => '<p>Data are available on request.</p>',
            'fr' => '<p>&nbsp;</p>',
            'de' => '<p><br></p>',
        ]);

        $articleBack = new ArticleBack();
        $articleBack->create($publication);

        $xpath = new DOMXPath($articleBack);
        $sections = $xpath->query('/back/sec[@sec-type="data-availability"]');
        self::assertCount(1, $sections);
        self::assertEquals('en', $sections->item(0)->getAttribute('xml:lang'));
    }

    /**
     * The data availability section precedes the reference list, and plain-text and
     * multi-paragraph statements are carried as paragraphs
     */
    public function testDataAvailabilityPrecedesReferenceList(): void
    {
        $publication = $this->createOAIRecordMockObject()->getData('article')->getCurrentPublication();
        $publication->setData('dataAvailability', [
            'en' => '<p>Data are available at <a href="https://example.com/data">the repository</a>.</p><p>Code is on request.</p>',
            'de' => 'Daten sind verfügbar.',
        ]);
        $citation = new Citation();
        $citation->setRawCitation('Author, A. (2020). A cited work.');
        $publication->setData('citations', collect([$citation]));

        $articleBack = new ArticleBack();
        $articleBack->create($publication);

        $children = [];
        foreach ($articleBack->documentElement->childNodes as $child) {
            $children[] = $child->nodeName;
        }
        self::assertEquals(['sec', 'sec', 'ref-list'], $children);

        $xpath = new DOMXPath($articleBack);
        self::assertCount(2, $xpath->query('/back/sec[@xml:lang="en"]/p'));
        self::assertCount(1, $xpath->query('/back/sec[@xml:lang="de"]/p'));
    }
}
