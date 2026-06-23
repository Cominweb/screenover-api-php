# (CRITICAL) `setProject()` does not scope GET reads

## Problem
`setProject()` / `setCurrentProject()` correctly store the current project, but reads
(`get()`) are not filtered by project. As a result, every list (`media`, `category`,
`tags`, `styles`, ...) mixes data from all projects the account can access →
multi-tenant risk (data leaking across projects).

## Expected behavior
Once `setProject($projectId)` has been called, any `get()` on a project-scoped
collection must be automatically filtered to that project, as announced in the
migration guide.

## Location
- `src/ScreenoverApi.php` → `get()` (~line 323): no injection of the current project.
- Cross-check with the `where`/options built in `src/Query/OptionParser.php`.

## Suggested fix
- Automatically inject the current-project filter into the `where` sent to Payload for
  project-scoped collections (do not inject it for global collections, nor when
  fetching a single document by id).
- Optionally allow an explicit opt-out (e.g. `get(..., $allProjects = true)`).

## Acceptance criteria
- [ ] After `setProject(A)`, `get('media')` only returns project A's media.
- [ ] `get('media', $id)` (by id) still works.
- [ ] Non-scoped collections are not filtered by mistake.
- [ ] Test covering multi-project scoping.

## Scope
100% SDK.
