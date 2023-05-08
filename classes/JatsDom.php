<?php

namespace APP\plugins\generic\jatsTemplate\classes;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use PKP\config\Config;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\search\SearchFileParser;
use PKP\submissionFile\SubmissionFile;

class JatsDom extends \DOMDocument
{
    protected array $rootAttributes = [];

    function __construct(&$record)
    {
        parent::__construct('1.0', 'UTF-8');
        $this->rootAttributes = [
        'xmlns:xlink'=>'http://www.w3.org/1999/xlink',
        'xmlns:mml'=>'http://www.w3.org/1998/Math/MathML',
        'xmlns:xsi'=>'http://www.w3.org/2001/XMLSchema-instance'
    ];
        $this->convertToXml($record);
    }

    /**
     * create xml element
     * @param $elementName string
     * @param $elementValue string|null
     * @param array $attributes
     * @return \DOMElement
     * @throws \DOMException
     */
    private function createDomElement(string $elementName, string $elementValue = null, array $attributes = []):\DOMElement
    {
        $element = $this->createElement($elementName, $elementValue);
        foreach ($attributes as $key => $attribute) {
            $element->setAttribute($key,$attribute);
        }
        return $element;
    }

    /**
     * append child element to parent element
     * @param $parent \DOMNode
     * @param $child \DOMNode
     */
    private function appendChildToParent($parent, $child)
    {
        $parent->appendChild($child);
    }

    /**
     * @param $record
     * @return bool|\DOMDocument
     * @throws \DOMException
     */
    public function convertToXml(&$record) :bool|\DOMDocument
    {
        $article =& $record->getData('article');
        $journal =& $record->getData('journal');
        $section =& $record->getData('section');
        $issue =& $record->getData('issue');
        $publication = $article->getCurrentPublication();

        $request = Application::get()->getRequest();

        $datePublished = $article->getDatePublished();
        if (!$datePublished) $datePublished = $issue->getDatePublished();
        if ($datePublished) $datePublished = strtotime($datePublished);
        $this->rootAttributes['xml:lang'] = substr($article->getLocale(), 0, 2);

        // create root element article
        $articleElement = $this->createDomElement('article',null,$this->rootAttributes);

        // create element front
        $front = $this->createDomElement('front');

        // create element journal-meta
        $journalMeta = $this->createJournalMetaSubElements($journal);

        //append element journal-meta to element front
        $this->appendChildToParent($front,$journalMeta);

        // create element article-meta
        $articleMeta = $this->createArticleMetaSubElements($article,$journal,$section,$request);
        //append element article-meta to element front
        $this->appendChildToParent($front,$articleMeta);
        // create element body
        $body = $this->createBodySubElements($article);
        // create element back
        $back = $this->createBackSubElements($publication);
        //append element front,body,back to element article
        $this->appendChildToParent($articleElement,$front);
        $this->appendChildToParent($articleElement,$body);
        $this->appendChildToParent($articleElement,$back);
        return $this->loadXml($this->saveXML($articleElement));
    }

