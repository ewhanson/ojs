<?php

/**
 * @file plugins/pubIds/doi/classes/DoiListPanel.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiListPanel
 * @ingroup classes_components_list
 *
 * @brief A ListPanel component for viewing and editing DOIs
 */

use APP\components\forms\FieldSelectIssues;
use PKP\submission\PKPSubmission;

class DoiListPanel extends PKP\components\listPanels\ListPanel
{
    /** @var string URL to the API endpoint where items can be retrieved */
    public $apiUrl = '';

    /** @var int How many items to display on one page in this list */
    public $count = 30;

    /** @var array Query parameters to pass if this list executes GET requests */
    public $getParams = [];

    /** @var int Max number of items available to display in this list panel */
    public $itemsMax = 0;

    /** @var bool Whether objects being passed to DOI List Panel are submissions or not */
    public $isSubmission = true;

    /** @var boolean Should items be loaded after the component is mounted? */
    public $lazyLoad = false;

    /** @var string DOI prefix set in DOI settings */
    public $doiPrefix = '';

    // TODO: Relocate to crossref plugin
    public $crossrefPluginEnabled = false;

    /** @var boolean Whether to show issue filters */
    public $includeIssuesFilter = false;

    /** @var array Which publishing objecst have DOIs enabled */
    public $enabledPublishingObjects = [];

    /** @var string DOI API url for handling DOI operations */
    public $doiApiUrl = '';


    /**
     * @copydoc ListPanel::getConfig()
     */
    public function getConfig()
    {
        AppLocale::requireComponents([LOCALE_COMPONENT_PKP_SUBMISSION]);
        $request = Application::get()->getRequest();

        $config = parent::getConfig();
        $config['apiUrl'] = $this->apiUrl;
        $config['doiApiUrl'] = $this->doiApiUrl;
        $config['count'] = $this->count;
        $config['getParams'] = $this->getParams;
        $config['lazyLoad'] = $this->lazyLoad;
        $config['itemsMax'] = $this->itemsMax;
        $config['hasDOIs'] = $this->enabledPublishingObjects;

        if ($this->isSubmission) {
            $config['filters'][] = [
                'heading' => 'Publication Status',
                'filters' => [
                    [
                        'title' => 'Published',
                        'param' => 'status',
                        'value' => (string) PKPSubmission::STATUS_PUBLISHED
                    ],
                    [
                        'title' => 'Unpublished',
                        'param' => 'status',
                        'value' => PKPSubmission::STATUS_QUEUED . ', ' . PKPSubmission::STATUS_SCHEDULED
                    ]
                ]
            ];
        } else {
            $config['filters'][] = [
                'heading' => 'Publication Status',
                'filters' => [
                    [
                        'title' => 'Published',
                        'param' => 'isPublished',
                        'value' => '1'
                    ],
                    [
                        'title' => 'Unpublished',
                        'param' => 'isPublished',
                        'value' => '0'
                    ],
                ]
            ];
        }

        if ($this->includeIssuesFilter) {
            $issueAutosuggestField = new FieldSelectIssues('issueIds', [
                'label' => __('issue.issues'),
                'value' => [],
                'apiUrl' => $request->getDispatcher()->url($request, PKPApplication::ROUTE_API, $request->getContext()->getPath(), 'issues'),
            ]);
            $config['filters'][] = [
                'filters' => [
                    [
                        'title' => __('issue.issues'),
                        'param' => 'issueIds',
                        'value' => [],
                        'filterType' => 'pkp-filter-autosuggest',
                        'component' => 'field-select-issues',
                        'autosuggestProps' => $issueAutosuggestField->getConfig(),
                    ]
                ]
            ];
        }

        $config = array_merge(
            $config,
            [
                // TODO: To remove. Make sure not changed elsewhere
                //                'apiUrl' => $this->apiUrl,
                //                'count' => $this->count,
                'doiPrefix' => $this->doiPrefix,
                //                'itemsMax' => $this->itemsMax,
                'isSubmission' => $this->isSubmission,
            ]
        );

        HookRegistry::call('DoiListPanel::setConfig', [&$config]);

        // Provide required locale keys
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->setConstants([
            PKPSubmission::STATUS_QUEUED,
            PKPSubmission::STATUS_PUBLISHED,
            PKPSubmission::STATUS_DECLINED,
            PKPSubmission::STATUS_SCHEDULED,
        ]);
        $templateMgr->setLocaleKeys([
            'plugins.importexport.crossref.status.notDeposited',
            'publication.status.unpublished',
            'publication.status.published'
        ]);

        return $config;
    }
}
