<?php

/**
 * @file JatsTemplatePlugin.inc.php
 *
 * Copyright (c) 2003-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS template plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class JatsTemplatePlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			HookRegistry::register('OAIMetadataFormat_JATS::findJats', [$this, 'callbackFindJats']);
			HookRegistry::register('LoadHandler', [$this, 'callbackHandleContent']);
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.jatsTemplate.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.jatsTemplate.description');
	}

	/**
	 * Prepare JATS template document
	 * @param $hookName string
	 * @param $args array
	 */
	public function callbackFindJats($hookName, $args) {
		$plugin =& $args[0];
		$record =& $args[1];
		$candidateFiles =& $args[2];
		$doc =& $args[3];

		if (!$doc && empty($candidateFiles)) {
			$doc = new DOMDocument();
			$doc->loadXml($this->toXml($record));
		}

		return false;
	}

	/**
	 * @see OAIMetadataFormat#toXml
	 */
	function toXml(&$record, $format = null) {
		$article =& $record->getData('article');
		$journal =& $record->getData('journal');
		$section =& $record->getData('section');
		$issue =& $record->getData('issue');
		$galleys =& $record->getData('galleys');
		$articleId = $article->getId();
		$publication = $article->getCurrentPublication();

		$request = Application::get()->getRequest();

		$abbreviation = $journal->getLocalizedSetting('abbreviation');
		$printIssn = $journal->getSetting('printIssn');
		$onlineIssn = $journal->getSetting('onlineIssn');
		$articleLocale = $article->getLocale();

		$datePublished = $article->getDatePublished();
		if (!$datePublished) $datePublished = $issue->getDatePublished();
		if ($datePublished) $datePublished = strtotime($datePublished);

		$response = "<article
			xmlns:xlink=\"http://www.w3.org/1999/xlink\"
			xmlns:mml=\"http://www.w3.org/1998/Math/MathML\"
			xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
			" . (($s = $section->getLocalizedIdentifyType())!=''?"\tarticle-type=\"" . htmlspecialchars($s) . "\"":'') . "
			xml:lang=\"" . substr($articleLocale, 0, 2) . "\">
			<front>
			<journal-meta>
				<journal-id journal-id-type=\"ojs\">" . htmlspecialchars($journal->getPath()) . "</journal-id>
				<journal-title-group>
			<journal-title xml:lang=\"" . substr($journal->getPrimaryLocale(), 0, 2) . "\">" . htmlspecialchars($journal->getName($journal->getPrimaryLocale())) . "</journal-title>";

		// Include translated journal titles
		foreach ($journal->getName(null) as $locale => $title) {
			if ($locale == $journal->getPrimaryLocale()) continue;
			$response .= "<trans-title-group xml:lang=\"" . substr($locale, 0, 2) . "\"><trans-title>" . htmlspecialchars($title) . "</trans-title></trans-title-group>\n";
		}
		$response .= '</journal-title-group>';

		$publisherGroup = '';
		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$citationStyleLanguagePlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
		$publisherLocation = $citationStyleLanguagePlugin ? $citationStyleLanguagePlugin->getSetting($journal->getId(), 'publisherLocation') : null;
		$publisherUrl = $journal->getSetting('publisherUrl');

		if ($publisherInstitution) $publisherGroup .= "\t\t\t<publisher-name>" . htmlspecialchars($publisherInstitution) . "</publisher-name>\n";
		if ($publisherLocation) {
			$publisherGroup .= "\t\t\t<publisher-loc>";
			if ($publisherLocation) $publisherGroup .= htmlspecialchars($publisherLocation);
			if ($publisherUrl) $publisherGroup .= '<uri>' . htmlspecialchars($publisherUrl) . '</uri>';
			$publisherGroup .= "</publisher-loc>\n";
		}


		$response .=
			(!empty($onlineIssn)?"\t\t\t<issn pub-type=\"epub\">" . htmlspecialchars($onlineIssn) . "</issn>":'') .
			(!empty($printIssn)?"\t\t\t<issn pub-type=\"ppub\">" . htmlspecialchars($printIssn) . "</issn>":'') .
			($publisherGroup != ''?"\t\t\t<publisher>$publisherGroup</publisher>\n":'') .
			"\t\t</journal-meta>\n" .
			"\t\t<article-meta>\n" .
			"\t\t\t<article-id pub-id-type=\"publisher-id\">" . $article->getId() . "</article-id>\n" .
			"\t\t\t<article-categories><subj-group xml:lang=\"" . $journal->getPrimaryLocale() . "\" subj-group-type=\"heading\"><subject>" . htmlspecialchars($section->getLocalizedTitle()) . "</subject></subj-group></article-categories>\n" .
			"\t\t\t<title-group>\n" .
			"\t\t\t\t<article-title xml:lang=\"" . substr($articleLocale, 0, 2) . "\">" . htmlspecialchars(strip_tags($article->getTitle($articleLocale))) . "</article-title>\n";
		if (!empty($subtitle = $article->getSubtitle($articleLocale))) $response .= "\t\t\t\t<subtitle xml:lang=\"" . substr($articleLocale, 0, 2) . "\">" . htmlspecialchars($subtitle) . "</subtitle>\n";

		// Include translated journal titles
		foreach ($article->getTitle(null) as $locale => $title) {
			if ($locale == $articleLocale) continue;
			if (trim(htmlspecialchars(strip_tags($title))) === '') continue;
			$response .= "\t\t\t\t<trans-title-group xml:lang=\"" . substr($locale, 0, 2) . "\">\n";
			$response .= "\t\t\t\t\t<trans-title>" . htmlspecialchars(strip_tags($title)) . "</trans-title>\n";
			if (!empty($subtitle = $article->getSubtitle($locale))) $response .= "\t\t\t\t\t<trans-subtitle>" . htmlspecialchars($subtitle) . "</trans-subtitle>\n";
			$response .= "\t\t\t\t\t</trans-title-group>\n";
		}

		$response .=
			"\t\t\t</title-group>\n" .
			"\t\t\t<contrib-group content-type=\"author\">\n";

		// Include authors
		$affiliations = array();
		foreach ($article->getAuthors() as $author) {
			$affiliation = $author->getLocalizedAffiliation();
			$affiliationToken = array_search($affiliation, $affiliations);
			if ($affiliation && !$affiliationToken) {
				$affiliationToken = 'aff-' . (count($affiliations)+1);
				$affiliations[$affiliationToken] = $affiliation;
			}
			$surname = method_exists($author, 'getLastName')?$author->getLastName():$author->getLocalizedFamilyName();
			$response .=
				"\t\t\t\t<contrib " . ($author->getPrimaryContact()?'corresp="yes" ':'') . ">\n" .
				($author->getOrcid()?"\t\t\t\t\t<contrib-id contrib-id-type=\"orcid\" authenticated=\"" . ($author->getData('orcidAccessToken') ? 'true' : 'false') . "\">" . htmlspecialchars($author->getOrcid()) . "</contrib-id>\n":'') .
				"\t\t\t\t\t<name-alternatives>\n";

			$preferredName = $author->getPreferredPublicName($articleLocale);
			if (!empty($preferredName)) {
				$response .=
					"\t\t\t\t\t\t<string-name specific-use=\"display\">" . htmlspecialchars($preferredName) . "</string-name>\n";
			}

			$response .= "\t\t\t\t\t\t<name name-style=\"western\" specific-use=\"primary\">\n" .
				($surname!=''?"\t\t\t\t\t\t\t<surname>" . htmlspecialchars($surname) . "</surname>\n":'') .
				"\t\t\t\t\t\t\t<given-names>" . htmlspecialchars(method_exists($author, 'getFirstName')?$author->getFirstName():$author->getLocalizedGivenName()) . (((method_exists($author, 'getMiddleName') && $s = $author->getMiddleName()) != '')?" $s":'') . "</given-names>\n" .
				"\t\t\t\t\t\t</name>\n" .
				"\t\t\t\t\t</name-alternatives>\n" .
				($affiliationToken?"\t\t\t\t\t<xref ref-type=\"aff\" rid=\"$affiliationToken\" />\n":'') .
				"\t\t\t\t\t<email>" . htmlspecialchars($author->getEmail()) . "</email>\n" .
				(($s = $author->getUrl()) != ''?"\t\t\t\t\t<uri>" . htmlspecialchars($s) . "</uri>\n":'') .
				"\t\t\t\t</contrib>\n";
		}
		$response .= "\t\t\t</contrib-group>\n";
		foreach ($affiliations as $affiliationToken => $affiliation) {
			$response .= "\t\t\t<aff id=\"$affiliationToken\"><institution content-type=\"orgname\">" . htmlspecialchars($affiliation) . "</institution></aff>\n";
		}

		if ($datePublished) $response .=
			"\t\t\t<pub-date date-type=\"pub\" publication-format=\"epub\">\n" .
			"\t\t\t\t<day>" . strftime('%d', $datePublished) . "</day>\n" .
			"\t\t\t\t<month>" . strftime('%m', $datePublished) . "</month>\n" .
			"\t\t\t\t<year>" . strftime('%Y', $datePublished) . "</year>\n" .
			"\t\t\t</pub-date>\n";

		// Include page info, if available and parseable.
		$matches = $pageCount = null;
		if (PKPString::regexp_match_get('/^(\d+)$/', $article->getPages(), $matches)) {
			$matchedPage = htmlspecialchars($matches[1]);
			$response .= "\t\t\t\t<fpage>$matchedPage</fpage><lpage>$matchedPage</lpage>\n";
			$pageCount = 1;
		} elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)$/', $article->getPages(), $matches)) {
			$matchedPage = htmlspecialchars($matches[1]);
			$response .= "\t\t\t\t<fpage>$matchedPage</fpage><lpage>$matchedPage</lpage>\n";
			$pageCount = 1;
		} elseif (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)[ ]?-[ ]?([Pp][Pp]?[.]?[ ]?)?(\d+)$/', $article->getPages(), $matches)) {
			$matchedPageFrom = htmlspecialchars($matches[1]);
			$matchedPageTo = htmlspecialchars($matches[3]);
			$response .=
				"\t\t\t\t<fpage>$matchedPageFrom</fpage>\n" .
				"\t\t\t\t<lpage>$matchedPageTo</lpage>\n";
			$pageCount = $matchedPageTo - $matchedPageFrom + 1;
		} elseif (PKPString::regexp_match_get('/^(\d+)[ ]?-[ ]?(\d+)$/', $article->getPages(), $matches)) {
			$matchedPageFrom = htmlspecialchars($matches[1]);
			$matchedPageTo = htmlspecialchars($matches[2]);
			$response .=
				"\t\t\t\t<fpage>$matchedPageFrom</fpage>\n" .
				"\t\t\t\t<lpage>$matchedPageTo</lpage>\n";
			$pageCount = $matchedPageTo - $matchedPageFrom + 1;
		}

		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION);
		$copyrightYear = $article->getCopyrightYear();
		$copyrightHolder = $article->getLocalizedCopyrightHolder();
		$licenseUrl = $article->getLicenseURL();
		$ccBadge = Application::get()->getCCLicenseBadge($licenseUrl, $article->getLocale());
		if ($copyrightYear || $copyrightHolder || $licenseUrl || $ccBadge) $response .=
			"\t\t\t<permissions>\n" .
			(($copyrightYear||$copyrightHolder)?"\t\t\t\t<copyright-statement>" . htmlspecialchars(__('submission.copyrightStatement', array('copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder))) . "</copyright-statement>\n":'') .
			($copyrightYear?"\t\t\t\t<copyright-year>" . htmlspecialchars($copyrightYear) . "</copyright-year>\n":'') .
			($copyrightHolder?"\t\t\t\t<copyright-holder>" . htmlspecialchars($copyrightHolder) . "</copyright-holder>\n":'') .
			($licenseUrl?"\t\t\t\t<license xlink:href=\"" . htmlspecialchars($licenseUrl) . "\">\n" .
				($ccBadge?"\t\t\t\t\t<license-p>" . strip_tags($ccBadge) . "</license-p>\n":'') .
			"\t\t\t\t</license>\n":'') .
			"\t\t\t</permissions>\n" .
			"\t\t\t<self-uri xlink:href=\"" . htmlspecialchars($request->url($journal->getPath(), 'article', 'view', $article->getBestArticleId())) . "\" />\n";

		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
		foreach ($submissionKeywordDao->getKeywords($publication->getId(), $journal->getSupportedLocales()) as $locale => $keywords) {
			if (empty($keywords)) continue;
			$response .= "\t\t\t<kwd-group xml:lang=\"" . substr($locale, 0, 2) . "\">\n";
			foreach ($keywords as $keyword) $response .= "\t\t\t\t<kwd>" . htmlspecialchars($keyword) . "</kwd>\n";
			$response .= "\t\t\t</kwd-group>\n";
		}

		$response .= (isset($pageCount)?"\t\t\t<counts><page-count count=\"" . (int) $pageCount. "\" /></counts>\n":'');

		$candidateFound = false;
		$layoutResponse = "\t\t\t\t<custom-meta-group>";
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$layoutFiles = Services::get('submissionFile')->getMany([
                        'submissionIds' => [$article->getId()],
                        'fileStages' => [SUBMISSION_FILE_PRODUCTION_READY],
                ]);
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
			$layoutResponse .= "\t\t\t\t\t<custom-meta>\t\t\t\t\t\t<meta-name>production-ready-file-url</meta-name>\n\t\t\t\t\t\t<meta-value><ext-link ext-link-type=\"uri\" xlink:href=\"" . htmlspecialchars($sourceFileUrl) . "\"/></meta-value>\n\t\t\t\t\t</custom-meta>\n";
		}
		$layoutResponse .= "\t\t\t\t</custom-meta-group>";
		if ($candidateFound) $response .= $layoutResponse;

		$response .= "\t\t</article-meta>\n\t</front>\n";

		// Include body text (for search indexing only)
		import('classes.search.ArticleSearchIndex');
		$text = '';
		$galleys = $article->getGalleys();

		// Give precedence to HTML galleys, as they're quickest to parse
		usort($galleys, function($a, $b) {
			return $a->getFileType() == 'text/html'?-1:1;
		});

		// Provide the full-text.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$fileService = Services::get('file');
		foreach ($galleys as $galley) {
			$galleyFile = $submissionFileDao->getById($galley->getData('submissionFileId'));
			if (!$galleyFile) continue;

			$filepath = $fileService->get($galleyFile->getData('fileId'))->path;
			$mimeType = $fileService->fs->getMimetype($filepath);
			if (in_array($mimeType, ['text/html'])) {
				static $purifier;
				if (!$purifier) {
					$config = HTMLPurifier_Config::createDefault();
					$config->set('HTML.Allowed', 'p');
					$config->set('Cache.SerializerPath', 'cache');
					$purifier = new HTMLPurifier($config);
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

				$text = '<p>' . htmlspecialchars($text, ENT_IGNORE) . '</p>';
			}
			// Use the first parseable galley.
			if (!empty($text)) break;
		}
		if (!empty($text)) $response .= "\t<body>$text</body>\n";

		$citationDao = DAORegistry::getDAO('CitationDAO');
		$citations = $citationDao->getByPublicationId($publication->getId())->toArray();
		if (count($citations)) {
			$response .= "\t<back>\n\t\t<ref-list>\n";
			$i=1;
			foreach ($citations as $citation) {
				$response .= "\t\t\t<ref id=\"R{$i}\"><mixed-citation>" . htmlspecialchars($citation->getRawCitation()) . "</mixed-citation></ref>\n";
				$i++;
			}
			$response .= "\t\t</ref-list>\n\t</back>\n";

		}

		$response .= "</article>";
		return $response;
	}

	/**
	 * Declare the handler function to process the actual page PATH
	 * @param $hookName string The name of the invoked hook
	 * @param $args array Hook parameters
	 * @return boolean Hook handling status
	 */
	function callbackHandleContent($hookName, $args) {
		$request = Application::get()->getRequest();
		$templateMgr = TemplateManager::getManager($request);

		$page =& $args[0];
		$op =& $args[1];

		if ($page == 'jatsTemplate' && $op == 'download') {
			define('HANDLER_CLASS', 'JatsTemplateDownloadHandler');
			$this->import('JatsTemplateDownloadHandler');
			JatsTemplateDownloadHandler::setPlugin($this);
			return true;
		}
		return false;
	}
}
