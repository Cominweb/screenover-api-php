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

Quick reference
---------------

| Mediative | ScreenOver |
|---|---|
| `new MediativeApi($pub, $secret, $domain)` | `new ScreenoverApi($pub, $apiKey)` (domain optional) |
| `$client->auth()` | `$client->auth()` (+ `setProject()` once) |
| resource `medias` | resource `media` |
| `$response->Media` / `$response[0]->Media->id` | returned document/array directly (`$response['id']`) |
| integer ids | UUID ids |
| `where` `%%`, `<`, `>`, `;` | same syntax, translated to PayloadCMS operators |
| update via `PUT` | `put()` (sent as `PATCH`) |
| `domain` scoping | `setProject()` scoping |
| single-step media add | `uploadMedia()` for files / `post()` for youtube/vimeo |

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
