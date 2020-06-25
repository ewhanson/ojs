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

import('classes.handler.Handler');

class DOIManagementHandler extends Handler
{
	public $_isBackendPage = true;

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();

		$this->addRoleAssignment(
			[ROLE_ID_MANAGER, ROLE_ID_SITE_ADMIN],
			['index', 'management']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {

		import('lib.pkp.classes.security.authorization.PolicySet');
		$rolePolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);

		import('lib.pkp.classes.security.authorization.RoleBasedHandlerOperationPolicy');
		foreach($roleAssignments as $role => $operations) {
			$rolePolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, $role, $operations));
		}
		$this->addPolicy($rolePolicy);

		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Displays DOI management index page
	 * @param array $args
	 * @param PKPRequest $request
	 */
	public function index($args, $request) {
		$this->management($args, $request);
	}

	/**
	 * Displays DOI management page
	 * @param $args
	 * @param $request
	 */
	public function management($args, $request) {
		$this->setupTemplate($request);
		$plugin = $this->_getPlugin();
		$templateMgr = TemplateManager::getManager($request);

		$templateMgr->assign([
			'pageTitle' => __('plugins.pubIds.doi.manager.displayName'),
		]);

		$templateMgr->display($plugin->getTemplateResource('doiManagement.tpl'));
	}

	//
	// Private helper methods
	//
	/**
	 * Get the DOI plugin object
	 * @return DOIPubIdPlugin
	 */
	function _getPlugin() {
		return PluginRegistry::getPlugin('pubIds', DOI_PLUGIN_NAME);
	}
}
