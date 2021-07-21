<?php
/**
 * @file classes/components/form/context/DoiSettingsForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiSettingsForm
 * @ingroup classes_controllers_form
 *
 * @brief A preset form for enabling and configuring DOI settings for a given context
 */

namespace APP\components\forms\context;

use PKP\components\forms\context\PKPDoiSettingsForm;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldSelect;
use PKP\components\forms\FieldText;
use PKP\context\Context;

class DoiSettingsForm extends PKPDoiSettingsForm
{
    public const SETTING_ENABLED_DOI_TYPES = 'enabledDoiTypes';
    public const SETTING_DOI_PREFIX = 'doiPrefix';
    public const SETTING_USE_DEFAULT_DOI_SUFFIX = 'useDefaultDoiSuffix';
    public const SETTING_CUSTOM_DOI_SUFFIX_TYPE = 'customDoiSuffixType';

    public const TYPE_ARTICLE = 'article';
    public const TYPE_ISSUE = 'issue';
    public const TYPE_GALLEY = 'galley';

    public const CUSTOM_SUFFIX_LEGACY = 'legacy';
    public const CUSTOM_SUFFIX_MANUAL = 'customId';

    public function __construct(string $action, array $locales, Context $context)
    {
        parent::__construct($action, $locales, $context);

        $registrationAgencies = [
            [
                'value' => 'none',
                'label' => __('doi.manager.settings.registrationAgency.none')
            ]
        ];
        \HookRegistry::call('DoiSettingsForm::setEnabledRegistrationAgencies', [&$registrationAgencies]);

        $this->addField(new FieldOptions(self::SETTING_ENABLED_DOI_TYPES, [
            'label' => __('doi.manager.settings.doiObjects'),
            'description' => __('doi.manager.settings.doiObjectsRequired'),
            'options' => [
                [
                    'value' => self::TYPE_ISSUE,
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('issue.issues')]),
                ],
                [
                    'value' => self::TYPE_ARTICLE,
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('doi.manager.settings.publications')]),
                ],
                [
                    'value' => self::TYPE_GALLEY,
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('submission.layout.galleys')]),
                ]
            ],
            'value' => $context->getData(self::SETTING_ENABLED_DOI_TYPES) ? $context->getData(self::SETTING_ENABLED_DOI_TYPES) : [],
            'showWhen' => self::SETTING_ENABLE_DOIS
        ]))
            ->addField(new FieldText(self::SETTING_DOI_PREFIX, [
                'label' => __('doi.manager.settings.doiPrefix'),
                'description' => __('doi.manager.settings.doiPrefix.description'),
                'value' => $context->getData(self::SETTING_DOI_PREFIX),
                'showWhen' => self::SETTING_ENABLE_DOIS,
                'isRequired' => true

            ]))
            ->addField(new FieldOptions(self::SETTING_USE_DEFAULT_DOI_SUFFIX, [
                'label' => __('doi.manager.settings.doiSuffix'),
                'description' => __('doi.manager.settings.doiSuffix.description'),
                'options' => [
                    [
                        'value' => true,
                        'label' => __('common.yes')
                    ],
                    [
                        'value' => false,
                        'label' => __('common.no')
                    ]
                ],
                'value' => $context->getData(self::SETTING_USE_DEFAULT_DOI_SUFFIX) !== null ? $context->getData(self::SETTING_USE_DEFAULT_DOI_SUFFIX) : true,
                'type' => 'radio',
                'showWhen' => self::SETTING_ENABLE_DOIS
            ]))
            ->addField(new FieldOptions(self::SETTING_CUSTOM_DOI_SUFFIX_TYPE, [
                'label' => __('doi.manager.settings.doiSuffix'),
                'description' => __('doi.manager.settings.doiSuffix.description'),
                'options' => [
                    [
                        'value' => self::CUSTOM_SUFFIX_LEGACY,
                        'label' => __('doi.manager.settings.doiSuffixLegacy')
                    ],
                    [
                        'value' => self::CUSTOM_SUFFIX_MANUAL,
                        'label' => __('doi.manager.settings.doiSuffixCustomIdentifier')
                    ],
                ],
                'value' => $context->getData(self::SETTING_CUSTOM_DOI_SUFFIX_TYPE) ? $context->getData(self::SETTING_CUSTOM_DOI_SUFFIX_TYPE) : 'legacy',
                'type' => 'radio',
                'showWhen' => [self::SETTING_USE_DEFAULT_DOI_SUFFIX, false],
            ]))
            ->addField(new FieldSelect('registrationAgency', [
                'label' => __('doi.manager.settings.registrationAgency'),
                'description' => __('doi.manager.settings.registrationAgency.description'),
                'options' => $registrationAgencies,
                'value' => $context->getData('registrationAgency'),
                'showWhen' => self::SETTING_ENABLE_DOIS
            ]));
    }
}
