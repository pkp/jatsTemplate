<?php

/**
 * @file JatsTemplateDownloadHandler.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @package plugins.generic.jatsTemplate
 * @class JatsTemplateDownloadHandler
 */

use PKP\submissionFile\SubmissionFile;
use APP\facades\Repo;
use Firebase\JWT\JWT;

class JatsTemplateDownloadHandler extends APP\handler\Handler {
	/** @var JatsTemplatePlugin The JATS Template plugin */
	static $plugin;

	/**
	 * Provide the JATS template plugin to the handler.
	 * @param $plugin JATSTemplatePlugin
	 */
	static function setPlugin($plugin) {
		self::$plugin = $plugin;
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		// Permit the use of the Authorization header and an API key for access to unpublished/subscription content
		if ($header = array_search('Authorization', array_flip(getallheaders()))) {
			list($bearer, $jwt) = explode(' ', $header);
			if (strcasecmp($bearer, 'Bearer') == 0) {
				$apiToken = JWT::decode($jwt, Config::getVar('security', 'api_key_secret', ''), array('HS256'));
				$this->setApiToken($apiToken);
			}
		}

		$this->addPolicy(new \PKP\security\authorization\ContextRequiredPolicy($request));
		$this->addPolicy(new \APP\security\authorization\OjsJournalMustPublishPolicy($request));

		return parent::authorize($request, $args, $roleAssignments);
	}

	protected function _isUserAllowedAccess($request) {
		$user = $request->getUser();
		$context = $request->getContext();
		if (!$user || !$context) return false;
		$roleDao = DAORegistry::getDAO('RoleDAO'); /** @var $roleDao RoleDAO */
		$roles = $roleDao->getByUserId($user->getId(), $context->getId());
		$allowedAccess = false;
		foreach ($roles as $role) {
			if (in_array($role->getRoleId(), [ROLE_ID_MANAGER, ROLE_ID_SUBSCRIPTION_MANAGER])) return true;
		}
		return false;
	}

	/**
	 * Handle a download request
	 * @param $args array Arguments array.
	 * @param $request PKPRequest Request object.
	 */
	function download($args, $request) {
		if (!$this->_isUserAllowedAccess($request)) $request->getDispatcher()->handle404();

		// Check the stage (this is only for consistency with other download URLs in the system
		// in case the built-in download handler can be used in place of this in the future)
		if ($request->getUserVar('stageId') != WORKFLOW_STAGE_ID_PRODUCTION) $request->getDispatcher()->handle404();

		$submissionId = $request->getUserVar('submissionId');
		$layoutFiles = Repo::submissionFile()->getMany(
			Repo::submissionFile()->getCollector()
			->filterBySubmissionIds([$submissionId])
			->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
                );
		foreach ($layoutFiles as $layoutFile) {
			if ($layoutFile->getId() != $request->getUserVar('submissionFileId') || $layoutFile->getData('fileId') != $request->getUserVar('fileId')) continue;

			$filename = Services::get('file')->formatFilename($layoutFile->getData('path'), $layoutFile->getLocalizedData('name'));
			Services::get('file')->download($layoutFile->getData('fileId'), $filename);
			return;
		}
		$request->getDispatcher()->handle404();
	}
}

