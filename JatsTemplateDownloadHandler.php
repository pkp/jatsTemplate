<?php

/**
 * @file JatsTemplateDownloadHandler.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief JATS download handler
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
use PKP\core\PKPJwt as JWT;
use Firebase\JWT\Key;
use stdClass;
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
                $secret = Config::getVar('security', 'api_key_secret', '');
                $headers = new stdClass();
                $apiToken = ((array)JWT::decode($jwt, new Key($secret, 'HS256'), $headers))[0]; /** @var string $apiToken */
                $this->setApiToken($apiToken);
            }
        }

        $this->addPolicy(new \PKP\security\authorization\ContextRequiredPolicy($request));
        $this->addPolicy(new \APP\security\authorization\OjsJournalMustPublishPolicy($request));

        return parent::authorize($request, $args, $roleAssignments);
    }

    protected function isUserAllowedAccess(PKPRequest $request): bool
    {
        $user = $request->getUser();
        $context = $request->getContext();
        if (!$user || !$context) {
            return false;
        }
        $roleDao = DAORegistry::getDAO('RoleDAO'); /** @var RoleDAO $roleDao */
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
     */
    public function download(array $args, PKPRequest $request): void
    {
        if (!$this->isUserAllowedAccess($request)) {
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
