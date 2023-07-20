<?php

/**
 * @file JatsTemplatePlugin.inc.php
 *
 * Copyright (c) 2003-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief JATS template plugin
 */

namespace APP\plugins\generic\jatsTemplate;

use APP\facades\Repo;
use APP\core\Services;
use APP\core\Application;
use APP\plugins\generic\jatsTemplate\classes\Article;
use APP\template\TemplateManager;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\search\SearchFileParser;
use PKP\submissionFile\SubmissionFile;
use PKP\config\Config;

class JatsTemplatePlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			Hook::add('OAIMetadataFormat_JATS::findJats', [$this, 'callbackFindJats']);
			Hook::add('LoadHandler', [$this, 'callbackHandleContent']);
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
            $doc = new Article($record);
		}

		return false;
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
			$args[3] = new JatsTemplateDownloadHandler($this);
			return true;
		}
		return false;
	}
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\jatsTemplate\JatsTemplatePlugin', '\JatsTemplatePlugin');
}
