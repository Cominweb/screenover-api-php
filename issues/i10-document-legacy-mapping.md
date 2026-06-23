# (doc) Document the legacy -> native mapping (relations, video/thumbnail URLs)

## Problem
There is no complete reference describing how the legacy Mediative model maps to the
native ScreenOver model. Integrators migrating from Mediative don't have a clear
field/relation correspondence, nor how video and thumbnail URLs are now built.

## Expected behavior
A documented mapping table (legacy Mediative -> ScreenOver/Payload) covering:
- fields and relations (e.g. `medias` -> `media`, category relations, tags, styles);
- how video URLs are built/retrieved per source type (youtube/vimeo/dailymotion vs
  uploaded/HLS files);
- how thumbnail URLs are built/retrieved;
- where to find the original reference of an imported record
  (`importSourceKey` / `importSourceData`).

## Location
- `Migrating-from-Mediative.md`: already contains a base (method/field overview and a
  quick-reference table) — to be completed with relation details and URL construction.

## Suggested fix
- Extend `Migrating-from-Mediative.md` with a dedicated "Field & relation mapping"
  section and a "Video/thumbnail URLs" section.
- Add concrete before/after examples for the relations and the URLs.

## Acceptance criteria
- [ ] Mapping table for all migrated fields/relations.
- [ ] Documented rules for video URLs (per source type).
- [ ] Documented rules for thumbnail URLs.
- [ ] Note on `importSourceKey` / `importSourceData` for tracing legacy ids.

## Scope
Documentation (SDK repo).
