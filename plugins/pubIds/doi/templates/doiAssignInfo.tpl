{**
 * @file plugins/pubIds/doi/templates/doiAssignInfo.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Assign DOI to an object option.
 *}

{assign var=pubObjectType value=$pubIdPlugin->getPubObjectType($pubObject)}
{assign var=enableObjectDoi value=$pubIdPlugin->getSetting($currentContext->getId(), "enable`$pubObjectType`Doi")}
{if $enableObjectDoi}
	{fbvFormArea id="pubIdDOIFormArea" class="border" title="plugins.pubIds.doi.editor.doi"}
	{if $pubObject->getStoredPubId($pubIdPlugin->getPubIdType())}
		{fbvFormSection}
			<p class="pkp_help">{translate key="plugins.pubIds.doi.editor.assignDoi.assigned" pubId=$pubObject->getStoredPubId($pubIdPlugin->getPubIdType()) doiManagementLink=$doiManagementLink}}</p>
		{/fbvFormSection}
	{else}
		{assign var=pubId value=$pubIdPlugin->getPubId($pubObject)}
		{if !$canBeAssigned}
			{fbvFormSection}
			{if !$pubId}
				<p class="pkp_help">{translate key="plugins.pubIds.doi.editor.assignDoi.emptySuffix"}</p>
			{else}
				<p class="pkp_help">{translate key="plugins.pubIds.doi.editor.assignDoi.pattern" pubId=$pubId}</p>
			{/if}
			{/fbvFormSection}
		{else}
			{assign var=doiManagementLink value=$pubIdPlugin->getDoiManagementLink()}
			<p class="pkp_help">{translate key="plugins.pubIds.doi.editor.assignDoi.toBeAssigned" pubId=$pubId}</p>
		{/if}
	{/if}
	{/fbvFormArea}
{/if}
