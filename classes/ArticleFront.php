<?php

/**
 * @file ArticleFront.php
 *
 * Copyright (c) 2003-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS XML article front element
 */

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\section\Section;
use APP\submission\Submission;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\author\creditRole\CreditRoleDegree;
use PKP\core\PKPApplication;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\decision\Decision;
use PKP\facades\Locale;
use PKP\galley\Galley;
use PKP\i18n\LocaleConversion;
use PKP\plugins\PluginRegistry;
use PKP\publication\enums\UpdateType;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\userGroup\UserGroup;

class ArticleFront extends DOMDocument
{
    /**
     * Create article front element
     */
    public function create(
        Journal $journal,
        Submission $submission,
        Section $section,
        ?Issue $issue,
        PKPRequest $request,
        ?Publication $workingPublication = null
    ): DOMNode {
        $frontNode = $this->appendChild($this->createElement('front'));
        $frontNode->appendChild($this->createJournalMeta($journal, $request));

        if ($workingPublication) {
            $frontNode->appendChild(
                $this->createArticleMeta(
                    $submission,
                    $journal,
                    $section,
                    $issue,
                    $request,
                    $workingPublication
                )
            );

            // <notes> is a sibling of <article-meta> within <front> (front-model: journal-meta, article-meta, notes?).
            $notesElement = JatsHelper::htmlToJatsElement(
                $this,
                'notes',
                (string) $workingPublication->getLocalizedData('summaryOfChanges', $workingPublication->getData('locale')),
                ['notes-type' => 'update-notice'],
                allowParagraphs: true
            );
            if ($notesElement) {
                $frontNode->appendChild($notesElement);
            }
        }

        return $frontNode;
    }

