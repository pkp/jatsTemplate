<?php

/**
 * @file ArticleFront.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS xml article front element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\section\Section;
use APP\submission\Submission;
use APP\publication\Publication;
use PKP\core\PKPApplication;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;
use PKP\core\PKPRequest;

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

        $publisherCountry = $journal->getSetting('country');
        $publisherUrl = $journal->getSetting('publisherUrl');
        $publisherElement = $journalMetaElement->appendChild($this->createElement('publisher'));
        $publisherElement->appendChild($this->createElement('publisher-name'))
            ->appendChild($this->createTextNode($journal->getSetting('publisherInstitution')));

        $citationStyleLanguagePlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
        $publisherLocation = $citationStyleLanguagePlugin?->getSetting($journal->getId(), 'publisherLocation');
        $publisherCountry = $journal->getSetting('country');
        $publisherUrl = $journal->getSetting('publisherUrl');
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

        if (!empty($journal->getSetting('onlineIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getSetting('onlineIssn')))->parentNode
                ->setAttribute('pub-type','epub');
        }
        if (!empty($journal->getSetting('printIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getSetting('printIssn')))->parentNode
                ->setAttribute('pub-type','ppub');
        }

        $router = $request->getRouter();
        $dispatcher = $router->getDispatcher();

        $journalUrl = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $journal->getPath());
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
            ->setAttribute('xml:lang', substr($journal->getPrimaryLocale(), 0, 2))->parentNode
            ->appendChild($this->createTextNode($journal->getName($journal->getPrimaryLocale())));

        foreach ($journal->getName(null) as $locale => $title) {
            if ($locale == $journal->getPrimaryLocale()) continue;
            $journalTitleGroupElement->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang', substr($locale, 0, 2))->parentNode
                ->appendChild($this->createElement('trans-title'))->appendChild($this->createTextNode($title));
        }
        //Include journal abbreviation titles
        foreach ($journal->getData('abbreviation') as $locale => $abbrevTitle) {
            $journalTitleGroupElement->appendChild($this->createElement('abbrev-journal-title'))
                ->setAttribute('xml:lang', substr($locale, 0, 2))->parentNode
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
            ->setAttribute('pub-id-type','publisher-id')->parentNode
            ->appendChild($this->createTextNode($submission->getId()));

        $articleMetaElement->appendChild($this->createElement('article-categories'))
            ->appendChild($this->createElement('subj-group'))
            ->setAttribute('xml:lang', $journal->getPrimaryLocale())->parentNode
            ->setAttribute('subj-group-type','heading')->parentNode
            ->appendChild($this->createElement('subject'))
            ->appendChild($this->createTextNode($section->getLocalizedTitle()));

        $titleGroupElement = $articleMetaElement->appendChild($this->createElement('title-group'));

        $titleGroupElement->appendChild($this->createElement('article-title', $article->mapHtmlTagsForTitle($publication->getLocalizedTitle(null, 'html'))))
            ->setAttribute('xml:lang', substr($submission->getData('locale'), 0, 2));

        if (!empty($subtitle = $article->mapHtmlTagsForTitle($publication->getLocalizedSubTitle(null, 'html')))) {
            $titleGroupElement->appendChild($this->createElement('subtitle', $subtitle))
                ->setAttribute('xml:lang', substr($submission->getData('locale'), 0, 2));
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
                ->setAttribute('xml:lang', substr($locale, 0, 2))->parentNode
                ->appendChild($this->createElement('trans-title', $translatedTitle));

            if (!empty($translatedSubTitle = $article->mapHtmlTagsForTitle($publication->getLocalizedSubTitle($locale, 'html')))) {
                $titleGroupElement->appendChild($this->createElement('trans-subtitle', $translatedSubTitle));
            }
        }
        $contribGroup = $this->createArticleContribGroup($submission, $publication);

        // Include authors
        $affiliations = $contribGroup['affiliations'];

        // append element contrib-group to article-meta
        $articleMetaElement->appendChild($contribGroup['contribGroupElement']);

        foreach ($affiliations as $affiliationToken => $affiliation) {
            $affNode = $articleMetaElement->appendChild($this->createElement('aff'))
                ->setAttribute('id', $affiliationToken)->parentNode;

            $affNode->appendChild($this->createElement('institution'))
                ->appendChild($this->createTextNode($affiliation))->parentNode
                ->setAttribute('content-type', 'orgname');
        }

        if ($datePublished = $publication->getData('datePublished')) {
            $datePublished = strtotime($datePublished);
        } else if ($issue) {
            $datePublished = $issue->getDatePublished();
        }

        // Include pub dates
        if ($datePublished) {
            $pubDateElement = $articleMetaElement->appendChild($this->createElement('pub-date'))
                ->setAttribute('date-type', 'pub')->parentNode
                ->setAttribute('publication-format','epub')->parentNode;

            $pubDateElement->appendChild($this->createElement('day'))
                ->appendChild($this->createTextNode(strftime('%d', (int)$datePublished)));

            $pubDateElement->appendChild($this->createElement('month'))
                ->appendChild($this->createTextNode(strftime('%m', (int)$datePublished)));

            $pubDateElement->appendChild($this->createElement('year'))
                ->appendChild($this->createTextNode(strftime('%Y', (int)$datePublished)));
        }

        // Include page info, if available and parseable.
        $matches = $pageCount = null;
        if (preg_match('/^(\d+)$/u', $publication->getData('pages'), $matches)) {
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $pageCount = 1;
        } elseif (preg_match('/^[Pp][Pp]?[.]?[ ]?(\d+)$/u', $publication->getData('pages'), $matches)) {
            $articleMetaElement->appendChild($this->createElement('fpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $articleMetaElement->appendChild($this->createElement('lpage'))
                ->appendChild($this->createTextNode($matches[1]));
            $pageCount = 1;
        } elseif (preg_match('/^[Pp][Pp]?[.]?[ ]?(\d+)[ ]?-[ ]?([Pp][Pp]?[.]?[ ]?)?(\d+)$/u', $publication->getData('pages'), $matches)) {
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

        $copyrightYear = $publication->getData('copyrightYear');
        $copyrightHolder = $publication->getLocalizedData('copyrightHolder');
        $licenseUrl = $publication->getData('licenseUrl');
        $ccBadge = Application::get()->getCCLicenseBadge($licenseUrl, $submission->getData('locale'))=== null?'':Application::get()->getCCLicenseBadge($licenseUrl, $submission->getData('locale'));
        if ($copyrightYear || $copyrightHolder || $licenseUrl || $ccBadge) {
            $permissionsElement = $articleMetaElement->appendChild($this->createElement('permissions'));
            if ($copyrightYear || $copyrightHolder){
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
        $dispatcher = $router->getDispatcher();

        $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $journal->getPath(), 'article', 'view', $publication->getData('urlPath') ?? $submission->getId(), null, null, true);

        $articleMetaElement
            ->appendChild($this->createElement('self-uri'))
            ->setAttribute('xlink:href', $url);

        $submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
        foreach ($submissionKeywordDao->getKeywords($publication->getId(), $journal->getSupportedSubmissionLocales()) as $locale => $keywords) {
            if (empty($keywords)) continue;

            $kwdGroupElement = $articleMetaElement
                ->appendChild($this->createElement('kwd-group'))
                ->setAttribute('xml:lang', substr($locale, 0, 2))->parentNode;
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
            $sourceFileUrl = $request->url(null, 'jatsTemplate', 'download', null,
                [
                    'submissionFileId' => $layoutFile->getId(),
                    'fileId' => $layoutFile->getData('fileId'),
                    'submissionId' => $submission->getId(),
                    'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
                ]
            );
            $customMetaGroupElement->appendChild($this->createElement('custom-meta'))
                ->appendChild($this->createElement('meta-name', 'production-ready-file-url'))->parentNode
                ->appendChild($this->createElement('meta-value'))
                ->appendChild($this->createElement('ext-link'))
                ->setAttribute('ext-link-type','uri')->parentNode
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
        $affiliations = [];
        foreach ($publication->getData('authors') as $author) {
            $affiliation = $author->getLocalizedAffiliation();
            $affiliationToken = array_search($affiliation, $affiliations);
            if ($affiliation && !$affiliationToken) {
                $affiliationToken = 'aff-' . (count($affiliations) + 1);
                $affiliations[$affiliationToken] = $affiliation;
            }

            $contribElement = $contribGroupElement->appendChild($this->createElement('contrib'));
            if ($publication->getData('primaryContactId') == $author->getId()) $contribElement->setAttribute('corresp', 'yes');

            // If using the CRediT plugin, credit roles may be available.
            $creditPlugin = PluginRegistry::getPlugin('generic', 'creditplugin');
            if ($creditPlugin && $creditPlugin->getEnabled()) {
                $contributorRoles = $author->getData('creditRoles') ?? [];
                $creditRoles = $creditPlugin->getCreditRoles($submission->getData('locale'));
                foreach ($contributorRoles as $role) {
                    $roleName = $creditRoles[$role];
                    $roleElement = $contribElement->appendChild($this->createElement('role'))
                        ->setAttribute('vocab-identifier','https://credit.niso.org/')->parentNode
                        ->setAttribute('vocab-term', $roleName)->parentNode
                        ->setAttribute('vocab-term-identifier', $role);

                    $roleElement->appendChild($this->createTextNode($roleName));
                }
            }

            if ($author->getOrcid()) {
                $contribElement->appendChild($this->createElement('contrib-id'))
                    ->setAttribute('contrib-id-type', 'orcid')->parentNode
                    ->setAttribute('authenticated', $author->getData('orcidAccessToken') ? 'true' : 'false')->parentNode
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

            if ($affiliationToken) {
                $contribElement->appendChild($this->createElement('xref'))
                    ->setAttribute('ref-type', 'aff')->parentNode
                    ->setAttribute( 'rid', $affiliationToken);
            }
            if (($s = $author->getUrl()) != '') {
                $contribElement->appendChild($this->createElement('uri'))
                    ->setAttribute('ref-type', 'aff')->parentNode
                    ->setAttribute( 'rid', $affiliationToken)->parentNode
                    ->appendChild($this->createTextNode($s));
            }

            foreach ((array) $author->getData('biography') as $locale => $bio) {
                if (empty($bio)) {
                    continue;
                }

                $bioElement = $this->createElement('bio');
                $bioElement->setAttribute('xml:lang', substr($locale, 0, 2));

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
        return ['contribGroupElement' => $contribGroupElement, 'affiliations' => $affiliations];
    }
}
