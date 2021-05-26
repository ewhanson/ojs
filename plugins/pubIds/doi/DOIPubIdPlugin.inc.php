<?php

/**
 * @file plugins/pubIds/doi/DOIPubIdPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DOIPubIdPlugin
 * @ingroup plugins_pubIds_doi
 *
 * @brief DOI plugin class
 */

use APP\facades\Repo;
use APP\plugins\PubIdPlugin;
use APP\publication\Publication;
use APP\issue\Issue;
use APP\issue\IssueGalley;
use APP\article\ArticleGalley;

use PKP\services\interfaces\EntityWriteInterface;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\RemoteActionConfirmationModal;

use Illuminate\Support\Facades\DB;

class DOIPubIdPlugin extends PubIdPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            HookRegistry::register('CitationStyleLanguage::citation', [$this, 'getCitationData']);
            HookRegistry::register('Publication::getProperties::summaryProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Publication::getProperties::fullProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Publication::validate', [$this, 'validatePublicationDoi']);
            HookRegistry::register('Issue::getProperties::summaryProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Issue::getProperties::fullProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Galley::getProperties::summaryProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Galley::getProperties::fullProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Publication::getProperties::values', [$this, 'modifyObjectPropertyValues']);
            HookRegistry::register('Issue::getProperties::values', [$this, 'modifyObjectPropertyValues']);
            HookRegistry::register('Galley::getProperties::values', [$this, 'modifyObjectPropertyValues']);
            HookRegistry::register('Form::config::before', [$this, 'addPublicationFormFields']);
            HookRegistry::register('Form::config::before', [$this, 'addPublishFormNotice']);
            // Automatic DOI minting on publication
            HookRegistry::register('Publication::publish::before', [$this, 'mintPublicationDois']);
            HookRegistry::register('IssueGridHandler::publishIssue', [$this, 'mintIssueDoi']);
            // DOI management page
            HookRegistry::register('TemplateManager::setupBackendPage', [$this, 'setupDoiManagementPage']);
            HookRegistry::register('LoadHandler', [$this, 'callbackLoadHandler']);
            $this->_registerTemplateResource(true);
            HookRegistry::register('Submission::getMany::queryObject', [$this, 'modifySubmissionQueryObject']);
            // Issue with publication status, publications
            HookRegistry::register('Issue::getProperties::summaryProperties', [$this, 'modifyObjectProperties']);
            HookRegistry::register('Issue::getProperties::values', [$this, 'modifyObjectPropertyValues']);
            // Edit published DOIs API
            HookRegistry::register('APIHandler::endpoints', [$this, 'modifySubmissionEndpoints']);
            HookRegistry::register('PKPHandler::authorize', [$this, 'authorizeApiCalls']);
        }
        return $success;
    }

    /**
     * Automatically creates DOIs for publication and galleys on publication or scheduling.
     *
     * @param $hookName string 'Publication::publish::before'
     * @param $args array [
     * 		@option &$newPublication Publication
     * 		@option $publication Publication
     * 		@option $submission Submission
     * ]
     */
    public function mintPublicationDois($hookName, $args)
    {
        $newPublication = & $args[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();

        // Get and set publication DOI
        $publicationPubId = $this->getPubId($newPublication);
        // TODO: Check if DOI already set before setting data, especially for galleys that hit the DB
        // One way around this: only do all this when published, not scheduled. Will need to check hook orders
        $newPublication->setData('pub-id::' . $this->getPubIdType(), $publicationPubId);

        // Get and set DOIs for all galleys associated with publication, if enabled
        if ($this->getSetting($context->getId(), 'enableRepresentationDoi')) {
            $galleys = Services::get('galley')->getMany(['publicationIds' => $newPublication->getId()]);
            $articleGalleyDao = DAORegistry::getDAO('ArticleGalleyDAO'); /* @var $articleGalleyDao ArticleGalleyDAO */

            foreach ($galleys as $galley) {
                $galleyPubId = $this->getPubId($galley);
                $this->setStoredPubId($galley, $galleyPubId);
                $articleGalleyDao->updateObject($galley);
            }
        }

        return false;
    }

    /**
     * Automatically creates DOIs for Issue on publication.
     *
     * @param $hookName string 'IssueGridHandler::publishIssue'
     * @param $args array [
     * 		@option &$issue Issue
     * ]
     */
    public function mintIssueDoi($hookName, $args)
    {
        $issue = & $args[0];

        $pubId = $this->getPubId($issue);
        $issue->setStoredPubId($this->getPubIdType(), $pubId);

        // TODO: Temporary to only run once. PubId plugins registered multiple times. See https://github.com/pkp/pkp-lib/issues/5474#issuecomment-586952149
        return true;
    }

    /**
     * Sets up backend DOI management page
     *
     * @param $hookname string Name of hook being called
     * @param $args array Hook arguments
     */
    public function setupDoiManagementPage($hookname, $args)
    {
        $request = Application::get()->getRequest();
        $isEnabled = $this->getEnabled();
        // TODO: Check if this should be done here
        if ($isEnabled == false) {
            return;
        }

        $router = $request->getRouter();
        $handler = $router->getHandler();
        $userRoles = (array) $handler->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');

        // Add DOI management page to nav menu
        if ($isEnabled && array_intersect([ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER], $userRoles)) {
            $doiLink = [
                'name' => __('plugins.pubIds.doi.manager.displayName'),
                'url' => $router->url($request, null, 'doiManagement'),
                'isCurrent' => $request->getRequestedPage() === 'doiManagement',
            ];

            // Assign DOI Mangement link to menu
            $index = array_search('submissions', array_keys($menu));
            if ($index === false || count($menu) <= ($index + 1)) {
                $menu['doiManagement'] = $doiLink;
            } else {
                $menu = array_slice($menu, 0, $index + 1, true) +
                    ['doiManagement' => $doiLink] +
                    array_slice($menu, $index + 1, null, true);
            }

            $templateMgr->setState(['menu' => $menu]);
        }
    }

    /**
     * @see PKPPageRouter::route()
     *
     * @param $args array [
     * 		@option string page
     * 		@option string op
     * 		@option string handler file
     * ]
     */
    public function callbackLoadHandler($hookname, $args)
    {
        // Check the page.
        $page = $args[0];
        if ($page !== 'doiManagement') {
            return;
        }
        // Check the operation.
        $availableOps = ['index', 'management'];
        $op = $args[1];
        if (!in_array($op, $availableOps)) {
            return;
        }
        // The handler had been requested.
        define('HANDLER_CLASS', 'DOIManagementHandler');
        // Plugin name needed to fetch plugin from Handler
        define('DOI_PLUGIN_NAME', $this->getName());
        $handlerFile = & $args[2];
        $handlerFile = $this->getPluginPath() . '/pages/doiManagement/' . 'DOIManagementHandler.inc.php';
    }

    /**
     * Add custom endpoints to APIHandler
     *
     * @param $hookName string APIHandler::endpoints
     * @param $args array [
     * 		@option $endpoints array
     * 		@option $handler APIHandler
     * ]
     */
    public function modifySubmissionEndpoints($hookName, $args)
    {
        $endpoints = & $args[0];
        $handler = $args[1];

        switch ($handler) {
            case is_a($handler, 'PKPSubmissionHandler'):
                array_unshift(
                    $endpoints['PUT'],
                    [
                        'pattern' => $handler->getEndpointPattern() . '/{submissionId}/publications/{publicationId}/doi',
                        'handler' => [$this, 'editPublicationDoi'],
                        'roles' => [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],
                    ],
                    [
                        'pattern' => $handler->getEndpointPattern() . '/{submissionId}/publications/{publicationId}/galleys/{galleyId}/doi',
                        'handler' => [$this, 'editGalleyDoi'],
                        'roles' => [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],

                    ]
                );
                break;
            case is_a($handler, 'IssueHandler'):
                $endpoints['PUT'] = [];
                array_unshift(
                    $endpoints['PUT'],
                    [
                        'pattern' => $handler->getEndpointPattern() . '/{issueId}/doi',
                        'handler' => [$this, 'editIssueDoi'],
                        'roles' => [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],
                    ]
                );
                break;
        }
    }

    /**
     * Add custom authorization to custom API calls
     *
     * @param $hookName string PKPHandler::authorize
     * @param $args
     */
    public function authorizeApiCalls($hookName, $args)
    {
        $handler = $args[0];
        $request = $args[1];
        $handlerArgs = & $args[2];
        $roleAssignments = $args[3];

        $isAPIHandler = is_a($handler, 'APIHandler');
        $isPKPSubmissionHandler = is_a($handler, 'PKPSubmissionHandler');
        $isIssueHandler = is_a($handler, 'IssueHandler');

        // Check if submission, issue, or galley handler
        if ((!$isPKPSubmissionHandler || !$isIssueHandler) && !$isAPIHandler) {
            return;
        }

        // Add relevant authorization policies
        $routeName = $handler->getSlimRequest()->getAttribute('route')->getName();

        switch ($routeName) {
            case 'editPublicationDoi':
            case 'editGalleyDoi':
                import('lib.pkp.classes.security.authorization.SubmissionAccessPolicy');
                $handler->addPolicy(new SubmissionAccessPolicy($request, $args, $roleAssignments));

                import('lib.pkp.classes.security.authorization.PublicationWritePolicy');
                $handler->addPolicy(new PublicationWritePolicy($request, $args, $roleAssignments));
                break;
            case 'editIssueDoi':
                import('classes.security.authorization.OjsIssueRequiredPolicy');
                $handler->addPolicy(new OjsIssueRequiredPolicy($request, $args));
                break;
            default:
                break;
        }
    }

    /**
     * Edit the published DOI for one of this submission's publications
     *
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     * @param array $args arguments
     *
     * @return Response
     */
    public function editPublicationDoi($slimRequest, $response, $args)
    {
        $request = $this->getRequest();
        $handler = Application::get()->getRequest()->getRouter()->getHandler();

        $submission = $handler->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
        $currentUser = $request->getUser();
        $publication = Services::get('publication')->get((int) $args['publicationId']);

        if (!$publication) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
        }

        if ($submission->getId() !== $publication->getData('submissionId')) {
            return $response->withStatus(403)->withJsonError('api.publications.403.submissionsDidNotMatch');
        }

        // Prevent users from editing publications if they do not have permission. Except for admins.
        $userRoles = $handler->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        if (!in_array(ROLE_ID_SITE_ADMIN, $userRoles) && !Services::get('submission')->canEditPublication($submission->getId(), $currentUser->getId())) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.userCantEdit');
        }

        $params = $handler->convertStringsToSchema(SCHEMA_PUBLICATION, $slimRequest->getParsedBody());

        // Only DOIs can be edited once Publication has been published
        if (count($params) != 1 || !array_key_exists('pub-id::doi', $params)) {
            return $response->withStatus(403)->withJsonError('api.publication.403.cantEditPublished');
        }

        $submissionContext = $request->getContext();
        if (!$submissionContext || $submissionContext->getId() !== $submission->getData('contextId')) {
            $submissionContext = Services::get('context')->get($submission->getData('contextId'));
        }
        $primaryLocale = $publication->getData('locale');
        $allowedLocales = $submissionContext->getData('supportedSubmissionLocales');

        // validatePublicationDoi() expects these params
        $params['id'] = $args['publicationId'];
        $params['submissionId'] = $args['submissionId'];

        $errors = Services::get('publication')->validate(VALIDATE_ACTION_EDIT, $params, $allowedLocales, $primaryLocale);

        if (!empty($errors)) {
            return $response->withStatus(400)->withJson($errors);
        }

        $publication = Services::get('publication')->edit($publication, $params, $request);
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */

        $publicationProps = Services::get('publication')->getFullProperties(
            $publication,
            [
                'request' => $request,
                'userGroups' => $userGroupDao->getByContextId($submission->getData('contextId'))->toArray(),
            ]
        );

        return $response->withJson($publicationProps, 200);
    }

    /**
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     * @param $args arguments
     *
     * @return Response
     */
    public function editGalleyDoi($slimRequest, $response, $args)
    {
        // Populate objects
        $request = $this->getRequest();
        $handler = Application::get()->getRequest()->getRouter()->getHandler();

        $submission = $handler->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
        $currentUser = $request->getUser();
        $publication = Services::get('publication')->get((int) $args['publicationId']);
        $galley = Services::get('galley')->get((int) $args['galleyId']);

        // Check for reasons to reject the request
        if (!$galley) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
        }

        if ($submission->getId() !== $publication->getData('submissionId')) {
            return $response->withStatus(403)->withJsonError('api.publications.403.submissionsDidNotMatch');
        }

        if ($publication->getId() !== $galley->getData('publicationId')) {
            return $response->withStatus(403)->withJsonError('api.galley.403.submissionsDidNotMatch');
        }

        // Prevent users from editing galleys if they do not have permission. Except for admins.
        $userRoles = $handler->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        if (!in_array(ROLE_ID_MANAGER, $userRoles) && !Services::get('submission')->canEditPublication($submission->getId(), $currentUser->getId())) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.userCantEdit');
        }

        // Make, validate, and save change
        $params = $handler->convertStringsToSchema(SCHEMA_GALLEY, $slimRequest->getParsedBody());

        // Only DOIs can be edited once Publication has been published
        if (count($params) != 1 || !array_key_exists('pub-id::doi', $params)) {
            return $response->withStatus(403)->withJsonError('api.galley.403.cantEditPublished');
        }

        $submissionContext = $request->getContext();
        if (!$submissionContext || $submissionContext->getId() !== $submission->getData('contextId')) {
            $submissionContext = Services::get('context')->get($submission->getData('contextId'));
        }
        $primaryLocale = $publication->getData('locale');
        $allowedLocales = $submissionContext->getData('supportedSubmissionLocales');

        $errors = Services::get('galley')->validate(VALIDATE_ACTION_EDIT, $params, $allowedLocales, $primaryLocale);

        if (!empty($errors)) {
            return $response->withStatus(400)->withJson($errors);
        }

        // Get props
        $galley = Services::get('galley')->edit($galley, $params, $request);
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */

        // Return response
        $galleyProps = Services::get('galley')->getFullProperties(
            $galley,
            [
                'request' => $request,
                'userGroups' => $userGroupDao->getByContextId($submission->getData('contextId'))->toArray(),
            ]
        );

        return $response->withJson($galleyProps, 200);
    }

    /**
     * Edit the published DOI for an issue
     *
     * @param $slimRequest Request Slim request object
     * @param $response Response object
     * @param array $args arguments
     *
     * @return Response
     */
    public function editIssueDoi($slimRequest, $response, $args)
    {
        $request = $this->getRequest();
        $handler = Application::get()->getRequest()->getRouter()->getHandler();

        $issue = $handler->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);

        if (!$issue) {
            return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
        }

        // Prevent users from editing publications if they do not have permission. Except for admins.
        $userRoles = $handler->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        if (!in_array(ROLE_ID_SITE_ADMIN, $userRoles)) {
            return $response->withStatus(403)->withJsonError('api.submissions.403.userCantEdit');
        }

        $params = $handler->convertStringsToSchema(SCHEMA_ISSUE, $slimRequest->getParsedBody());

        // Only DOIs can be edited once Issue has been published
        if (count($params) != 1 || !array_key_exists('pub-id::doi', $params)) {
            return $response->withStatus(403)->withJsonError('api.issue.403.canEditPublishedDoi');
        }

        if ($this->validatePubId($params['pub-id::doi']) == false) {
            return $response->withStatus(400)->withJsonError('api.issue.400.invalidDoi');
        }

        // Save changes
        $issueDao = DAORegistry::getDAO('IssueDAO');
        $issueDao->changePubId($issue->getId(), $this->getPubIdType(), $params['pub-id::doi']);

        // Send props
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */

        $issueProps = Services::get('issue')->getFullProperties(
            $issue,
            [
                'request' => $request,
                'userGroups' => $userGroupDao->getByContextId($issue->getData('contextId'))->toArray(),
                'slimRequest' => $slimRequest
            ],
        );

        return $response->withJson($issueProps, 200);
    }

    //
    // Implement template methods from Plugin.
    //
    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.pubIds.doi.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.pubIds.doi.description');
    }


    //
    // Implement template methods from PubIdPlugin.
    //
    /**
     * @copydoc PKPPubIdPlugin::constructPubId()
     */
    public function constructPubId($pubIdPrefix, $pubIdSuffix, $contextId)
    {
        return $pubIdPrefix . '/' . $pubIdSuffix;
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdType()
     */
    public function getPubIdType()
    {
        return 'doi';
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdDisplayType()
     */
    public function getPubIdDisplayType()
    {
        return 'DOI';
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdFullName()
     */
    public function getPubIdFullName()
    {
        return 'Digital Object Identifier';
    }

    /**
     * @copydoc PKPPubIdPlugin::getResolvingURL()
     */
    public function getResolvingURL($contextId, $pubId)
    {
        return 'https://doi.org/' . $this->_doiURLEncode($pubId);
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdMetadataFile()
     */
    public function getPubIdMetadataFile()
    {
        return $this->getTemplateResource('doiSuffixEdit.tpl');
    }

    /**
     * @copydoc PKPPubIdPlugin::getPubIdAssignFile()
     */
    public function getPubIdAssignFile()
    {
        return $this->getTemplateResource('doiAssignInfo.tpl');
    }

    public function getDoiManagementLink()
    {
        $dispatcher = Application::get()->getDispatcher();
        $request = Application::get()->getRequest();

        return $dispatcher->url($request, ROUTE_PAGE, $request->getcontext()->getPath(), 'doiManagement', 'management');
    }

    /**
     * @copydoc PKPPubIdPlugin::instantiateSettingsForm()
     */
    public function instantiateSettingsForm($contextId)
    {
        $this->import('classes.form.DOISettingsForm');
        return new DOISettingsForm($this, $contextId);
    }

    /**
     * @copydoc PKPPubIdPlugin::getFormFieldNames()
     */
    public function getFormFieldNames()
    {
        return ['doiSuffix'];
    }

    /**
     * @copydoc PKPPubIdPlugin::getAssignFormFieldName()
     */
    public function getAssignFormFieldName()
    {
        return 'assignDoi';
    }

    /**
     * @copydoc PKPPubIdPlugin::getPrefixFieldName()
     */
    public function getPrefixFieldName()
    {
        return 'doiPrefix';
    }

    /**
     * @copydoc PKPPubIdPlugin::getSuffixFieldName()
     */
    public function getSuffixFieldName()
    {
        return 'doiSuffix';
    }

    /**
     * @copydoc PKPPubIdPlugin::getLinkActions()
     */
    public function getLinkActions($pubObject)
    {
        $linkActions = [];
        $request = Application::get()->getRequest();
        $userVars = $request->getUserVars();
        $classNameParts = explode('\\', get_class($this)); // Separate namespace info from class name
        $userVars['pubIdPlugIn'] = end($classNameParts);
        // Clear object pub id
        $linkActions['clearPubIdLinkActionDoi'] = new LinkAction(
            'clearPubId',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.pubIds.doi.editor.clearObjectsDoi.confirm'),
                __('common.delete'),
                $request->url(null, null, 'clearPubId', null, $userVars),
                'modal_delete'
            ),
            __('plugins.pubIds.doi.editor.clearObjectsDoi'),
            'delete',
            __('plugins.pubIds.doi.editor.clearObjectsDoi')
        );

        if ($pubObject instanceof Issue) {
            // Clear issue objects pub ids
            $linkActions['clearIssueObjectsPubIdsLinkActionDoi'] = new LinkAction(
                'clearObjectsPubIds',
                new RemoteActionConfirmationModal(
                    $request->getSession(),
                    __('plugins.pubIds.doi.editor.clearIssueObjectsDoi.confirm'),
                    __('common.delete'),
                    $request->url(null, null, 'clearIssueObjectsPubIds', null, $userVars),
                    'modal_delete'
                ),
                __('plugins.pubIds.doi.editor.clearIssueObjectsDoi'),
                'delete',
                __('plugins.pubIds.doi.editor.clearIssueObjectsDoi')
            );
        }

        return $linkActions;
    }

    /**
     * @copydoc PKPPubIdPlugin::getSuffixPatternsFieldNames()
     */
    public function getSuffixPatternsFieldNames()
    {
        return [
            'Issue' => 'doiIssueSuffixPattern',
            'Publication' => 'doiPublicationSuffixPattern',
            'Representation' => 'doiRepresentationSuffixPattern'
        ];
    }

    /**
     * @copydoc PKPPubIdPlugin::getDAOFieldNames()
     */
    public function getDAOFieldNames()
    {
        return ['pub-id::doi'];
    }

    /**
     * @copydoc PKPPubIdPlugin::isObjectTypeEnabled()
     */
    public function isObjectTypeEnabled($pubObjectType, $contextId)
    {
        return (bool) $this->getSetting($contextId, "enable${pubObjectType}Doi");
    }

    /**
     * @copydoc PKPPubIdPlugin::isObjectTypeEnabled()
     */
    public function getNotUniqueErrorMsg()
    {
        return __('plugins.pubIds.doi.editor.doiSuffixCustomIdentifierNotUnique');
    }

    /**
     * @copydoc PKPPubIdPlugin::validatePubId()
     */
    public function validatePubId($pubId)
    {
        return preg_match('/^\d+(.\d+)+\//', $pubId);
    }

    /*
     * Public methods
     */
    /**
     * Add DOI to citation data used by the CitationStyleLanguage plugin
     *
     * @see CitationStyleLanguagePlugin::getCitation()
     *
     * @param $hookname string
     * @param $args array
     *
     * @return false
     */
    public function getCitationData($hookname, $args)
    {
        $citationData = $args[0];
        $article = $args[2];
        $issue = $args[3];
        $journal = $args[4];

        if ($issue && $issue->getPublished()) {
            $pubId = $article->getStoredPubId($this->getPubIdType());
        } else {
            $pubId = $this->getPubId($article);
        }

        if (!$pubId) {
            return;
        }

        $citationData->DOI = $pubId;
    }


    /*
     * Private methods
     */
    /**
     * Encode DOI according to ANSI/NISO Z39.84-2005, Appendix E.
     *
     * @param $pubId string
     *
     * @return string
     */
    public function _doiURLEncode($pubId)
    {
        $search = ['%', '"', '#', ' ', '<', '>', '{'];
        $replace = ['%25', '%22', '%23', '%20', '%3c', '%3e', '%7b'];
        $pubId = str_replace($search, $replace, $pubId);
        return $pubId;
    }

    /**
     * Validate a publication's DOI against the plugin's settings
     *
     * @param $hookName string
     * @param $args array
     */
    public function validatePublicationDoi($hookName, $args)
    {
        $errors = & $args[0];
        $object = $args[1];
        $props = & $args[2];

        if (empty($props['pub-id::doi'])) {
            return;
        }

        if (is_null($object)) {
            $submission = Repo::submission()->get($props['submissionId']);
        } else {
            $publication = Repo::publication()->get($props['id']);
            $submission = Repo::submission()->get($publication->getData('submissionId'));
        }

        $contextId = $submission->getData('contextId');
        $doiPrefix = $this->getSetting($contextId, 'doiPrefix');

        $doiErrors = [];
        if (strpos($props['pub-id::doi'], $doiPrefix) !== 0) {
            $doiErrors[] = __('plugins.pubIds.doi.editor.missingPrefix', ['doiPrefix' => $doiPrefix]);
        }
        if (!$this->checkDuplicate($props['pub-id::doi'], 'Publication', $submission->getId(), $contextId)) {
            $doiErrors[] = $this->getNotUniqueErrorMsg();
        }
        if (!empty($doiErrors)) {
            $errors['pub-id::doi'] = $doiErrors;
        }
    }

    /**
     * Add DOI to submission, issue or galley properties
     *
     * @param $hookName string <Object>::getProperties::summaryProperties or
     *  <Object>::getProperties::fullProperties
     * @param $args array [
     * 		@option $props array Existing properties
     * 		@option $object Submission|Issue|Galley
     * 		@option $args array Request args
     * ]
     *
     * @return array
     */
    public function modifyObjectProperties($hookName, $args)
    {
        $props = & $args[0];
        $object = $args[1];
        $propertyArgs = $args[2];

        $props[] = 'pub-id::doi';

        // Used in Issue DOI management
		// TODO: See if only used with Crossref or DOI management at large
        if ($object instanceof Issue && isset($_REQUEST['crossrefPluginEnabled']) && $_REQUEST['crossrefPluginEnabled'] == true)
		{
            $props[] = 'isPublished';
            $props[] = 'articles';
        }
    }

    /**
     * Add DOI submission, issue or galley values
     *
     * @param $hookName string <Object>::getProperties::values
     * @param $args array [
     * 		@option $values array Key/value store of property values
     * 		@option $object Submission|Issue|Galley
     * 		@option $props array Requested properties
     * 		@option $args array Request args
     * ]
     *
     * @return array
     */
    public function modifyObjectPropertyValues($hookName, $args)
    {
        $values = & $args[0];
        $object = $args[1];
        $props = $args[2];

        // DOIs are not supported for IssueGalleys
        if ($object instanceof IssueGalley) {
            return;
        }

        // DOIs are already added to property values for Publications and Galleys
        if ($object instanceof Publication || $object instanceof ArticleGalley) {
            return;
        }

        if (in_array('pub-id::doi', $props)) {
            $pubId = $this->getPubId($object);
            $values['pub-id::doi'] = $pubId ? $pubId : null;
        }

        // Used in Issue DOI management
        if ($object instanceof Issue && in_array('isPublished', $props)) {
            $values['isPublished'] = (bool) $object->getPublished();
        }
    }

    /**
     * Add DOI fields to the publication identifiers form
     *
     * @param $hookName string Form::config::before
     * @param $form FormComponent The form object
     */
    public function addPublicationFormFields($hookName, $form)
    {
        if ($form->id !== 'publicationIdentifiers') {
            return;
        }

        if (!$this->getSetting($form->submissionContext->getId(), 'enablePublicationDoi')) {
            return;
        }

        $prefix = $this->getSetting($form->submissionContext->getId(), 'doiPrefix');

        $suffixType = $this->getSetting($form->submissionContext->getId(), 'doiSuffix');
        $pattern = '';
        if ($suffixType === 'default') {
            $pattern = '%j.v%vi%i.%a';
        } elseif ($suffixType === 'pattern') {
            $pattern = $this->getSetting($form->submissionContext->getId(), 'doiPublicationSuffixPattern');
        }

        // Add a text field to enter the DOI if no pattern exists
        if (!$pattern) {
            $form->addField(new \PKP\components\forms\FieldText('pub-id::doi', [
                'label' => __('metadata.property.displayName.doi'),
                'description' => __('plugins.pubIds.doi.editor.doi.description', ['prefix' => $prefix]),
                'value' => $form->publication->getData('pub-id::doi'),
            ]));
        } else {
            $fieldData = [
                'label' => __('metadata.property.displayName.doi'),
                'value' => $form->publication->getData('pub-id::doi'),
                'prefix' => $prefix,
                'pattern' => $pattern,
                'contextInitials' => PKPString::regexp_replace('/[^A-Za-z0-9]/', '', PKPString::strtolower($form->submissionContext->getData('acronym', $form->submissionContext->getData('primaryLocale')))) ?? '',
                'separator' => '/',
                'submissionId' => $form->publication->getData('submissionId'),
                'assignIdLabel' => __('plugins.pubIds.doi.editor.doi.assignDoi'),
                'clearIdLabel' => __('plugins.pubIds.doi.editor.clearObjectsDoi'),
            ];
            if ($form->publication->getData('pub-id::publisher-id')) {
                $fieldData['publisherId'] = $form->publication->getData('pub-id::publisher-id');
            }
            if ($form->publication->getData('pages')) {
                $fieldData['pages'] = $form->publication->getData('pages');
            }
            if ($form->publication->getData('issueId')) {
                $issue = Services::get('issue')->get($form->publication->getData('issueId'));
                if ($issue) {
                    $fieldData['issueNumber'] = $issue->getNumber() ?? '';
                    $fieldData['issueVolume'] = $issue->getVolume() ?? '';
                    $fieldData['year'] = $issue->getYear() ?? '';
                }
            }
            if ($suffixType === 'default') {
                $fieldData['missingPartsLabel'] = __('plugins.pubIds.doi.editor.missingIssue');
            } else {
                $fieldData['missingPartsLabel'] = __('plugins.pubIds.doi.editor.missingParts');
            }
            $form->addField(new \PKP\components\forms\FieldPubId('pub-id::doi', $fieldData));
        }
    }

    /**
     * Show DOI during final publish step
     *
     * @param $hookName string Form::config::before
     * @param $form FormComponent The form object
     */
    public function addPublishFormNotice($hookName, $form)
    {
        if ($form->id !== 'publish' || !empty($form->errors)) {
            return;
        }

        $submission = Repo::submission()->get($form->publication->getData('submissionId'));
        $publicationDoiEnabled = $this->getSetting($submission->getData('contextId'), 'enablePublicationDoi');
        $galleyDoiEnabled = $this->getSetting($submission->getData('contextId'), 'enableRepresentationDoi');
        $warningIconHtml = '<span class="fa fa-exclamation-triangle pkpIcon--inline"></span>';

        if (!$publicationDoiEnabled && !$galleyDoiEnabled) {
            return;

        // Use a simplified view when only assigning to the publication
        } elseif (!$galleyDoiEnabled) {
            if ($form->publication->getData('pub-id::doi')) {
                $msg = __('plugins.pubIds.doi.editor.preview.publication', ['doi' => $form->publication->getData('pub-id::doi')]);
            } else {
                $toBeAssignedPubId = $this->getpubId($form->publication);
                if ($toBeAssignedPubId != null) {
                    $msg = __('plugins.pubIds.doi.editor.preview.publication', ['doi' => $toBeAssignedPubId]);
                } else {
                    $msg = '<div class="pkpNotification pkpNotification--warning">' . $warningIconHtml . __('plugins.pubIds.doi.editor.preview.publication.none') . '</div>';
                }
            }
            $form->addField(new \PKP\components\forms\FieldHTML('doi', [
                'description' => $msg,
                'groupId' => 'default',
            ]));
            return;

        // Show a table if more than one DOI is going to be created
        } else {
            $doiTableRows = [];
            if ($publicationDoiEnabled) {
                if ($form->publication->getData('pub-id::doi')) {
                    $doiTableRows[] = [$form->publication->getData('pub-id::doi'), 'Publication'];
                } else {
                    $toBeAssignedPubId = $this->getpubId($form->publication);
                    if ($toBeAssignedPubId != null) {
                        $doiTableRows[] = [$toBeAssignedPubId, 'Publication'];
                    } else {
                        $doiTableRows[] = [$warningIconHtml . __('submission.status.unassigned'), 'Publication'];
                    }
                }
            }
            if ($galleyDoiEnabled) {
                foreach ((array) $form->publication->getData('galleys') as $galley) {
                    if ($galley->getStoredPubId('doi')) {
                        $doiTableRows[] = [$galley->getStoredPubId('doi'), __('plugins.pubIds.doi.editor.preview.galleys', ['galleyLabel' => $galley->getGalleyLabel()])];
                    } else {
                        $toBeAssignedPubId = $this->getpubId($galley);
                        if ($toBeAssignedPubId != null) {
                            $doiTableRows[] = [$toBeAssignedPubId, __('plugins.pubIds.doi.editor.preview.galleys', ['galleyLabel' => $galley->getGalleyLabel()])];
                        } else {
                            $doiTableRows[] = [$warningIconHtml . __('submission.status.unassigned'), __('plugins.pubIds.doi.editor.preview.galleys', ['galleyLabel' => $galley->getGalleyLabel()])];
                        }
                    }
                }
            }
            if (!empty($doiTableRows)) {
                $table = '<table class="pkpTable"><thead><tr>' .
                    '<th>' . __('plugins.pubIds.doi.editor.doi') . '</th>' .
                    '<th>' . __('plugins.pubIds.doi.editor.preview.objects') . '</th>' .
                    '</tr></thead><tbody>';
                foreach ($doiTableRows as $doiTableRow) {
                    $table .= '<tr><td>' . $doiTableRow[0] . '</td><td>' . $doiTableRow[1] . '</td></tr>';
                }
                $table .= '</tbody></table>';
            }
            $form->addField(new \PKP\components\forms\FieldHTML('doi', [
                'description' => $table,
                'groupId' => 'default',
            ]));
        }
    }
}
