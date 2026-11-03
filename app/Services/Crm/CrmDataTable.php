<?php

namespace App\Services\Crm;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Yajra\DataTables\EloquentDataTable;

/**
 * DataTables for the CRM page, counting and paging in one pass.
 *
 * The CRM tab queries join a dozen tables. Counting them and then sorting all rows for one page
 * meant running every join twice over every row. Instead:
 *  1. one slim query returns the key (applicant, sale, interview) of every filtered row, in display
 *     order. It leaves out LEFT JOINs that can never add or remove a row (primary-key lookups such as
 *     job titles and users, and the "latest cv note per applicant + sale" join), unless the filters,
 *     search or sort use them;
 *  2. the record count is the number of keys;
 *  3. the full query runs only for the keys of the requested page.
 * If a tab's rows are not unique by that key, it falls back to the normal DataTables queries.
 */
class CrmDataTable extends EloquentDataTable
{
    /** Left-joined tables looked up by their primary key: at most one row each. */
    private const PRIMARY_KEY_JOINS = ['job_titles', 'job_categories', 'job_sources', 'applicant_owner_users', 'users', 'sale_job_sources'];

    /** Left-joined "latest cv_notes row per applicant + sale" subqueries: at most one row each. */
    private const LATEST_PAIR_JOINS = ['cv_notes', 'cv_last_notes', 'latest_cv_notes'];

    /** Row key columns: every CRM tab joins applicants, sales and (left) interviews. */
    private const KEY_COLUMNS = ['applicants.id as crm_key_applicant', 'sales.id as crm_key_sale', 'interviews.id as crm_key_interview'];

    /**
     * How the page is fetched once the keys are known (measured on crm_staging):
     *  - up to FETCH_ALL_ROWS rows: the full query without ORDER BY / LIMIT (the cheap plan the count
     *    uses), sorted and sliced by the keys. MariaDB often picks a much slower plan for "ORDER BY ... LIMIT";
     *  - up to DIRECT_PAGE_ROWS rows: the normal "ORDER BY ... LIMIT" page query;
     *  - more: the full query for the page's applicants and sales only.
     */
    private const FETCH_ALL_ROWS = 1000;
    private const DIRECT_PAGE_ROWS = 4000;

    /** @var array<int, string>|null keys of all filtered rows in display order, or null to use the normal queries */
    private ?array $rowKeys = null;

    private bool $rowKeysLoaded = false;

    /**
     * The tab queries join ~17 tables and subqueries. With MariaDB's default exhaustive join-order search
     * (optimizer_search_depth = 62) planning alone took up to ~0.5 s on tabs whose rows take 3 ms to read.
     * Depth 0 lets the server pick the depth. It only changes the plan, never the rows; the previous value
     * is restored afterwards.
     */
    public function make(bool $mDataSupport = true): JsonResponse
    {
        $connection = $this->getConnection();
        try {
            $depth = (int) $connection->selectOne('select @@optimizer_search_depth as depth')->depth;
            $connection->statement('set session optimizer_search_depth = 0');
        } catch (\Throwable) {
            return parent::make($mDataSupport);
        }

        try {
            return parent::make($mDataSupport);
        } finally {
            $connection->statement('set session optimizer_search_depth = ' . $depth);
        }
    }

    public function count(): int
    {
        $keys = $this->rowKeys();

        return $keys === null ? parent::count() : count($keys);
    }

    public function results(): Collection
    {
        if (isset($this->results)) {
            return $this->results;
        }

        $keys = $this->rowKeys();
        $total = $keys === null ? 0 : count($keys);
        if ($keys === null || ($total > self::FETCH_ALL_ROWS && $total <= self::DIRECT_PAGE_ROWS)) {
            return parent::results();
        }

        if ($this->request->isPaginationable() && !$this->skipPaging) {
            $length = $this->request->length();
            $keys = array_slice($keys, $this->request->start(), $length > 0 ? $length : 10);
        }
        if ($keys === []) {
            return $this->results = $this->query->getModel()->newCollection();
        }

        $position = array_flip($keys);

        $query = clone $this->query;
        $base = $query->getQuery();
        $base->limit = $base->offset = null;
        $base->orders = null;
        $base->addSelect(self::KEY_COLUMNS);

        if ($total > self::FETCH_ALL_ROWS) {
            // Many rows: fetch only the page's applicants and sales.
            $pairs = array_map(fn ($key) => explode('|', $key), $keys);
            $base->whereIn('applicants.id', array_values(array_unique(array_column($pairs, 0))))
                ->whereIn('sales.id', array_values(array_unique(array_column($pairs, 1))));
        }

        // Keep the page's rows (the query can return other rows too), in the order of the keys.
        // getModels() skips the afterQuery callbacks, which then run for the page's rows only.
        $rows = $query->getModel()->newCollection($query->getModels())
            ->filter(fn ($row) => isset($position[$this->rowKey($row)]))
            ->sortBy(fn ($row) => $position[$this->rowKey($row)])
            ->values()
            ->each(function ($row) {
                unset($row->crm_key_applicant, $row->crm_key_sale, $row->crm_key_interview);
            });

        return $this->results = $query->applyAfterQueryCallbacks($rows);
    }

