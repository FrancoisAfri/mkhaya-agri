<?php

namespace App\Modules\Operations\ProjectManagement\Support\Api;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The three query conventions every PM list endpoint honours (§4): cursor pagination,
 * `filter[...]`, and `?fields=`.
 *
 * Cursor rather than offset pagination because the Gantt and the mobile sync both walk large,
 * actively-changing result sets — with offsets, a row inserted mid-walk shifts every later page and
 * the client silently skips records.
 *
 * Field selection is not a nicety here: the Gantt fetches thousands of tasks and needs a handful of
 * columns per row, so `?fields=` is what keeps that payload survivable on a 3G link.
 */
class ApiQuery
{
    public const MAX_LIMIT = 200;

    public const DEFAULT_LIMIT = 50;

    /** Requested page size, clamped so one client cannot ask for the whole table. */
    public static function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    /**
     * Apply `?filter[key]=value` for an allow-listed set of columns.
     *
     * Allow-listed rather than pass-through: forwarding arbitrary keys into a where clause lets a
     * client filter on columns it should not even know exist.
     *
     * @param  array<string, callable(Builder, mixed): void>  $handlers  filter key => applier
     */
    public static function applyFilters(Builder $query, Request $request, array $handlers): Builder
    {
        $filters = $request->query('filter');

        if (! is_array($filters)) {
            return $query;
        }

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || ! isset($handlers[$key])) {
                continue;
            }

            $handlers[$key]($query, $value);
        }

        return $query;
    }

    /**
     * Narrow a presented row to the requested fields.
     *
     * `id` is always kept: a client that drops it gets rows it cannot address, which is never what
     * it meant. An unknown field name is ignored rather than rejected, so adding a field to a
     * client does not break against an older server.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function selectFields(array $row, Request $request): array
    {
        $fields = $request->query('fields');

        if (! is_string($fields) || trim($fields) === '') {
            return $row;
        }

        $wanted = array_filter(array_map('trim', explode(',', $fields)));

        if ($wanted === []) {
            return $row;
        }

        $wanted[] = 'id';

        return array_intersect_key($row, array_flip($wanted));
    }

    /**
     * The list envelope: the rows, the cursor to continue from, and the capability manifest the
     * client uses to decide which integration actions exist (§4).
     *
     * @param  array<int, array<string,mixed>>  $data
     * @return array<string,mixed>
     */
    public static function envelope(array $data, ?string $nextCursor, array $capabilities = [], array $meta = []): array
    {
        return [
            'data' => $data,
            'meta' => array_merge([
                'next_cursor'  => $nextCursor,
                'has_more'     => $nextCursor !== null,
                'capabilities' => $capabilities,
            ], $meta),
        ];
    }
}
