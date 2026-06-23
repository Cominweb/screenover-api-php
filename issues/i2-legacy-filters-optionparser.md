# (blocking) Legacy filters `id:` / `Category.id:` / `category:` are not translated

## Problem
Some legacy Mediative filters are not translated by the `OptionParser`: `id:`,
`Category.id:`, `category:` (and similar). As a result, the list is returned
**unfiltered and without any error**, silently hiding the bug on the caller side.

## Expected behavior
Either these filters are correctly translated into PayloadCMS `where` syntax, or the
`OptionParser` fails loudly (explicit exception) instead of silently ignoring the
filter.

## Location
- `src/Query/OptionParser.php`:
  - `OPERATORS` (~l.26) and `FIELD_MAP` (~l.41): no mapping for these keys/prefixes.
  - `applyWhere()` / `build()`: translation of prefixed fields (`Model.field`,
    `field:`) is not handled for these cases.

## Suggested fix
- Extend `FIELD_MAP` and the parsing logic to recognize `id`, `Category.id`,
  `category` and map them to the correct ScreenOver fields/relations.
- Handle the `Model.` prefix (e.g. `Category.id` → `category.id` or `category`).
- Throw a clear exception when a filter is not recognized (instead of ignoring it).

## Acceptance criteria
- [ ] `where: 'id:xxx'` filters by id.
- [ ] `where: 'Category.id:xxx'` / `category:xxx` filter by the category relation.
- [ ] An unrecognized filter throws an explicit exception (no silent pass-through).
- [ ] Tests for each of these filters.

## Scope
100% SDK.
