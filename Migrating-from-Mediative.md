Migrating from Mediative
========================

This is the **complete migration guide** from the legacy Mediative PHP wrapper
(`MediativeApi`) to `screenover-api-php` (`Screenover\Api\ScreenoverApi`).

`screenover-api-php` is designed as a **drop-in replacement**: the public interface
(`auth`, `get`, `post`, `put`, `delete`, plus the token/secure/reset/close helpers
and the positional flags `$autoMap`, `$shortCut`, `$check`) is kept identical, with
the same method names, the same argument order and the same exception messages. Only
the backend differs (ScreenOver / PayloadCMS instead of the old Mediative API), and
the SDK hides those differences for you: header-based auth, `PATCH` updates,
paginated `{ docs }` responses, project scoping and multi-step media uploads.

Contents
--------

- [What changes (and what does not)](#what-changes-and-what-does-not)
- [Step-by-step](#step-by-step)
- [Field & relation mapping](#field--relation-mapping)
- [Video & thumbnail URLs](#video--thumbnail-urls)
- [Pagination](#pagination)
- [Quick reference](#quick-reference)
- [Before / after: a full script](#before--after-a-full-script)
- [Troubleshooting](#troubleshooting)

What changes (and what does not)
--------------------------------

**Unchanged** (your existing code keeps working as-is):

- the method names and signatures: `auth()`, `get()`, `post()`, `put()`, `delete()`,
  `getToken()/setToken()`, `enableSecure()/disableSecure()`, `reset()`, `close()`;
- the legacy query-option syntax (`where`, `order`, `fields`, `recursive`, `limit`) —
  it is translated to PayloadCMS automatically;
- the credential-validation error messages (e.g. *"Please provide your public auth
  token."*, *"Please provide an ID to update"*);
- exception handling: every error still extends `\Exception`, so existing
  `try { ... } catch (Exception $e) { ... }` blocks keep working.

**What you must adapt** (consequences of the new backend):

1. the class name (`MediativeApi` → `ScreenoverApi`) and namespace;
2. the `domain` argument is now optional (defaults to `screenover.com`);
3. **scoping**: call `setProject()` once instead of relying on the `domain`;
4. the resource name `medias` → `media`;
5. **reading responses**: ScreenOver returns plain arrays (`$response['id']`) instead
   of objects (`$response->Media`, `$response[0]->Media->id`);
6. IDs are now **UUID strings**, not integers;
7. media-creation fields follow the ScreenOver model (`source: { type, url }`);
8. real file uploads go through `uploadMedia()`.

Step-by-step
------------

### 1. Get your ScreenOver credentials

From your administrator, obtain:

- your **API key** (a UUID, generated from your ScreenOver user account),
- your **project id** (the project your data was imported into).

You no longer need a "domain": all clients are hosted on `screenover.com` (the
default).

### 2. Install the SDK and switch the `require`

```php
// before
require 'MediativeApi.php';
// after
require __DIR__ . '/screenover-api-php/autoload.php'; // or vendor/autoload.php
```

With Composer: `composer require cominweb/screenover-api-php` then
`require 'vendor/autoload.php';`.

### 3. Replace the class name and drop the domain argument

```php
// before
$client = new MediativeApi(PUBLIC, SECRET, DOMAIN);
// after
use Screenover\Api\ScreenoverApi;
$client = new ScreenoverApi(PUBLIC, API_KEY); // domain defaults to screenover.com
```

### 4. Authenticate the same way, then select your project once

This replaces the old per-domain scoping:

```php
$client->auth();
$client->setProject(PROJECT_ID); // setCurrentProject() is an alias
```

If you do not know the project id, discover it through the API:

```php
$projects = $client->getProjects();
foreach ($projects as $p) {
    echo $p['id'] . ' => ' . $p['title'] . "\n";
}
$client->setCurrentProject($projects[0]['id']);
// or let the SDK auto-select when the account has a single project:
$client->selectProject();        // auto-select
$client->selectProject('My project'); // or select by title (case-insensitive)
```

### 5. Rename the resource `medias` → `media`

```php
$client->get('media'); // was: $client->get('medias');
```

### 6. Adapt how you read responses

Mediative wrapped results in a `->Media` object; ScreenOver returns plain arrays:

```php
// before
$id = $response[0]->Media->id;
$title = $query->Media->title;
// after
$id = $response['id'];
$title = $query['title'];
```

> Note: `post()` now returns the **created document directly** (`$response['id']`),
> not a list — there is no `$response[0]` indirection anymore.

### 7. IDs are now UUID strings

They are not integers. If you stored Mediative integer ids, map them to the new
ScreenOver ids — the import keeps the original reference in `importSourceKey` /
`importSourceData` on each media.

### 8. Adapt media creation fields to the ScreenOver model

```php
// before (Mediative)
$client->post('medias', ['title' => 'Test', 'type' => 'youtube', 'license' => 'public']);
// after (ScreenOver)
$client->post('media', [
    'title'  => 'Test',
    'source' => ['type' => 'youtube', 'url' => 'https://youtu.be/xxxx'],
]);
```

### 9. Use `uploadMedia()` for real files

Instead of inlining file data, real files (image, video, pdf...) go through the
multi-step GCS upload flow, handled automatically:

```php
$media = $client->uploadMedia('/path/to/photo.jpg', ['title' => 'My photo']);
echo $media['id'];
```

### 10. Query options are unchanged

`where`, `order`, `fields`, `limit` keep the same syntax and are translated for you.
You can additionally pass `locale` (`fr`/`en`).

Legacy filter aliases are supported as well:

- `id:xxx` -> filter by document id
- `category:xxx` and `Category.id:xxx` -> filter medias by category relation

If a legacy `where` condition cannot be translated, the SDK now throws an explicit
`UnsupportedFilterException` instead of silently dropping the filter.

```php
$client->get('media', [
    'where'     => 'title%%test;created<2014-11-12', // %% = like, ";" = AND
    'order'     => 'created:DESC,title',
    'fields'    => 'Media.id,Media.created,Media.title',
    'recursive' => -1,        // mapped to PayloadCMS "depth"
    'limit'     => '0,25',    // "offset,count" -> limit + page
    'locale'    => 'fr',
]);
```

For category lists intended for public navigation/UI, prefer the dedicated helper:

```php
$client->getPublicCategories([
    'order' => 'created:DESC',
    'limit' => '0,25',
]);
```

It keeps project scoping and applies the public-visibility filter internally.

Field & relation mapping
------------------------

### Scalar fields

| Mediative field | ScreenOver / PayloadCMS field | Notes |
|---|---|---|
| `id` | `id` | Now a UUID string, not an integer |
| `title` | `title` | Unchanged |
| `created` | `createdAt` | ISO 8601 timestamp |
| `modified` / `updated` | `updatedAt` | ISO 8601 timestamp |
| `type` | `source.type` | Moved into the `source` object |
| `license` | *(removed)* | No equivalent; use tags/styles instead |
| `importSourceKey` | `importSourceKey` | **Original Mediative integer id** — use to map legacy ids to new UUIDs |
| `importSourceData` | `importSourceData` | Full original Mediative payload stored as JSON — use to access any field from the import |

> **Tracing legacy ids:** every record imported from Mediative keeps its original integer id in
> `importSourceKey` and the full source payload in `importSourceData`. You can therefore look up
> any imported media by its old id:
>
> ```php
> $media = $client->get('media', ['where' => 'importSourceKey=12345']);
> echo $media['id']; // the new UUID
> ```

### Relations

| Mediative relation | ScreenOver / PayloadCMS | Notes |
|---|---|---|
| `Category` (flat field `category_id`) | `categories[].category` | Stored as a join collection `category-media`; each entry has a `category` relation field pointing to the category document |
| `Tag` | `tags[]` | Direct relation array on the media document |
| `Style` | `styles[]` | Direct relation array on the media document |

#### Reading categories

Because categories are stored through the `category-media` join table, the `categories` array on each media document contains join records, not category documents directly:

```php
// depth=1 (or recursive=1) is required to populate the relations
$media = $client->get('media', ['where' => 'id=<uuid>', 'recursive' => 1]);

foreach ($media['categories'] as $entry) {
    $category = $entry['category']; // populated category document (array)
    echo $category['id'] . ': ' . $category['title'] . "\n";
}
```

#### Filtering by category

Both legacy and native forms are accepted:

```php
// legacy Mediative filter (colon operator, resolved automatically)
$client->get('media', ['where' => 'category:<category-uuid>']);
$client->get('media', ['where' => 'Category.id:<category-uuid>']);

// native PayloadCMS filter (array form)
$client->get('media', ['where' => ['categories.category' => ['equals' => '<category-uuid>']]]);
```

### Project scoping

The following collections are automatically scoped to the active project on both reads and writes:

`media`, `category`, `category-media`, `tags`, `styles`, `media-watch`, `media-watch-result`

No extra `where` condition is needed — the SDK injects the project filter transparently. To
opt-out and query across all projects, pass `true` as the fifth argument to `get()`:

```php
$client->get('media', [], true, true, true); // allProjects = true
```

### Query field aliases

The SDK resolves legacy field names (including `Model.` prefixes) automatically in `where`,
`order`, and `fields` options:

| Mediative token | Resolved ScreenOver field |
|---|---|
| `created` / `Media.created` | `createdAt` |
| `modified` / `updated` | `updatedAt` |
| `id` / `Media.id` | `id` |
| `category` / `Category.id` | `categories.category` |
| `Media.<field>` (any other) | `<field>` (prefix stripped) |

If a legacy `where` condition uses an unrecognised operator, the SDK throws
`Screenover\Api\Exception\UnsupportedFilterException` instead of silently dropping the filter.

Video & thumbnail URLs
----------------------

### Source types

Each media document has a `source` object with at least a `type` field. The `url` field is
present for all externally-hosted types:

| `source.type` | Where the video lives | `source.url` value |
|---|---|---|
| `youtube` | YouTube platform | The full YouTube URL (e.g. `https://youtu.be/xxxx`) |
| `vimeo` | Vimeo platform | The full Vimeo URL (e.g. `https://vimeo.com/xxxx`) |
| `dailymotion` | Dailymotion platform | The full Dailymotion URL |
| `upload` | Google Cloud Storage | GCS public/signed URL to the video file (set by the API after upload) |
| `hls` | External HLS stream | The `.m3u8` manifest URL |

### Reading the video URL

```php
$media = $client->get('media', $id);

$type = $media['source']['type'];   // 'youtube', 'vimeo', 'upload', …
$url  = $media['source']['url'];    // playback URL for all types

// Embed examples per type:
switch ($type) {
    case 'youtube':
        // extract video id from url, e.g. https://www.youtube.com/embed/{id}
        preg_match('/(?:youtu\.be\/|[?&]v=)([A-Za-z0-9_-]{11})/', $url, $m);
        $embedUrl = 'https://www.youtube.com/embed/' . $m[1];
        break;
    case 'vimeo':
        preg_match('#vimeo\.com/(\d+)#', $url, $m);
        $embedUrl = 'https://player.vimeo.com/video/' . $m[1];
        break;
    case 'dailymotion':
        preg_match('#(?:dai\.ly|dailymotion\.com/video)/([A-Za-z0-9]+)#', $url, $m);
        $embedUrl = 'https://www.dailymotion.com/embed/video/' . $m[1];
        break;
    case 'upload':
    case 'hls':
        $embedUrl = $url; // direct file / manifest URL
        break;
}
```

### Thumbnail URLs

Thumbnails are stored under the `thumbnail` key of the media document. For uploaded files the
thumbnail is computed during the finalisation step of `uploadMedia()` and returned by the API.

```php
$media = $client->get('media', $id);

// preferred thumbnail (API-resolved, works for all source types)
$thumb = $media['thumbnail']['url'] ?? null;

// fallback: build a YouTube/Vimeo thumbnail from the source URL yourself
if ($thumb === null && $media['source']['type'] === 'youtube') {
    preg_match('/(?:youtu\.be\/|[?&]v=)([A-Za-z0-9_-]{11})/', $media['source']['url'], $m);
    $thumb = 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
}
```

> The `thumbnail` object may include additional sizes (`small`, `medium`, `large`) depending on
> your ScreenOver plan. Inspect `$media['thumbnail']` to see what is available.

Pagination
----------

`get()` on a collection returns a list. After each list call you can retrieve the pagination
metadata without any extra request:

```php
$medias = $client->get('media', ['limit' => 25, 'page' => 2]);

$pagination  = $client->getPagination();  // full PayloadCMS envelope
$totalDocs   = $client->getTotalDocs();   // (int) total matching documents
$totalPages  = $client->getTotalPages();  // (int) total pages for the current limit

echo "Page 2 of $totalPages ($totalDocs total media)\n";
foreach ($medias as $m) {
    echo $m['title'] . "\n";
}
```

Available pagination keys (`getPagination()` returns all of them when present):

| Key | Type | Description |
|---|---|---|
| `totalDocs` | int | Total number of documents matching the query |
| `totalPages` | int | Total number of pages |
| `page` | int | Current page number |
| `limit` | int | Page size used |
| `hasNextPage` | bool | `true` when a next page exists |
| `hasPrevPage` | bool | `true` when a previous page exists |

#### Random ordering

Pass `random` as the order value to retrieve a random selection:

```php
$randomMedias = $client->get('media', ['order' => 'random', 'limit' => 5]);
```

Quick reference
---------------

| Mediative | ScreenOver |
|---|---|
| `new MediativeApi($pub, $secret, $domain)` | `new ScreenoverApi($pub, $apiKey)` (domain optional) |
| `$client->auth()` | `$client->auth()` (+ `setProject()` once) |
| resource `medias` | resource `media` |
| `$response->Media` / `$response[0]->Media->id` | returned document/array directly (`$response['id']`) |
| integer ids | UUID ids |
| `where` `%%`, `<`, `>`, `=`, `;` | same syntax, translated to PayloadCMS operators |
| `id:xxx` / `category:xxx` / `Category.id:xxx` | legacy colon filters, resolved automatically |
| `created` field | `createdAt` field (mapped automatically) |
| `category_id` / Category relation | `categories[].category` (join collection) |
| Tag / Style relations | `tags[]` / `styles[]` (direct relations) |
| update via `PUT` | `put()` (sent as `PATCH`) |
| `domain` scoping | `setProject()` scoping |
| single-step media add | `uploadMedia()` for files / `post()` for youtube/vimeo/dailymotion/hls |
| manual category visibility filter | `getPublicCategories()` helper |
| *(no pagination metadata)* | `getPagination()` / `getTotalDocs()` / `getTotalPages()` after each list |
| *(silent unknown filter)* | throws `UnsupportedFilterException` |
| original integer id | `importSourceKey` / `importSourceData` on every migrated document |

Before / after: a full script
------------------------------

**Before (Mediative):**

```php
require 'MediativeApi.php';

$client = new MediativeApi(PUB, SECRET, DOMAIN);
$client->auth();

$response = $client->post('medias', ['title' => 'test api', 'type' => 'youtube']);
$id = $response[0]->Media->id;

$query = $client->get('medias', $id);
echo $query->Media->title;

$client->put('medias', ['id' => $id, 'title' => 'updated api']);
$client->delete('medias', $id);
```

**After (ScreenOver):**

```php
require __DIR__ . '/screenover-api-php/autoload.php';

use Screenover\Api\ScreenoverApi;

$client = new ScreenoverApi(PUB, API_KEY); // domain optional
$client->auth();
$client->setProject(PROJECT_ID);

$media = $client->post('media', [
    'title'  => 'test api',
    'source' => ['type' => 'youtube', 'url' => 'https://youtu.be/xxxx'],
]);
$id = $media['id'];

$query = $client->get('media', $id);
echo $query['title'];

$client->put('media', ['id' => $id, 'title' => 'updated api']);
$client->delete('media', $id);
```

See [`examples/index.php`](examples/index.php) for a complete, runnable CRUD scenario.

Troubleshooting
---------------

- **`AuthException: You should set your auth token before making a request.`** — call
  `$client->auth()` before any `get/post/put/delete`.
- **`AuthException: Please provide the domain without path and protocol.`** — pass a
  bare host (e.g. `demo.screenover.com` or `localhost:3000`), not a URL.
- **Created resources are not scoped / rejected** — make sure you called
  `setProject()` (or `selectProject()`); project-scoped collections (`media`,
  `category`, `tags`, `styles`, ...) require an active project.
- **`NotFoundException` on a known id** — IDs are UUID strings now; check you are not
  passing a legacy integer id.
- **Validation errors on media creation** — use the new field model
  (`source: { type, url }`) instead of the flat `type` / `license` fields.
- **Self-signed certificate (local dev only)** — `$client->disableSecure();` (never
  in production).
