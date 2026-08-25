<?php

/**
 * @file ArticleTest.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article unit tests
 */

namespace APP\plugins\generic\jatsTemplate\tests\functional;

use APP\author\Author;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\jatsTemplate\classes\Article;
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
use PKP\author\Repository as AuthorRepository;
use PKP\citation\Citation;
use PKP\citation\enum\CitationType;
use PKP\doi\Doi;
use PKP\galley\Collector as GalleyCollector;
use PKP\galley\Galley;
use PKP\oai\OAIRecord;
use PKP\publication\enums\UpdateType;
use PKP\publication\enums\VersionRelationType;
use PKP\tests\PKPTestCase;

#[CoversClass(Article::class)]
class ArticleTest extends PKPTestCase
{
    use UsesRequestMock;
    use ValidatesAgainstJats;

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
        return [...parent::getMockedContainerKeys(), GalleyCollector::class, AuthorRepository::class, Repository::class];
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

        // Citations
        $citations = $this->createCitationMocks();
        $publication->setData('citations', $citations);

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

        /** @var Doi|MockObject $galleyDoiObject */
        $galleyDoiObject = $this->getMockBuilder(Doi::class)
            ->onlyMethods([])
            ->getMock();
        $galleyDoiObject->setData('doi', 'galley-doi');

        // Mock SubmissionFile to provide mimetype
        $submissionFileMock = Mockery::mock(\PKP\submissionFile\SubmissionFile::class);
        $submissionFileMock->shouldReceive('getData')
            ->andReturnUsing(function ($key) {
                return match ($key) {
                    'mimetype' => 'galley-filetype',
                    'fileId' => 1, // Return a valid fileId
                    default => null
                };
            });

        $suppSubmissionFileMock = Mockery::mock(\PKP\submissionFile\SubmissionFile::class);
        $suppSubmissionFileMock->shouldReceive('getData')
            ->andReturnUsing(function ($key) {
                return match ($key) {
                    'mimetype' => 'application/pdf',
                    'fileId' => 2,
                    'genreId' => 2,
                    default => null
                };
            });

        // Mock file service to prevent ArticleBody from crashing
        $fileObjectMock = Mockery::mock();
        $fileObjectMock->path = null; // This will cause mimeType to skip or handle gracefully

        $fileServiceMock = Mockery::mock();
        $fileServiceMock->shouldReceive('get')->andReturn($fileObjectMock);
        $fileServiceMock->fs = Mockery::mock();
        $fileServiceMock->fs->shouldReceive('mimeType')->andReturn(null); // Return null instead of crashing

        app()->instance('file', $fileServiceMock);

        // Mock Collector for method chaining
        $collectorMock = Mockery::mock(\PKP\submissionFile\Collector::class);
        $collectorMock->shouldReceive('filterBySubmissionIds')->andReturnSelf();
        $collectorMock->shouldReceive('filterByFileStages')->andReturnSelf();
        $collectorMock->shouldReceive('getMany')->andReturn(\Illuminate\Support\LazyCollection::make([])); // Return empty LazyCollection

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
        $article->setData('publications', collect([$previousPublication, $publication]));

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
        $record->setData('journal', $journal);
        $record->setData('section', $section);
        $record->setData('issue', $issue);

