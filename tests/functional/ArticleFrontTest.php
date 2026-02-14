<?php

/**
 * @file ArticleFrontTest.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article front element unit tests.
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use APP\author\Author;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\plugins\generic\jatsTemplate\classes\ArticleFront;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\affiliation\Affiliation;
use PKP\author\contributorRole\ContributorRole;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;

#[CoversClass(ArticleFront::class)]
class ArticleFrontTest extends \PKP\tests\PKPTestCase
{
    use UsesRequestMock;

    private string $xmlFilePath = 'plugins/generic/jatsTemplate/tests/data/';
    /**
     * @see PKPTestCase::getMockedRegistryKeys()
     */
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), 'request'];
    }

    /**
     * Create article mock instance.
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
     * Create mock OAIRecord object.
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
        $author->setBiography("<p>Test biography</p>", 'en');

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
        $publication->setData('coverage', ['en' => ['article-coverage-geo', 'article-coverage-chron', 'article-coverage-sample']]);
        $publication->setData('keywords', ['en' => [['name' => 'Professional Development'],['name' => 'Social Transformation']]]);
        $publication->setData('abstract', 'article-abstract', 'en');
        $publication->setData('sponsor', 'article-sponsor', 'en');
        $publication->setData('doiObject', $publicationDoiObject);
        $publication->setData('languages', ['en' => ['en']]);
        $publication->setData('copyrightHolder', 'article-copyright');
        $publication->setData('copyrightYear', 'year');
        $publication->setData('authors', collect([$author]));

        // Article
        /** @var Submission|MockObject $article */
        $article = $this->getMockBuilder(Submission::class)
            ->onlyMethods(['getBestId', 'getCurrentPublication'])
            ->getMock();
        $article->expects($this->any())
            ->method('getBestId')
            ->willReturn(9);
        $article->setId(9);
        $article->setData('contextId', $journalId);
        $article->setData('locale', 'en');
        $author->setSubmissionId($article->getId());
        $article->expects($this->any())
            ->method('getCurrentPublication')
            ->willReturn($publication);

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

        // Mock SubmissionFile Repository to provide mimetype
        $submissionFileMock = \Mockery::mock(\PKP\submissionFile\SubmissionFile::class);
        $submissionFileMock->shouldReceive('getData')
            ->andReturnUsing(function ($key) {
                return match ($key) {
                    'mimetype' => 'galley-filetype',
                    'fileId' => 1,
                    default => null
                };
            });

        // Mock Collector for method chaining
        $collectorMock = \Mockery::mock(\PKP\submissionFile\Collector::class);
        $collectorMock->shouldReceive('filterBySubmissionIds')->andReturnSelf();
        $collectorMock->shouldReceive('filterByFileStages')->andReturnSelf();
        $collectorMock->shouldReceive('getMany')->andReturn(\Illuminate\Support\LazyCollection::make([]));

        $submissionFileRepoMock = \Mockery::mock(\APP\submissionFile\Repository::class);
        $submissionFileRepoMock->shouldReceive('get')
            ->with(98)
            ->andReturn($submissionFileMock);
        $submissionFileRepoMock->shouldReceive('getCollector')
            ->andReturn($collectorMock);

        app()->instance(\APP\submissionFile\Repository::class, $submissionFileRepoMock);

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
        $journal->setName('journal-title', 'gr');
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
     * Test creating ArticleFront element.
     */
    public function testCreate()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article'); /** @var Submission $submission */
        $journal =& $record->getData('journal'); /** @var Journal $journal */
        $section =& $record->getData('section'); /** @var Section $section */
        $issue =& $record->getData('issue'); /** @var Issue $issue */
        $article = $this->createArticleMockInstance($record);
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->create(
            $journal,
            $submission,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $article,
            $publication
        );
        $xml->ownerDocument->formatOutput = true;
        self::assertEquals(
            trim(file_get_contents($this->xmlFilePath . 'frontElement.xml')),
            trim($articleFrontElement->saveXML($xml))
        );
    }

    /**
     * Test creating journal-meta element.
     */
    public function testCreateJournalMeta()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $journal =& $record->getData('journal'); /** @var Journal $journal */

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createJournalMeta(
            $journal,
            $this->createRequestMockInstance(),
        );
        $xml->ownerDocument->formatOutput = true;
        self::assertEquals(
            trim(file_get_contents($this->xmlFilePath . 'journalMetaElement.xml')),
            trim($articleFrontElement->saveXML($xml))
        );
    }

    /**
     * Test creating article-meta element.
     */
    public function testCreateArticleMeta()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article'); /** @var Submission $submission */
        $journal =& $record->getData('journal'); /** @var Journal $journal */
        $section =& $record->getData('section'); /** @var Section $section */
        $issue =& $record->getData('issue'); /** @var Issue $issue */
        $article = $this->createArticleMockInstance($record);
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $article,
            $publication
        );
        $xml->ownerDocument->formatOutput = true;
        self::assertEquals(
            trim(file_get_contents($this->xmlFilePath . 'articleMetaElement.xml')),
            trim($articleFrontElement->saveXML($xml))
        );
    }

    /**
     * Test creating journal-meta journal-title-group element.
     */
    public function testCreateJournalMetaJournalTitleGroup()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $journal =& $record->getData('journal'); /** @var Journal $journal */

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createJournalMetaJournalTitleGroup(
            $journal
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath . 'journalMeta_JournalTitleGroupElement.xml',
            $articleFrontElement->saveXML($xml)
        );
    }

    /**
     * Test creating article-meta contrib-group element.
     */
    public function testCreateArticleContribGroup()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record =& $OAIRecord;
        $submission =& $record->getData('article'); /** @var Submission $submission */

        $this->createRequestMockInstance();

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleContribGroup(
            $submission,
            $submission->getCurrentPublication()
        );
        self::assertXmlStringEqualsXmlFile(
            $this->xmlFilePath . 'articleMetaArticle_ContribGroupElement.xml',
            $articleFrontElement->saveXML($xml['contribGroupElement'])
        );
    }
}
