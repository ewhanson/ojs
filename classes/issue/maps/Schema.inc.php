<?php

namespace APP\issue\maps;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\issue\IssueGalleyDAO;
use APP\journal\SectionDAO;
use Illuminate\Support\Enumerable;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\services\PKPSchemaService;
use UserGroupDAO;

class Schema extends \PKP\core\maps\Schema
{
    /** @var Enumerable */
    public $collection;

    /** @var string */
    public $schema = PKPSchemaService::SCHEMA_ISSUE;

    public function __construct(PKPRequest $request, Context $context, PKPSchemaService $schemaService)
    {
        parent::__construct($request, $context, $schemaService);
    }

    /**
     * Map an Issue
     *
     * Includes all properties in the Issue schema
     *
     */
    public function map(Issue $item): array
    {
        return $this->mapByProperties($this->getProps(), $item);
    }

    /**
     * Summarize an Issue
     *
     * Includes properties with the apiSummary flag in the Issue schema.
     *
     */
    public function summarize(Issue $item): array
    {
        return $this->mapByProperties($this->getSummaryProps(), $item);
    }

    /**
     * Map a collection of Issues
     *
     * @see self::map
     *
     */
    public function mapMany(Enumerable $collection): Enumerable
    {
        $this->collection = $collection;
        return $collection->map(function ($item) {
            return $this->map($item);
        });
    }

    /**
     * Summarize a collection of Issues
     *
     * @see self::summarize
     *
     */
    public function summarizeMany(Enumerable $collection): Enumerable
    {
        $this->collection = $collection;
        return $collection->map(function ($item) {
            return $this->summarize($item);
        });
    }

    /**
     * Map schema properties of an Issue to an assoc array
     *
     */
    private function mapByProperties(array $props, Issue $issue): array
    {
        $output = [];

        foreach ($props as $prop) {
            switch ($prop) {
                case '_href':
                    $output[$prop] = $this->getApiUrl('issues/' . $issue->getId(), $this->context->getData('urlPath'));
                    break;
                case 'articles':
                    $data = [];

                    $submissions = Repo::submission()->getMany(
                        Repo::submission()
                            ->getCollector()
                            ->filterByContextIds([$issue->getJournalId()])
                            ->filterByIssueIds([$issue->getId()])
                    );

                    /** @var UserGroupDAO $userGroupsDao */
                    $userGroupsDao = DAORegistry::getDAO('UserGroupDAO');
                    $userGroups = $userGroupsDao->getByContextId($this->context->getId());
                    foreach ($submissions as $submission) {
                        $data[] = Repo::submission()->getSchemaMap()->summarize($submission, $userGroups);
                    }

                    $output[$prop] = $data;
                    break;
                case 'coverImageUrl':
                    $output[$prop] = $issue->getCoverImageUrls();
                    break;
                case 'galleys':
                case 'galleysSummary':
                    // TODO: See about summary vs. not
                    $data = [];
                    /** @var IssueGalleyDAO $issueGalleyDao */
                    $issueGalleyDao = DAORegistry::getDAO('IssueGalleyDAO');
                    $galleys = $issueGalleyDao->getByIssueId($issue->getId());
                    if (!empty($galleys)) {
                        $galleyArgs = ['issue' => $issue];
                        foreach ($galleys as $galley) {
                            $data[] = ($prop == 'galleys')
                                ? Services::get('galley')->getFullProperties($galley, $galleyArgs)
                                : Services::get('galley')->getSummaryProperties($galley, $galleyArgs);
                        }
                    }
                    $output[$prop] = $data;
                    break;
                case 'publishedUrl':
                    $output['publishedUrl'] = $this->request->getDispatcher()->url(
                        $this->request,
                        Application::ROUTE_PAGE,
                        $this->context->getPath(),
                        'issue',
                        'view',
                        $issue->getId()
                    );
                    break;
                case 'sections':
                    $data = [];
                    /** @var SectionDAO $sectionDao */
                    $sectionDao = DAORegistry::getDAO('SectionDAO');
                    $sections = $sectionDao->getByIssueId($issue->getId());
                    if (!empty($sections)) {
                        foreach ($sections as $section) {
                            $sectionProperties = Services::get('section')->getSummaryProperties($section);
                            $customSequence = $sectionDao->getCustomSectionOrder($issue->getId(), $section->getId());
                            if ($customSequence) {
                                $sectionProperties['seq'] = $customSequence;
                            }
                            $data[] = $sectionProperties;
                        }
                    }
                    $output[$prop] = $data;
                    break;
                default:
                    $output[$prop] = $issue->getData($prop);
            }
        }

        return $output;
    }
}
