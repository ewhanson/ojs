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

        $this->addField(new FieldOptions('enabledDoiTypes', [
            'label' => __('doi.manager.settings.doiObjects'),
            'description' => __('doi.manager.settings.doiObjectsRequired'),
            'options' => [
                [
                    'value' => 'issue',
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('issue.issues')]),
                ],
                [
                    'value' => 'article',
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('doi.manager.settings.publications')]),
                ],
                [
                    'value' => 'galley',
                    'label' => __('doi.manager.settings.enableFor', ['objects' => __('submission.layout.galleys')]),
                ]
            ],
            'value' => $context->getData('enabledDoiTypes') ? $context->getData('enabledDoiTypes') : [],
            'showWhen' => 'enableDois'
        ]))
            ->addField(new FieldText('doiPrefix', [
                'label' => __('doi.manager.settings.doiPrefix'),
                'description' => __('doi.manager.settings.doiPrefix.description'),
                'value' => $context->getData('doiPrefix'),
                'showWhen' => 'enableDois',
                'isRequired' => true

            ]))
            ->addField(new FieldOptions('doiSuffix', [
                'label' => __('doi.manager.settings.doiSuffix'),
                'description' => __('doi.manager.settings.doiSuffix.description'),
                'options' => [
                    [
                        'value' => 'default',
                        'label' => __('doi.manager.settings.doiSuffixDefault')
                    ],
                    [
                        'value' => 'customId',
                        'label' => __('doi.manager.settings.doiSuffixCustomIdentifier')
                    ],
                    [
                        'value' => 'legacy',
                        'label' => __('doi.manager.settings.doiSuffixLegacy')
                    ]
                ],
                'value' => $context->getData('doiSuffix'),
                'type' => 'radio',
                'showWhen' => 'enableDois',
                'isRequired' => true

            ]))
            ->addField(new FieldSelect('registrationAgency', [
                'label' => __('doi.manager.settings.registrationAgency'),
                'description' => __('doi.manager.settings.registrationAgency.description'),
                'options' => $registrationAgencies,
                'showWhen' => 'enableDois'
            ]));
    }
}
