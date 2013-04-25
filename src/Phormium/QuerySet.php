<?php

namespace Phormium;

/**
 * Performs lazy database lookup for sets of objects.
 */
class QuerySet
{
    /**
     * Meta data of the Model this QuerySet is handling.
     * @var Meta
     */
    private $meta;

    /** Order by clauses. */
    private $order = array();

    /** Array of Filter objects. */
    private $filters = array();

    /** Maximum number of rows to fetch. */
    private $limit = null;

    /** Offset of the first row to return. */
    private $offset = null;

    public function __construct(Query $query, Meta $meta)
    {
        $this->meta = $meta;
        $this->query = $query;
    }

    // ******************************************
    // *** Clone methods                      ***
    // ******************************************

    /**
     * Returns a new QuerySet that is a copy of the current one.
     * @return QuerySet
     */
    public function all()
    {
        return clone $this;
    }

    /**
     * Returns a new query set with the filter AND-ed to the existing one.
     * @return QuerySet
     */
    public function filter($column, $operation, $value = null)
    {
        $qs = clone $this;
        $qs->addFilter(new Filter($column, $operation, $value));
        return $qs;
    }

    /**
     * Returns a new QuerySet with the ordering changed.
     *
     * @param string $column Name of the column to order by.
     * @param string $direction Direction to sort by: 'asc' (default)
     *      or 'desc'. Optional.
     */
    public function orderBy($column, $direction = 'asc')
    {
        $qs = clone $this;
        $qs->addOrder($column, $direction);
        return $qs;
    }

    /**
     * Returns a new QuerySet with the limit and offset populated with given
     * values.
     */
    public function limit($limit, $offset = null)
    {
        if (!is_null($limit) && !is_int($limit) && !preg_match('/^[0-9]+$/', $limit)) {
            throw new \Exception("Limit must be an integer or null.");
        }

        if (!is_null($offset) && !is_int($offset) && !preg_match('/^[0-9]+$/', $offset)) {
            throw new \Exception("Offset must be an integer or null.");
        }

        $qs = clone $this;
        $qs->limit = $limit;
        $qs->offset = $offset;
        return $qs;
    }

    // ******************************************
    // *** Query methods                      ***
    // ******************************************

    /**
     * Performs a SELECT COUNT(*) and returns the number of records matching
     * the current filter.
     *
     * @return integer
     */
    public function count()
    {
        return $this->query->count($this->filters);
    }

    /**
     * Returns the AVG aggregate on the given column, using the current filters.
     * @param string $column
     */
    public function avg($column)
    {
        $agg = new Aggregate(Aggregate::AVERAGE, $column);
        return $this->query->aggregate($this->filters, $agg);
    }

    /**
     * Returns the MAX aggregate on the given column, using the current filters.
     * @param string $column
     */
    public function max($column)
    {
        $agg = new Aggregate(Aggregate::MAX, $column);
        return $this->query->aggregate($this->filters, $agg);
    }

    /**
     * Returns the MIN aggregate on the given column, using the current filters.
     * @param string $column
     */
    public function min($column)
    {
        $agg = new Aggregate(Aggregate::MIN, $column);
        return $this->query->aggregate($this->filters, $agg);
    }

    /**
     * Returns the SUM aggregate on the given column, using the current filters.
     * @param string $column
     */
    public function sum($column)
    {
        $agg = new Aggregate(Aggregate::SUM, $column);
        return $this->query->aggregate($this->filters, $agg);
    }

    /**
     * Fetches the count of records matching the current filter and returns
     * TRUE if it's greater than 0, or FALSE otherwise.
     *
     * @return boolean
     */
    public function exists()
    {
        return $this->query->count($this->filters) > 0;
    }

    /**
     * Performs a SELECT query on the table, and returns rows matching the
     * current filter.
     */
    public function fetch()
    {
        return $this->query->select($this->filters, $this->order, null, $this->limit, $this->offset);
    }

    /**
     * Performs a SELECT query on the table, and returns a single row which
     * matches the current filter.
     *
     * @throws \Exception If multiple rows are found.
     * @throws \Exception If no rows are found.
     */
    public function single()
    {
        $data = $this->fetch();
        $count = count($data);

        if ($count > 1) {
            throw new \Exception("Query returned multiple rows ($count). Requested a single row.");
        }

        if ($count == 0) {
            throw new \Exception("Query returned 0 rows. Requested a single row.");
        }

        return isset($data[0]) ? $data[0] : null;
    }

    /**
     * Performs a SELECT query on the table, and returns rows matching the
     * current filter as associative arrays (instead of objects which are
     * returned by fetch().
     *
     * One or more column names can be provided as parameters, and only these
     * columns will be fetched. If no parameters are given, all columns are
     * fetched.
     */
    public function values()
    {
        $columns = func_get_args();
        if (empty($columns)) {
            $columns = null;
        }

        return $this->query->select($this->filters, $this->order, $columns, $this->limit, $this->offset, \PDO::FETCH_ASSOC);
    }

    /**
     * Performs a SELECT query on the table, and returns rows matching the
     * current filter as number-indexed arrays (instead of objects which are
     * returned by fetch().
     *
     * One or more column names can be provided as parameters, and only these
     * columns will be fetched. If no parameters are given, all columns are
     * fetched.
     */
    public function valuesList()
    {
        $columns = func_get_args();
        if (empty($columns)) {
            $columns = null;
        }

        return $this->query->select($this->filters, $this->order, $columns, $this->limit, $this->offset, \PDO::FETCH_NUM);
    }

    /**
     * Performs a SELECT DISTINCT query on the given columns.
     */
    public function distinct()
    {
        $columns = func_get_args();
        return $this->query->selectDistinct($this->filters, $this->order, $columns);
    }

    /**
     * Performs an UPDATE query on all records matching the current filter.
     */
    public function update($updates)
    {
        return $this->query->batchUpdate($this->filters, $updates);
    }

    /**
     * DELETEs all records matching the current filter.
     */
    public function delete()
    {
        return $this->query->batchDelete($this->filters);
    }

    // ******************************************
    // *** Private methods                    ***
    // ******************************************

    private function addFilter(Filter $filter)
    {
        $column = $filter->column;
        if (isset($filter->column) && !in_array($column, $this->meta->columns)) {
            $table = $this->meta->table;
            throw new \Exception("Invalid filter: Column [$column] does not exist in table [$table].");
        }
        $this->filters[] = $filter;
    }

    private function addOrder($column, $direction)
    {
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new \Exception("Invalid order direction [$direction]. Expected 'asc' or 'desc'.");
        }

        if (!in_array($column, $this->meta->columns)) {
            $table = $this->meta->table;
            throw new \Exception("Cannot order by column [$column] because it does not exist in table [$table].");
        }

        $this->order[] = "{$column} {$direction}";
    }

    // ******************************************
    // *** Accessors                          ***
    // ******************************************

    public function getFilters()
    {
        return $this->filters;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getMeta()
    {
        return $this->meta;
    }
}