    public function prepareCountQuery(): QueryBuilder
    {
        $query = $this->query;
        $this->query = clone $query;

        try {
            $this->query->getQuery()->columns = [];
            $this->dropRowNeutralJoins($this->query->getQuery());

            return parent::prepareCountQuery();
        } finally {
            $this->query = $query;
        }
    }

    /** Called from count(), i.e. after DataTables applied its search and before its ordering and paging. */
    private function rowKeys(): ?array
    {
        if ($this->rowKeysLoaded) {
            return $this->rowKeys;
        }
        $this->rowKeysLoaded = true;

        $query = clone $this->query;
        $base = $query->getQuery();
        $aliases = array_map(fn ($join) => $this->joinAlias($join), $base->joins ?? []);
        if (!is_callable($this->orderCallback) || !in_array('sales', $aliases, true) || !in_array('interviews', $aliases, true)) {
            return null;
        }

        call_user_func($this->orderCallback, $query);
        $orderColumns = [];
        foreach ($base->orders ?? [] as $order) {
            if (!isset($order['column']) || !is_string($order['column'])) {
                return null;
            }
            $orderColumns[] = $order['column'];
        }

        // Keep the selected columns the ORDER BY refers to by name (e.g. "... as show_created_at").
        $columns = self::KEY_COLUMNS;
        foreach ($base->columns ?? [] as $column) {
            if (is_string($column) && in_array($this->selectedName($column), $orderColumns, true)) {
                $columns[] = $column;
            }
        }
        $base->columns = $columns;
        $this->dropRowNeutralJoins($base);

        $keys = $base->get()->map(fn ($row) => $this->rowKey($row))->all();

        return $this->rowKeys = count($keys) === count(array_unique($keys)) ? $keys : null;
    }

    private function rowKey($row): string
    {
        return $row->crm_key_applicant . '|' . $row->crm_key_sale . '|' . ($row->crm_key_interview ?? '');
    }

    /** "table.column as alias" -> alias, "table.column" -> column */
    private function selectedName(string $column): string
    {
        if (preg_match('/\s+as\s+`?(\w+)`?$/i', $column, $m)) {
            return $m[1];
        }

        return substr($column, (int) strrpos($column, '.') + (str_contains($column, '.') ? 1 : 0));
    }

    private function dropRowNeutralJoins(QueryBuilder $query): void
    {
        do {
            $dropped = false;
            foreach ($query->joins ?? [] as $i => $join) {
                $alias = $this->joinAlias($join);
                if ($join->type !== 'left' || $alias === null || !$this->isRowNeutral($join, $alias)) {
                    continue;
                }

                $rest = clone $query;
                $rest->joins = array_values(array_diff_key($query->joins, [$i => true]));
                if ($this->references($rest->toSql(), $alias)) {
                    continue;
                }

                $query->joins = $rest->joins;
                $dropped = true;
                break;
            }
        } while ($dropped);
    }

    private function isRowNeutral(JoinClause $join, string $alias): bool
    {
        // Only joins without bound values, so the remaining bindings stay in order.
        if ($join->getBindings() !== [] || str_contains($this->tableSql($join), '?')) {
            return false;
        }

        $columns = [];
        foreach ($join->wheres as $where) {
            if ($where['type'] !== 'Column' || $where['operator'] !== '=') {
                return false;
            }
            foreach ([$where['first'], $where['second']] as $column) {
                if (str_starts_with($column, $alias . '.')) {
                    $columns[] = substr($column, strlen($alias) + 1);
                }
            }
        }
        sort($columns);

        if (in_array($alias, self::PRIMARY_KEY_JOINS, true)) {
            return count($join->wheres) === 1 && $columns === ['id'];
        }

        if (in_array($alias, self::LATEST_PAIR_JOINS, true) && $join->table instanceof Expression) {
            // "where id in (select MAX(id) ... group by pair)" or the same as a join on the grouped ids
            $latest = '(?:where `(?:\w+`\.`)?id` in \(select MAX\(id\) from `cv_notes` group by `applicant_id`, `sale_id`\)'
                . '|inner join \(select MAX\(id\) as latest_id from `cv_notes` group by `applicant_id`, `sale_id`\) as `latest_ids` on `latest_ids`\.`latest_id` = `(?:\w+`\.`)?id`)';

            return $columns === ['applicant_id', 'sale_id']
                && preg_match('/^\(select [^()]* from `cv_notes`(?: as `\w+`)? ' . $latest . '\) as `' . preg_quote($alias, '/') . '`$/', $this->tableSql($join)) === 1;
        }

        return false;
    }

    private function tableSql(JoinClause $join): string
    {
        return $join->table instanceof Expression
            ? (string) $join->table->getValue($this->getConnection()->getQueryGrammar())
            : (string) $join->table;
    }

    private function joinAlias(JoinClause $join): ?string
    {
        $table = $this->tableSql($join);

        if (preg_match('/ as `?(\w+)`?$/i', $table, $m)) {
            return $m[1];
        }

        return preg_match('/^\w+$/', $table) ? $table : null;
    }

    private function references(string $sql, string $alias): bool
    {
        return preg_match('/(`' . preg_quote($alias, '/') . '`\.|\b' . preg_quote($alias, '/') . '\.)/', $sql) === 1;
    }
}
