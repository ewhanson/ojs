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

class DoiListPanel extends PKP\components\listPanels\ListPanel
{
    /** @var string URL to the API endpoint where items can be retrieved */
    public $apiUrl = '';

    /** @var int How many items to display on one page in this list */
    public $count = 30;

    /** @var array Query parameters to pass if this list executes GET requests */
    public $getParams = [];

    /** @var int Max number of items available to display in this list panel */
    public $itemsMax = [];

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

    /**
     * @copydoc ListPanel::getConfig()
     */
    public function getConfig()
    {
        AppLocale::requireComponents([LOCALE_COMPONENT_PKP_SUBMISSION]);
        $request = Application::get()->getRequest();

        $config = parent::getConfig();
        $config['apiUrl'] = $this->apiUrl;
        $config['count'] = $this->count;
        $config['getParams'] = $this->getParams;
        $config['lazyLoad'] = $this->lazyLoad;
        $config['itemMax'] = $this->itemsMax;
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
            $config['filters'][] = [
                'heading' => 'Crossref Deposit Status',
                'filters' => [
                    [
                        'title' => 'Not deposited',
                        'param' => 'crossrefStatus',
                        'value' => 'notDeposited'
                    ],
                    [
                        'title' => 'Active',
                        'param' => 'crossrefStatus',
                        'value' => 'registered'
                    ],
                    [
                        'title' => 'Failed',
                        'param' => 'crossrefStatus',
                        'value' => 'failed'
                    ],
                    [
                        'title' => 'Marked Active',
                        'param' => 'crossrefStatus',
                        'value' => 'markedRegistered'
                    ],
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
                        'title' => 'Published',
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

        // TODO: Make inclusion of crossref plugin dependent on Crossref plugin, not DOI plugin
        $config = array_merge(
            $config,
            [
                'apiUrl' => $this->apiUrl,
                'count' => $this->count,
                'doiPrefix' => $this->doiPrefix,
                'itemMax' => $this->itemsMax,
                'isSubmission' => $this->isSubmission,
                'crossrefPluginEnabled' => $this->crossrefPluginEnabled,
            ]
        );

        // Provide required locale keys
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->setConstants([
            'STATUS_QUEUED',
            'STATUS_PUBLISHED',
            'STATUS_DECLINED',
            'STATUS_SCHEDULED',
        ]);
        $templateMgr->setLocaleKeys([
            'plugins.importexport.crossref.status.notDeposited',
            'publication.status.unpublished',
            'publication.status.published'
        ]);

        return $config;
    }
//
//	/**
//	 * Helper method to get the items property according to the self::$getParams
//	 *
//	 * @param Request $request
//	 * @return array
//	 */
//	public function getItems($request) {
//		$itemType = $this->isSubmission ? 'submission' : 'issues';
//		$items = [];
//		$itemIterator = Services::get($itemType)->getMany($this->_getItemsParams());
//
//		$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
//		$propertyArgs = [
//			'request' => $request,
//			'userGroups' => $userGroupDao->getByContextId($request->getContext()->getId())->toArray(),
//			'doiManagementArgs' => [
//				// TODO: See if getting crossref status here is helpful or a simple generic, "doi managment" status instead
//				'includeCrossrefStatus' => $this->crossrefPluginEnabled
//			]
//		];
//
//		foreach ($itemIterator as $item) {
//			$items[] = Services::get($itemType)->getSummaryProperties($item, $propertyArgs);
//		}
//
//		return $items;
//
//	}
//
//	/**
//	 * Helper method to get the itemsMax property according to self::$getParams
//	 *
//	 * @return int
//	 */
//	public function getItemsMax() {
//		$itemType = $this->isSubmission ? 'submission' : 'issues';
//		return Services::get($itemType)->getMax($this->_getItemsParams());
//	}
//
//	/**
//	 * Helper method to compile initial params to get items
//	 *
//	 * @return array
//	 */
//	protected function _getItemsParams() {
//		$request = \Application::get()->getRequest();
//		$context = $request->getContext();
//		$contextId = $context ? $context->getId() : CONTEXT_ID_NONE;
//
//		return array_merge(
//			array(
//				'contextId' => $contextId,
//				'count' => $this->count,
//				'offset' => 0,
//				// TODO: See what counts as incomplete before passing
////				'isIncomplete' => false,
//			),
//			$this->getParams
//		);
//	}
}
