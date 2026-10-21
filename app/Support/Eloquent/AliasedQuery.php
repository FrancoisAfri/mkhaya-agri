<?php

namespace App\Support\Eloquent;

use Illuminate\Database\Query\Builder;

/**
 * A query over another module's table that also takes the farm's names for its columns, so
 * `FarmMachine::query()->orderBy('code')->get(['uuid', 'code'])` reads fleet_number. Only plain
 * column names are translated (optionally qualified with the table); raw SQL is left alone.
 */
class AliasedQuery extends Builder
{
    /** @var array<string, string> */
    public array $aliases = [];

    public function column($column)
    {
        if (! is_string($column) || $this->aliases === []) {
            return $column;
        }
        $as = '';
        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $column, $m)) {
            [$column, $as] = [$m[1], ' as '.$m[2]];
        }
        $prefix = '';
        if (($dot = strrpos($column, '.')) !== false) {
            [$prefix, $column] = [substr($column, 0, $dot + 1), substr($column, $dot + 1)];
        }
        if (isset($this->aliases[$column])) {
            return $prefix.$this->aliases[$column].$as;   // the model reads the farm name back through its alias
        }

        return $prefix.$column.$as;
    }

    private function columns($columns)
    {
        return is_array($columns) ? array_map(fn ($c) => $this->column($c), $columns) : $this->column($columns);
    }

    public function select($columns = ['*'])
    {
        return parent::select($this->columns(is_array($columns) ? $columns : func_get_args()));
    }

    public function addSelect($column)
    {
        return parent::addSelect($this->columns(is_array($column) ? $column : func_get_args()));
    }

    public function get($columns = ['*'])
    {
        return parent::get($this->columns($columns));
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        return parent::where($this->column($column), $operator, $value, $boolean);
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        return parent::whereIn($this->column($column), $values, $boolean, $not);
    }

    public function whereNull($columns, $boolean = 'and', $not = false)
    {
        return parent::whereNull(is_array($columns) ? array_map(fn ($c) => $this->column($c), $columns) : $this->column($columns), $boolean, $not);
    }

    public function orderBy($column, $direction = 'asc')
    {
        return parent::orderBy($this->column($column), $direction);
    }

    public function groupBy(...$groups)
    {
        return parent::groupBy(...array_map(fn ($g) => is_array($g) ? array_map(fn ($c) => $this->column($c), $g) : $this->column($g), $groups));
    }

    public function pluck($column, $key = null)
    {
        return parent::pluck($this->column($column), $key === null ? null : $this->column($key));
    }
}
