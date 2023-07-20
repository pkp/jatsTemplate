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
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\submissionFile\SubmissionFile;

class ArticleFront extends \DOMDocument
{

    /**
     * create article front element
     * @param Journal $journal
     * @param Submission $submission
     * @param Section $section
     * @param Issue $issue
     * @param $request
     * @param Article $article
     * @return \DOMNode
     */
    public function create(Journal $journal,Submission $submission, Section $section,Issue $issue, $request, Article $article): \DOMNode
    {
        $journalMeta = $this->createJournalMeta($journal);
        $articleMeta = $this->createArticleMeta($submission,$journal,$section,$issue,$request,$article);
        return $this->appendChild($this->createElement('front'))
            ->appendChild($journalMeta)
            ->parentNode
            ->appendChild($articleMeta)
            ->parentNode;
    }

    /**
     * create xml journal-meta DOMNode
     * @param $journal Journal
     * @return \DOMNode
     */
    public function createJournalMeta(Journal $journal): \DOMNode
    {
        // create element journal-title-group
        $journalTitleGroupElement = $this->createJournalMetaJournalTitleGroup($journal);

        $journalMetaElement = $this->appendChild($this->createElement('journal-meta'))
                                ->appendChild($this->createElement('journal-id', htmlspecialchars($journal->getPath())))
                                ->setAttribute('journal-id-type','ojs')
                                ->parentNode
                                ->parentNode
                                ->appendChild($journalTitleGroupElement)
                                ->parentNode
                                ->appendChild($this->createElement('publisher'))
                                ->appendChild($this->createElement('publisher-name', htmlspecialchars($journal->getSetting('publisherInstitution'))))
                                ->parentNode
                                ->parentNode;

        // create element issn
        if (!empty($journal->getSetting('onlineIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn',htmlspecialchars($journal->getSetting('onlineIssn'))))
                ->setAttribute('pub-type','epub')
                ->parentNode
                ->parentNode;
        }
        if (!empty($journal->getSetting('printIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn',htmlspecialchars($journal->getSetting('printIssn'))))
                ->setAttribute('pub-type','ppub')
                ->parentNode
                ->parentNode;
        }
        return $journalMetaElement;

    }

    /**
     * create Journal title group element
     * @param Journal $journal
     * @return \DOMElement
     */
    public function createJournalMetaJournalTitleGroup(Journal $journal): \DOMNode
    {
        $journalTitleGroupElement = $this->appendChild($this->createElement('journal-title-group'))
            ->appendChild($this->createElement('journal-title',htmlspecialchars($journal->getName($journal->getPrimaryLocale()))))
            ->setAttribute('xml:lang',substr($journal->getPrimaryLocale(), 0, 2))
            ->parentNode
            ->parentNode;

        foreach ($journal->getName(null) as $locale => $title) {
            if ($locale == $journal->getPrimaryLocale()) continue;
            $journalTitleGroupElement->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang',substr($locale, 0, 2))
                ->parentNode
                ->appendChild($this->createElement('trans-title',htmlspecialchars($title)))
                ->parentNode
                ->parentNode
                ->parentNode;
        }
        //Include journal abbreviation titles
        foreach ($journal->getData('abbreviation') as $locale => $abbrevTitle) {
            $journalTitleGroupElement->appendChild($this->createElement('abbrev-journal-title',$abbrevTitle))
                ->setAttribute('xml:lang',substr($locale, 0, 2))
                ->parentNode
                ->parentNode;
        }
        return $journalTitleGroupElement;
    }

