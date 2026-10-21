<?php

namespace App\Support\Eloquent;

/**
 * A farm model kept over another module's table, under the names farm code has always used.
 *
 * Farm machines are Fleet assets (fleet_vehicles…) and shift templates are T&A shifts (attendance_shifts); each
 * names some columns its own way (vehicle_id, fleet_number, registration_number). The model maps
 * the farm's name to Fleet's column for reading, writing and arrays: `$m->code` reads fleet_number,
 * `create(['machine_id' => …])` writes vehicle_id, and queries take either name (AliasedQuery).
 */
trait AliasesColumns
{
    /** @return array<string, string> farm name => the table's column */
    abstract protected function columnAliases(): array;

    /** create()/update() take the farm's names too: they are translated before Eloquent checks them against the table. */
    protected function newBaseQueryBuilder()
    {
        $conn = $this->getConnection();
        $query = new AliasedQuery($conn, $conn->getQueryGrammar(), $conn->getPostProcessor());
        $query->aliases = $this->columnAliases();

        return $query;
    }

    public function fill(array $attributes)
    {
        $aliases = $this->columnAliases();
        $translated = [];
        foreach ($attributes as $key => $value) {
            $translated[$aliases[$key] ?? $key] = $value;
        }

        return parent::fill($translated);
    }

    public function getAttribute($key)
    {
        return parent::getAttribute($this->columnAliases()[$key] ?? $key);
    }

    public function setAttribute($key, $value)
    {
        return parent::setAttribute($this->columnAliases()[$key] ?? $key, $value);
    }

    public function toArray()
    {
        $array = parent::toArray();
        foreach ($this->columnAliases() as $alias => $column) {
            if (array_key_exists($column, $array)) {
                $array[$alias] = $array[$column];
            }
        }

        return $array;
    }
}
