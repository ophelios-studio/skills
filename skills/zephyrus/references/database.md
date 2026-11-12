# Database: listings and hydration

Read this when building a paginated, sorted or filtered listing from request
input, or when an entity hydrates unexpectedly. Basics and gotchas are in
`SKILL.md`.

## Pagination, sorting, filtering

```php
use Zephyrus\Data\FilterRequest;
use Zephyrus\Data\PaginationRequest;
use Zephyrus\Data\SortRequest;

// $request->query is the public array of the whole query string; $request->query('page') is the method reading one key.
// GET /projects?page=1&per_page=500&sort=-name&status=active
$page = PaginationRequest::fromArrayWithBounds($request->query, defaultPerPage: 25, maxPerPage: 100);
$sort = SortRequest::fromQuery($request->query, ['name', 'created_at'], defaultColumn: 'name');
$filter = FilterRequest::fromQuery($request->query, ['status']);

$result = $db->paginateFilteredSortedResultWith(
    'SELECT id, name FROM project', // no WHERE or ORDER BY: the requests append them
    'SELECT count(*) FROM project',
    $filter,
    ['status' => 'status'], // query key => column
    $sort,
    $page,
);
$result->items;      // stdClass rows, here 15 active projects ordered by name descending
$result->total;      // 15
$result->perPage;    // 100: per_page=500 was clamped
$result->hasNext;    // false
```

- `PaginationRequest::fromArray($request->query)` without a `maxPerPage` sends
  the client's `per_page` straight to `LIMIT` (`per_page=1000000` stays
  1000000). Use `fromArrayWithBounds()`.
- `SortRequest::fromQuery()` requires the allowlist: a column outside it, or
  any injection attempt, falls back to the default column. It reads `sort`
  (`name`, `-name`), `sort_by`, `orderBy`... and `sort_dir`, `direction`.
  `SortRequest::fromArray($data, 'name')` ignores `sort` and, without its
  `allowedColumns` argument, orders by any column `sort_by` names.
- `$sort->toSql()` already starts with ` ORDER BY`: never write `ORDER BY`
  before it.
- `FilterRequest` builds exact-match conditions with bound parameters
  (` WHERE "status" = :f_status`), only for the allowed keys.
- Smaller pieces: `paginateSortedResultWith()`, `paginateResultWith()`,
  `selectPageWith()`, `selectSorted()`, `selectFiltered()`; the `paginate*`
  variants without `Result` return an array with `items`, `total`, `page`,
  `per_page`, `total_pages`, `has_previous`, `has_next`. A `Broker` has the
  same methods as protected helpers.

## Column type cache and statements

Rows are decoded from the column types PostgreSQL reports, cached per SQL text and result
width, in the process and in APCu when it is available. Set `column_cache_version` in the
`database` section to a value that changes with every migration (for example the id of the
latest one): without it, a column that changes type while keeping its name and the width of
the result keeps its old converter, so a `jsonb` column comes back as a string. That lasts up
to an hour under PHP-FPM with APCu, and only the current request under PHP-FPM without it; in
a long-running process (CLI, RoadRunner) until it restarts or `Database::flushSharedColumnMetadata()`
runs in that process.

```yaml
database:
  column_cache_version: !env DB_SCHEMA_VERSION
```

`!env DB_SCHEMA_VERSION` unquoted loads through `Configuration::fromYamlFile()` and reaches
`DatabaseConfig::$columnCacheVersion` as a string. When `DB_SCHEMA_VERSION` is not set it loads `''`
without any error (no version, so the stale window returns). Change the value after the migration has
run and before the release serves traffic. It helps when the PHP master survives a release
and re-reads `config.yml` (symlink deploys); a process restart already clears the cache.

