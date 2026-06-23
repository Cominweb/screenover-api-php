# (important) Expose the total result count (pagination) — `totalDocs` / `totalPages`

## Problem
By default `get()` uses `shortCut = true`, so `normalize()` returns only the `docs`
array and **drops the pagination metadata** returned by PayloadCMS (`totalDocs`,
`totalPages`, `page`, `limit`, `hasNextPage`, `hasPrevPage`). The caller therefore
knows neither the total number of results nor the number of pages.

## Expected behavior
Be able to retrieve the pagination metadata while keeping backward compatibility with
the legacy behavior (plain list by default).

## Location
- `src/ScreenoverApi.php`:
  - `get()` (~l.323): `shortCut = true` by default.
  - `normalize()` (~l.539): `return $response['docs'];` strips everything else.

## Suggested fix
- Add a helper exposing pagination (e.g. `getPagination()` / `getTotalDocs()` based on
  the last response), **or**
- Document/ease the use of `shortCut = false` to get the full response (with
  `totalDocs`/`totalPages`), **or**
- Keep the last request's metadata in an accessible property.
- Do not break the default `docs` return (backward compatibility).

## Acceptance criteria
- [ ] `totalDocs` and `totalPages` can be obtained after a list `get()`.
- [ ] The default return (the `docs` list) stays unchanged.
- [ ] Test verifying the availability of pagination metadata.

## Scope
100% SDK.
