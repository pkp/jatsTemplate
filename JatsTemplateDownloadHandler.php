<?php

/**
 * @file JatsTemplateDownloadHandler.inc.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @package plugins.generic.jatsTemplate
 * @class JatsTemplateDownloadHandler
 */

namespace APP\plugins\generic\jatsTemplate;

use PKP\core\PKPRequest;
use PKP\security\Role;
use PKP\security\RoleDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\db\DAORegistry;
use PKP\config\Config;
use APP\facades\Repo;
use APP\handler\Handler;
use Firebase\JWT\JWT;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class JatsTemplateDownloadHandler extends Handler
{
    /** @var JatsTemplatePlugin The JATS Template plugin */
    public JatsTemplatePlugin $plugin;

    /**
     * Constructor
     */
    public function __construct(JatsTemplatePlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
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

    protected function _isUserAllowedAccess($request)
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context) {
            return false;
        }
        $roleDao = DAORegistry::getDAO('RoleDAO'); /** @var $roleDao RoleDAO */
        $roles = $roleDao->getByUserId($user->getId(), $context->getId());
        foreach ($roles as $role) {
            if (in_array($role->getRoleId(), [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUBSCRIPTION_MANAGER])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle a download request
     * @param $args array Arguments array.
     * @param $request PKPRequest Request object.
     */
    public function download($args, $request)
    {
        if (!$this->_isUserAllowedAccess($request)) {
            throw new NotFoundHttpException();
        }

        // Check the stage (this is only for consistency with other download URLs in the system
        // in case the built-in download handler can be used in place of this in the future)
        if ($request->getUserVar('stageId') != WORKFLOW_STAGE_ID_PRODUCTION) {
            throw new NotFoundHttpException();
        }

        $submissionId = $request->getUserVar('submissionId');
        $layoutFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$submissionId])
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
            ->getMany();
        foreach ($layoutFiles as $layoutFile) {
            if ($layoutFile->getId() != $request->getUserVar('submissionFileId') || $layoutFile->getData('fileId') != $request->getUserVar('fileId')) {
                continue;
            }

            $filename = app()->get('file')->formatFilename($layoutFile->getData('path'), $layoutFile->getLocalizedData('name'));
            app()->get('file')->download($layoutFile->getData('fileId'), $filename);
            return;
        }
        throw new NotFoundHttpException();
    }
}
