<?php

/**
 * @file plugins/pubIds/doi/pages/doiManagement/DOIManagementHandler.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DOIManagementHandler
 * @ingroup pages_doiManagement
 *
 * @brief Handle requests for DOI management functions.
 */

import('plugins.pubIds.doi.classes.DoiListPanel');

use APP\handler\Handler;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;

use PKP\security\Role;

class DOIManagementHandler extends Handler
{
    public const ENABLE_ISSUE_DOI = 'enableIssueDoi';
    public const ENABLE_PUBLICATION_DOI = 'enablePublicationDoi';
    public const ENABLE_REPRESENTATION_DOI = 'enableRepresentationDoi';

    public $_isBackendPage = true;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
            ['index', 'management']
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $rolePolicy = new PolicySet(PolicySet::COMBINING_PERMIT_OVERRIDES);

        foreach ($roleAssignments as $role => $operations) {
            $rolePolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, $role, $operations));
        }
        // TODO: See if problem re: warning, "Expected parameter of type 'AuthorizationPolicy', 'PolicySet' provided
        $this->addPolicy($rolePolicy);

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Displays DOI management index page
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->management($args, $request);
    }

    /**
     * Displays DOI management page
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function management($args, $request)
    {
        $this->setupTemplate($request);

        $plugin = $this->_getPlugin();
        $context = $request->getContext();
        $contextId = $context->getId();

        $issueDoisEnabled = $plugin->getSetting($contextId, self::ENABLE_ISSUE_DOI);
        $publicationDoisEnabled = $plugin->getSetting($contextId, self::ENABLE_PUBLICATION_DOI);
        $representationDoisEnabled = $plugin->getSetting($contextId, self::ENABLE_REPRESENTATION_DOI);

        $enabledPublishingObjects = [];
        if ($issueDoisEnabled) {
            array_push($enabledPublishingObjects, 'issues');
        }
        if ($publicationDoisEnabled) {
            array_push($enabledPublishingObjects, 'publications');
        }
        if ($representationDoisEnabled) {
            array_push($enabledPublishingObjects, 'representations');
        }

        $templateMgr = TemplateManager::getManager($request);

        $commonArgs = [
            'doiPrefix' => $plugin->getSetting($request->getContext()->getId(), $plugin->getPrefixFieldName()) . '/',
            'getParams' => [],
            'lazyLoad' => true,
            'enabledPublishingObjects' => $enabledPublishingObjects,
        ];

        HookRegistry::call('DoiManagement::setListPanelArgs', [&$commonArgs]);

        $stateComponents = [];

        if ($publicationDoisEnabled || $representationDoisEnabled) {
            $submissionDoiListPanel = new DoiListPanel(
                'submissionDoiListPanel',
                __('plugins.pubIds.doi.manager.articleDois'),
                array_merge(
                    $commonArgs,
                    [
                        'apiUrl' => $request->getDispatcher()->url($request, ROUTE_API, $context->getPath(), 'submissions'),
                        'isSubmission' => true,
                        'includeIssuesFilter' => true,
                    ]
                )
            );
            $stateComponents[$submissionDoiListPanel->id] = $submissionDoiListPanel->getConfig();
        }

        if ($issueDoisEnabled) {
            $issueDoiListPanel = new DoiListPanel(
                'issueDoiListPanel',
                __('plugins.pubIds.doi.manager.issueDois'),
                array_merge(
                    $commonArgs,
                    [
                        'apiUrl' => $request->getDispatcher()->url($request, ROUTE_API, $context->getPath(), 'issues'),
                        'isSubmission' => false,
                        'includeIssuesFilter' => false,
                    ]
                )
            );
            $stateComponents[$issueDoiListPanel->id] = $issueDoiListPanel->getConfig();
        }

        $templateMgr->setState(['components' => $stateComponents]);

        $templateMgr->assign([
            'pageTitle' => __('plugins.pubIds.doi.manager.displayName'),
            'displayArticlesTab' => $publicationDoisEnabled || $representationDoisEnabled,
            'displayIssuesTab' => $issueDoisEnabled
        ]);

        $templateMgr->display($plugin->getTemplateResource('doiManagement.tpl'));
    }

    //
    // Private helper methods
    //
    /**
     * Get the DOI plugin object
     *
     * @return DOIPubIdPlugin
     */
    public function _getPlugin()
    {
        return PluginRegistry::getPlugin('pubIds', DOI_PLUGIN_NAME);
    }

    /**
     * Get Crossref plugin status
     *
     * @return bool
     */
    public function _getCrossrefPluginStatus()
    {
        $crossrefEnabled = false;
        $importExportPlugins = PluginRegistry::getPlugins('importexport');

        foreach ($importExportPlugins as $importExportPlugin) {
            if ($importExportPlugin instanceof CrossrefExportPlugin) {
                $crossrefEnabled = true;
                break;
            }
        }

        return $crossrefEnabled;
    }
}
