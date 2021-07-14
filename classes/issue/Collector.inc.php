<?php

namespace APP\issue;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;

class Collector implements \PKP\core\interfaces\CollectorInterface
{
    public const ORDERBY_DATE_PUBLISHED = 'datePublished';
    public const ORDERBY_LAST_MODIFIED = 'lastModified';
    public const ORDERBY_SEQUENCE = 'seq';
    public const ORDERBY_PUBLISHED_ISSUES = 'publishedIssues';
    public const ORDERBY_UNPUBLISHED_ISSUES = 'unpublishedIssues';
    public const ORDERBY_GATEWAY_LOCKSS = 'gatewayLockss';
    public const ORDER_DIR_ASC = 'ASC';
    public const ORDER_DIR_DESC = 'DESC';

    /** @var DAO */
    protected $dao;

    /** @var int */
    protected $count = 30;

    /** @var int */
    protected $offset = 0;

    /** @var array List of columns to select with query */
    protected $columns = [];

    /** @var int|string|null Context ID or PKPApplication::CONTEXT_ID_ALL to get from all contexts */
    protected $contextId = null;

    /** @var bool Whether or not only current issue should be fetched for a given context  */
    protected $isCurrent = false;

    /** @var string order by column */
    protected $orderColumn = 'i.date_published';

    /** @var string order by direction */
    protected $orderDirection = self::ORDER_DIR_DESC;

    /** @var array Additional orderBy pairings for legacy queries */
    protected $additionalOrderings = [];

    /** @var boolean return published issues */
    protected $isPublished = null;

    /** @var array list of issue ids to retrieve */
    protected $issueIds = [];

    /** @var array return issues in volume(s) */
    protected $volumes = null;

    /** @var array return issues with number(s) */
    protected $numbers = null;

    /** @var array return issues with year(s) */
    protected $years = null;

    /** @var string Returns Issue by URL path  */
    protected $urlPath = null;

    /** @var bool whether to return only a count of results */
    protected $countOnly = null;

    /** @var string return issues which match words from this search phrase */
    protected $searchPhrase = '';

    public function __construct(DAO $dao)
    {
        $this->dao = $dao;
    }

    /**
     * Set context issues filter
     *
     * @return $this
     */
    public function filterByContext(int $contextId): self
    {
        $this->contextId = $contextId;
        return $this;
    }

    public function filterByCurrent(): self
    {
        $this->isCurrent = true;
        return $this;
    }

    /**
     * Set result order column
     *
     * @return $this
     */
    public function orderBy(string $column): self
    {
        switch ($column) {
            case self::ORDERBY_LAST_MODIFIED:
                $this->orderColumn = 'i.last_modified';
                break;
            case self::ORDERBY_SEQUENCE:
                $this->orderColumn = 'o.seq';
                break;
            case self::ORDERBY_PUBLISHED_ISSUES:
                $this->orderColumn = 'o.seq';
                $this->orderDirection = self::ORDER_DIR_ASC;
                $this->additionalOrderings = [
                    [
                        'orderBy' => 'i.current',
                        'direction' => self::ORDER_DIR_DESC
                    ],
                    [
                        'orderBy' => 'i.date_published',
                        'direction' => self::ORDER_DIR_DESC
                    ]
                ];
                break;
            case self::ORDERBY_UNPUBLISHED_ISSUES:
                $this->orderColumn = 'i.year';
                $this->orderDirection = self::ORDER_DIR_ASC;
                $this->additionalOrderings = [
                    [
                        'orderBy' => 'i.volume',
                        'direction' => self::ORDER_DIR_ASC
                    ],
                    [
                        'orderBy' => 'i.number',
                        'direction' => self::ORDER_DIR_ASC
                    ]
                ];
                break;
            case self::ORDERBY_GATEWAY_LOCKSS:
                $this->orderColumn = 'i.current';
                $this->orderDirection = self::ORDER_DIR_DESC;
                $this->additionalOrderings = [
                    [
                        'orderBy' => 'i.year',
                        'direction' => self::ORDER_DIR_ASC
                    ],
                    [
                        'orderBy' => 'i.volume',
                        'direction' => self::ORDER_DIR_ASC
                    ],
                    [
                        'orderBy' => 'i.number',
                        'direction' => self::ORDER_DIR_ASC
                    ]
                ];
                break;
            default:
                $this->orderColumn = 'i.date_published';
                break;
        }
        return $this;
    }

    /**
     * Set result order direction
     *
     * @return $this
     */
    public function orderDirection(string $direction): self
    {
        $this->orderDirection = $direction;
        return $this;
    }

    /**
     * Set published filter
     *
     * @return $this
     */
    public function filterByPublished(bool $isPublished): self
    {
        $this->isPublished = $isPublished;
        return $this;
    }

    /**
     * Set volumes filter
     *
     * @param int[] $volumes
     *
     * @return $this
     */
    public function filterByVolumes(array $volumes): self
    {
        if (!is_null($volumes) && !is_array($volumes)) {
            $volumes = [$volumes];
        }
        $this->volumes = $volumes;
        return $this;
    }

    /**
     * Set volumes filter
     *
     * @param int[] $numbers
     *
     * @return $this
     */
    public function filterByNumbers(array $numbers): self
    {
        if (!is_null($numbers) && !is_array($numbers)) {
            $numbers = [$numbers];
        }
        $this->numbers = $numbers;
        return $this;
    }

    /**
     * Set volumes filter
     *
     * @param int[] $years
     *
     * @return $this
     */
    public function filterByYears(array $years): self
    {
        if (!is_null($years) && !is_array($years)) {
            $years = [$years];
        }
        $this->years = $years;
        return $this;
    }

