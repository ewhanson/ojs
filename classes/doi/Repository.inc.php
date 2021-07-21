<?php
/**
 * @file classes/doi/Repository.inc.php
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2000-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class doi
 *
 * @brief A repository to find and manage DOIs.
 */

namespace APP\doi;

use APP\components\forms\context\DoiSettingsForm;
use APP\facades\Repo;
use APP\issue\IssueDAO;
use APP\plugins\PubIdPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\doi\Doi;

class Repository extends \PKP\doi\Repository
{
    public function mintPublicationDoi(Publication $publication): ?int
    {
        $context = $this->request->getContext();
        $contextId = $context->getId();

        $doiPrefix = $this->getPrefix();
        $doiSuffix = '';
        // TODO: This may not be helpful. Find a way to notify user this must be set rather than returning null
        if (empty($doiPrefix)) {
            return null;
        }

        if ($this->getContextSetting(DoiSettingsForm::SETTING_USE_DEFAULT_DOI_SUFFIX)) {
            // Use Cool DOI implementation
        } else {
            switch ($this->getContextSetting(DoiSettingsForm::SETTING_CUSTOM_DOI_SUFFIX_TYPE)) {
                case DoiSettingsForm::CUSTOM_SUFFIX_LEGACY:
                    $submission = Repo::submission()->get($publication->getData('submissionId'));

                    assert(!is_null($submission));
                    // TODO: Replace with Repo after issue refactor
                    $issueDao = \DAORegistry::getDAO('IssueDAO'); /** @var IssueDAO $issueDao */
                    $issue = $issueDao->getBySubmissionId($submission->getId(), $contextId);

                    if ($issue && $contextId != $issue->getJournalId()) {
                        return null;
                    }

                    $doiSuffix = PubIdPlugin::generateDefaultPattern($context, $issue, $submission);

                    break;

                case DoiSettingsForm::CUSTOM_SUFFIX_MANUAL:
                    break;
            }
        }

        // TODO: Same as above. if it's empty, it's likely something went wrong
        if (empty($doiSuffix)) {
            return null;
        }

        $completeDoi = $doiPrefix . '/' . $doiSuffix;

        $doiParams = [
            'doi' => $completeDoi,
            'contextId' => $contextId
        ];

        $doi = $this->newDataObject($doiParams);
        return Repo::doi()->add($doi);
    }

    /**
     * Checks if DOIs of a given type are enabled for the current context
     *
     * @param string $doiType One of DoiSettingsForm::TYPE_*
     *
     */
    public function shouldMintDoiType(string $doiType): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return in_array($doiType, $this->getContextSetting(DoiSettingsForm::SETTING_ENABLED_DOI_TYPES));
    }

    /**
     * Gets all relevant DOI IDs related to a submission (article, galley, issue)
     *
     *
     * @return array DOI IDs
     */
    public function getDoisForSubmission(int $submissionId): array
    {
        // TOOD: Move to pkp-lib and have private abstract method to fetch relevant info
        $doiIds = [];

        // Publications
        $publicationsCollector = Repo::publication()->getCollector()->filterBySubmissionIds([$submissionId]);
        /** @var Publication[] $publications */
        $publications = Repo::publication()->getMany($publicationsCollector);

        foreach ($publications as $publication) {
            $publicationDoiId = $publication->getData('doiId');
            if (!empty($publicationDoiId)) {
                $doiIds[] = $publicationDoiId;
            }
        }

        // TODO: Galleys
        // TODO: Issues

        return $doiIds;
    }

    public function updateStaleDoisStatus(array $doiIds)
    {
        $updatableStatuses = [
            Doi::STATUS_SUBMITTED,
            Doi::STATUS_REGISTERED
        ];

        foreach ($doiIds as $doiId) {
            $doi = Repo::doi()->get($doiId);

            if (in_array($doi->getStatus(), $updatableStatuses)) {
                Repo::doi()->setStatus(Doi::STATUS_STALE, $doi);
            }
        }
    }
}
