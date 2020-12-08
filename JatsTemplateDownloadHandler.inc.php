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

import('classes.handler.Handler');

use \Firebase\JWT\JWT;

class JatsTemplateDownloadHandler extends Handler {
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

		import('lib.pkp.classes.security.authorization.ContextRequiredPolicy');
		$this->addPolicy(new ContextRequiredPolicy($request));

		import('classes.security.authorization.OjsJournalMustPublishPolicy');
		$this->addPolicy(new OjsJournalMustPublishPolicy($request));

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
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		if ($request->getUserVar('stageId') != WORKFLOW_STAGE_ID_PRODUCTION) $request->getDispatcher()->handle404();

		$submissionId = $request->getUserVar('submissionId');
		$layoutFiles = $submissionFileDao->getLatestRevisions($submissionId, SUBMISSION_FILE_PRODUCTION_READY);
		foreach ($layoutFiles as $layoutFile) {
			if ($layoutFile->getFileId() != $request->getUserVar('fileId') || $layoutFile->getRevision() != $request->getUserVar('revision')) continue;
			if (!self::$plugin->isCandidateFile($layoutFile)) continue;

			import('lib.pkp.classes.file.SubmissionFileManager');
			$fileManager = new SubmissionFileManager($request->getContext()->getId(), $submissionId);
			if (!$fileManager->downloadById($layoutFile->getFileId(), $layoutFile->getRevision(), false, $layoutFile->getClientFileName())) {
				error_log('FileApiHandler: File ' . $layoutFile->getFilePath() . ' does not exist or is not readable!');
				header('HTTP/1.0 500 Internal Server Error');
				fatalError('500 Internal Server Error');
			} else return;
		}
		$request->getDispatcher()->handle404();
	}
}

