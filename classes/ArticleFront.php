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

use DOMNode;
use DOMElement;
use DOMDocument;
use HTMLPurifier;
use Carbon\Carbon;
use APP\issue\Issue;
use APP\facades\Repo;
use APP\author\Author;
use PKP\config\Config;
use PKP\core\PKPString;
use APP\journal\Journal;
use APP\section\Section;
use HTMLPurifier_Config;
use PKP\core\PKPRequest;
use APP\core\Application;
use PKP\core\PKPApplication;
use PKP\userGroup\UserGroup;
use APP\submission\Submission;
use PKP\i18n\LocaleConversion;
use PKP\plugins\PluginRegistry;
use APP\publication\Publication;
use PKP\submissionFile\SubmissionFile;
use PKP\controlledVocab\ControlledVocab;
use PKP\author\creditRole\CreditRoleDegree;

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
        
        $journalMetaElement->appendChild($this->createElement('journal-id'))
            ->setAttribute('journal-id-type', 'publisher')->parentNode
            ->appendChild($this->createTextNode($journal->getPath()))->parentNode;

        $journalMetaElement->appendChild($this->createJournalMetaJournalTitleGroup($journal));

        // Editorial team (contrib-group)
        $journalMetaElement->appendChild($this->createJournalMetaJournalContribGroup($journal, $request));

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
     * Create journal-meta contrib-group element
     *
     * @param Journal $journal
     * @param PKPRequest $request
     * @return \DOMNode
     */
    public function createJournalMetaJournalContribGroup(Journal $journal, PKPRequest $request): \DOMNode
    {
        $contribGroupElement = $this->createElement('contrib-group');

        // Map user group roles to contrib-type
        $keyContribTypeMapping = [
            'default.groups.name.manager' => 'jmanager',
            'default.groups.name.editor' => 'editor',
            'default.groups.name.sectionEditor' => 'secteditor',
        ];

        $sitePrimaryLocale = $request->getSite()->getPrimaryLocale();
        $userGroups = UserGroup::query()->withContextIds([$journal->getId()])->get();

        foreach ($userGroups as $userGroup) {
            // Skip if the user group role is not mapped
            if (!isset($keyContribTypeMapping[$userGroup->nameLocaleKey])) {
                continue;
            }

            // Get users in the user group
            $users = Repo::user()->getCollector()
                ->filterByUserGroupIds([$userGroup->id])
                ->getMany();

            foreach ($users as $user) {
                $contribElement = $contribGroupElement->appendChild($this->createElement('contrib'));
                $contribElement->setAttribute('contrib-type', $keyContribTypeMapping[$userGroup->nameLocaleKey]);

                $nameElement = $contribElement->appendChild($this->createElement('name'));

                // Add surname if available
                if ($user->getFamilyName($sitePrimaryLocale)) {
                    $nameElement->appendChild($this->createElement('surname'))
                        ->appendChild($this->createTextNode($user->getFamilyName($sitePrimaryLocale)));
                }

                // Add given names
                $nameElement->appendChild($this->createElement('given-names'))
                    ->appendChild($this->createTextNode($user->getGivenName($sitePrimaryLocale)));
            }
        }

        return $contribGroupElement;
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

        // Store the publisher-id
        $articleMetaElement->appendChild($this->createElement('article-id'))
            ->setAttribute('pub-id-type', 'publisher-id')->parentNode
            ->appendChild($this->createTextNode($submission->getId()));
        
        // Store the DOI
        if ($doi = trim($publication->getStoredPubId('doi'))) {
            $articleMetaElement->appendChild($this->createElement('article-id'))
                ->setAttribute('pub-id-type', 'doi')->parentNode
                ->appendChild($this->createTextNode($doi));
        }

        // Store the issue-id, volume, number, and title
        if ($issue) {
            // Store the volume
            if ($issue->getShowVolume()) {
                $volumeElement = $this->createElement('volume');
                $volumeElement->appendChild($this->createTextNode($issue->getVolume()));
                $volumeElement->setAttribute('seq', ((int) $publication->getData('seq')) + 1);
                $articleMetaElement->appendChild($volumeElement);
            }

            // Store the issue number
            if ($issue->getShowNumber()) {
                $articleMetaElement->appendChild($this->createElement('issue'))
                    ->appendChild($this->createTextNode($issue->getNumber()));
            }

            // Store the issue id
            if ($issue->getShowNumber()) {
                $articleMetaElement->appendChild($this->createElement('issue-id'))
                    ->appendChild($this->createTextNode($issue->getId()));
            }

            // Store the issue title
            if ($issue->getShowTitle()) {
                foreach ($issue->getTitle(null) as $locale => $title) {
                    if (empty($title)) {
                        continue;
                    }
                    $articleMetaElement->appendChild($this->createElement('issue-title'))
                        ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                        ->appendChild($this->createTextNode($title));
                }
            }
        }

        // Store the article-categories
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

        // Add abstract
        $abstracts = $publication->getData('abstract');
        if (!empty($abstracts)) {
            foreach ($abstracts as $locale => $abstract) {
                if (empty($abstract)) {
                    continue;
                }

                $elementType = ($locale == $submission->getData('locale')) ? 'abstract' : 'trans-abstract';
                $abstractElement = $this->createAbstractElement(
                    $elementType,
                    $locale,
                    $abstract,
                    $articleMetaElement,
                    $article,
                );
                $articleMetaElement->appendChild($abstractElement);
            }
        }

        // Add plain-language summary
        $plainLanguageSummaries = $publication->getData('plainLanguageSummary');
        if (!empty($plainLanguageSummaries)) {
            foreach ($plainLanguageSummaries as $locale => $plainLanguageSummary) {
                if (empty($plainLanguageSummary)) {
                    continue;
                }
                $strippedSummary = PKPString::stripUnsafeHtml($plainLanguageSummary);
                if (trim($strippedSummary) === '') {
                    continue;
                }
                $elementType = ($locale == $submission->getData('locale')) ? 'abstract' : 'trans-abstract';
                // $title = __('submission.plainLanguageSummary', [], $locale); // Need this ?
                $abstractElement = $this->createAbstractElement(
                    $elementType,
                    $locale,
                    $strippedSummary,
                    $articleMetaElement,
                    $article,
                    'plain-language-summary',
                    // $title
                );
                $articleMetaElement->appendChild($abstractElement);
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
        $pageCount = null;
        if ($publication->getData('pages')) {
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

            $kwdGroupElement->appendChild($this->createElement('title'))
                ->appendChild($this->createTextNode('Keywords'));
                
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
        
        // Issue cover page
        if ($coverUrl = $issue?->getLocalizedCoverImageUrl()) {
            $customMetaElement = $customMetaGroupElement->appendChild($this->createElement('custom-meta'));
            $metaNameElement = $customMetaElement->appendChild($this->createElement('meta-name'));
            $metaNameElement->appendChild($this->createTextNode('issue-cover'));
            $metaValueElement = $customMetaElement->appendChild($this->createElement('meta-value'));
            $inlineGraphicElement = $metaValueElement->appendChild($this->createElement('inline-graphic'));
            $inlineGraphicElement->setAttribute('xlink:href', $coverUrl);
        }

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
            $customMetaElement = $customMetaGroupElement->appendChild($this->createElement('custom-meta'));
            $metaNameElement = $customMetaElement->appendChild($this->createElement('meta-name'));
            $metaNameElement->appendChild($this->createTextNode('production-ready-file-url'));
            $metaValueElement = $customMetaElement->appendChild($this->createElement('meta-value'));
            $extLinkElement = $metaValueElement->appendChild($this->createElement('ext-link'));
            $extLinkElement->setAttribute('ext-link-type', 'uri');
            $extLinkElement->setAttribute('xlink:href', $sourceFileUrl);
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
        $creditRoleTerms = Repo::creditRole()->getTerms($submission->getData('locale'));
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

            foreach ($author->getData('creditRoles') as ['role' => $role, 'degree' => $degree]) {
                $roleTerm = $creditRoleTerms['roles'][$role];
                $roleElement = $contribElement->appendChild($this->createElement('role'));
                $roleElement
                    ->setAttribute('vocab', 'CRediT')->parentNode
                    ->setAttribute('vocab-identifier', 'https://credit.niso.org/')->parentNode
                    ->setAttribute('vocab-term', $roleTerm)->parentNode
                    ->setAttribute('vocab-term-identifier', $role)->parentNode
                    ->setAttribute('degree-contribution', $creditRoleTerms['degrees'][CreditRoleDegree::toLabel($degree)]);
                $roleElement->appendChild($this->createTextNode($roleTerm));
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

    /**
     * Create an abstract or trans-abstract element with parsed content, supporting inline tags and lists
     *
     * @param string $elementType 'abstract' or 'trans-abstract'
     * @param string $locale The locale of the content
     * @param string $content The HTML or plain text content
     * @param DOMElement $parentElement The parent element to append to
     * @param Article $article The Article object for accessing mapHtmlTagsForTitle
     * @param string|null $abstractType The abstract type (e.g., 'plain-language-summary')
     * @param string|null $title The title for the abstract (e.g., "Abstract" or "Plain Language Summary")
     * 
     * @return DOMElement The created abstract element
     */
    protected function createAbstractElement(
        string $elementType,
        string $locale,
        string $content,
        DOMElement $parentElement,
        Article $article,
        ?string $abstractType = null,
        ?string $title = null
    ): DOMElement
    {
        // Create the abstract or trans-abstract element
        $abstractElement = $parentElement->ownerDocument->createElement($elementType);
        if ($abstractType) {
            $abstractElement->setAttribute('abstract-type', $abstractType);
        }
        $abstractElement->setAttribute('xml:lang', LocaleConversion::toBcp47($locale));

        // Add title
        if ($title) {
            $abstractElement->appendChild($parentElement->ownerDocument->createElement('title'))
                ->appendChild($parentElement->ownerDocument->createTextNode($title));
        }

        // Parse HTML content
        $summaryDocument = new DOMDocument();
        @$summaryDocument->loadHTML('<?xml encoding="UTF-8">' . $content); // Suppress warnings for malformed HTML
        $body = $summaryDocument->getElementsByTagName('body')->item(0);

        if (!$body) {
            // Fallback for plain text or malformed HTML
            $pElement = $parentElement->ownerDocument->createElement('p');
            $pElement->appendChild($parentElement->ownerDocument->createTextNode($article->mapHtmlTagsForTitle($content)));
            $abstractElement->appendChild($pElement);
            return $abstractElement;
        }

        foreach ($body->childNodes as $node) {
            if ($node->nodeName === 'p') {
                $pElement = $parentElement->ownerDocument->createElement('p');
                $this->processNodeContent($node, $pElement, $parentElement->ownerDocument, $article);
                if ($pElement->hasChildNodes()) {
                    $abstractElement->appendChild($pElement);
                }
            } elseif ($node->nodeName === 'ul' || $node->nodeName === 'ol') {
                $listElement = $parentElement->ownerDocument->createElement('list');
                $listElement->setAttribute('list-type', $node->nodeName === 'ul' ? 'bullet' : 'order');
                foreach ($node->childNodes as $liNode) {
                    if ($liNode->nodeName === 'li') {
                        $listItemElement = $parentElement->ownerDocument->createElement('list-item');
                        $liPElement = $parentElement->ownerDocument->createElement('p');
                        $this->processNodeContent($liNode, $liPElement, $parentElement->ownerDocument, $article);
                        if ($liPElement->hasChildNodes()) {
                            $listItemElement->appendChild($liPElement);
                            $listElement->appendChild($listItemElement);
                        }
                    }
                }
                if ($listElement->hasChildNodes()) {
                    $pWrapper = $parentElement->ownerDocument->createElement('p');
                    $pWrapper->appendChild($listElement);
                    $abstractElement->appendChild($pWrapper);
                }
            }
        }

        // If no valid content was added, append a single paragraph with the mapped content
        if (!$abstractElement->hasChildNodes() || ($abstractElement->childNodes->length === 1 && $abstractElement->childNodes->item(0)->nodeName === 'title')) {
            $pElement = $parentElement->ownerDocument->createElement('p');
            $pElement->appendChild($parentElement->ownerDocument->createTextNode($article->mapHtmlTagsForTitle($content)));
            $abstractElement->appendChild($pElement);
        }

        return $abstractElement;
    }

    /**
     * Process node content, mapping inline tags and appending to a JATS element
     *
     * @param DOMNode $node The source HTML node
     * @param DOMElement $jatsElement The target JATS element (e.g., <p>)
     * @param DOMDocument $jatsDocument The JATS document
     * @param Article $article The Article object for accessing mapHtmlTagsForTitle
     */
    protected function processNodeContent(
        DOMNode $node,
        DOMElement $jatsElement,
        DOMDocument $jatsDocument,
        Article $article
    ): void
    {
        foreach ($node->childNodes as $childNode) {
            if ($childNode->nodeType === XML_TEXT_NODE) {
                $text = trim($childNode->textContent);
                if ($text !== '') {
                    $jatsElement->appendChild($jatsDocument->createTextNode($article->mapHtmlTagsForTitle($text)));
                }
            } elseif (in_array($childNode->nodeName, ['b', 'strong', 'i', 'em', 'u'])) {
                $tagMap = [
                    'b' => 'bold',
                    'strong' => 'bold',
                    'i' => 'italic',
                    'em' => 'italic',
                    'u' => 'underline'
                ];
                $jatsChildElement = $jatsDocument->createElement($tagMap[$childNode->nodeName]);
                $this->processNodeContent($childNode, $jatsChildElement, $jatsDocument, $article);
                if ($jatsChildElement->hasChildNodes()) {
                    $jatsElement->appendChild($jatsChildElement);
                }
            }
        }
    }

    /**
     * Generate an abstract or trans-abstract element with sanitized content
     *
     * @param string $elementType 'abstract' or 'trans-abstract'
     * @param string $locale The locale of the content
     * @param string $content The HTML or plain text content
     * @param DOMElement $parentElement The parent element to append to
     * @param string|null $abstractType The abstract type (e.g., 'plain-language-summary')
     * @return DOMElement The created abstract element
     */
    protected function generateAbstractElement(
        string $elementType,
        string $locale,
        string $content,
        DOMElement $parentElement,
        ?string $abstractType = null
    ): DOMElement
    {
        static $purifier;
        if (!$purifier) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', 'p');
            $config->set('Cache.SerializerPath', Config::getVar('files', 'files_dir') . '/cache');
            $purifier = new HTMLPurifier($config);
        }

        // Sanitize content to allow only <p> tags
        $sanitizedContent = $purifier->purify($content);

        // Wrap plain text in <p> if no <p> tags are present
        if (strpos($sanitizedContent, '<p>') === false) {
            $sanitizedContent = "<p>$sanitizedContent</p>";
        }

        // Create the abstract or trans-abstract element
        $abstractElement = $parentElement->ownerDocument->createElement($elementType);
        if ($abstractType) {
            $abstractElement->setAttribute('abstract-type', $abstractType);
        }
        $abstractElement->setAttribute('xml:lang', LocaleConversion::toBcp47($locale));

        // Parse sanitized HTML
        $summaryDocument = new DOMDocument();
        @$summaryDocument->loadHTML('<?xml encoding="UTF-8">' . $sanitizedContent); // Suppress warnings for malformed HTML
        $body = $summaryDocument->getElementsByTagName('body')->item(0);

        if ($body) {
            foreach ($body->childNodes as $node) {
                if ($node->nodeName === 'p') {
                    $text = trim($node->textContent);
                    if ($text !== '') {
                        $pElement = $parentElement->ownerDocument->createElement('p');
                        $pElement->appendChild($parentElement->ownerDocument->createTextNode($text));
                        $abstractElement->appendChild($pElement);
                    }
                }
            }
        }

        // Fallback for empty or malformed content
        if (!$abstractElement->hasChildNodes()) {
            $pElement = $parentElement->ownerDocument->createElement('p');
            $pElement->appendChild($parentElement->ownerDocument->createTextNode(trim($sanitizedContent)));
            $abstractElement->appendChild($pElement);
        }

        return $abstractElement;
    }
}
