<?php

/**
 * Nexus value serializer.
 *
 * This file is loaded in two places:
 *   1. Base64-eval'd into a *target* project's `php artisan tinker` process by
 *      TinkerResultSerializer, so it can turn the last-evaluated value into a
 *      typed envelope the Vue frontend renders (table / tree / raw).
 *   2. `require`d directly by Nexus's own PHPUnit suite to test the logic.
 *
 * It therefore must be dependency-free beyond the Laravel classes that *any*
 * target Laravel app already provides (Model, Collection, Arrayable), and it
 * probes for those with instanceof so it never hard-requires them. The helpers
 * are global (no namespace) because the injected emitter line calls them by
 * bare name inside the tinker REPL.
 *
 * Everything is capped (depth, children, rows, string length) so a `User::all()`
 * against a million-row table can't blow up the pipe or the JSON encode.
 */
if (! defined('NEXUS_MAX_DEPTH')) {
    define('NEXUS_MAX_DEPTH', 6);        // nesting levels before a node is summarised
    define('NEXUS_MAX_CHILDREN', 200);   // entries rendered per array/object node
    define('NEXUS_MAX_ROWS', 500);       // rows collected for the tabular view
    define('NEXUS_MAX_STRING', 20000);   // chars kept per string (true length preserved)
}

