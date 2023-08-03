<?php

/**
 * @file ArticleFrontTest.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article front element unit tests
 */

namespace functional;

use APP\author\Author;
use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\plugins\generic\jatsTemplate\classes\ArticleFront;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\core\PKPRouter;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;

class ArticleFrontTest extends \PKP\tests\PKPTestCase
{

    private string $xmlFilePath = 'plugins/generic/jatsTemplate/tests/data/';
    /**
     * @see PKPTestCase::getMockedRegistryKeys()
     */
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), 'request'];
    }

    /**
     * create article mock instance
     * @throws \DOMException
     */
    private function createArticleMockInstance(OAIRecord $record)
    {
        $article = $this->getMockBuilder(Article::class)
            ->setConstructorArgs([$record])
            ->onlyMethods([])
            ->getMock();

        return $article;
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

        // Article
        /** @var Submission|MockObject */
        $article = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getBestId', 'getCurrentPublication'])
            ->getMock();
        $article->expects($this->any())
            ->method('getBestId')
            ->will($this->returnValue(9));
        $article->setId(9);
        $article->setData('contextId', $journalId);
        $author->setSubmissionId($article->getId());
        $article->expects($this->any())
            ->method('getCurrentPublication')
            ->will($this->returnValue($publication));

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
            ->will($this->returnValue('galley-filetype'));
        $galley->expects(self::any())
            ->method('getBestGalleyId')
            ->will($this->returnValue(98));
        $galley->setId(98);
        $galley->setData('doiObject', $galleyDoiObject);

        $galleys = [$galley];

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
        $journal->setName('journal-title', 'gr');
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
            ->will($this->returnValue('issue-identification'));
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

    /*
     * create mock request
     */
    private function createRequestMockInstance(){
        // Router
        /** @var PKPRouter|MockObject */
        $router = $this->getMockBuilder(PKPRouter::class)
            ->onlyMethods(['url','handleAuthorizationFailure','route'])
            ->getMock();
        $application = Application::get();
        $router->setApplication($application);
        $router->expects($this->any())
            ->method('url')
            ->will($this->returnCallback(fn ($request, $newContext = null, $handler = null, $op = null, $path = null) => $handler . '-' . $op . '-' . $path));

        // Request
        $requestMock = $this->getMockBuilder(Request::class)
            ->onlyMethods(['getRouter'])
            ->getMock();
        $requestMock->expects($this->any())
            ->method('getRouter')
            ->will($this->returnValue($router));

        return $requestMock;
    }

    /**
     * testing create front element
     * @throws \DOMException
     */
    public function testCreate(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article');
        $journal =& $record->getData('journal');
        $section =& $record->getData('section');
        $issue =& $record->getData('issue');
        $article = $this->createArticleMockInstance($record);

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->create(
            $journal,
            $submission,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $article,
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'frontElement.xml',
            $articleFrontElement->saveXML($xml));
    }

    /**
     * testing create journal-meta element
     * @throws \DOMException
     */
    public function testCreateJournalMeta(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $journal =& $record->getData('journal');

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createJournalMeta(
            $journal
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'journalMetaElement.xml',
            $articleFrontElement->saveXML($xml));
    }

    /**
     * testing create article-meta element
     * @throws \DOMException
     */
    public function testCreateArticleMeta(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article');
        $journal =& $record->getData('journal');
        $section =& $record->getData('section');
        $issue =& $record->getData('issue');
        $article = $this->createArticleMockInstance($record);

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $article,
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'articleMetaElement.xml',
            $articleFrontElement->saveXML($xml)
        );
    }

    /**
     * testing create journal-meta journal-title-group element
     * @throws \DOMException
     */
    public function testCreateJournalMetaJournalTitleGroup(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $journal =& $record->getData('journal');

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createJournalMetaJournalTitleGroup(
            $journal
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'journalMeta_JournalTitleGroupElement.xml',
            $articleFrontElement->saveXML($xml)
        );
    }

    /**
     * testing create article-meta article-categories element
     * @throws \DOMException
     */
    public function testCreateArticleCategories(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $journal =& $record->getData('journal');
        $section =& $record->getData('section');

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleCategories(
            $journal,
            $section
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'articleMetaArticle_CategoriesElement.xml',
            $articleFrontElement->saveXML($xml)
        );
    }

    /**
     * testing create article-meta contrib-group element
     * @throws \DOMException
     */
    public function testCreateArticleContribGroup(){
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article');

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleContribGroup(
            $submission
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath.'articleMetaArticle_ContribGroupElement.xml',
            $articleFrontElement->saveXML($xml['contribGroupElement'])
        );
    }

}