    /**
     * create xml journal-meta DOMNode
     * @param $journal Journal
     * @return \DOMNode
     */
    private function createJournalMetaSubElements($journal):\DOMNode
    {
        // create element journal-meta
        $journalMeta = $this->createDomElement('journal-meta');
        // create element journal-id
        $journalId = $this->createDomElement('journal-id',htmlspecialchars($journal->getPath()),['journal-id-type'=>'ojs']);
        // create element journal-title-group
        $journalTitleGroup = $this->createDomElement('journal-title-group',null,[]);
        // create element journal-title
        $journalTitle = $this->createDomElement('journal-title',htmlspecialchars($journal->getName($journal->getPrimaryLocale())),['xml:lang'=>substr($journal->getPrimaryLocale(), 0, 2)]);
        //append element journal-title to element journal-title-group
        $this->appendChildToParent($journalTitleGroup,$journalTitle);
        // Include translated journal titles
        foreach ($journal->getName(null) as $locale => $title) {
            if ($locale == $journal->getPrimaryLocale()) continue;
            $journalTransTitleGroup = $this->createDomElement('trans-title-group',null,['xml:lang'=>substr($locale, 0, 2)]);
            $journalTransTitle = $this->createDomElement('trans-title',htmlspecialchars($title),[]);
            //append element trans-title to element trans-title-group
            $this->appendChildToParent($journalTransTitleGroup, $journalTransTitle);
            //append element trans-title-group to element journal-title-group
            $this->appendChildToParent($journalTitleGroup,$journalTransTitleGroup);
        }
        // create element publisher
        $publisher = $this->createDomElement('publisher',null,[]);
        // create element publisher-name
        $publisherName = $this->createDomElement('publisher-name',htmlspecialchars($journal->getSetting('publisherInstitution')),[]);
        //append element publisher-name to element publisher
        $this->appendChildToParent($publisher, $publisherName);

        //append element publisher,journal-id,journal-title-group to element journal-meta
        $this->appendChildToParent($journalMeta,$journalId);
        $this->appendChildToParent($journalMeta,$journalTitleGroup);
        $this->appendChildToParent($journalMeta,$publisher);

        // create element issn
        if(!empty($journal->getSetting('onlineIssn'))){
            $issnOnline = $this->createDomElement('issn',htmlspecialchars($journal->getSetting('onlineIssn')),['pub-type'=>'epub']);
            $this->appendChildToParent($journalMeta,$issnOnline);
        }
        if(!empty($journal->getSetting('printIssn'))){
            $issnPrint = $this->createDomElement('issn',htmlspecialchars($journal->getSetting('printIssn')),['pub-type'=>'ppub']);
            $this->appendChildToParent($journalMeta,$issnPrint);
        }
        return $journalMeta;

    }