        return $record;
    }

    /**
     * Create mock Citation objects for testing
     * (citations do not exist)
     * @return array<Citation>
     */
    private function createCitationMocks(): array
    {
        $citations = [];
        // Structured citation 1: Journal article with DOI
        $citation1 = new Citation();
        $citation1->setData('isStructured', true);
        $citation1->setData('type', CitationType::JOURNAL_ARTICLE->value);
        $citation1->setData('title', 'The Effects of Climate Change on Biodiversity');
        $citation1->setData('sourceName', 'Nature Climate Change');
        $citation1->setData('authors', [
            ['familyName' => 'Smith', 'givenName' => 'John'],
            ['familyName' => 'Johnson', 'givenName' => 'Mary'],
        ]);
        $citation1->setData('date', '2023-05-15');
        $citation1->setData('doi', '10.1038/s41558-023-01234-5');
        $citation1->setData('volume', '13');
        $citation1->setData('issue', '5');
        $citation1->setData('firstPage', '423');
        $citation1->setData('lastPage', '435');
        $citation1->setRawCitation('Smith J, Johnson M. The Effects of Climate Change on Biodiversity. Nature Climate Change. 2023;13(5):423-435. doi:10.1038/s41558-023-01234-5');
        $citations[] = $citation1;

        // Structured citation 2: Book
        $citation2 = new Citation();
        $citation2->setData('isStructured', true);
        $citation2->setData('type', CitationType::BOOK->value);
        $citation2->setData('title', 'Introduction to Machine Learning');
        $citation2->setData('authors', [
            ['familyName' => 'Williams', 'givenName' => 'Robert'],
        ]);
        $citation2->setData('date', '2022-01-01');
        $citation2->setRawCitation('Williams R. Introduction to Machine Learning. Cambridge University Press; 2022.');
        $citations[] = $citation2;

        // Structured citation 3: Book chapter
        $citation3 = new Citation();
        $citation3->setData('isStructured', true);
        $citation3->setData('type', CitationType::BOOK_CHAPTER->value);
        $citation3->setData('title', 'Deep Learning Fundamentals');
        $citation3->setData('sourceName', 'Handbook of Artificial Intelligence');
        $citation3->setData('authors', [
            ['familyName' => 'Chen', 'givenName' => 'Wei'],
        ]);
        $citation3->setData('date', '2021-01-01');
        $citation3->setData('firstPage', '145');
        $citation3->setData('lastPage', '198');
        $citation3->setRawCitation('Chen W. Deep Learning Fundamentals. In: Handbook of Artificial Intelligence. 2021:145-198.');
        $citations[] = $citation3;

        // Structured citation 4: Preprint with arXiv
        $citation4 = new Citation();
        $citation4->setData('isStructured', true);
        $citation4->setData('type', CitationType::PREPRINT->value);
        $citation4->setData('title', 'Novel Approaches to Natural Language Processing');
        $citation4->setData('sourceName', 'arXiv');
        $citation4->setData('authors', [
            ['familyName' => 'Garcia', 'givenName' => 'Maria'],
            ['familyName' => 'Lee', 'givenName' => 'David'],
        ]);
        $citation4->setData('date', '2024-01-10');
        $citation4->setData('arxiv', '2401.12345');
        $citation4->setRawCitation('Garcia M, Lee D. Novel Approaches to Natural Language Processing. arXiv:2401.12345. 2024.');
        $citations[] = $citation4;

        // Structured citation 5: Dataset
        $citation5 = new Citation();
        $citation5->setData('isStructured', true);
        $citation5->setData('type', CitationType::DATASET->value);
        $citation5->setData('title', 'Global Temperature Records 1900-2023');
        $citation5->setData('sourceName', 'Zenodo');
        $citation5->setData('authors', [
            ['familyName' => 'Brown', 'givenName' => 'Alice'],
        ]);
        $citation5->setData('date', '2023-06-15');
        $citation5->setData('doi', '10.5281/zenodo.1234567');
        $citation5->setRawCitation('Brown A. Global Temperature Records 1900-2023. Zenodo. 2023. doi:10.5281/zenodo.1234567');
        $citations[] = $citation5;

        // Structured citation 6: Without type
        $citation6 = new Citation();
        $citation6->setData('isStructured', true);
        // No type set - tests the fallback in getJATSPublicationType()
        $citation6->setData('title', 'An Article Without Explicit Type');
        $citation6->setData('sourceName', 'Unknown Journal');
        $citation6->setData('authors', [
            ['familyName' => 'Doe', 'givenName' => 'Jane'],
        ]);
        $citation6->setData('date', '2020-03-15');
        $citation6->setData('url', 'https://example.com/article');
        $citation6->setRawCitation('Doe J. An Article Without Explicit Type. Unknown Journal. 2020. https://example.com/article');
        $citations[] = $citation6;

        // Unstructured citation
        $citation7 = new Citation();
        $citation7->setData('isStructured', false);
        $citation7->setRawCitation('Thompson, E. P. (1963). The Making of the English Working Class. Victor Gollancz Ltd.');
        $citations[] = $citation7;

        // Unstructured citation with HTML formatting
        $citation8 = new Citation();
        $citation8->setData('isStructured', false);
        $citation8->setRawCitation('Smith, J. & Jones, M. (2024). The <i>effects</i> of H<sub>2</sub>O on x<sup>2</sup>. <b>Nature</b>, 14(3). <a href="https://doi.org/10.1234/test">https://doi.org/10.1234/test</a>');
        $citations[] = $citation8;

        return $citations;
    }

    public function testConvertToXml()
    {
        $request = $this->createRequestMockInstance();
        $record = $this->createOAIRecordMockObject();
        $this->stubPreviousVersionRelation();
        $article = new Article();
        $article->convertOAIToXml($record, $request);
        self::assertXmlStringEqualsXmlFile($this->xmlFilePath . 'ie1.xml', $article->saveXml());
    }

    /**
     * Test that the generated XML is valid against the JATS 1.2 DTD.
     */
    public function testValidateJats()
    {
        $request = $this->createRequestMockInstance();
        $record = $this->createOAIRecordMockObject();
        $this->stubPreviousVersionRelation();
        $article = new Article();
        $article->convertOAIToXml($record, $request);

        // Validate against JATS 1.2 DTD
        $this->assertXmlValidatesAgainstJats12($article);
    }

    /**
     * Stub a deterministic preceding-version relation; the mock submission's
     * publications don't carry the versionStage/versionMajor/versionMinor data
     * the real getVersionRelation() needs to group/sort them.
     */
    private function stubPreviousVersionRelation(): void
    {
        $versionRelation = (object) [
            'publicationId' => 2,
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
}
