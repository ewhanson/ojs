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

	public function management(Array $args, Request $request) {
		// TODO: Validation and authorization
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
