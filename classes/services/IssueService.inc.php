<?php

/**
 * @file classes/services/IssueService.php
*
* Copyright (c) 2014-2021 Simon Fraser University
* Copyright (c) 2000-2021 John Willinsky
* Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
*
* @class IssueService
* @ingroup services
*
* @brief Helper class that encapsulates issue business logic
*/

namespace APP\services;

use APP\core\Services;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\submission\Submission;

use PKP\db\DAORegistry;
use PKP\services\interfaces\EntityPropertyInterface;
use PKP\services\interfaces\EntityReadInterface;
use PKP\services\PKPSchemaService;

class IssueService implements EntityPropertyInterface, EntityReadInterface
{
    /**
     * Determine if a user can access galleys for a specific issue
     *
     *
     * @return boolean
     */
    public function userHasAccessToGalleys(\Journal $journal, \Issue $issue)
    {
        import('classes.issue.IssueAction');
        $issueAction = new \IssueAction();

        $subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
        $subscribedUser = $issueAction->subscribedUser($journal, $issue);
        $subscribedDomain = $issueAction->subscribedDomain($journal, $issue);

        return !$subscriptionRequired || $issue->getAccessStatus() == Issue::ISSUE_ACCESS_OPEN || $subscribedUser || $subscribedDomain;
    }

    /**
     * Determine issue access status based on journal publishing mode
     *
     * @param \Journal $journal
     *
     * @return int
     */
    public function determineAccessStatus(Journal $journal)
    {
        import('classes.issue.Issue');
        $accessStatus = null;

        switch ($journal->getData('publishingMode')) {
            case \APP\journal\Journal::PUBLISHING_MODE_SUBSCRIPTION:
            case \APP\journal\Journal::PUBLISHING_MODE_NONE:
                $accessStatus = Issue::ISSUE_ACCESS_SUBSCRIPTION;
                break;
            case \APP\journal\Journal::PUBLISHING_MODE_OPEN:
            default:
                $accessStatus = Issue::ISSUE_ACCESS_OPEN;
                break;
        }

        return $accessStatus;
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getProperties()
     *
     * @param null|mixed $args
     */
    public function getProperties($issue, $props, $args = null)
    {
        \PluginRegistry::loadCategory('pubIds', true);
        $request = $args['request'];
        $context = $request->getContext();
        $dispatcher = $request->getDispatcher();
        $router = $request->getRouter();
        $values = [];

        foreach ($props as $prop) {
            switch ($prop) {
                case 'id':
                    $values[$prop] = (int) $issue->getId();
                    break;
                case '_href':
                    $values[$prop] = null;
                    if (!empty($args['slimRequest'])) {
                        $route = $args['slimRequest']->getAttribute('route');
                        $arguments = $route->getArguments();
                        $values[$prop] = $dispatcher->url(
                            $args['request'],
                            \PKPApplication::ROUTE_API,
                            $arguments['contextPath'],
                            'issues/' . $issue->getId()
                        );
                    }
                    break;
                case 'title':
                    $values[$prop] = $issue->getTitle(null);
                    break;
                case 'description':
                    $values[$prop] = $issue->getDescription(null);
                    break;
                case 'identification':
                    $values[$prop] = $issue->getIssueIdentification();
                    break;
                case 'volume':
                    $values[$prop] = (int) $issue->getVolume();
                    break;
                case 'number':
                    $values[$prop] = $issue->getNumber();
                    break;
                case 'year':
                    $values[$prop] = (int) $issue->getYear();
                    break;
                case 'isCurrent':
                    $values[$prop] = (bool) $issue->getCurrent();
                    break;
                case 'datePublished':
                    $values[$prop] = $issue->getDatePublished();
                    break;
                case 'dateNotified':
                    $values[$prop] = $issue->getDateNotified();
                    break;
                case 'lastModified':
                    $values[$prop] = $issue->getLastModified();
                    break;
                case 'publishedUrl':
                    $values[$prop] = null;
                    if ($context) {
                        $values[$prop] = $dispatcher->url(
                            $request,
                            \PKPApplication::ROUTE_PAGE,
                            $context->getPath(),
                            'issue',
                            'view',
                            $issue->getBestIssueId()
                        );
                    }
                    break;
                case 'articles':
                    $values[$prop] = [];
                    $submissions = Repo::submission()->getMany(
                        Repo::submission()
                            ->getCollector()
                            ->filterByContextIds([$issue->getJournalId()])
                            ->filterByIssueIds([Submission::STATUS_PUBLISHED])
                    );
                    foreach ($submissions as $submission) {
                        $values[$prop][] = Repo::submission()->getSchemaMap()->summarize($submission, $args['userGroups']);
                    }
                    break;
                case 'sections':
                    $values[$prop] = [];
                    $sectionDao = DAORegistry::getDAO('SectionDAO');
                    $sections = $sectionDao->getByIssueId($issue->getId());
                    if (!empty($sections)) {
                        foreach ($sections as $section) {
                            $sectionProperties = \Services::get('section')->getSummaryProperties($section, $args);
                            $customSequence = $sectionDao->getCustomSectionOrder($issue->getId(), $section->getId());
                            if ($customSequence) {
                                $sectionProperties['seq'] = $customSequence;
                            }
                            $values[$prop][] = $sectionProperties;
                        }
                    }
                    break;
                case 'coverImageUrl':
                    $values[$prop] = $issue->getCoverImageUrls(null);
                    break;
                case 'coverImageAltText':
                    $values[$prop] = $issue->getCoverImageAltText(null);
                    break;
                case 'galleys':
                case 'galleysSummary':
                    $data = [];
                    $issueGalleyDao = \DAORegistry::getDAO('IssueGalleyDAO');
                    $galleys = $issueGalleyDao->getByIssueId($issue->getId());
                    if ($galleys) {
                        $galleyArgs = array_merge($args, ['issue' => $issue]);
                        foreach ($galleys as $galley) {
                            $data[] = ($prop === 'galleys')
                                ? \Services::get('galley')->getFullProperties($galley, $galleyArgs)
                                : \Services::get('galley')->getSummaryProperties($galley, $galleyArgs);
                        }
                    }
                    $values['galleys'] = $data;
                    break;
            }
        }

        $values = Services::get('schema')->addMissingMultilingualValues(PKPSchemaService::SCHEMA_ISSUE, $values, $context->getSupportedFormLocales());

        \HookRegistry::call('Issue::getProperties::values', [&$values, $issue, $props, $args]);

        ksort($values);

        return $values;
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getSummaryProperties()
     *
     * @param null|mixed $args
     */
    public function getSummaryProperties($issue, $args = null)
    {
        $props = [
            'id','_href','title','description','identification','volume','number','year',
            'datePublished', 'publishedUrl', 'coverImageUrl','coverImageAltText','galleysSummary',
        ];

        \HookRegistry::call('Issue::getProperties::summaryProperties', [&$props, $issue, $args]);

        return $this->getProperties($issue, $props, $args);
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getFullProperties()
     *
     * @param null|mixed $args
     */
    public function getFullProperties($issue, $args = null)
    {
        $props = [
            'id','_href','title','description','identification','volume','number','year','isPublished',
            'isCurrent','datePublished','dateNotified','lastModified','publishedUrl','coverImageUrl',
            'coverImageAltText','articles','sections','tableOfContetnts','galleysSummary',
        ];

        \HookRegistry::call('Issue::getProperties::fullProperties', [&$props, $issue, $args]);

        return $this->getProperties($issue, $props, $args);
    }
}