    /**
     * create xml article-meta DOMNode
     * @param $journal Journal
     * @param $article Article
     * @param $section Section
     * @param $request
     * @return \DOMNode
     * @throws \Exception
     */
    private function createArticleMetaSubElements($article,$journal,$section,$request):\DOMNode
    {
        // create element article-meta
        $articleMeta = $this->createDomElement('article-meta');
        // create element article-id
        $articleId = $this->createDomElement('article-id',$article->getId(),['pub-id-type'=>'publisher-id']);
        //append element article-subj-group to element article-categories
        $this->appendChildToParent($articleMeta,$articleId);
        // create element article-categories
        $articleCategories = $this->createDomElement('article-categories',null,[]);
        // create element article-subj-group
        $subjGroup = $this->createDomElement('subj-group',null,['xml:lang'=>$journal->getPrimaryLocale(),'subj-group-type'=>'heading']);
        // create element article-categories
        $subject = $this->createDomElement('subject',htmlspecialchars($section->getLocalizedTitle()),[]);
        //append element subject to element article-subj-group
        $this->appendChildToParent($subjGroup,$subject);
        //append element article-subj-group to element article-categories
        $this->appendChildToParent($articleCategories,$subjGroup);
        //append element article-categories to element article-meta
        $this->appendChildToParent($articleMeta,$articleCategories);

        // create element title-group
        $titleGroup = $this->createDomElement('title-group',null,[]);
        // create element article-title
        $articleTitle  = $this->createDomElement('article-title',$this->mapHtmlTagsForTitle($article->getCurrentPublication()->getLocalizedTitle(null, 'html')),['xml:lang'=>substr($article->getLocale(), 0, 2)]);
        //append element article-title to element title-group
        $this->appendChildToParent($titleGroup,$articleTitle);
        // create element subtitle
        if (!empty($subtitle = $this->mapHtmlTagsForTitle($article->getCurrentPublication()->getLocalizedSubTitle(null, 'html')))) {
            $subtitleElement = $this->createDomElement('subtitle',$subtitle,['xml:lang'=>substr($article->getLocale(), 0, 2)]);
            //append element subtitle to element title-group
            $this->appendChildToParent($titleGroup,$subtitleElement);
        }

        // Include translated submission titles
        foreach ($article->getCurrentPublication()->getTitles('html') as $locale => $title) {
            if ($locale == $article->getLocale()) {
                continue;
            }

            if (trim($translatedTitle = $this->mapHtmlTagsForTitle($article->getCurrentPublication()->getLocalizedTitle($locale, 'html'))) === '') {
                continue;
            }

            // create element trans-title-group
            $transTitleGroup = $this->createDomElement('trans-title-group',null,['xml:lang'=>substr($locale, 0, 2)]);
            // create element trans-title
            $transTitle = $this->createDomElement('trans-title',$translatedTitle,[]);
            //append element trans-title to element trans-title-group
            $this->appendChildToParent($transTitleGroup,$transTitle);

            if (!empty($translatedSubTitle = $this->mapHtmlTagsForTitle($article->getCurrentPublication()->getLocalizedSubTitle($locale, 'html')))) {
                // create element trans-subtitle
                $transSubTitle = $this->createDomElement('trans-subtitle',$translatedSubTitle,[]);
                //append element trans-subtitle to element trans-title-group
                $this->appendChildToParent($transTitleGroup,$transSubTitle);
            }
            //append element trans-title-group to element title-group
            $this->appendChildToParent($titleGroup,$transTitleGroup);
        }
        //append element title-group to element article-meta
        $this->appendChildToParent($articleMeta,$titleGroup);

        // create element contrib-group
        $contribGroup = $this->createDomElement('contrib-group',null,['content-type'=>'author']);

        // Include authors
        $affiliations = [];
        foreach ($article->getCurrentPublication()->getData('authors') as $author) {
            $affiliation = $author->getLocalizedAffiliation();
            $affiliationToken = array_search($affiliation, $affiliations);
            if ($affiliation && !$affiliationToken) {
                $affiliationToken = 'aff-' . (count($affiliations) + 1);
                $affiliations[$affiliationToken] = $affiliation;
            }
            $surname = method_exists($author, 'getLastName') ? $author->getLastName() : $author->getLocalizedFamilyName();

            // create element contrib
            $contrib = $this->createDomElement('contrib', null, $author->getPrimaryContact() ? ['corresp' => 'yes'] : []);
            // If using the CRediT plugin, credit roles may be available.
            $creditPlugin = PluginRegistry::getPlugin('generic', 'creditplugin');
            if ($creditPlugin && $creditPlugin->getEnabled()) {
                $contributorRoles = $author->getData('creditRoles') ?? [];
                $creditRoles = $creditPlugin->getCreditRoles();
                foreach ($contributorRoles as $role) {
                    $roleName = $creditRoles[$role];
                    // create element role
                    $role = $this->createDomElement('role', htmlspecialchars($roleName), ['vocab-identifier' => 'https://credit.niso.org/', 'vocab-term' => htmlspecialchars($roleName), 'vocab-term-identifier' => htmlspecialchars($role)]);
                    // append element role to contrib
                    $this->appendChildToParent($contrib, $role);
                }
            }

            if ($author->getOrcid()) {
                // create element role
                $contribId = $this->createDomElement('contrib-id', htmlspecialchars($author->getOrcid()), ['contrib-id-type' => 'orcid']);
                // append element contrib-id to contrib
                $this->appendChildToParent($contrib, $contribId);
            }
            // create element name
            $name = $this->createDomElement('name', null, ['name-style' => 'western']);
            if ($surname != '') {
                // create element surname
                $surnameElement = $this->createDomElement('surname', htmlspecialchars($surname), []);
                // append element surname to name
                $this->appendChildToParent($name, $surnameElement);
                // append element name to contrib
                $this->appendChildToParent($contrib, $name);
            }
            // create element given-names
            $givenNames = $this->createDomElement(
                'given-names',
                htmlspecialchars(method_exists($author, 'getFirstName') ? $author->getFirstName() : $author->getLocalizedGivenName()) . (((method_exists($author, 'getMiddleName') && $s = $author->getMiddleName()) != '') ? " $s" : ''),
                []);
            // append element given-names,surname to name
            $this->appendChildToParent($name, $givenNames);
            // create element email
            $email = $this->createDomElement('email', htmlspecialchars($author->getEmail()), []);
            // append element email to contrib
            $this->appendChildToParent($contrib, $email);
            if ($affiliationToken) {
                // create element xref
                $xref = $this->createDomElement('xref', null, ['ref-type' => 'aff', 'rid' => $affiliationToken]);
                // append element $xref to contrib
                $this->appendChildToParent($contrib, $xref);
            }
            if (($s = $author->getUrl()) != '') {
                // create element uri
                $uri = $this->createDomElement('uri', htmlspecialchars($s), ['ref-type' => 'aff', 'rid' => $affiliationToken]);
                // append element contrib-id to contrib
                $this->appendChildToParent($contrib, $uri);
            }
            // append element name to contrib
            $this->appendChildToParent($contrib, $name);
            // append element contrib to contrib-group
            $this->appendChildToParent($contribGroup,$contrib);
        }

        // append element contrib-group to article-meta
        $this->appendChildToParent($articleMeta,$contribGroup);

        foreach ($affiliations as $affiliationToken => $affiliation) {
            // create element aff
            $aff = $this->createDomElement('aff', null, ['id' => $affiliationToken]);
            // create element institution
            $institution = $this->createDomElement('institution', htmlspecialchars($affiliation), ['content-type' => 'orgname']);
            // append element institution to aff
            $this->appendChildToParent($aff, $institution);
            // append element aff to article-meta
            $this->appendChildToParent($articleMeta,$aff);
        }

        //include pub dates
        if ($article->getDatePublished()){
            // create element pub-date
            $pubDate = $this->createDomElement('pub-date', null, ['date-type' => 'pub','publication-format'=>'epub']);
            // create element day
            $day = $this->createDomElement('day', strftime('%d', (int)$article->getDatePublished()), []);
            // create element month
            $month = $this->createDomElement('month', strftime('%m', (int)$article->getDatePublished()), []);
            // create element year
            $year = $this->createDomElement('year', strftime('%Y', (int)$article->getDatePublished()), []);
            // append element day,month,year to pub-date
            $this->appendChildToParent($pubDate,$day);
            $this->appendChildToParent($pubDate,$month);
            $this->appendChildToParent($pubDate,$year);
            // append element aff to article-meta
            $this->appendChildToParent($articleMeta,$pubDate);
        }
        // Include page info, if available and parseable.
        $matches = $pageCount = null;
        if (PKPString::regexp_match_get('/^(\d+)$/', $article->getPages(), $matches)) {
            $matchedPage = htmlspecialchars($matches[1]);
            // create element fpage
            $fpage = $this->createDomElement('fpage', $matchedPage, []);
            // create element lpage
            $lpage = $this->createDomElement('lpage', $matchedPage, []);
            $pageCount = 1;
        } elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)$/', $article->getPages(), $matches)) {
            $matchedPage = htmlspecialchars($matches[1]);
            // create element fpage
            $fpage = $this->createDomElement('fpage', $matchedPage, []);
            // create element lpage
            $lpage = $this->createDomElement('lpage', $matchedPage, []);
            $pageCount = 1;
        } elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)[ ]?-[ ]?([Pp][Pp]?[.]?[ ]?)?(\d+)$/', $article->getPages(), $matches)) {
            $matchedPageFrom = htmlspecialchars($matches[1]);
            $matchedPageTo = htmlspecialchars($matches[3]);
            // create element fpage
            $fpage = $this->createDomElement('fpage', $matchedPageFrom, []);
            // create element lpage
            $lpage = $this->createDomElement('lpage', $matchedPageTo, []);
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        } elseif (PKPString::regexp_match_get('/^(\d+)[ ]?-[ ]?(\d+)$/', $article->getPages(), $matches)) {
            $matchedPageFrom = htmlspecialchars($matches[1]);
            $matchedPageTo = htmlspecialchars($matches[2]);
            $fpage = $this->createDomElement('fpage', $matchedPageFrom, []);
            // create element lpage
            $lpage = $this->createDomElement('lpage', $matchedPageTo, []);
            $pageCount = $matchedPageTo - $matchedPageFrom + 1;
        }
        // append element aff to article-meta
        $this->appendChildToParent($articleMeta,$fpage);
        $this->appendChildToParent($articleMeta,$lpage);

        $copyrightYear = $article->getCopyrightYear();
        $copyrightHolder = $article->getLocalizedCopyrightHolder();
        $licenseUrl = $article->getLicenseURL();
        $ccBadge = Application::get()->getCCLicenseBadge($licenseUrl, $article->getLocale());
        if ($copyrightYear || $copyrightHolder || $licenseUrl || $ccBadge){
            // create element permissions
            $permissions = $this->createDomElement('permissions', null, []);
            if($copyrightYear||$copyrightHolder){
                // create element copyright-statement
                $copyrightStatement = $this->createDomElement('copyright-statement', htmlspecialchars(__('submission.copyrightStatement', ['copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder])), []);
                // append element copyright-statement to permissions
                $this->appendChildToParent($permissions,$copyrightStatement);
            }
            if($copyrightYear){
                // create element copyright-year
                $copyrightYearElement = $this->createDomElement('copyright-year', htmlspecialchars($copyrightYear) , []);
                // append element copyright-year to permissions
                $this->appendChildToParent($permissions,$copyrightYearElement);
            }
            if($copyrightHolder){
                // create element copyright-holder
                $copyrightHolderElement = $this->createDomElement('copyright-holder', htmlspecialchars($copyrightHolder), []);
                // append element copyright-holder to permissions
                $this->appendChildToParent($permissions,$copyrightHolderElement);
            }
            if($licenseUrl) {
                // create element license
                $licenseUrlElement = $this->createDomElement('license', null, ['xlink:href' => htmlspecialchars($licenseUrl)]);
                if($ccBadge){
                    // create element license-p
                    $ccBadgeElement = $this->createDomElement('license-p', strip_tags($ccBadge) , []);
                    // append element license-p to license
                    $this->appendChildToParent($licenseUrlElement,$ccBadgeElement);
                }
                // append element copyright-statement to permissions
                $this->appendChildToParent($permissions,$licenseUrlElement);
            }
            // append element permissions to article-meta
            $this->appendChildToParent($articleMeta,$permissions);
        }

        // create element self-uri
        $selfUri= $this->createDomElement('self-uri', strip_tags($ccBadge) , ['xlink:href'=>htmlspecialchars($request->url($journal->getPath(), 'article', 'view', $article->getBestArticleId()))]);
        //append element self-uri to article-meta
        $this->appendChildToParent($articleMeta,$selfUri);

        $submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
        foreach ($submissionKeywordDao->getKeywords($article->getCurrentPublication(), $journal->getSupportedLocales()) as $locale => $keywords) {
            if (empty($keywords)) continue;
            // create element kwd-group
            $kwdGroup = $this->createDomElement('kwd-group', null , ['xml:lang'=>substr($locale, 0, 2)]);
            foreach ($keywords as $keyword) {
                // create element kwd
                $kwd = $this->createDomElement('kwd', null , ['xml:lang'=>substr($locale, 0, 2)]);
                // append element kwd to kwd-group
                $this->appendChildToParent($kwdGroup,$kwd);
            }
            // append element kwd-group to article-meta
            $this->appendChildToParent($articleMeta,$kwdGroup);
        }

        if(isset($pageCount)){
            // create element counts
            $count = $this->createDomElement('counts', null , []);
            // create element page-count
            $pageCountElement = $this->createDomElement('page-count', null , ['count'=>(int) $pageCount]);
            // append element page-count to count
            $this->appendChildToParent($count,$pageCountElement);
            // append element count to article-meta
            $this->appendChildToParent($articleMeta,$count);
        }

        $candidateFound = false;
        // create element custom-meta-group
        $customMetaGroup = $this->createDomElement('custom-meta-group', null , []);
        $layoutFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$article->getId()])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
            ->getMany();

        foreach ($layoutFiles as $layoutFile) {
            $candidateFound = true;
            $sourceFileUrl = $request->url(null, 'jatsTemplate', 'download', null,
                [
                    'submissionFileId' => $layoutFile->getId(),
                    'fileId' => $layoutFile->getData('fileId'),
                    'submissionId' => $article->getId(),
                    'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
                ]
            );
            // create element custom-meta-group
            $customMetaGroup = $this->createDomElement('custom-meta', null , []);
            // create element meta-name
            $metaName = $this->createDomElement('meta-name', 'production-ready-file-url' , []);
            // create element meta-value
            $metaValue = $this->createDomElement('meta-value', null , []);
            // create element ext-link
            $extLink = $this->createDomElement('ext-link', null , ['ext-link-type'=>'uri','xlink:href'=>htmlspecialchars($sourceFileUrl)]);
            // append element ext-link to meta-value
            $this->appendChildToParent($metaValue,$extLink);
            // append element meta-value to meta-name
            $this->appendChildToParent($metaName,$metaValue);
            // append element meta-name to custom-meta-group
            $this->appendChildToParent($customMetaGroup,$metaName);

        }
        if ($candidateFound){
            // append element custom-meta-group to article-meta
            $this->appendChildToParent($articleMeta,$customMetaGroup);
        };

        return $articleMeta;
    }

    /**
     * create xml body DOMNode
     * @param $article Article
     * @return \DOMNode
     */
    private function createBodySubElements($article):\DOMNode
    {
        // create element body
        $bodyElement = $this->createDomElement('body', null , []);
        $text = '';
        $galleys = $article->getGalleys();

        // Give precedence to HTML galleys, as they're quickest to parse
        usort($galleys, function($a, $b) {
            return $a->getFileType() == 'text/html'?-1:1;
        });

        // Provide the full-text.
        $fileService = Services::get('file');
        foreach ($galleys as $galley) {
            $galleyFile = Repo::submissionFile()->get($galley->getData('submissionFileId'));
            if (!$galleyFile) continue;

            $filepath = $fileService->get($galleyFile->getData('fileId'))->path;
            $mimeType = $fileService->fs->mimeType($filepath);
            if (in_array($mimeType, ['text/html'])) {
                static $purifier;
                if (!$purifier) {
                    $config = \HTMLPurifier_Config::createDefault();
                    $config->set('HTML.Allowed', 'p');
                    $config->set('Cache.SerializerPath', 'cache');
                    $purifier = new \HTMLPurifier($config);
                }
                // Remove non-paragraph content
                $text = $purifier->purify(file_get_contents(Config::getVar('files', 'files_dir') . '/' . $filepath));
                // Remove empty paragraphs
                $text = preg_replace('/<p>[\W]*<\/p>/', '', $text);
            } else {
                $parser = SearchFileParser::fromFile($galleyFile);
                if ($parser && $parser->open()) {
                    while(($s = $parser->read()) !== false) $text .= $s;
                    $parser->close();
                }
                // create element p
                $paragraphElement = $this->createDomElement('p', htmlspecialchars($text, ENT_IGNORE) , []);
            }
            // Use the first parseable galley.
            if (!empty($text)) break;
        }
        if (!empty($text))
        {
            // append element p to body
            $this->appendChildToParent($bodyElement,$paragraphElement);
        }

        return $bodyElement;
    }

    /**
     * create xml back DOMNode
     * @param $publication
     * @return \DOMNode
     * @throws \DOMException
     */
    private function createBackSubElements($publication):\DOMNode
    {
        // create element back
        $backElement = $this->createDomElement('back', null , []);

        $citationDao = DAORegistry::getDAO('CitationDAO');
        $citations = $citationDao->getByPublicationId($publication->getId())->toArray();
        if (count($citations)) {
            // create element ref-list
            $refList = $this->createDomElement('ref-list', null , []);
            $i=1;
            foreach ($citations as $citation) {
                // create element ref
                $refElement = $this->createDomElement('ref', null , ['id'=>'R'.$i]);
                // create element mixed-citation
                $mixedCitation = $this->createDomElement('mixed-citation', htmlspecialchars($citation->getRawCitation()) , []);
                // append element mixed-citation to ref
                $this->appendChildToParent($refElement,$mixedCitation);
                // append element ref to ref-list
                $this->appendChildToParent($refList,$refElement);
                $i++;
            }
            // append element ref-list to back
            $this->appendChildToParent($backElement,$refList);
        }

        return  $backElement;
    }

    /**
     * Map the specific HTML tags in title/ sub title for JATS schema compability
     * @see https://jats.nlm.nih.gov/publishing/0.4/xsd/JATS-journalpublishing0.xsd
     *
     * @param  string $htmlTitle The submission title/sub title as in HTML
     * @return string
     */
    public function mapHtmlTagsForTitle(string $htmlTitle): string
    {
        $mappings = [
            '<b>' 	=> '<bold>',
            '</b>' 	=> '</bold>',
            '<i>' 	=> '<italic>',
            '</i>' 	=> '</italic>',
            '<u>' 	=> '<underline>',
            '</u>' 	=> '</underline>',
        ];

        return str_replace(array_keys($mappings), array_values($mappings), $htmlTitle);
    }
}