if (! function_exists('nexus_serialize')) {
    /**
     * The typed envelope for a single value: metadata + a recursive tree node
     * (for the tree view) + an optional flat table (when the value is tabular).
     */
    function nexus_serialize($value): array
    {
        return [
            'meta' => [
                'phpType' => nexus_type_name($value),
                'preview' => nexus_preview($value),
            ],
            'root' => nexus_node($value, 0),
            'table' => nexus_table($value),
        ];
    }

    /**
     * The full payload emitted between sentinels: the value envelope plus any
     * SQL captured via DB::enableQueryLog() during the run (folded in here so
     * nexus_serialize() stays pure and unit-testable without a database).
     */
    function nexus_envelope($value): array
    {
        $env = nexus_serialize($value);
        $env['queries'] = nexus_queries();

        return $env;
    }

    function nexus_type_name($value): string
    {
        if (is_object($value)) {
            return get_class($value);
        }

        return match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'double',
            is_bool($value) => 'boolean',
            is_string($value) => 'string',
            is_array($value) => 'array',
            $value === null => 'null',
            default => gettype($value),
        };
    }

    /** A short, single-line human summary — what the type chip / raw hint shows. */
    function nexus_preview($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $s = mb_substr($value, 0, 120);

            return '"'.$s.(mb_strlen($value) > 120 ? '…' : '').'"';
        }
        if (nexus_is_model($value)) {
            $key = method_exists($value, 'getKey') ? $value->getKey() : null;

            return get_class($value).($key !== null ? ' #'.$key : '');
        }
        if (nexus_is_collection($value)) {
            return 'Collection('.$value->count().')';
        }
        if (is_array($value)) {
            return (array_is_list($value) ? 'array[' : 'array{').count($value).(array_is_list($value) ? ']' : '}');
        }
        if (is_object($value)) {
            return get_class($value);
        }

        return gettype($value);
    }

    /** Recursive tree node with depth/child caps. */
    function nexus_node($value, int $depth): array
    {
        if ($value === null) {
            return ['kind' => 'null'];
        }
        if (is_bool($value)) {
            return ['kind' => 'bool', 'value' => $value];
        }
        if (is_int($value)) {
            return ['kind' => 'number', 'value' => $value, 'phpType' => 'integer'];
        }
        if (is_float($value)) {
            // JSON can't carry INF/NAN — degrade to a string so the pipe survives.
            return ['kind' => 'number', 'value' => is_finite($value) ? $value : (string) $value, 'phpType' => 'double'];
        }
        if (is_string($value)) {
            $len = mb_strlen($value);
            $truncated = $len > NEXUS_MAX_STRING;

            return [
                'kind' => 'string',
                'value' => $truncated ? mb_substr($value, 0, NEXUS_MAX_STRING) : $value,
                'length' => $len,
                'truncated' => $truncated,
            ];
        }

        // Models and collections normalise to their array shape but keep their
        // class name so the tree can label them.
        if (nexus_is_model($value)) {
            return nexus_container('model', get_class($value), nexus_model_array($value), $depth);
        }
        if (nexus_is_collection($value)) {
            return nexus_container('collection', get_class($value), $value->all(), $depth);
        }
        if (nexus_is_arrayable($value)) {
            return nexus_container('object', get_class($value), $value->toArray(), $depth);
        }
        if (is_array($value)) {
            return nexus_container(array_is_list($value) ? 'list' : 'assoc', null, $value, $depth);
        }
        if (is_object($value)) {
            return nexus_container('object', get_class($value), get_object_vars($value), $depth);
        }

        // Closures, resources, etc.
        return ['kind' => 'opaque', 'preview' => nexus_preview($value)];
    }

    /** Build an expandable node from an array-shaped value, honouring the caps. */
    function nexus_container(string $kind, ?string $class, array $items, int $depth): array
    {
        $node = ['kind' => $kind, 'count' => count($items)];
        if ($class !== null) {
            $node['class'] = $class;
        }

        if ($depth >= NEXUS_MAX_DEPTH) {
            $node['collapsed'] = true;
            $node['preview'] = ($class ?? ucfirst($kind)).'('.count($items).')';

            return $node;
        }

        $entries = [];
        $i = 0;
        foreach ($items as $key => $item) {
            if ($i >= NEXUS_MAX_CHILDREN) {
                $node['truncated'] = true;
                break;
            }
            $entries[] = ['key' => (string) $key, 'node' => nexus_node($item, $depth + 1)];
            $i++;
        }
        $node['entries'] = $entries;

        return $node;
    }

    /**
     * Flat table for tabular values (a list of models / assoc arrays), or null
     * when the value isn't a uniform row set. Columns are the union of keys in
     * insertion order; nested cell values collapse to a short preview so the
     * grid stays flat (the tree view handles deep inspection).
     */
    function nexus_table($value): ?array
    {
        if (nexus_is_collection($value)) {
            $items = $value->all();
        } elseif (is_array($value) && array_is_list($value)) {
            $items = $value;
        } else {
            return null;
        }

        if ($items === []) {
            return null;
        }

        $columns = [];
        $rows = [];
        $count = 0;
        foreach ($items as $item) {
            if (nexus_is_model($item)) {
                $row = nexus_model_array($item);
            } elseif (nexus_is_arrayable($item)) {
                $row = $item->toArray();
            } elseif (is_array($item) && ! array_is_list($item)) {
                $row = $item;
            } else {
                // A non-associative element (scalar, list) — not a table.
                return null;
            }

            $count++;
            if (count($rows) >= NEXUS_MAX_ROWS) {
                continue; // keep counting the total, stop collecting cells
            }
            foreach (array_keys($row) as $k) {
                if (! in_array((string) $k, $columns, true)) {
                    $columns[] = (string) $k;
                }
            }
            $rows[] = $row;
        }

        // Reshape into ordered cell arrays keyed by the discovered columns.
        $cells = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = array_key_exists($col, $row) ? nexus_cell($row[$col]) : null;
            }
            $cells[] = $line;
        }

        return [
            'columns' => $columns,
            'rows' => $cells,
            'count' => $count,
            'truncated' => $count > count($rows),
        ];
    }

    /** Scalar cells pass through; everything nested becomes a short preview. */
    function nexus_cell($value)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        return nexus_preview($value);
    }

    /** SQL captured during the run, if query logging was enabled. Best-effort. */
    function nexus_queries(): array
    {
        try {
            if (! class_exists(\DB::class)) {
                return [];
            }
            $log = \DB::getQueryLog();
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach (array_slice($log, 0, 200) as $q) {
            $bindings = $q['bindings'] ?? [];
            $out[] = [
                'sql' => $q['query'] ?? ($q['sql'] ?? ''),
                'bindings' => array_map(
                    fn ($b) => (is_scalar($b) || $b === null) ? $b : nexus_preview($b),
                    is_array($bindings) ? $bindings : []
                ),
                'time' => $q['time'] ?? null,
            ];
        }

        return $out;
    }

    function nexus_is_model($value): bool
    {
        return is_object($value) && $value instanceof \Illuminate\Database\Eloquent\Model;
    }

    function nexus_is_collection($value): bool
    {
        return is_object($value) && $value instanceof \Illuminate\Support\Collection;
    }

    function nexus_is_arrayable($value): bool
    {
        return is_object($value) && $value instanceof \Illuminate\Contracts\Support\Arrayable;
    }

    function nexus_model_array($model): array
    {
        // attributesToArray() gives the DB-backed columns without eagerly
        // pulling every loaded relation into the row (toArray() would).
        return method_exists($model, 'attributesToArray') ? $model->attributesToArray() : (array) $model;
    }
}
