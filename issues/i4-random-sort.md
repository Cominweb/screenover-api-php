# (blocking) Add random sorting (or a random endpoint)

## Problem
There is currently no way to fetch a randomly ordered list through the SDK.

## Expected behavior
Be able to request a random ordering of results, e.g. via a dedicated `order` value
(`order: 'random'`) translated by the `OptionParser`, or via a dedicated
helper/endpoint.

## Location
- `src/Query/OptionParser.php` → `applyOrder()`: add support for a `random` key.
- `src/ScreenoverApi.php` → optional `getRandom()` helper if a backend random endpoint
  is exposed.

## Suggested fix
- If Payload/the backend supports random sorting (or a random endpoint): wire it up.
- Otherwise, SDK fallback: fetch then shuffle client-side (documenting the pagination
  limitation), or request a random endpoint from the backend.

## To clarify
- Is random sorting natively supported by the backend, or is a client-side fallback
  required? (to confirm with the ScreenOver team).

## Acceptance criteria
- [ ] `get('media', ['order' => 'random'])` returns a random order.
- [ ] Behavior documented (native vs client-side fallback).
- [ ] Test covering random sorting.

## Scope
100% SDK.
