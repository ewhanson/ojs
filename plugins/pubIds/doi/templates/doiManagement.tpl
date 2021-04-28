{extends file="layouts/backend.tpl"}
{block name="page"}
	<!-- Add page content here -->
	<h1 class="app__pageHeading">
		{translate key="plugins.pubIds.doi.manager.displayName"}
	</h1>

	<tabs>
		{if $displayArticlesTab}
			<!-- TODO: Localize label -->
			<tab id="article-doi-management" label="Articles">
				<doi-list-panel
					v-bind="components.submissionDoiListPanel"
					@set="set"
				/>
			</tab>
		{/if}
		{if $displayIssuesTab}
			<!-- TODO: Localize label -->
			<tab id="issue-doi-management" label="Issues">
				<doi-list-panel
						v-bind="components.issueDoiListPanel"
						@set="set"
				/>
			</tab>
		{/if}

		<tab id="doi-settings" label={translate key="navigation.settings"}>
			{capture assign=doiSettingsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="doipubidplugin" category="pubIds" verb="" escape=false}{/capture}
			{load_url_in_div id="doiSettingsGridUrl" url=$doiSettingsGridUrl}
		</tab>

		{call_hook name="Template::doiManagement"}
	</tabs>
{/block}
