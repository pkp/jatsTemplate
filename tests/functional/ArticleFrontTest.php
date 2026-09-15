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
use PHPUnit\Framework\Attributes\DataProvider;
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
        $article->setData('funders', collect());
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

    /**
     * Test converting abstract HTML (also used for the plain language summary) to JATS abstract content.
     */
    #[DataProvider('abstractHtmlProvider')]
    public function testGenerateAbstractContentFromXSL(string $html, string $expectedXml)
    {
        $submission = new Submission();
        $submission->setId(9);

        $articleFrontElement = new ArticleFront();
        $articleMetaElement = $articleFrontElement->appendChild($articleFrontElement->createElement('article-meta'));
        $abstractElement = $articleFrontElement->generateAbstractContentFromXSL(
            $submission,
            'abstract',
            'en',
            $html,
            $articleMetaElement
        );

        self::assertSame($expectedXml, $articleFrontElement->saveXML($abstractElement));
    }

    /**
     * Abstract HTML as stored by the rich text editor, and the JATS abstract it must convert to.
     */
    public static function abstractHtmlProvider(): array
    {
        return [
            'plain text is wrapped in a paragraph' => [
                'article-abstract',
                '<abstract><p>article-abstract</p></abstract>',
            ],
            'inline markup stays within its paragraph' => [
                '<p>Intro <em>italic</em> and <strong>bold</strong>.</p>',
                '<abstract><p>Intro <italic>italic</italic> and <bold>bold</bold>.</p></abstract>',
            ],
            'stray inline content between blocks is wrapped in a paragraph' => [
                '<p>First</p>Stray <em>text</em>',
                '<abstract><p>First</p><p>Stray <italic>text</italic></p></abstract>',
            ],
            'line break becomes a space' => [
                '<p>Line one<br>Line two</p>',
                '<abstract><p>Line one Line two</p></abstract>',
            ],
            'unordered list becomes a bullet list wrapped in a paragraph' => [
                '<p>Intro</p><ul><li>One</li><li>Two</li></ul>',
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>One</p></list-item><list-item><p>Two</p></list-item></list></p></abstract>',
            ],
            'ordered list becomes an order list' => [
                '<ol><li>First</li><li>Second</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>First</p></list-item><list-item><p>Second</p></list-item></list></p></abstract>',
            ],
            'editor whitespace between block elements is ignored' => [
                "<p>Intro</p>\n<ul>\n<li>Parent\n<ul>\n<li>Child</li>\n</ul>\n</li>\n<li>Two</li>\n</ul>\n<p>Outro</p>",
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>Parent' . "\n" . '</p><list list-type="bullet"><list-item><p>Child</p></list-item></list></list-item><list-item><p>Two</p></list-item></list></p><p>Outro</p></abstract>',
            ],
            'nested list follows the paragraph of its list item' => [
                '<ol><li>Parent<ul><li>Child one</li><li>Child two</li></ul></li><li>Sibling</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>Parent</p><list list-type="bullet"><list-item><p>Child one</p></list-item><list-item><p>Child two</p></list-item></list></list-item><list-item><p>Sibling</p></list-item></list></p></abstract>',
            ],
            'lists nest to any depth' => [
                '<ul><li>L1<ol><li>L2<ul><li>L3</li></ul></li></ol></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>L1</p><list list-type="order"><list-item><p>L2</p><list list-type="bullet"><list-item><p>L3</p></list-item></list></list-item></list></list-item></list></p></abstract>',
            ],
            'text after a nested list gets its own paragraph' => [
                '<ul><li>Before<ul><li>Nested</li></ul>After</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Before</p><list list-type="bullet"><list-item><p>Nested</p></list-item></list><p>After</p></list-item></list></p></abstract>',
            ],
            'list item holding only a nested list has no paragraph of its own' => [
                '<ul><li><ul><li>Child only</li></ul></li><li>Sibling</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><list list-type="bullet"><list-item><p>Child only</p></list-item></list></list-item><list-item><p>Sibling</p></list-item></list></p></abstract>',
            ],
            'sibling nested lists in one list item keep their order and type' => [
                '<ul><li>Parent<ul><li>Bullet child</li></ul><ol><li>Numbered child</li></ol></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Parent</p><list list-type="bullet"><list-item><p>Bullet child</p></list-item></list><list list-type="order"><list-item><p>Numbered child</p></list-item></list></list-item></list></p></abstract>',
            ],
            'text between nested lists in one list item gets its own paragraph' => [
                '<ol><li>Start<ul><li>First nested</li></ul>Middle text<ul><li>Second nested</li></ul>End</li></ol>',
                '<abstract><p><list list-type="order"><list-item><p>Start</p><list list-type="bullet"><list-item><p>First nested</p></list-item></list><p>Middle text</p><list list-type="bullet"><list-item><p>Second nested</p></list-item></list><p>End</p></list-item></list></p></abstract>',
            ],
            'nested list without any non-empty item is dropped from its list item' => [
                "<ul>\n<li>Parent\n<ul>\n<li>\u{00A0}</li>\n</ul>\n</li>\n</ul>",
                '<abstract><p><list list-type="bullet"><list-item><p>Parent' . "\n" . '</p></list-item></list></p></abstract>',
            ],
            'paragraph inside a list item is not wrapped again' => [
                '<ul><li><p>Pasted paragraph</p></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>Pasted paragraph</p></list-item></list></p></abstract>',
            ],
            'inline markup inside a list item is preserved' => [
                '<ul><li>H<sub>2</sub>O and E=mc<sup>2</sup> in <strong>bold</strong></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>H<sub>2</sub>O and E=mc<sup>2</sup> in <bold>bold</bold></p></list-item></list></p></abstract>',
            ],
            'links become ext-link and email' => [
                '<ul><li>See <a href="https://example.com/?a=1&amp;b=2">site</a> or <a href="mailto:editor@example.com">email</a></li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>See <ext-link ext-link-type="uri" xlink:href="https://example.com/?a=1&amp;b=2">site</ext-link> or <email>editor@example.com</email></p></list-item></list></p></abstract>',
            ],
            'empty list items and empty lists are dropped' => [
                '<p>Intro</p><ul><li></li><li><br></li><li>Kept</li></ul><ol><li> </li></ol>',
                '<abstract><p>Intro</p><p><list list-type="bullet"><list-item><p>Kept</p></list-item></list></p></abstract>',
            ],
            'list items holding only a non-breaking space, as the editor stores empty items, are dropped' => [
                "<ul>\n<li>\u{00A0}</li>\n<li>Kept</li>\n</ul>\n<ol>\n<li>\u{00A0}</li>\n</ol>",
                '<abstract><p><list list-type="bullet"><list-item><p>Kept</p></list-item></list></p></abstract>',
            ],
            'paragraph holding only a non-breaking space, as the editor stores blank lines, is dropped' => [
                "<p>Intro</p>\n<p>\u{00A0}</p>\n<p>Outro</p>",
                '<abstract><p>Intro</p><p>Outro</p></abstract>',
            ],
            'list directly inside a list gets its own list item' => [
                '<ul><li>A</li><ul><li>A1</li></ul><li>B</li></ul>',
                '<abstract><p><list list-type="bullet"><list-item><p>A</p></list-item><list-item><list list-type="bullet"><list-item><p>A1</p></list-item></list></list-item><list-item><p>B</p></list-item></list></p></abstract>',
            ],
            'entities are not double escaped' => [
                '<p>Cats &amp; dogs</p><ul><li>x &lt; y</li></ul>',
                '<abstract><p>Cats &amp; dogs</p><p><list list-type="bullet"><list-item><p>x &lt; y</p></list-item></list></p></abstract>',
            ],
        ];
    }
}
