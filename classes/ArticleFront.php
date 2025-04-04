<?php

/**
 * @file ArticleFront.php
 *
 * Copyright (c) 2003-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article front element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\author\Author;
use APP\issue\Issue;
use APP\facades\Repo;
use Dispatcher;
use PKP\core\PKPString;
use APP\journal\Journal;
use APP\section\Section;
use PKP\core\PKPRequest;
use APP\core\Application;
use PKP\core\PKPApplication;
use APP\submission\Submission;
use PKP\plugins\PluginRegistry;
use APP\publication\Publication;
use PKP\controlledVocab\ControlledVocab;
use PKP\i18n\LocaleConversion;
use PKP\submissionFile\SubmissionFile;

use Carbon\Carbon;

class ArticleFront extends \DOMDocument
{
    /**
     * Create article front element
     */
    public function create(Journal $journal, Submission $submission, Section $section, ?Issue $issue, PKPRequest $request, Article $article, ?Publication $workingPublication = null): \DOMNode
    {
        return $this->appendChild($this->createElement('front'))
            ->appendChild($this->createJournalMeta($journal, $request))
            ->parentNode
            ->appendChild($this->createArticleMeta($submission, $journal, $section, $issue, $request, $article, $workingPublication))
            ->parentNode;
    }

    /**
     * Create xml journal-meta DOMNode
     */
    public function createJournalMeta(Journal $journal, PKPRequest $request): \DOMNode
    {
        $journalMetaElement = $this->appendChild($this->createElement('journal-meta'));

        $journalMetaElement->appendChild($this->createElement('journal-id'))
            ->setAttribute('journal-id-type', 'ojs')->parentNode
            ->appendChild($this->createTextNode($journal->getPath()))->parentNode;

        $journalMetaElement->appendChild($this->createJournalMetaJournalTitleGroup($journal));

        $publisherElement = $journalMetaElement->appendChild($this->createElement('publisher'));
        $publisherElement->appendChild($this->createElement('publisher-name'))
            ->appendChild($this->createTextNode($journal->getData('publisherInstitution')));

        $citationStyleLanguagePlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
        $publisherLocation = $citationStyleLanguagePlugin?->getSetting($journal->getId(), 'publisherLocation');
        $publisherCountry = $journal->getData('country');
        $publisherUrl = $journal->getData('publisherUrl');
        if ($publisherLocation || $publisherCountry || $publisherUrl) {
            $publisherLocElement = $publisherElement->appendChild($this->createElement('publisher-loc'));
            if ($publisherLocation) {
                $publisherLocElement->appendChild($this->createTextNode($publisherLocation));
            }
            if ($publisherCountry) {
                $publisherLocElement->appendChild($this->createElement('country'))->appendChild($this->createTextNode($publisherCountry));
            }
            if ($publisherUrl) {
                $publisherLocElement->appendChild($this->createElement('uri'))->appendChild($this->createTextNode($publisherUrl));
            }
        }

        if (!empty($journal->getData('onlineIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getData('onlineIssn')))->parentNode
                ->setAttribute('pub-type', 'epub');
        }
        if (!empty($journal->getData('printIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getData('printIssn')))->parentNode
                ->setAttribute('pub-type', 'ppub');
        }

        $router = $request->getRouter();
        $dispatcher = $router->getDispatcher();

        $journalUrl = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $journal->getPath(), urlLocaleForPage: '');
        $journalMetaElement
            ->appendChild($this->createElement('self-uri'))
            ->setAttribute('xlink:href', $journalUrl);

        return $journalMetaElement;
    }

    /**
     * Create Journal title group element
     */
    public function createJournalMetaJournalTitleGroup(Journal $journal): \DOMNode
    {
        $journalTitleGroupElement = $this->appendChild($this->createElement('journal-title-group'));

        $journalTitleGroupElement->appendChild($this->createElement('journal-title'))
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($journal->getPrimaryLocale()))->parentNode
            ->appendChild($this->createTextNode($journal->getName($journal->getPrimaryLocale())));

        foreach ($journal->getName(null) as $locale => $title) {
            if ($locale == $journal->getPrimaryLocale()) {
                continue;
            }
            $journalTitleGroupElement->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                ->appendChild($this->createElement('trans-title'))->appendChild($this->createTextNode($title));
        }
        //Include journal abbreviation titles
        foreach ($journal->getData('abbreviation') as $locale => $abbrevTitle) {
            $journalTitleGroupElement->appendChild($this->createElement('abbrev-journal-title'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                ->appendChild($this->createTextNode($abbrevTitle));
        }
        return $journalTitleGroupElement;
    }

    /**
     * Create xml article-meta DOMNode
     */
    function createArticleMeta(Submission $submission, Journal $journal, Section $section, ?Issue $issue, $request, Article $article, ?Publication $workingPublication = null)
    {
        $publication = $submission->getCurrentPublication();
        if ($workingPublication) {
            $publication = $workingPublication;
        }

        $articleMetaElement = $this->appendChild($this->createElement('article-meta'));

        $articleMetaElement->appendChild($this->createElement('article-id'))
            ->setAttribute('pub-id-type', 'publisher-id')->parentNode
            ->appendChild($this->createTextNode($submission->getId()));

        $articleMetaElement->appendChild($this->createElement('article-categories'))
            ->appendChild($this->createElement('subj-group'))
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($journal->getPrimaryLocale()))->parentNode
            ->setAttribute('subj-group-type', 'heading')->parentNode
            ->appendChild($this->createElement('subject'))
            ->appendChild($this->createTextNode($section->getLocalizedTitle()));

        $titleGroupElement = $articleMetaElement->appendChild($this->createElement('title-group'));

        $titleGroupElement->appendChild($this->createElement('article-title', $article->mapHtmlTagsForTitle($publication->getLocalizedTitle(null, 'html'))))
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($submission->getData('locale')));

        if (!empty($subtitle = $article->mapHtmlTagsForTitle($publication->getLocalizedSubTitle(null, 'html')))) {
            $titleGroupElement->appendChild($this->createElement('subtitle', $subtitle))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($submission->getData('locale')));
        }

        // Include translated submission titles
        foreach ($publication->getTitles('html') as $locale => $title) {
            if ($locale == $submission->getData('locale')) {
                continue;
            }

            if (trim($translatedTitle = $article->mapHtmlTagsForTitle($publication->getLocalizedTitle($locale, 'html'))) === '') {
                continue;
            }
            $titleGroupElement->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                ->appendChild($this->createElement('trans-title', $translatedTitle));

            if (!empty($translatedSubTitle = $article->mapHtmlTagsForTitle($publication->getLocalizedSubTitle($locale, 'html')))) {
                $titleGroupElement->appendChild($this->createElement('trans-subtitle', $translatedSubTitle));
            }
        }
        $contribGroup = $this->createArticleContribGroup($submission, $publication);

        // Include authors
        $institutions = $contribGroup['institutions'];

        // append element contrib-group to article-meta
        $articleMetaElement->appendChild($contribGroup['contribGroupElement']);
        foreach ($institutions as $affiliationToken => $institution) {
            $affNode = $articleMetaElement->appendChild($this->createElement('aff'))
                ->setAttribute('id', $affiliationToken)->parentNode;
            if (isset($institution['id'])) {
                $institutionWrapNode = $affNode->appendChild($this->createElement('institution-wrap'));
                $institutionWrapNode->appendChild($this->createElement('institution'))
                    ->appendChild($this->createTextNode($institution['name']))->parentNode
                    ->setAttribute('content-type', 'orgname');
                $institutionWrapNode->appendChild($this->createElement('institution-id'))
                    ->appendChild($this->createTextNode($institution['id']))->parentNode
                    ->setAttribute('institution-id-type', 'ROR');
            } else {
                $affNode->appendChild($this->createElement('institution'))
                ->appendChild($this->createTextNode($institution['name']))->parentNode
                ->setAttribute('content-type', 'orgname');
            }
        }

        if ($datePublished = $publication->getData('datePublished')) {
            $datePublished = strtotime($datePublished);
        } elseif ($issue) {
            $datePublished = $issue->getDatePublished();
        }

        // Include pub dates
        if ($datePublished) {
            $pubDateElement = $articleMetaElement->appendChild($this->createElement('pub-date'))
                ->setAttribute('date-type', 'pub')->parentNode
                ->setAttribute('publication-format', 'epub')->parentNode;

            $pubDateElement->appendChild($this->createElement('day'))
                ->appendChild($this->createTextNode(date('d', (int)$datePublished)));

            $pubDateElement->appendChild($this->createElement('month'))
                ->appendChild($this->createTextNode(date('m', (int)$datePublished)));

            $pubDateElement->appendChild($this->createElement('year'))
                ->appendChild($this->createTextNode(date('Y', (int)$datePublished)));
        }

        // Include page info, if available and parseable.
        $matches = $pageCount = null;
        if (preg_match('/^(\d+)$/u', $publication->getData('pages'), $matches)) {
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $pageCount = 1;
        } elseif (preg_match('/^[Pp]?[Pp]?[.]?[ ]?(\d+)$/u', $publication->getData('pages'), $matches)) {
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $pageCount = 1;
        } elseif (preg_match('/^[Pp]?[Pp]?[.]?[ ]?(\d+)[ ]?-[ ]?([Pp][Pp]?[.]?[ ]?)?(\d+)$/u', $publication->getData('pages'), $matches)) {
            $matchedPageFrom = $matches[1];
            $matchedPageTo = $matches[3];
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matchedPageFrom));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matchedPageTo));
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        } elseif (preg_match('/^(\d+)[ ]?-[ ]?(\d+)$/u', $publication->getData('pages'), $matches)) {
            $matchedPageFrom = $matches[1];
            $matchedPageTo = $matches[2];
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matchedPageFrom));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matchedPageTo));
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        }

        if (($date = $submission->getData('dateSubmitted')) !== null) {
            $date = Carbon::createFromTimestamp(strtotime($date));
            $eventElement = $articleMetaElement->appendChild($this->createElement('pub-history'))
                ->appendChild($this->createElement('event'));
            $eventElement->setAttribute('event-type', 'received');
            $eventDescElement = $eventElement->appendChild($this->createElement('event-desc'));
            $eventDescElement->appendChild($this->createTextNode('Received: '));
            $dateElement = $eventDescElement->appendChild($this->createElement('date'));
            $dateElement->setAttribute('date-type', 'received');
            $dateElement->setAttribute('iso-8601-date', $date->toIso8601String());
            $dateElement->appendChild($this->createElement('day'))
                ->appendChild($this->createTextNode($date->day));
            $dateElement->appendChild($this->createElement('month'))
                ->appendChild($this->createTextNode($date->month));
            $dateElement->appendChild($this->createElement('year'))
                ->appendChild($this->createTextNode($date->year));
        }

        $copyrightYear = $publication->getData('copyrightYear');
        $copyrightHolder = $publication->getLocalizedData('copyrightHolder');
        $licenseUrl = $publication->getData('licenseUrl');
        $ccBadge = Application::get()->getCCLicenseBadge($licenseUrl, $submission->getData('locale')) === null ? '' : Application::get()->getCCLicenseBadge($licenseUrl, $submission->getData('locale'));
        if ($copyrightYear || $copyrightHolder || $licenseUrl || $ccBadge) {
            $permissionsElement = $articleMetaElement->appendChild($this->createElement('permissions'));
            if ($copyrightYear || $copyrightHolder) {
                $permissionsElement->appendChild($this->createElement('copyright-statement'))
                    ->appendChild($this->createTextNode(__('submission.copyrightStatement', ['copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder])));
            }
            if ($copyrightYear) {
                $permissionsElement->appendChild($this->createElement('copyright-year'))
                    ->appendChild($this->createTextNode($copyrightYear));
            }
            if ($copyrightHolder) {
                $permissionsElement->appendChild($this->createElement('copyright-holder'))
                    ->appendChild($this->createTextNode($copyrightHolder));
            }
            if ($licenseUrl) {
                $licenseElement = $permissionsElement->appendChild($this->createElement('license'))
                    ->setAttribute('xlink:href', $licenseUrl)->parentNode;
                if ($ccBadge) {
                    $licenseElement->appendChild($this->createElement('license-p'))
                                   ->appendChild($this->createTextNode($ccBadge));
                }
            }
        }

        $router = $request->getRouter();
        $dispatcher = $router->getDispatcher(); /* @var $dispatcher Dispatcher */

        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $journal->getPath(), 'article', 'view', [$publication->getData('urlPath') ?? $submission->getId()], null, null, true, '');

        $articleMetaElement
            ->appendChild($this->createElement('self-uri'))
            ->setAttribute('xlink:href', $url);

        $keywordVocabs = Repo::controlledVocab()->getBySymbolic(
            ControlledVocab::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
            Application::ASSOC_TYPE_PUBLICATION,
            $publication->getId()
        );

        foreach ($keywordVocabs as $locale => $keywords) {
            if (empty($keywords)) {
                continue;
            }

            $kwdGroupElement = $articleMetaElement
                ->appendChild($this->createElement('kwd-group'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode;
            foreach ($keywords as $keyword) {
                $kwdGroupElement
                    ->appendChild($this->createElement('kwd'))
                    ->appendChild($this->createTextNode($keyword));
            }
        }

        if ($pageCount) {
            $articleMetaElement
                ->appendChild($this->createElement('counts'))
                ->appendChild($this->createElement('page-count'))
                ->setAttribute('count', $pageCount);
        }

        $customMetaGroupElement = $articleMetaElement->appendChild($this->createElement('custom-meta-group'));
        $layoutFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
            ->getMany();

        foreach ($layoutFiles as $layoutFile) {
            $sourceFileUrl = $request->getDispatcher()->url(
                $request,
                PKPApplication::ROUTE_PAGE,
                null,
                'jatsTemplate',
                'download',
                null,
                [
                    'submissionFileId' => $layoutFile->getId(),
                    'fileId' => $layoutFile->getData('fileId'),
                    'submissionId' => $submission->getId(),
                    'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
                ],
                urlLocaleForPage: ''
            );
            $customMetaGroupElement->appendChild($this->createElement('custom-meta'))
                ->appendChild($this->createElement('meta-name', 'production-ready-file-url'))->parentNode
                ->appendChild($this->createElement('meta-value'))
                ->appendChild($this->createElement('ext-link'))
                ->setAttribute('ext-link-type', 'uri')->parentNode
                ->setAttribute('xlink:href', $sourceFileUrl);
        }
        return $articleMetaElement;
    }

    /**
     * Create article-meta contrib-group element
     */
    public function createArticleContribGroup(Submission $submission, Publication $publication): array
    {
        $contribGroupElement = $this->appendChild($this->createElement('contrib-group'))
            ->setAttribute('content-type', 'author')->parentNode;

        // Include authors
        $affiliations = $institutions = [];
        foreach ($publication->getData('authors') as $author) { /** @var Author $author */
            $authorTokenList = [];
            $authorAffiliations = $author->getAffiliations();
            foreach ($authorAffiliations as $authorAffiliation) {
                $affiliationName = $authorAffiliation->getLocalizedName($publication->getData('locale'));
                $affiliationToken = array_search($affiliationName, $affiliations);
                if ($affiliationName && !$affiliationToken) {
                    $affiliationToken = 'aff-' . (count($affiliations) + 1);
                    $authorTokenList[] = $affiliationToken;
                    $affiliations[$affiliationToken] = $affiliationName;
                    $institutions[$affiliationToken]['name'] = $affiliationName;
                    $institutions[$affiliationToken]['id'] = $authorAffiliation->getRor();
                }
            }

            $contribElement = $contribGroupElement->appendChild($this->createElement('contrib'));
            if ($publication->getData('primaryContactId') == $author->getId()) {
                $contribElement->setAttribute('corresp', 'yes');
            }

            if ($author->getOrcid()) {
                $contribElement->appendChild($this->createElement('contrib-id'))
                    ->setAttribute('contrib-id-type', 'orcid')->parentNode
                    ->setAttribute('authenticated', $author->hasVerifiedOrcid() ? 'true' : 'false')->parentNode
                    ->appendChild($this->createTextNode($author->getOrcid()));
            }

            $nameAlternativesElement = $contribElement->appendChild($this->createElement('name-alternatives'));

            $preferredName = $author->getPreferredPublicName($submission->getData('locale'));
            if (!empty($preferredName)) {
                $stringNameElement = $nameAlternativesElement->appendChild($this->createElement('string-name'))
                    ->setAttribute('specific-use', 'display')->parentNode;
                $stringNameElement->appendChild($this->createTextNode($preferredName));
            }

            $nameElement = $nameAlternativesElement->appendChild($this->createElement('name'))
                ->setAttribute('name-style', 'western')->parentNode;
            $nameElement->setAttribute('specific-use', 'primary');

            if ($surname = $author->getLocalizedFamilyName()) {
                $nameElement->appendChild($this->createElement('surname'))
                    ->appendChild($this->createTextNode($surname));
            }
            $nameElement->appendChild($this->createElement('given-names'))
                ->appendChild($this->createTextNode($author->getLocalizedGivenName()));

            $contribElement->appendChild($this->createElement('email'))
                ->appendChild($this->createTextNode($author->getEmail()));

            foreach ($authorTokenList as $token) {
                $contribElement->appendChild($this->createElement('xref'))
                    ->setAttribute('ref-type', 'aff')->parentNode
                    ->setAttribute('rid', $token);
            }
            if (($s = $author->getUrl()) != '') {
                $contribElement->appendChild($this->createElement('uri'))
                    ->setAttribute('ref-type', 'aff')->parentNode
                    ->setAttribute('rid', $affiliationToken)->parentNode
                    ->appendChild($this->createTextNode($s));
            }

            foreach ((array) $author->getData('biography') as $locale => $bio) {
                if (empty($bio)) {
                    continue;
                }

                $bioElement = $this->createElement('bio');
                $bioElement->setAttribute('xml:lang', LocaleConversion::toBcp47($locale));

                $strippedBio = PKPString::stripUnsafeHtml($bio);
                $bioDocument = new \DOMDocument();
                $bioDocument->createDocumentFragment();
                $bioDocument->loadHTML($strippedBio);
                foreach ($bioDocument->getElementsByTagName('body')->item(0)->childNodes->getIterator() as $bioChildNode) {
                    $bioElement->appendChild($this->importNode($bioChildNode, true));
                }
                $contribElement->appendChild($bioElement);
            }
        }
        return ['contribGroupElement' => $contribGroupElement, 'institutions' => $institutions];
    }
}
