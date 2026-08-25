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
use APP\plugins\generic\jatsTemplate\classes\ArticleFront;
use APP\publication\Publication;
use APP\publication\Repository;
use APP\section\Section;
use APP\submission\Submission;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PKP\affiliation\Affiliation;
use PKP\author\contributorRole\ContributorRole;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\doi\Doi;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;
use PKP\publication\enums\UpdateType;
use PKP\publication\enums\VersionRelationType;
use PKP\submissionFile\SubmissionFile;

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
     * @see PKPTestCase::getMockedContainerKeys()
     */
    protected function getMockedContainerKeys(): array
    {
        return [
            ...parent::getMockedContainerKeys(),
            \APP\submissionFile\Repository::class,
            Repository::class,
        ];
    }

    /**
     * Stub the publication repository to report a single preceding published
     * version, for tests whose fixtures expect a deterministic version-linking
     * related-article element rather than whatever the real (unmocked)
     * getVersionRelation() would compute from the shared mock submission data.
     */
    private function stubPreviousVersionRelation(): void
    {
        $versionRelation = (object) [
            'publicationId' => 5,
            'versionStage' => 'VoR',
            'versionString' => '',
            'doi' => '10.1234/previous',
            'doiUrl' => 'https://doi.org/10.1234/previous',
            'datePublished' => '2010-01-01',
            'relationType' => VersionRelationType::IS_NEW_VERSION_OF,
            'updateType' => UpdateType::NEW_VERSION,
        ];
        $publicationRepoMock = Mockery::mock(Repository::class);
        $publicationRepoMock->shouldReceive('getVersionRelation')
            ->andReturn($versionRelation);
        app()->instance(Repository::class, $publicationRepoMock);
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
        $author->setCompetingInterests("<p>Competing interests</p>", 'en');
        $author->setCountry('GB');

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
        $publication->setData('title', 'article-title-en with <b>bold</b> &amp; special chars', 'en');
        $publication->setData('title', 'article-title-de with <i>italic</i>', 'de');
        $publication->setData('subtitle', 'article-subtitle-en with <i>italic</i>', 'en');
        $publication->setData('subtitle', 'article-subtitle-de with <u>underline</u>', 'de');
        $publication->setData('coverage', ['en' => ['article-coverage-geo', 'article-coverage-chron', 'article-coverage-sample']]);
        $publication->setData('keywords', ['en' => [['name' => 'Professional Development'],['name' => 'Social Transformation']]]);
        $publication->setData('abstract', 'article-abstract', 'en');
        $publication->setData('abstract', 'article-abstract-de', 'de');
        $publication->setData('plainLanguageSummary', 'article-plain-language-summary-en', 'en');
        $publication->setData('plainLanguageSummary', 'article-plain-language-summary-de', 'de');
        $publication->setData('sponsor', 'article-sponsor', 'en');
        $publication->setData('doiObject', $publicationDoiObject);
        $publication->setData('versionStage', 'VoR');
        $publication->setData('versionMajor', 1);
        $publication->setData('versionMinor', 0);
        $publication->setData('languages', ['en' => ['en']]);
        $publication->setData('copyrightHolder', 'article-copyright');
        $publication->setData('copyrightYear', 'year');
        $publication->setData('licenseUrl', 'https://creativecommons.org/licenses/by/4.0');
        $publication->setData('authors', collect([$author]));
        $publication->setData('status', Submission::STATUS_PUBLISHED);
        $publication->setData('updateType', 'new_version');
        $publication->setData('summaryOfChanges', '<p>This version corrects an error in Table 2.</p>', 'en');

        // Previous published version, for the related-article back-link
        /** @var Doi|MockObject $previousDoiObject */
        $previousDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $previousDoiObject->setData('doi', '10.1234/previous');

        /** @var Publication|MockObject $previousPublication */
        $previousPublication = $this->getMockBuilder(Publication::class)
            ->onlyMethods([])
            ->getMock();
        $previousPublication->setData('id', 2);
        $previousPublication->setData('status', Submission::STATUS_PUBLISHED);
        $previousPublication->setData('doiObject', $previousDoiObject);

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
        $article->setData('publications', collect([$previousPublication, $publication]));

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
        $galley->setData('label', 'galley-label');
        $galley->setData('locale', 'en');

        // Supplementary galley (genre 2 = "Research Instrument", supplementary in the test DB)
        /** @var Galley|MockObject $suppGalley */
        $suppGalley = $this->getMockBuilder(Galley::class)
            ->onlyMethods(['getBestGalleyId'])
            ->getMock();
        $suppGalley->expects(self::any())
            ->method('getBestGalleyId')
            ->willReturn(99);
        $suppGalley->setId(99);
        $suppGalley->setData('submissionFileId', 99);
        $suppGalley->setData('label', 'supp-label');

        $galleys = collect([$galley, $suppGalley]);
        $publication->setData('galleys', $galleys);

        // Mock SubmissionFile Repository to provide mimetype
        $submissionFileMock = Mockery::mock(SubmissionFile::class);
        $submissionFileMock->shouldReceive('getData')
            ->andReturnUsing(function ($key) {
                return match ($key) {
                    'mimetype' => 'galley-filetype',
                    'fileId' => 1,
                    default => null
                };
            });

        $suppSubmissionFileMock = \Mockery::mock(\PKP\submissionFile\SubmissionFile::class);
        $suppSubmissionFileMock->shouldReceive('getData')
            ->andReturnUsing(function ($key) {
                return match ($key) {
                    'mimetype' => 'application/pdf',
                    'fileId' => 2,
                    'genreId' => 2,
                    default => null
                };
            });

        // Mock Collector for method chaining
        $collectorMock = Mockery::mock(\PKP\submissionFile\Collector::class);
        $collectorMock->shouldReceive('filterBySubmissionIds')->andReturnSelf();
        $collectorMock->shouldReceive('filterByFileStages')->andReturnSelf();
        $collectorMock->shouldReceive('getMany')->andReturn(\Illuminate\Support\LazyCollection::make([]));

        $submissionFileRepoMock = Mockery::mock(\APP\submissionFile\Repository::class);
        $submissionFileRepoMock->shouldReceive('get')
            ->with(98)
            ->andReturn($submissionFileMock);
        $submissionFileRepoMock->shouldReceive('get')
            ->with(99)
            ->andReturn($suppSubmissionFileMock);
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
        $journal->setData('abbreviation', 'J Pub Know', 'en');
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
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */
        $journal = & $record->getData('journal'); /** @var Journal $journal */
        $section = & $record->getData('section'); /** @var Section $section */
        $issue = & $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        $this->stubPreviousVersionRelation();

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->create(
            $journal,
            $submission,
            $section,
            $issue,
            $this->createRequestMockInstance(),
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
        $record = & $OAIRecord;
        $journal = & $record->getData('journal'); /** @var Journal $journal */

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
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */
        $journal = & $record->getData('journal'); /** @var Journal $journal */
        $section = & $record->getData('section'); /** @var Section $section */
        $issue = & $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        $this->stubPreviousVersionRelation();

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $publication
        );
        $xml->ownerDocument->formatOutput = true;
        self::assertEquals(
            trim(file_get_contents($this->xmlFilePath . 'articleMetaElement.xml')),
            trim($articleFrontElement->saveXML($xml))
        );
    }

    /**
     * Test that the immediately preceding version is linked as a related-article.
     */
    public function testCreateArticleMetaVersionRelation()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */
        $journal = & $record->getData('journal'); /** @var Journal $journal */
        $section = & $record->getData('section'); /** @var Section $section */
        $issue = & $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        // Stub the repository to return a single preceding-version relation (chain-only).
        $versionRelation = (object) [
            'publicationId' => 5,
            'versionStage' => 'VoR',
            'versionString' => 'Version of Record 1.0',
            'doi' => '10.1234/test.prev',
            'doiUrl' => 'https://doi.org/10.1234/test.prev',
            'datePublished' => '2010-01-01',
            'relationType' => VersionRelationType::IS_NEW_VERSION_OF,
            'updateType' => UpdateType::NEW_VERSION,
        ];
        $publicationRepoMock = Mockery::mock(Repository::class);
        $publicationRepoMock->shouldReceive('getVersionRelation')
            ->andReturn($versionRelation);
        app()->instance(Repository::class, $publicationRepoMock);

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $publication
        );

        $relatedArticles = $xml->getElementsByTagName('related-article');
        self::assertCount(1, $relatedArticles);

        $relatedArticle = $relatedArticles->item(0);
        // NEW_VERSION maps to the JATS updated-article type; the ordering relation and version
        // string are preserved.
        self::assertSame('updated-article', $relatedArticle->getAttribute('related-article-type'));
        self::assertSame('isNewVersionOf', $relatedArticle->getAttribute('specific-use'));
        self::assertSame('doi', $relatedArticle->getAttribute('ext-link-type'));
        self::assertSame('10.1234/test.prev', $relatedArticle->getAttribute('xlink:href'));
        self::assertSame('Version of Record 1.0', $relatedArticle->textContent);
    }

    /**
     * Test that the publication version is expressed as a JAV article-version element.
     */
    public function testCreateArticleMetaArticleVersion()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */
        $journal = & $record->getData('journal'); /** @var Journal $journal */
        $section = & $record->getData('section'); /** @var Section $section */
        $issue = & $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */

        $publicationRepoMock = Mockery::mock(Repository::class);
        $publicationRepoMock->shouldReceive('getVersionRelation')->andReturnNull();
        app()->instance(Repository::class, $publicationRepoMock);

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $publication
        );

        $versions = $xml->getElementsByTagName('article-version');
        self::assertCount(1, $versions);

        $version = $versions->item(0);
        // Version of Record is part of the JAV standard, so the JAV vocabulary is included.
        self::assertSame('VoR', $version->getAttribute('article-version-type'));
        self::assertSame('1.0', $version->textContent);
        self::assertSame('JAV', $version->getAttribute('vocab'));
        self::assertSame('http://www.niso.org/publications/rp/RP-8-2008.pdf', $version->getAttribute('vocab-identifier'));
        self::assertSame('Version of Record', $version->getAttribute('vocab-term'));
    }

    /**
     * Test that a PMUR version omits the JAV vocabulary, as PMUR is not part of the JAV standard.
     */
    public function testCreateArticleMetaArticleVersionPmurOmitsJavVocab()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */
        $journal = & $record->getData('journal'); /** @var Journal $journal */
        $section = & $record->getData('section'); /** @var Section $section */
        $issue = & $record->getData('issue'); /** @var Issue $issue */
        $publication = $submission->getCurrentPublication(); /** @var Publication $publication */
        $publication->setData('versionStage', 'PMUR');

        $publicationRepoMock = Mockery::mock(Repository::class);
        $publicationRepoMock->shouldReceive('getVersionRelation')->andReturnNull();
        app()->instance(Repository::class, $publicationRepoMock);

        $articleFrontElement = new ArticleFront();
        $xml = $articleFrontElement->createArticleMeta(
            $submission,
            $journal,
            $section,
            $issue,
            $this->createRequestMockInstance(),
            $publication
        );

        $versions = $xml->getElementsByTagName('article-version');
        self::assertCount(1, $versions);

        $version = $versions->item(0);
        self::assertSame('PMUR', $version->getAttribute('article-version-type'));
        self::assertSame('1.0', $version->textContent);
        self::assertFalse($version->hasAttribute('vocab'));
        self::assertFalse($version->hasAttribute('vocab-identifier'));
        self::assertFalse($version->hasAttribute('vocab-term'));
    }

    /**
     * Test creating journal-meta journal-title-group element.
     */
    public function testCreateJournalMetaJournalTitleGroup()
    {
        $OAIRecord = $this->createOAIRecordMockObject();
        $record = & $OAIRecord;
        $journal = & $record->getData('journal'); /** @var Journal $journal */

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
        $record = & $OAIRecord;
        $submission = & $record->getData('article'); /** @var Submission $submission */

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
