<?php

/**
 * @file ArticleTest.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article unit tests
 */

namespace functional;

use APP\core\Application;
use APP\author\Author;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\author\Repository as AuthorRepository;
use PKP\doi\Doi;
use PKP\galley\Collector as GalleyCollector;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;
use PKP\tests\PKPTestCase;

class ArticleTest extends PKPTestCase
{
    use \APP\plugins\generic\jatsTemplate\tests\functional\UsesRequestMock;

    private string $xmlFilePath = 'plugins/generic/jatsTemplate/tests/data/';
    /**
     * @see PKPTestCase::getMockedDAOs()
     */
    protected function getMockedDAOs(): array
    {
        return [...parent::getMockedDAOs(), 'OAIDAO'];
    }

    /**
     * @see PKPTestCase::getMockedRegistryKeys()
     */
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), 'request'];
    }

    /**
     * @see PKPTestCase::getMockedContainerKeys()
     */
    protected function getMockedContainerKeys(): array
    {
        return [...parent::getMockedContainerKeys(), GalleyCollector::class, AuthorRepository::class];
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
        $author->setAffiliation('author-affiliation', 'en');
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
        $galley = Repo::galley()->newDataObject();
        /** @var Galley|MockObject */
        $galley = $this->getMockBuilder(Galley::class)
            ->onlyMethods(['getFileType', 'getBestGalleyId'])
            ->setProxyTarget($galley)
            ->getMock();
        $galley->expects(self::any())
            ->method('getFileType')
            ->willReturn('galley-filetype');
        $galley->expects(self::any())
            ->method('getBestGalleyId')
            ->willReturn(98);
        $galley->setId(98);
        $galley->setData('submissionFileId',98);
        $galley->setData('doiObject', $galleyDoiObject);

        $galleys = [$galley];

        /** @var Doi|MockObject */
        $galleyDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $galleyDoiObject->setData('doi', 'galley-doi');

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
        $journal->setData('abbreviation', 'publicknowledgeJ Pub Know','en');
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
        $record->setData('journal', $journal);
        $record->setData('section', $section);
        $record->setData('issue', $issue);

        return $record;
    }

    /**
     * @covers \APP\plugins\generic\jatsTemplate\classes\Article::convertOAIToXml
     */
    public function testConvertToXml()
    {
        $request = $this->createRequestMockInstance();
        $record = $this->createOAIRecordMockObject();
        $article = new Article();
        $article->convertOAIToXml($record, $request);
        self::assertXmlStringEqualsXmlFile($this->xmlFilePath.'ie1.xml', $article->saveXml());
    }

    /**
     * @covers \APP\plugins\generic\jatsTemplate\classes\Article::mapHtmlTagsForTitle
     */
    public function testMapHtmlTagsForTitle(){
        $request = $this->createRequestMockInstance();
        $expected = '<bold>test</bold>';
        $htmlString = '<b>test</b>';
        $record = $this->createOAIRecordMockObject();
        $article = new Article();
        $article->convertOAIToXml($record, $request);
        $actual = $article->mapHtmlTagsForTitle($htmlString);
        self::assertEquals($expected, $actual);
    }
}
