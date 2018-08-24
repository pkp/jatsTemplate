<?php

/**
 * @file JatsTemplatePlugin.inc.php
 *
 * Copyright (c) 2003-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @brief JATS template plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class JatsTemplatePlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			HookRegistry::register('OAIMetadataFormat_JATS::findJats', array($this, 'callback'));
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
	 * Send submission files to iThenticate.
	 * @param $hookName string
	 * @param $args array
	 */
	public function callback($hookName, $args) {
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

		$request = Application::getRequest();

		$abbreviation = $journal->getLocalizedSetting('abbreviation');
		$printIssn = $journal->getSetting('printIssn');
		$onlineIssn = $journal->getSetting('onlineIssn');

		$publisherInstitution = $journal->getSetting('publisherInstitution');
		$datePublished = $article->getDatePublished();
		if (!$datePublished) $datePublished = $issue->getDatePublished();
		if ($datePublished) $datePublished = strtotime($datePublished);

		$response = "<article
			xmlns:xlink=\"http://www.w3.org/1999/xlink\"
			xmlns:mml=\"http://www.w3.org/1998/Math/MathML\"
			xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
			" . (($s = $section->getLocalizedIdentifyType())!=''?"\tarticle-type=\"" . htmlspecialchars($s) . "\"":'') . "
			xml:lang=\"" . substr($article->getLocale(), 0, 2) . "\">
			<front>
			<journal-meta>
				<journal-id journal-id-type=\"publisher\">" . htmlspecialchars($journal->getPath()) . "</journal-id>
				<journal-title-group>
			<journal-title xml:lang=\"" . substr($article->getLocale(), 0, 2) . "\">" . htmlspecialchars($journal->getName($article->getLocale())) . "</journal-title>";

		// Include translated journal titles
		foreach ($journal->getName(null) as $locale => $title) {
			if ($locale == $article->getLocale()) continue;
			$response .= "<trans-title-group xml:lang=\"" . substr($locale, 0, 2) . "\"><trans-title>" . htmlspecialchars($title) . "</trans-title></trans-title-group>\n";
		}
		$response .= '</journal-title-group>';

		$response .=
			(!empty($onlineIssn)?"\t\t\t<issn pub-type=\"epub\">" . htmlspecialchars($onlineIssn) . "</issn>":'') .
			(!empty($printIssn)?"\t\t\t<issn pub-type=\"ppub\">" . htmlspecialchars($printIssn) . "</issn>":'') .
			($publisherInstitution != ''?"\t\t\t<publisher><publisher-name>" . htmlspecialchars($publisherInstitution) . "</publisher-name></publisher>\n":'') .
			"\t\t</journal-meta>\n" .
			"\t\t<article-meta>\n" .
			"\t\t\t<article-id pub-id-type=\"publisher-id\">" . $article->getId() . "</article-id>\n" .
			"\t\t\t<article-categories><subj-group subj-group-type=\"heading\"><subject>" . htmlspecialchars($section->getLocalizedTitle()) . "</subject></subj-group></article-categories>\n" .
			"\t\t\t<title-group>\n" .
			"\t\t\t\t<article-title xml:lang=\"" . substr($locale, 0, 2) . "\">" . htmlspecialchars(strip_tags($article->getLocalizedTitle())) . "</article-title>\n";

		// Include translated journal titles
		foreach ($article->getTitle(null) as $locale => $title) {
			if ($locale == $article->getLocale()) continue;
			$response .= "\t\t\t\t<trans-title xml:lang=\"" . substr($locale, 0, 2) . "\">" . htmlspecialchars(strip_tags($title)) . "</trans-title>\n";
		}

		$response .=
			"\t\t\t</title-group>\n" .
			"\t\t\t<contrib-group>\n";

		// Include authors
		foreach ($article->getAuthors() as $author) {
			$response .=
				"\t\t\t\t<contrib " . ($author->getPrimaryContact()?'corresp="yes" ':'') . "contrib-type=\"author\">\n" .
				"\t\t\t\t\t<name name-style=\"western\">\n" .
				"\t\t\t\t\t\t<surname>" . htmlspecialchars(method_exists($author, 'getLastName')?$author->getLastName():$author->getLocalizedFamilyName()) . "</surname>\n" .
				"\t\t\t\t\t\t<given-names>" . htmlspecialchars(method_exists($author, 'getFirstName')?$author->getFirstName():$author->getLocalizedGivenName()) . (((method_exists($author, 'getMiddleName') && $s = $author->getMiddleName()) != '')?" $s":'') . "</given-names>\n" .
				"\t\t\t\t\t</name>\n" .
				(($s = $author->getLocalizedAffiliation()) != ''?"\t\t\t\t\t<aff>" . htmlspecialchars($s) . "</aff>\n":'') .
				"\t\t\t\t\t<email>" . htmlspecialchars($author->getEmail()) . "</email>\n" .
				(($s = $author->getUrl()) != ''?"\t\t\t\t\t<uri>" . htmlspecialchars($s) . "</uri>\n":'') .
				"\t\t\t\t</contrib>\n";
		}

		$response .= "\t\t\t</contrib-group>\n";
		if ($datePublished) $response .=
			"\t\t\t<pub-date pub-type=\"epub\">\n" .
			"\t\t\t\t<day>" . strftime('%d', $datePublished) . "</day>\n" .
			"\t\t\t\t<month>" . strftime('%m', $datePublished) . "</month>\n" .
			"\t\t\t\t<year>" . strftime('%Y', $datePublished) . "</year>\n" .
			"\t\t\t</pub-date>\n";

		// Include page info, if available and parseable.
		$matches = null;
		if (PKPString::regexp_match_get('/^[Pp][Pp]?[.]?[ ]?(\d+)$/', $article->getPages(), $matches)) {
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
		}
		AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION);
		$response .=
			"\t\t\t<permissions>\n" .
			"\t\t\t\t<copyright-statement>" . htmlspecialchars(__('submission.copyrightStatement', array('copyrightYear' => $article->getCopyrightYear(), 'copyrightHolder' => $article->getLocalizedCopyrightHolder()))) . "</copyright-statement>\n" .
			($datePublished?"\t\t\t\t<copyright-year>" . $article->getCopyrightYear() . "</copyright-year>\n":'') .
			"\t\t\t\t<license xlink:href=\"" . $article->getLicenseURL() . "\">\n" .
			(($s = Application::getCCLicenseBadge($article->getLicenseURL()))?"\t\t\t\t\t<license-p>" . strip_tags($s) . "</license-p>\n":'') .
			"\t\t\t\t</license>\n" .
			"\t\t\t</permissions>\n" .
			"\t\t\t<self-uri xlink:href=\"" . htmlspecialchars($request->url($journal->getPath(), 'article', 'view', $article->getBestArticleId())) . "\" />\n";

		$subjects = array();
		if (is_array($article->getSubject(null))) foreach ($article->getSubject(null) as $locale => $subject) {
			$s = array_map('trim', explode(';', $subject));
			if (!empty($s)) $subjects[$locale] = $s;
		}
		if (!empty($subjects)) foreach ($subjects as $locale => $s) {
			$response .= "\t\t\t<kwd-group xml:lang=\"" . substr($locale, 0, 2) . "\">\n";
			foreach ($s as $subject) $response .= "\t\t\t\t<kwd>" . htmlspecialchars($subject) . "</kwd>\n";
			$response .= "\t\t\t</kwd-group>\n";
		}

		$response .=
			(isset($pageCount)?"\t\t\t<counts><page-count count=\"" . (int) $pageCount. "\" /></counts>\n":'') .
			"\t\t</article-meta>\n" .
			"\t</front>\n";

		// Include body text (for search indexing only)
		import('classes.search.ArticleSearchIndex');
		$text = '';
		$galleys = $article->getGalleys();

		// Give precedence to HTML galleys, as they're quickest to parse
		usort($galleys, create_function('$a, $b', 'return $a->getFileType() == \'text/html\'?-1:1;'));

		// Provide the full-text.
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		foreach ($galleys as $galley) {
			$submissionFiles = $submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_GALLEY, $galley->getId(), $article->getId(), SUBMISSION_FILE_PROOF);
			foreach ($submissionFiles as $submissionFile) {
				$parser =& SearchFileParser::fromFile($submissionFile);
				if ($parser && $parser->open()) {
					while(($s = $parser->read()) !== false) $text .= $s;
					$parser->close();
				}

				if (in_array($submissionFile->getFileType(), array('text/html'))) {
					static $purifier;
					if (!$purifier) {
						$config = HTMLPurifier_Config::createDefault();
						$config->set('HTML.Allowed', 'p');
						$config->set('Cache.SerializerPath', 'cache');
						$purifier = new HTMLPurifier($config);
					}
					$text = $purifier->purify($text);
				} else {
					$text = '<p>' . htmlspecialchars($text) . '</p>';
				}
				if (!empty($text)) break 2;
			}
			// Use the first parseable galley.
		}
		if (!empty($text)) $response .= "\t<body>$text</body>\n";

		$response .= "</article>";
		return $response;
	}
}