    /**
     * create xml article-meta DOMNode
     * @param Submission $submission
     * @param Journal $journal
     * @param Section $section
     * @param $request
     * @param Issue $issue
     * @param Article $article
     * @return \DOMNode
     */
    function createArticleMeta(Submission $submission, Journal $journal, Section $section, Issue $issue, $request, Article $article)
    {
        // create element article-categories
        $articleCategoriesElement = $this->createArticleCategories($journal, $section);
        // create element article-meta
        $articleMetaElement = $this->appendChild($this->createElement('article-meta'))
            ->appendChild($this->createElement('article-id',$submission->getId()))
            ->setAttribute('pub-id-type','publisher-id')
            ->parentNode
            ->parentNode
            ->appendChild($articleCategoriesElement)
            ->parentNode
            ->appendChild($this->createElement('title-group'))
            ->appendChild($this->createElement('article-title',$article->mapHtmlTagsForTitle($submission->getCurrentPublication()->getLocalizedTitle(null, 'html'))))
            ->setAttribute('xml:lang',substr($submission->getLocale()=== null?'':$submission->getLocale(), 0, 2))
            ->parentNode
            ->parentNode
            ->parentNode;

        // create element subtitle
        if (!empty($subtitle = $article->mapHtmlTagsForTitle($submission->getCurrentPublication()->getLocalizedSubTitle(null, 'html')))) {
            $articleMetaElement
                ->lastChild
                ->appendChild($this->createElement('subtitle',$subtitle))
                ->setAttribute('xml:lang',substr($submission->getLocale()=== null?'':$submission->getLocale(), 0, 2));
        }

        $translatedTitle='';
        // Include translated submission titles
        foreach ($submission->getCurrentPublication()->getTitles('html') as $locale => $title) {
            if ($locale == $submission->getLocale()) {
                continue;
            }

            if (trim($translatedTitle = $article->mapHtmlTagsForTitle($submission->getCurrentPublication()->getLocalizedTitle($locale, 'html'))) === '') {
                continue;
            }
            $articleMetaElement
                ->lastChild
                ->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang',substr($locale, 0, 2))
                ->parentNode
                ->appendChild($this->createElement('trans-title',$translatedTitle));

            if (!empty($translatedSubTitle = $article->mapHtmlTagsForTitle($submission->getCurrentPublication()->getLocalizedSubTitle($locale, 'html')))) {
                $articleMetaElement
                    ->lastChild
                    ->lastChild
                    ->appendChild($this->createElement('trans-subtitle',$translatedSubTitle));
            }
        }
        // create element contrib-group
        $contribGroup = $this->createArticleContribGroup($submission);

        // Include authors
        $affiliations = $contribGroup['affiliations'];

        // append element contrib-group to article-meta
        $articleMetaElement->appendChild($contribGroup['contribGroupElement']);

        foreach ($affiliations as $affiliationToken => $affiliation) {
            // create element aff
            $articleMetaElement
                ->appendChild($this->createElement('aff'))
                ->setAttribute('id',$affiliationToken)
                ->parentNode
                ->appendChild($this->createElement('institution',htmlspecialchars($affiliation)))
                ->setAttribute('content-type' , 'orgname');
        }

        $datePublished = $submission->getDatePublished();
        if (!$datePublished) $datePublished = $issue->getDatePublished();
        if ($datePublished) $datePublished = strtotime($datePublished);

        //include pub dates
        if ($submission->getDatePublished()){
            // create element pub-date
            $articleMetaElement
                ->appendChild($this->createElement('pub-date'))
                ->setAttribute('date-type', 'pub')
                ->parentNode
                ->setAttribute('publication-format','epub')
                ->parentNode
                ->appendChild($this->createElement('day',strftime('%d', (int)$datePublished)))
                ->parentNode
                ->appendChild($this->createElement('month',strftime('%m', (int)$datePublished)))
                ->parentNode
                ->appendChild($this->createElement('year',strftime('%Y', (int)$datePublished)));
        }
        // Include page info, if available and parseable.
        $matches = $pageCount = null;
        if (PKPString::regexp_match_get('/^(\d+)$/', $submission->getPages(), $matches)) {
            $matchedPage = htmlspecialchars($matches[1]);
            // create element fpage lpage
            $articleMetaElement
                ->appendChild($this->createElement('fpage',$matchedPage))
                ->parentNode
                ->appendChild($this->createElement('lpage',$matchedPage));
            $pageCount = 1;
        } elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)$/', $submission->getPages(), $matches)) {
            $matchedPage = htmlspecialchars($matches[1]);
            // create element fpage lpage
            $articleMetaElement
                ->appendChild($this->createElement('fpage',$matchedPage))
                ->parentNode
                ->appendChild($this->createElement('lpage',$matchedPage));
            $pageCount = 1;
        } elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)[ ]?-[ ]?([Pp][Pp]?[.]?[ ]?)?(\d+)$/', $submission->getPages(), $matches)) {
            $matchedPageFrom = htmlspecialchars($matches[1]);
            $matchedPageTo = htmlspecialchars($matches[3]);
            // create element fpage lpage
            $articleMetaElement
                ->appendChild($this->createElement('fpage',$matchedPageFrom))
                ->parentNode
                ->appendChild($this->createElement('lpage',$matchedPageTo));
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        } elseif (PKPString::regexp_match_get('/^(\d+)[ ]?-[ ]?(\d+)$/', $submission->getPages(), $matches)) {
            $matchedPageFrom = htmlspecialchars($matches[1]);
            $matchedPageTo = htmlspecialchars($matches[2]);
            // create element fpage lpage
            $articleMetaElement
                ->appendChild($this->createElement('fpage',$matchedPageFrom))
                ->parentNode
                ->appendChild($this->createElement('lpage',$matchedPageTo));
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        }

        $copyrightYear = $submission->getCopyrightYear();
        $copyrightHolder = $submission->getLocalizedCopyrightHolder();
        $licenseUrl = $submission->getLicenseURL();
        $ccBadge = Application::get()->getCCLicenseBadge($licenseUrl, $submission->getLocale())=== null?'':Application::get()->getCCLicenseBadge($licenseUrl, $submission->getLocale());
        if ($copyrightYear || $copyrightHolder || $licenseUrl || $ccBadge){
            // create element permissions
            $articleMetaElement->appendChild($this->createElement('permissions'));
            if($copyrightYear||$copyrightHolder){
                // create element copyright-statement
                $articleMetaElement
                    ->lastChild
                    ->appendChild(
                        $this->createElement(
                            'copyright-statement',
                            htmlspecialchars(__('submission.copyrightStatement', ['copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder])))
                    );
            }
            if($copyrightYear){
                // create element copyright-year
                $articleMetaElement
                    ->lastChild
                    ->appendChild(
                        $this->createElement(
                            'copyright-year',
                            htmlspecialchars($copyrightYear)
                        )
                    );
            }
            if($copyrightHolder){
                // create element copyright-holder
                $articleMetaElement
                    ->lastChild
                    ->appendChild(
                        $this->createElement(
                            'copyright-holder',
                            htmlspecialchars($copyrightHolder)
                        )
                    );
            }
            if($licenseUrl) {
                // create element license
                $articleMetaElement
                    ->lastChild
                    ->appendChild(
                        $this->createElement(
                            'license',
                            htmlspecialchars($copyrightHolder)
                        )
                    )
                    ->setAttribute('xlink:href' , htmlspecialchars($licenseUrl));
                if($ccBadge){
                    // create element license-p
                    $articleMetaElement
                        ->lastChild
                        ->lastChild
                        ->appendChild(
                            $this->createElement(
                                'license-p',
                                strip_tags($ccBadge)
                            )
                        )
                        ->setAttribute('xlink:href' , htmlspecialchars($licenseUrl));
                }
            }
        }

        // create element self-uri
        $articleMetaElement
            ->appendChild($this->createElement('self-uri', strip_tags($ccBadge)))
            ->setAttribute(
                'xlink:href' ,
                htmlspecialchars(
                    $request->url($journal->getPath(), 'article', 'view', $submission->getBestArticleId()) != null && $request->url($journal->getPath(),
                        'article', 'view', $submission->getBestArticleId()))
            );

        $submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
        foreach ($submissionKeywordDao->getKeywords($submission->getCurrentPublication()->getId(), $journal->getSupportedLocales()) as $locale => $keywords) {
            if (empty($keywords)) continue;
            // create element kwd-group
            $articleMetaElement
                ->appendChild($this->createElement('kwd-group', strip_tags($ccBadge)))
                ->setAttribute('xml:lang' , substr($locale, 0, 2));
            foreach ($keywords as $keyword) {
                // create element kwd
                $articleMetaElement
                    ->lastChild
                    ->appendChild($this->createElement('kwd',htmlspecialchars($keyword)))
                    ->setAttribute('xml:lang' , substr($locale, 0, 2));
            }
        }

        if(isset($pageCount)){
            // create element counts
            $articleMetaElement
                ->appendChild($this->createElement('counts'))
                ->appendChild($this->createElement('page-count'))
                ->setAttribute('count',(int) $pageCount);
        }
        // create element custom-meta-group
        $articleMetaElement->appendChild($this->createElement('custom-meta-group'));
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
            // create element custom-meta-group
            $articleMetaElement
                ->lastChild
                ->appendChild($this->createElement('custom-meta'))
                ->appendChild($this->createElement('meta-name','production-ready-file-url'))
                ->appendChild($this->createElement('meta-value'))
                ->appendChild($this->createElement('ext-link'))
                ->setAttribute('ext-link-type','uri')
                ->parentNode
                ->setAttribute('xlink:href',htmlspecialchars($sourceFileUrl));
        }
        return $articleMetaElement;
    }

    /**
     * @param Journal $journal
     * @param Section $section
     */
    public function createArticleCategories(Journal $journal, Section $section)
    {
        return $this->appendChild($this->createElement('article-categories'))
            ->appendChild($this->createElement('subj-group'))
            ->setAttribute('xml:lang' ,$journal->getPrimaryLocale())
            ->parentNode
            ->setAttribute('subj-group-type','heading')
            ->parentNode
            ->appendChild($this->createElement('subject',htmlspecialchars($section->getLocalizedTitle())))
            ->parentNode
            ->parentNode;
    }

    /**
     * create article-meta contrib-group element
     * @param Submission $submission
     * @return array
     * @throws \DOMException
     */
    public function createArticleContribGroup(Submission $submission): array
    {
        // create element contrib-group
        $contribGroupElement = $this->appendChild($this->createElement('contrib-group'))
            ->setAttribute('content-type','author')
            ->parentNode;

        // Include authors
        $affiliations = [];
        foreach ($submission->getCurrentPublication()->getData('authors') as $author) {
            $affiliation = $author->getLocalizedAffiliation();
            $affiliationToken = array_search($affiliation, $affiliations);
            if ($affiliation && !$affiliationToken) {
                $affiliationToken = 'aff-' . (count($affiliations) + 1);
                $affiliations[$affiliationToken] = $affiliation;
            }
            $surname = method_exists($author, 'getLastName') ? $author->getLastName() : $author->getLocalizedFamilyName();

            // create element contrib
            $contribGroupElement->appendChild($this->createElement('contrib'));
            if($author->getPrimaryContact()){
                $contribGroupElement
                    ->setAttribute('corresp','yes')
                    ->parentNode;
            }
            // If using the CRediT plugin, credit roles may be available.
            $creditPlugin = PluginRegistry::getPlugin('generic', 'creditplugin');
            if ($creditPlugin && $creditPlugin->getEnabled()) {
                $contributorRoles = $author->getData('creditRoles') ?? [];
                $creditRoles = $creditPlugin->getCreditRoles();
                foreach ($contributorRoles as $role) {
                    $roleName = $creditRoles[$role];
                    // create element role
                    $contribGroupElement
                        ->lastChild
                        ->appendChild($this->createElement('role',htmlspecialchars($roleName)))
                        ->setAttribute('vocab-identifier','https://credit.niso.org/')
                        ->parentNode
                        ->setAttribute('vocab-term',htmlspecialchars($roleName))
                        ->parentNode
                        ->setAttribute('vocab-term-identifier' ,htmlspecialchars($role));
                }
            }

            if ($author->getOrcid()) {
                $contribGroupElement
                    ->lastChild
                    ->appendChild($this->createElement('contrib-id',htmlspecialchars($author->getOrcid())))
                    ->setAttribute('contrib-id-type' , 'orcid');
            }
            // create element name
            $contribGroupElement->lastChild->appendChild($this->createElement('name',htmlspecialchars($author->getOrcid())))
                ->setAttribute('name-style' , 'western');
            if ($surname != '') {
                // create element surname
                $contribGroupElement
                    ->lastChild
                    ->lastChild
                    ->appendChild(
                        $this->createElement('surname', htmlspecialchars($surname))
                    );
            }
            // create element given-names
            $contribGroupElement
                ->lastChild
                ->lastChild
                ->appendChild(
                $this->createElement(
                    'given-names',
                    htmlspecialchars(method_exists($author, 'getFirstName') ? $author->getFirstName() : $author->getLocalizedGivenName()) . (((method_exists($author, 'getMiddleName') && $s = $author->getMiddleName()) != '') ? " $s" : '')
                    )
                );
            // create element email
            $contribGroupElement
                ->lastChild
                ->appendChild(
                    $this->createElement('email',  htmlspecialchars($author->getEmail()))
                );
            if ($affiliationToken) {
                // create element xref
                $contribGroupElement
                    ->lastChild
                    ->appendChild($this->createElement('xref'))
                    ->setAttribute('ref-type' , 'aff')
                    ->parentNode
                    ->setAttribute( 'rid' ,$affiliationToken);
            }
            if (($s = $author->getUrl()) != '') {
                // create element uri
                $contribGroupElement
                    ->lastChild
                    ->appendChild($this->createElement('uri',htmlspecialchars($s)))
                    ->setAttribute('ref-type' , 'aff')
                    ->parentNode
                    ->setAttribute( 'rid' ,$affiliationToken);
            }
        }
        return ['contribGroupElement'=>$contribGroupElement,'affiliations'=>$affiliations];
    }
}
