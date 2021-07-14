<?php

/**
 * @file classes/issue/IssueDAO.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class IssueDAO
 * @ingroup issue
 *
 * @see Issue
 *
 * @brief Operations for retrieving and modifying Issue objects.
 */

namespace APP\issue;

use PKP\db\DAOResultFactory;

class IssueDAO extends \PKP\db\DAO
{
    /**
     * Retrieve Issues by identification
     *
     * @param $journalId int
     * @param $volume int
     * @param $number string
     * @param $year int
     * @param $titles array
     *
     * @return DAOResultFactory
     */
    public function getIssuesByIdentification($journalId, $volume = null, $number = null, $year = null, $titles = [])
    {
        $params = [];

        $i = 1;
        $sqlTitleJoin = '';
        foreach ($titles as $title) {
            $sqlTitleJoin .= ' JOIN issue_settings iss' . $i . ' ON (i.issue_id = iss' . $i . '.issue_id AND iss' . $i . '.setting_name = \'title\' AND iss' . $i . '.setting_value = ?)';
            $params[] = $title;
            $i++;
        }
        $params[] = (int) $journalId;
        if ($volume !== null) {
            $params[] = (int) $volume;
        }
        if ($number !== null) {
            $params[] = $number;
        }
        if ($year !== null) {
            $params[] = (int) $year;
        }

        $result = $this->retrieve(
            'SELECT i.*
			FROM issues i'
            . $sqlTitleJoin
            . ' WHERE i.journal_id = ?'
            . (($volume !== null) ? ' AND i.volume = ?' : '')
            . (($number !== null) ? ' AND i.number = ?' : '')
            . (($year !== null) ? ' AND i.year = ?' : ''),
            $params
        );
        return new DAOResultFactory($result, $this, '_returnIssueFromRow');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\issue\IssueDAO', '\IssueDAO');
}