    /**
     * Set issue id filter
     *
     * @return $this
     */
    public function filterByIds(array $issueIds): self
    {
        $this->issueIds = $issueIds;
        return $this;
    }

    /**
     * set urlPath filter
     *
     * @return $this
     */
    public function filterByUrlPath(string $urlPath): self
    {
        $this->urlPath = $urlPath;
        return $this;
    }

    /**
     * Set query search phrase
     *
     * @return $this
     */
    public function searchPhrase(string $phrase): self
    {
        $this->searchPhrase = $phrase;
        return $this;
    }

    /**
     * Limit the number of objects retrieved
     *
     * @return $this
     */
    public function limit(int $count): self
    {
        $this->count = $count;
        return $this;
    }

    /**
     * Offset the number of objects retrieved, for example to
     * retrieve the second page of contents
     *
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getQueryBuilder(): Builder
    {
        $this->columns[] = 'i.*';
        $q = DB::table($this->dao->table, 'i')
            ->leftJoin('issue_settings as iss', 'i.issue_id', '=', 'iss.issue_id')
            ->leftJoin('custom_issue_orders as o', 'o.issue_id', '=', 'i.issue_id')
            ->groupBy('i.issue_id', $this->orderColumn);

        // Context
        // Never permit a query without a context_id unless the PKPApplication::CONTEXT_ID_ALL wildcard
        // has been explicitly set
        if (is_null($this->contextId)) {
            $q->where('i.journal_id', '=', PKPApplication::CONTEXT_ID_NONE);
        } elseif ($this->contextId !== PKPApplication::CONTEXT_ID_ALL) {
            $q->where('i.journal_id', '=', $this->contextId);
        }

        // Current
        // NB: This should not really be used in combination with anything other than
        // filterByContext
        if ($this->isCurrent) {
            $q->where('i.current', '=', 1);
        }

        // Published
        if (!is_null($this->isPublished)) {
            $q->where('i.published', $this->isPublished ? 1 : 0);
        }

        // Volumes
        if (!is_null($this->volumes)) {
            $q->whereIn('i.volume', $this->volumes);
        }

        // Numbers
        if (!is_null($this->numbers)) {
            $q->whereIn('i.number', $this->numbers);
        }

        // Years
        if (!is_null($this->years)) {
            $q->whereIn('i.year', $this->years);
        }

        // Issue ids
        if (!empty($this->issueIds)) {
            $q->whereIn('i.issue_id', $this->issueIds);
        }

        // URL path
        if (!empty($this->urlPath)) {
            $q->where('i.url_path', '=', $this->urlPath);
        }

        // Search phrase
        if (!empty($this->searchPhrase)) {
            $searchPhrase = $this->searchPhrase;

            // Add support for searching for the volume, number and year
            // using the localized issue identification formats. In
            // en_US this will match Vol. 1. No. 1 (2018) against:
            // i.volume = 1 AND i.number = 1 AND i.year = 2018
            $volume = '';
            $number = '';
            $year = '';
            $volumeRegex = '/' . preg_quote(__('issue.vol')) . '\s\S/';
            preg_match($volumeRegex, $searchPhrase, $matches);
            if (count($matches)) {
                $volume = trim(str_replace(__('issue.vol'), '', $matches[0]));
                $searchPhrase = str_replace($matches[0], '', $searchPhrase);
            }
            $numberRegex = '/' . preg_quote(__('issue.no')) . '\s\S/';
            preg_match($numberRegex, $searchPhrase, $matches);
            if (count($matches)) {
                $number = trim(str_replace(__('issue.no'), '', $matches[0]));
                $searchPhrase = str_replace($matches[0], '', $searchPhrase);
            }
            preg_match('/\(\d{4}\)\:?/', $searchPhrase, $matches);
            if (count($matches)) {
                $year = substr($matches[0], 1, 4);
                $searchPhrase = str_replace($matches[0], '', $searchPhrase);
            }
            if ($volume !== '' || $number !== '' || $year !== '') {
                $q->where(function ($q) use ($volume, $number, $year) {
                    if ($volume) {
                        $q->where('i.volume', '=', $volume);
                    }
                    if ($number) {
                        $q->where('i.number', '=', $number);
                    }
                    if ($year) {
                        $q->where('i.year', '=', $year);
                    }
                });
            }

            $words = array_unique(explode(' ', $searchPhrase));
            if (count($words)) {
                foreach ($words as $word) {
                    $word = strtolower(addcslashes($word, '%_'));
                    $q->where(function ($q) use ($word) {
                        $q->where(function ($q) use ($word) {
                            $q->where('iss.setting_name', 'title');
                            $q->where(DB::raw('lower(iss.setting_value)'), 'LIKE', "%${word}%");
                        })
                            ->orWhere(function ($q) use ($word) {
                                $q->where('iss.setting_name', 'description');
                                $q->where(DB::raw('lower(iss.setting_value)'), 'LIKE', "%${word}%");
                            });

                        // Match any four-digit number to the year
                        if (ctype_digit($word) && strlen($word) === 4) {
                            $q->orWhere('i.year', '=', $word);
                        }
                    });
                }
            }
        }

        // Ordering for query-builder-based and legacy-based orderings
        $q->orderBy($this->orderColumn, $this->orderDirection);
        if (!empty($this->additionalOrderings)) {
            foreach ($this->additionalOrderings as $additionalOrdering) {
                $q->orderBy($additionalOrdering['orderBy'], $additionalOrdering['direction']);
            }
        }

        // Limit and offset results for pagination
        if (!is_null($this->count)) {
            $q->limit($this->count);
        }
        if (!is_null($this->offset)) {
            $q->offset($this->offset);
        }

        // Add app-specific query statements
        \HookRegistry::call('Issue::getMany::queryObject', [&$q, $this]);

        $q->select($this->columns);

        return $q;
    }
}