Only a statement whose first element, after blanks and comments, is a parenthesis or one of
`SELECT`, `WITH`, `VALUES`, `TABLE`, `INSERT`, `UPDATE`, `DELETE`, `MERGE` uses the cache
*(0.15)*. Any other (`FETCH`, `EXECUTE`, `SHOW`, `CALL`, a leading `;`, a nested comment (an
old query left commented out, for instance)) has its columns described on every call,
because the same text can return another shape. The cost is one to two round trips per
column depending on the types, so fetch in large batches. On `v0.14.1` a `FETCH 1000 FROM c`
on a second cursor of the same width reused the first cursor's converters and its `jsonb`
columns came back as strings.

Queries of a `Database` built by `Database::fromConfig()` go out as unnamed statements with
the parameters bound by the server, still not emulated *(0.15)*; a `new Database($pdo)` keeps
named statements. On `v0.14.1` each query was a named statement, prepared and then
closed (a `DEALLOCATE`, or a protocol close under libpq 17): three round trips per query
against one on `v0.15.0`. That is two more per query, one to prepare and one to close.

## Hydration rules

Date strings and the refusals below (a date, a nested entity, an unsupported type or
value) arrived in `v0.15.0` *(0.15)*. On `v0.14.1` a date property stayed unset and most
values it could not hydrate were skipped without an error.

- Columns convert by type: integers, floats, booleans, PostgreSQL arrays as
  PHP arrays, JSON and JSONB decoded (objects as `stdClass`). `NUMERIC` becomes
  a float (gotcha 23), `bytea` a stream (gotcha 24).
- `Entity::build()` casts each column to the declared property type; a column
  without a matching public property is ignored, and `getRawData()` returns
  the whole row.
- A `null` value is assigned as is, so a non-nullable property throws a
  `TypeError` (gotcha 31). A backed enum property takes `Enum::from()`; a value
  outside the enum (or of the wrong type, such as `'1'` for an int-backed enum)
  throws `InvalidArgumentException: Invalid value for enum ... on property
  $status`. A pure (non-backed) enum throws `Cannot hydrate property $name of
  <Entity>: type <Enum> is not supported`.
- An `Entity`-typed property (`self` and `parent` included) is built from a
  `stdClass` value (a JSON object column, or a row you assemble); any other
  non-null value throws `InvalidArgumentException: Invalid value for entity
  ... on property $name`.
- A `DateTimeImmutable` or `DateTimeInterface` property takes
  `new DateTimeImmutable($value)`, a `DateTime` property `new DateTime($value)`.
  The value must be an ISO string: `YYYY-MM-DD`, then optionally `T` or a
  space and `HH:MM[:SS[.ffffff]]`, then optionally `Z` or an offset (`-05`,
  `+05:30`, `-05:17:32`), as PostgreSQL renders a `date` or `timestamptz` under
  the default ISO `DateStyle` (under `SQL`, `Postgres` or `German` the column is
  refused), and a real date. Anything else throws `InvalidArgumentException:
  Invalid value for date ... on property $name` (the value is not quoted): an
  empty or blank string, `0000-00-00`, `2030-02-30`, relative formats
  (`tomorrow`, `1 day`, `12:00`, `2024-01-01 +1 year`), `@0`, `infinity` and
  `-infinity`, BC dates, a year over 4 digits, a named zone (`2024-01-01
  Europe/Paris`), an integer. An offset such as `+02:00` is kept. A
  second-precision offset (`-05:17:32`) gives the right instant, but `format('c')`
  and `format('P')` show it truncated to `-05:17`.
- Native types: `bool`, `int`, `float`, `string`, `array` and `object` cast the
  value; `mixed` takes it unchanged; any other native type (`iterable`,
  `callable`, `false`, ...) throws `Cannot hydrate property $name of <Entity>:
  type <type> is not supported`. So does a non-null value for any other class
  type, a union or an intersection. A `null` is not refused. Declare the
  column as `string` and convert it in a method, or assign the property after
  `build()` from a column the entity does not map.
- `json_encode($entity)` skips properties the row did not set and properties
  marked `#[JsonIgnore]` (password hashes, internal ids).
