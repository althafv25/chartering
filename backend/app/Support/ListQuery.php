<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Shared list helpers: allow-listed sorting ("name" / "-name"). */
final class ListQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $allowed
     * @return Builder<TModel>
     */
    public static function sort(Builder $query, ?string $sort, array $allowed, string $default): Builder
    {
        $sort = $sort ?: $default;
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (! in_array($column, $allowed, true)) {
            $column = ltrim($default, '-');
            $direction = str_starts_with($default, '-') ? 'desc' : 'asc';
        }

        return $query->orderBy($column, $direction)->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /** Escapes LIKE wildcards in user input. */
    public static function like(string $term): string
    {
        return '%'.addcslashes(trim($term), '%_\\').'%';
    }
}