    /**
     * Create xml journal-meta DOMNode
     */
    public function createJournalMeta(Journal $journal, PKPRequest $request): DOMNode
    {
        $journalMetaElement = $this->appendChild($this->createElement('journal-meta'));

        $journalMetaElement->appendChild($this->createElement('journal-id'))
            ->setAttribute('journal-id-type', 'ojs')->parentNode
            ->appendChild($this->createTextNode($journal->getPath()))->parentNode;

        if ($abbreviation = $journal->getLocalizedData('abbreviation', $journal->getPrimaryLocale())) {
            $journalMetaElement->appendChild($this->createElement('journal-id'))
                ->setAttribute('journal-id-type', 'publisher')->parentNode
                ->appendChild($this->createTextNode($abbreviation))->parentNode;
        }

        $journalMetaElement->appendChild($this->createJournalMetaJournalTitleGroup($journal));

        // Editorial team (contrib-group)
        $journalMetaElement->appendChild($this->createJournalMetaJournalContribGroup($journal, $request));

        if (!empty($journal->getData('onlineIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getData('onlineIssn')))->parentNode
                ->setAttribute('publication-format', 'electronic');
        }
        if (!empty($journal->getData('printIssn'))) {
            $journalMetaElement->appendChild($this->createElement('issn'))
                ->appendChild($this->createTextNode($journal->getData('printIssn')))->parentNode
                ->setAttribute('publication-format', 'print');
        }

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
                $publisherLocElement->appendChild($this->createElement('country'))
                    ->appendChild($this->createTextNode($publisherCountry));
            }
            if ($publisherUrl) {
                $publisherLocElement->appendChild($this->createElement('uri'))
                    ->appendChild($this->createTextNode($publisherUrl));
            }
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
    public function createJournalMetaJournalTitleGroup(Journal $journal): DOMNode
    {
        $journalTitleGroupElement = $this->appendChild($this->createElement('journal-title-group'));

        $journalTitleGroupElement->appendChild($this->createElement('journal-title'))
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($journal->getPrimaryLocale()))->parentNode
            ->appendChild($this->createTextNode($journal->getName($journal->getPrimaryLocale())));

        foreach ($journal->getName() as $locale => $title) {
            if ($locale == $journal->getPrimaryLocale()) {
                continue;
            }
            $journalTitleGroupElement->appendChild($this->createElement('trans-title-group'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                ->appendChild($this->createElement('trans-title'))->appendChild($this->createTextNode($title));
        }

        // Include journal abbreviation titles
        if (!empty($journal->getData('abbreviation'))) {
            foreach ($journal->getData('abbreviation') as $locale => $abbrevTitle) {
                $journalTitleGroupElement->appendChild($this->createElement('abbrev-journal-title'))
                    ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode
                    ->appendChild($this->createTextNode($abbrevTitle));
            }
        }
        return $journalTitleGroupElement;
    }

    /**
     * Create journal-meta contrib-group element
     */
    public function createJournalMetaJournalContribGroup(Journal $journal, PKPRequest $request): DOMNode
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
    public function createArticleMeta(
        Submission $submission,
        Journal $journal,
        Section $section,
        ?Issue $issue,
        PKPRequest $request,
        Publication $publication
    ): DOMNode|DOMDocument {
        $articleMetaElement = $this->appendChild($this->createElement('article-meta'));

        // Store the publisher-id
        $articleMetaElement->appendChild($this->createElement('article-id'))
            ->setAttribute('pub-id-type', 'publisher-id')->parentNode
            ->appendChild($this->createTextNode($submission->getId()));

        // Store the DOI
        if ($publication->getDoi()) {
            $doi = trim($publication->getDoi());
            $articleMetaElement->appendChild($this->createElement('article-id'))
                ->setAttribute('pub-id-type', 'doi')->parentNode
                ->appendChild($this->createTextNode($doi));
        }

        // Store the OJS publication (version) ID.
        $articleMetaElement->appendChild($this->createElement('article-id'))
            ->setAttribute('pub-id-type', 'publisher-id')->parentNode
            ->setAttribute('specific-use', 'publication')->parentNode
            ->appendChild($this->createTextNode($publication->getId()));

        // Store the article-version
        $versionStage = $publication->getData('versionStage');
        if ($versionStage) {
            $stage = VersionStage::tryFrom($versionStage);
            $versionLabel = $stage?->label('en');

            $versionMajor = $publication->getData('versionMajor');
            $versionMinor = $publication->getData('versionMinor');
            $version = null;
            if ($versionMajor !== null) {
                $version = $versionMajor . '.' . ($versionMinor ?? 0);
            }

            if ($versionLabel && $version) {
                $articleVersionElement = $this->createElement('article-version');
                // Do not include for PMUR as it is not yet part of the JAV standard
                if ($versionStage !== VersionStage::PUBLISHED_MANUSCRIPT_UNDER_REVIEW->value) {
                    $articleVersionElement->setAttribute('vocab', 'JAV');
                    $articleVersionElement->setAttribute('vocab-identifier', 'http://www.niso.org/publications/rp/RP-8-2008.pdf');
                    $articleVersionElement->setAttribute('vocab-term', $versionLabel);
                }
                $articleVersionElement->setAttribute('article-version-type', $versionStage);
                $articleVersionElement->appendChild($this->createTextNode($version));
                $articleMetaElement->appendChild($articleVersionElement);
            }
        }

        // Store the article-categories
        $articleMetaElement->appendChild($this->createElement('article-categories'))
            ->appendChild($this->createElement('subj-group'))
            ->setAttribute('xml:lang', LocaleConversion::toBcp47($journal->getPrimaryLocale()))->parentNode
            ->setAttribute('subj-group-type', 'heading')->parentNode
            ->appendChild($this->createElement('subject'))
            ->appendChild($this->createTextNode($section->getLocalizedData('title', $journal->getPrimaryLocale())));

        $titleGroupElement = $articleMetaElement->appendChild($this->createElement('title-group'));

        $titleGroupElement->appendChild(JatsHelper::htmlToJatsElement(
            $this,
            'article-title',
            $publication->getLocalizedTitle(null, 'html'),
            ['xml:lang' => LocaleConversion::toBcp47($submission->getData('locale'))]
        ));

        if (!empty($subtitle = $publication->getLocalizedSubTitle(null, 'html'))) {
            $titleGroupElement->appendChild(JatsHelper::htmlToJatsElement(
                $this,
                'subtitle',
                $subtitle,
                ['xml:lang' => LocaleConversion::toBcp47($submission->getData('locale'))]
            ));
        }

        // Include translated submission titles
        foreach ($publication->getTitles('html') as $locale => $title) {
            if ($locale == $submission->getData('locale')) {
                continue;
            }

            $translatedTitle = $publication->getLocalizedTitle($locale, 'html');
            if (trim($translatedTitle) === '') {
                continue;
            }

            $transTitleGroupElement = $this->createElement('trans-title-group');
            $transTitleGroupElement->appendChild(JatsHelper::htmlToJatsElement($this, 'trans-title', $translatedTitle));
            if (!empty($translatedSubTitle = $publication->getLocalizedSubTitle($locale, 'html'))) {
                $transTitleGroupElement->appendChild(JatsHelper::htmlToJatsElement($this, 'trans-subtitle', $translatedSubTitle));
            }
            $titleGroupElement->appendChild($transTitleGroupElement)
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode;
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
                    ->setAttribute('institution-id-type', 'ror');
            } else {
                $affNode->appendChild($this->createElement('institution'))
                    ->appendChild($this->createTextNode($institution['name']))->parentNode
                    ->setAttribute('content-type', 'orgname');
            }
        }

        $competingInterests = $contribGroup['competingInterests'];
        $correspondingAuthor = $contribGroup['correspondingAuthor'];
        $correspondingAuthorEmail = $correspondingAuthor
            && $correspondingAuthor->getData('contributorType') !== ContributorType::ANONYMOUS->getName()
            ? $correspondingAuthor->getEmail()
            : null;
        if ($correspondingAuthorEmail || count($competingInterests) > 0) {
            $authorNotesNode = $this->createElement('author-notes');

            if ($correspondingAuthorEmail) {
                $correspNode = $authorNotesNode->appendChild($this->createElement('corresp'));
                $correspNode->setAttribute('id', 'corresp-1');
                $correspNode->appendChild($this->createElement('email'))
                    ->appendChild($this->createTextNode($correspondingAuthorEmail));
            }

            foreach ($competingInterests as $id => $competingInterest) {
                $authorNotesNode->appendChild(JatsHelper::htmlToJatsElement(
                    $this,
                    'fn',
                    $competingInterest['coi-statement'],
                    ['fn-type' => 'coi-statement', 'id' => $id],
                    allowParagraphs: true
                ));
            }
            $articleMetaElement->appendChild($authorNotesNode);
        }

        // Both dates are strings, so each is resolved to a timestamp before use
        if ($datePublished = $publication->getData('datePublished')) {
            $datePublished = strtotime($datePublished);
        } elseif ($datePublished = $issue?->getDatePublished()) {
            $datePublished = strtotime($datePublished);
        }

        // Include pub dates
        if ($datePublished) {
            $pubDateElement = $articleMetaElement->appendChild($this->createElement('pub-date'))
                ->setAttribute('date-type', 'pub')->parentNode
                ->setAttribute('publication-format', 'electronic')->parentNode
                ->setAttribute('iso-8601-date', date('Y-m-d', $datePublished))->parentNode;

            $pubDateElement->appendChild($this->createElement('day'))
                ->appendChild($this->createTextNode(date('d', $datePublished)));

            $pubDateElement->appendChild($this->createElement('month'))
                ->appendChild($this->createTextNode(date('m', $datePublished)));

            $pubDateElement->appendChild($this->createElement('year'))
                ->appendChild($this->createTextNode(date('Y', $datePublished)));
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

            // Store the issue number and issue id
            if ($issue->getShowNumber()) {
                $articleMetaElement
                    ->appendChild($this->createElement('issue'))
                    ->appendChild($this->createTextNode($issue->getNumber()));
                $articleMetaElement
                    ->appendChild($this->createElement('issue-id'))
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

        // Include page info, if available and parseable.
        $pageCount = null;
        if ($publication->getData('pages')) {
            $matches = null;
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
        } elseif ($articleNumber = $publication->getData('articleNumber')) {
            $articleMetaElement->appendChild($this->createElement('elocation-id'))
                ->appendChild($this->createTextNode($articleNumber));
        }

        // Supplementary galley files (JATS puts this before history/pub-history/permissions/self-uri)
        $galleys = $publication->getData('galleys');
        $supplementaryGalleyFiles = [];
        if (!empty($galleys)) {
            foreach ($galleys as $galley) { /** @var Galley $galley */
                $galleyFile = $this->getSupplementaryGalleyFile($galley);
                if (!$galleyFile) {
                    continue;
                }
                $supplementaryGalleyFiles[$galley->getId()] = $galleyFile;
                $suppNode = $articleMetaElement->appendChild($this->createElement('supplementary-material'));
                $suppNode->setAttribute('xlink:href', $this->buildGalleyDownloadUrl($request, $journal, $submission, $galley));
                if ($galley->getLabel()) {
                    $suppNode->setAttribute('xlink:title', $galley->getLabel());
                }
                if ($fileType = $galleyFile->getData('mimetype')) {
                    $suppNode->setAttribute('mimetype', $fileType);
                }
            }
        }

        // Processing dates go in <history>, which PMC reads: the date the submission was
        // received, and the date it was accepted where an editor recorded that decision.
        if (($dateSubmitted = $submission->getData('dateSubmitted')) !== null) {
            $historyElement = $articleMetaElement->appendChild($this->createElement('history'));
            $historyElement->appendChild($this->createHistoryDate('received', $dateSubmitted));

            // The latest acceptance, should the submission have been accepted more than once
            $acceptDecision = Repo::decision()->getCollector()
                ->filterBySubmissionIds([$submission->getId()])
                ->getMany()
                ->filter(fn (Decision $decision) => $decision->getData('decision') === Decision::ACCEPT)
                ->sortBy(fn (Decision $decision) => $decision->getData('dateDecided'))
                ->last();
            if ($acceptDecision?->getData('dateDecided')) {
                $historyElement->appendChild($this->createHistoryDate('accepted', $acceptDecision->getData('dateDecided')));
            }

            // The received event is also kept in <pub-history>, as OAI harvesters have had it
            $date = Carbon::createFromTimestamp(strtotime($dateSubmitted));
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
        $copyrightHolder = $publication->getLocalizedData('copyrightHolder', $publication->getData('locale'));
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
            // NISO ALI free-to-read indicator (JATS4R permissions, PMC tagging guidelines)
            if ($this->isFreeToRead($journal, $issue, $publication)) {
                $permissionsElement->appendChild($this->createElement('ali:free_to_read'));
            }
            if ($licenseUrl) {
                $licenseElement = $permissionsElement->appendChild($this->createElement('license'));
                if ($ccBadge) {
                    // The CC badge locale string is "<a...><img.../></a><p>prose sentence</p>";
                    // keep only the prose sentence - the image-badge anchor has no text content to preserve.
                    $ccProse = preg_match('#<p>(.*)</p>#s', $ccBadge, $matches) ? $matches[1] : strip_tags($ccBadge, '<a>');
                    $contentType = str_contains($licenseUrl, '/by-nc') ? 'licensed non-commercial use' : 'open-access';
                    $licenseElement->appendChild(JatsHelper::htmlToJatsElement($this, 'license-p', $ccProse, ['content-type' => $contentType]));
                }
                // The machine-readable licence URL goes in ali:license_ref, and must match any
                // licence link in license-p exactly. The badge prose links the canonical URL
                // for the licence, so it is used wherever the URL appears.
                $licenseRef = $licenseUrl;
                foreach ($licenseElement->getElementsByTagName('ext-link') as $extLink) {
                    if ($href = $extLink->getAttribute('xlink:href')) {
                        $licenseRef = $href;
                        break;
                    }
                }
                $licenseElement->setAttribute('xlink:href', $licenseRef);
                $licenseRefElement = $this->createElement('ali:license_ref');
                $licenseRefElement->appendChild($this->createTextNode($licenseRef));
                $licenseElement->insertBefore($licenseRefElement, $licenseElement->firstChild);
            }
        }

        $router = $request->getRouter();
        $dispatcher = $router->getDispatcher();

        $url = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $journal->getPath(),
            'article',
            'view',
            [$publication->getData('urlPath') ?? $submission->getId()],
            null,
            null,
            true,
            ''
        );

        $articleMetaElement
            ->appendChild($this->createElement('self-uri'))
            ->setAttribute('xlink:href', $url);

        if (!empty($galleys)) {
            foreach ($galleys as $galley) { /** @var Galley $galley */
                if (isset($supplementaryGalleyFiles[$galley->getId()])) {
                    continue;
                }
                $uriNode = $articleMetaElement->appendChild($this->createElement('self-uri'));
                $uriNode->setAttribute('xlink:href', $this->buildGalleyDownloadUrl($request, $journal, $submission, $galley));
                if ($galley->getLocale()) {
                    $uriNode->setAttribute('xml:lang', LocaleConversion::toBcp47($galley->getLocale()));
                }
                if ($galley->getLabel()) {
                    $uriNode->setAttribute('xlink:title', $galley->getLabel());
                }
                if (!$galley->getData('urlRemote')) {
                    $fileType = $galley->getData('submissionFileId')
                        ? Repo::submissionFile()->get((int) $galley->getData('submissionFileId'))?->getData('mimetype')
                        : null;

                    if ($fileType) {
                        $uriNode->setAttribute('content-type', $fileType);
                    }
                }
            }
        }

        // Link the immediately preceding published version as a related article
        $versionRelation = Repo::publication()->getVersionRelation($publication, $submission, $journal);
        if ($versionRelation) {
            $relatedArticleElement = $articleMetaElement->appendChild($this->createElement('related-article'));
            $relatedArticleElement->setAttribute('related-article-type', $this->versionRelatedArticleType($versionRelation));
            $relatedArticleElement->setAttribute('id', 'ra1');
            $relatedArticleElement->setAttribute('specific-use', $versionRelation->relationType->value);
            if ($versionRelation->doi) {
                $relatedArticleElement->setAttribute('ext-link-type', 'doi');
                $relatedArticleElement->setAttribute('xlink:href', $versionRelation->doi);
            } else {
                $relatedArticleElement->setAttribute('ext-link-type', 'uri');
                $relatedArticleElement->setAttribute('xlink:href', $dispatcher->url(
                    $request,
                    PKPApplication::ROUTE_PAGE,
                    $journal->getPath(),
                    'article',
                    'view',
                    [$submission->getBestId(), 'version', $versionRelation->publicationId],
                    null,
                    null,
                    true,
                    ''
                ));
            }
            if ($versionRelation->versionString) {
                $relatedArticleElement->appendChild($this->createTextNode($versionRelation->versionString));
            }
        }

        // Add abstract
        $abstracts = $publication->getData('abstract');
        $transAbstracts = [];
        if (!empty($abstracts)) {
            foreach ($abstracts as $locale => $abstract) {
                if (empty($abstract)) {
                    continue;
                }
                $abstractElement = $this->createAbstractElement($articleMetaElement, $submission, $locale, $abstract);
                if ($abstractElement?->nodeName === 'trans-abstract') {
                    $transAbstracts[] = $abstractElement;
                }
            }
        }

        // Add plain-language summary
        $plainLanguageSummaries = $publication->getData('plainLanguageSummary');
        if (!empty($plainLanguageSummaries)) {
            foreach ($plainLanguageSummaries as $locale => $plainLanguageSummary) {
                if (empty($plainLanguageSummary)) {
                    continue;
                }
                $plainLanguageSummaryElement = $this->createAbstractElement($articleMetaElement, $submission, $locale, $plainLanguageSummary, 'plain-language-summary');
                if ($plainLanguageSummaryElement?->nodeName === 'trans-abstract') {
                    $transAbstracts[] = $plainLanguageSummaryElement;
                }
            }
        }

        // Translations follow every abstract in the submission's locale
        foreach ($transAbstracts as $transAbstractElement) {
            $articleMetaElement->appendChild($transAbstractElement);
        }

        // Fetch keyword data from the publication object, this will only include the name attribute.
        $keywordVocabs = collect($publication->getData('keywords'))
            ->map(
                fn (array $items): array => collect($items)
                    ->pluck('name')
                    ->all()
            )
            ->all();

        foreach ($keywordVocabs as $locale => $keywords) {
            if (empty($keywords)) {
                continue;
            }

            $kwdGroupElement = $articleMetaElement
                ->appendChild($this->createElement('kwd-group'))
                ->setAttribute('xml:lang', LocaleConversion::toBcp47($locale))->parentNode;

            $kwdGroupElement->appendChild($this->createElement('title'))
                ->appendChild($this->createTextNode(__('common.keywords', [], $locale)));

            foreach ($keywords as $keyword) {
                $kwdGroupElement
                    ->appendChild($this->createElement('kwd'))
                    ->appendChild($this->createTextNode($keyword));
            }
        }

        // Funding data
        $funders = $submission->getData('funders');
        $fundingStatement = $publication->getData('fundingStatement');
        if ($funders->isNotEmpty() || !empty($fundingStatement)) {
            $fundingGroupNode = $this->createElement('funding-group');
            foreach ($funders as $i => $funder) {
                $awardGroupNode = $this->createElement('award-group');
                $awardGroupNode->setAttribute('id', 'ag' . $i);

                $fundingSourceNode = $this->createElement('funding-source');
                $institutionWrapNode = $this->createElement('institution-wrap');

                if (!empty($funder->ror)) {
                    $institutionIdNode = $this->createElement('institution-id', $funder->ror);
                    $institutionIdNode->setAttribute('institution-id-type', 'ror');
                    $institutionWrapNode->appendChild($institutionIdNode);
                }

                $institutionWrapNode->appendChild($this->createElement('institution', $funder->getLocalizedData('name', $locale)));
                $fundingSourceNode->appendChild($institutionWrapNode);
                $awardGroupNode->appendChild($fundingSourceNode);

                if (!empty($funder->grants)) {
                    foreach ($funder->grants as $grant) {
                        // Use grant DOI as award-id if available, otherwise fall back to grant number
                        // In JATS 1.3 the @award-id-type attribute can specify the type of identifier (e.g. 'doi', 'grant_number', etc.).
                        $awardId = $grant['grantDoi'] ?? $grant['grantNumber'] ?? null;
                        if ($awardId) {
                            $awardIdNode = $this->createElement('award-id', $awardId);
                            $awardGroupNode->appendChild($awardIdNode);
                        }
                    }
                }

                $fundingGroupNode->appendChild($awardGroupNode);
            }
            if (!empty($fundingStatement)) {
                foreach ($fundingStatement as $locale => $statement) {
                    $lang = LocaleConversion::toBcp47($locale);
                    $fundingGroupNode->appendChild(JatsHelper::htmlToJatsElement(
                        $this,
                        'funding-statement',
                        $statement,
                        ['xml:lang' => $lang]
                    ));
                }
            }
            $articleMetaElement->appendChild($fundingGroupNode);
        }

        if ($pageCount) {
            $articleMetaElement
                ->appendChild($this->createElement('counts'))
                ->appendChild($this->createElement('page-count'))
                ->setAttribute('count', $pageCount);
        }

        $coverUrl = $issue?->getLocalizedCoverImageUrl();
        $layoutFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
            ->getMany();

        if (!empty($coverUrl) || $layoutFiles->isNotEmpty()) {
            $customMetaGroupElement = $articleMetaElement->appendChild($this->createElement('custom-meta-group'));

            // Issue cover page
            if ($coverUrl) {
                $customMetaElement = $customMetaGroupElement->appendChild($this->createElement('custom-meta'));
                $metaNameElement = $customMetaElement->appendChild($this->createElement('meta-name'));
                $metaNameElement->appendChild($this->createTextNode('issue-cover'));
                $metaValueElement = $customMetaElement->appendChild($this->createElement('meta-value'));
                $inlineGraphicElement = $metaValueElement->appendChild($this->createElement('inline-graphic'));
                $inlineGraphicElement->setAttribute('xlink:href', $coverUrl);
            }

            foreach ($layoutFiles as $layoutFile) {
                $sourceFileUrl = $request->getDispatcher()->url(
                    $request,
                    PKPApplication::ROUTE_PAGE,
                    null,
                    'jatsTemplate',
                    'download',
                    [$journal->getPath()],
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
        }

        return $articleMetaElement;
    }

    /**
     * A history date: day, month and year as integers, with the date in ISO 8601 form on
     * the element.
     */
    protected function createHistoryDate(string $dateType, string $date): DOMElement
    {
        $date = Carbon::parse($date);
        $dateElement = $this->createElement('date');
        $dateElement->setAttribute('date-type', $dateType);
        $dateElement->setAttribute('iso-8601-date', $date->toDateString());
        $dateElement->appendChild($this->createElement('day', (string) $date->day));
        $dateElement->appendChild($this->createElement('month', (string) $date->month));
        $dateElement->appendChild($this->createElement('year', (string) $date->year));

        return $dateElement;
    }

    /**
     * Whether the article is available without access barriers: published in an open access
     * journal, or as an open access issue or article in a subscription journal.
     */
    protected function isFreeToRead(Journal $journal, ?Issue $issue, Publication $publication): bool
    {
        $publishingMode = $journal->getData('publishingMode');

        return ($publishingMode !== null && (int) $publishingMode === Journal::PUBLISHING_MODE_OPEN)
            || (int) $issue?->getAccessStatus() === Issue::ISSUE_ACCESS_OPEN
            || (int) $publication->getData('accessStatus') === Submission::ARTICLE_ACCESS_OPEN;
    }

    /**
     * Get a galley's underlying submission file if its genre is marked as supplementary, else null.
     */
    protected function getSupplementaryGalleyFile(Galley $galley): ?SubmissionFile
    {
        if (!$galley->getData('submissionFileId')) {
            return null;
        }
        $galleyFile = Repo::submissionFile()->get((int) $galley->getData('submissionFileId'));
        if (!$galleyFile) {
            return null;
        }
        $genreDao = DAORegistry::getDAO('GenreDAO'); /** @var GenreDAO $genreDao */
        $genre = $genreDao->getById($galleyFile->getData('genreId'));
        return ($genre && $genre->getSupplementary()) ? $galleyFile : null;
    }

    /**
     * Build the download URL for a galley
     */
    protected function buildGalleyDownloadUrl(PKPRequest $request, Journal $journal, Submission $submission, Galley $galley): string
    {
        return $request->getRouter()->getDispatcher()->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $journal->getData('urlPath'),
            'article',
            'download',
            [$submission->getBestId(), $galley->getBestGalleyId(), $galley->getData('submissionFileId')],
            urlLocaleForPage: ''
        );
    }

    /**
     * Create article-meta contrib-group element
     */
    public function createArticleContribGroup(Submission $submission, Publication $publication): array
    {
        $submissionLocale = $submission->getData('locale');
        $contribGroupElement = $this->appendChild($this->createElement('contrib-group'));

        // Include authors
        $creditRoleTerms = Repo::creditRole()->getTerms($submissionLocale);
        $affiliations = $institutions = $competingInterests = [];
        $correspondingAuthor = null;
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

            $roleNodes = [];
            foreach ($author->getData('creditRoles') ?? [] as ['role' => $role, 'degree' => $degree]) {
                $roleTerm = $creditRoleTerms['roles'][$role];
                $roleNode = $this->createElement('role');
                $roleNode
                    ->setAttribute('vocab', 'credit')->parentNode
                    ->setAttribute('vocab-identifier', 'https://credit.niso.org/')->parentNode
                    ->setAttribute('vocab-term', $roleTerm)->parentNode
                    ->setAttribute('vocab-term-identifier', $role);
                $degreeValue = CreditRoleDegree::toValue($degree);
                if ($degreeValue && !empty($creditRoleTerms['degrees'][$degreeValue])) {
                    $roleNode->setAttribute('degree-contribution', $creditRoleTerms['degrees'][$degreeValue]);
                }
                $roleNode->appendChild($this->createTextNode($roleTerm));
                $roleNodes[] = $roleNode;
            }

            $contribElement = $contribGroupElement->appendChild($this->createElement('contrib'));
            $contribRoleIds = $author->getContributorRoleIdentifiers();

            // Only one contrib-type may be set for a contributor, so prioritize author and translator roles.
            $contribType = match (true) {
                in_array(ContributorRoleIdentifier::AUTHOR->getName(), $contribRoleIds) => 'author',
                in_array(ContributorRoleIdentifier::TRANSLATOR->getName(), $contribRoleIds) => 'translator',
                default => strtolower($contribRoleIds[0] ?? 'other'),
            };
            $contribElement->setAttribute('contrib-type', $contribType);

            $isCorrespondingAuthor = $publication->getData('primaryContactId') == $author->getId();
            if ($isCorrespondingAuthor) {
                $contribElement->setAttribute('corresp', 'yes');
                $correspondingAuthor = $author;
            }

            $contributorType = $author->getData('contributorType');
            if (
                $contributorType === ContributorType::PERSON->getName() ||
                $contributorType === ContributorType::ORGANIZATION->getName()
            ) {
                if ($contributorType === ContributorType::PERSON->getName()) {
                    if ($author->getOrcid()) {
                        $contribElement->appendChild($this->createElement('contrib-id'))
                            ->setAttribute('contrib-id-type', 'orcid')->parentNode
                            ->setAttribute('authenticated', $author->hasVerifiedOrcid() ? 'true' : 'false')->parentNode
                            ->appendChild($this->createTextNode($author->getOrcid()));
                    }

                    $nameAlternativesElement = $contribElement->appendChild($this->createElement('name-alternatives'));

                    $preferredName = $author->getPreferredPublicName($submissionLocale);
                    if (!empty($preferredName)) {
                        $stringNameElement = $nameAlternativesElement->appendChild($this->createElement('string-name'))
                            ->setAttribute('specific-use', 'display')->parentNode;
                        $stringNameElement->appendChild($this->createTextNode($preferredName));
                    }

                    $nameElement = $nameAlternativesElement->appendChild($this->createElement('name'));

                    if ($surname = $author->getFamilyName($submissionLocale)) {
                        $nameStyle = 'western';
                        $nameElement->appendChild($this->createElement('surname'))
                            ->appendChild($this->createTextNode($surname));
                    } else {
                        $nameStyle = 'given-only';
                    }
                    $nameElement->setAttribute('name-style', $nameStyle);
                    $nameElement->setAttribute('specific-use', 'primary');
                    $nameElement->appendChild($this->createElement('given-names'))
                        ->appendChild($this->createTextNode($author->getGivenName($submissionLocale)));
                }

                if ($contributorType === ContributorType::ORGANIZATION->getName()) {
                    $collabElement = $this->createElement('collab');
                    $collabElement->appendChild($this->createTextNode($author->getLocalizedOrganizationName($submissionLocale)));
                    $contribElement->appendChild($collabElement);
                }

                foreach ((array) $author->getData('biography') as $locale => $bio) {
                    if (empty($bio)) {
                        continue;
                    }
                    $this->appendContributorBiography($contribElement, $bio, LocaleConversion::toBcp47($locale));
                }

                if ($country = $author->getCountry()) {
                    $countryName = Locale::getCountries($submissionLocale)->getByAlpha2($country)?->getLocalName();
                    $contribElement->appendChild($this->createElement('address'))
                        ->appendChild($this->createElement('country'))
                        ->setAttribute('country', $country)->parentNode
                        ->appendChild($this->createTextNode($countryName ?? $country));
                }

                $contribElement->appendChild($this->createElement('email'))
                    ->appendChild($this->createTextNode($author->getEmail()));

                foreach ($roleNodes as $roleNode) {
                    $contribElement->appendChild($roleNode);
                }

                if (($s = $author->getUrl()) != '') {
                    $contribElement
                        ->appendChild($this->createElement('uri'))
                        ->appendChild($this->createTextNode($s));
                }

                foreach ($authorTokenList as $token) {
                    $contribElement->appendChild($this->createElement('xref'))
                        ->setAttribute('ref-type', 'aff')->parentNode
                        ->setAttribute('rid', $token);
                }

                if ($isCorrespondingAuthor && $author->getEmail()) {
                    $contribElement->appendChild($this->createElement('xref'))
                        ->setAttribute('ref-type', 'corresp')->parentNode
                        ->setAttribute('rid', 'corresp-1');
                }

                // Competing interests: a blank statement gets neither a footnote nor a reference to one
                $authorCompetingInterests = (string) $author->getCompetingInterests($submissionLocale);
                if (JatsHelper::hasBlockContent($authorCompetingInterests)) {
                    $competingInterestTokenList = [];
                    $competingInterestsToken = 'con-' . (count($competingInterests) + 1);
                    $competingInterestTokenList[] = $competingInterestsToken;
                    $competingInterests[$competingInterestsToken]['coi-statement'] = $authorCompetingInterests;

                    foreach ($competingInterestTokenList as $token) {
                        $contribElement->appendChild($this->createElement('xref'))
                            ->setAttribute('ref-type', 'author-notes')->parentNode
                            ->setAttribute('rid', $token);
                    }
                }
            } elseif ($contributorType === ContributorType::ANONYMOUS->getName()) {
                $contribElement->appendChild($this->createElement('anonymous'));

                foreach ($roleNodes as $roleNode) {
                    $contribElement->appendChild($roleNode);
                }
            }
        }

        return [
            'contribGroupElement' => $contribGroupElement,
            'institutions' => $institutions,
            'competingInterests' => $competingInterests,
            'correspondingAuthor' => $correspondingAuthor
        ];
    }

    /**
     * Append bio element with HTML converted to JATS.
     */
    protected function appendContributorBiography(DOMElement $contribElement, string $biography, string $locale): void
    {
        $bioElement = JatsHelper::htmlToJatsElement($this, 'bio', $biography, ['xml:lang' => $locale], allowParagraphs: true);
        if ($bioElement) {
            $contribElement->appendChild($bioElement);
        }
    }

    /**
     * Append an <abstract> for the submission's locale, or a <trans-abstract> for any other,
     * holding the HTML converted to JATS paragraphs and inline markup.
     */
    public function createAbstractElement(DOMElement $parentElement, Submission $submission, string $locale, string $html, ?string $abstractType = null): ?DOMElement
    {
        $isTranslation = $locale != $submission->getData('locale');
        $attributes = [];
        if ($abstractType) {
            $attributes['abstract-type'] = $abstractType;
        }
        if ($isTranslation) {
            $attributes['xml:lang'] = LocaleConversion::toBcp47($locale);
        }

        $abstractElement = JatsHelper::htmlToJatsElement(
            $this,
            $isTranslation ? 'trans-abstract' : 'abstract',
            $html,
            $attributes,
            allowParagraphs: true
        );
        if (!$abstractElement) {
            return null;
        }
        $parentElement->appendChild($abstractElement);

        return $parentElement->lastChild;
    }

    /**
     * Map a version relationship to a JATS related-article-type. Relations are backward-only,
     * so the target is always an OLDER version that this record acts on (e.g. a correction names
     * the corrected-article; a retraction the retracted-article; a new version/edition names the
     * updated-article, following PMC's convention for updated/republished articles since it is not
     * captured in the JATS guidelines). The attribute is required, so a version without an
     * update type falls back to updated-article.
     *
     * https://jats.nlm.nih.gov/archiving/tag-library/1.2/attribute/related-article-type.html
     * https://pmc.ncbi.nlm.nih.gov/tagging-guidelines/article/tags/#el-relart
     */
    protected function versionRelatedArticleType(object $versionRelation): string
    {
        return match ($versionRelation->updateType) {
            UpdateType::ADDENDUM => 'addendum',
            UpdateType::CLARIFICATION, UpdateType::CORRECTION,
            UpdateType::CORRIGENDUM, UpdateType::ERRATUM => 'corrected-article',
            UpdateType::EXPRESSION_OF_CONCERN => 'expression-of-concern',
            UpdateType::PARTIAL_RETRACTION => 'partial-retraction',
            UpdateType::RETRACTION, UpdateType::WITHDRAWAL,
            UpdateType::REMOVAL => 'retracted-article',
            default => 'updated-article', // NEW_VERSION, NEW_EDITION, and general fallback
        };
    }
}
