screenover-api-php
==================

ScreenOver PHP API wrapper.

This PHP class lets you use the ScreenOver API from external sources. It is a **drop-in
replacement** for the legacy Mediative PHP wrapper: the public
interface (`auth`, `get`, `post`, `put`, `delete`) is kept identical, so existing client
code only needs new credentials, a new domain and the project id.

Under the hood it talks to the ScreenOver backend (PayloadCMS REST API) and hides all the
differences for you (header-based auth, `PATCH` updates, paginated `{ docs }` responses,
project scoping, multi-step media uploads).

How to install ?
----------------

Get your credentials (API key, domain) from your ScreenOver administrator. Then either:

**With Composer**

```bash
composer require cominweb/screenover-api-php
```

```php
require 'vendor/autoload.php';
```

**Without Composer** — require the bundled autoloader:

```php
require __DIR__ . '/screenover-api-php/autoload.php';
```

Then create a client:

```php
use Screenover\Api\ScreenoverApi;

$client = new ScreenoverApi(PUBLIC, API_KEY); // domain defaults to screenover.com
$client->auth();
$client->setCurrentProject(PROJECT_ID); // replaces the legacy Mediative "domain" scoping
```

Selecting a project
-------------------

ScreenOver scopes most resources (media, categories, tags, styles…) to a **project**.
You must select one before creating or listing scoped resources — this replaces the
legacy Mediative "domain".

If you already know your project id (an UUID, visible in the ScreenOver admin URL when
you open a project), set it directly:

```php
$client->setCurrentProject('cea6f741-10d4-441b-acc5-6e0cd0993fb0');
// setProject() is kept as an alias for backward compatibility
```

Otherwise, discover it through the API with `getProjects()`:

```php
$projects = $client->getProjects();
foreach ($projects as $p) {
    echo $p['id'] . ' => ' . $p['title'] . "\n";
}
$client->setCurrentProject($projects[0]['id']);
```

For convenience, `selectProject()` fetches the list and selects one for you:

```php
$client->selectProject();        // auto-selects when the account has exactly one project
$client->selectProject('test');  // or select by title (case-insensitive)
echo $client->getProject();      // the active project id
```

`selectProject()` throws if no project is accessible, if the title is not found, or if
several projects exist and none was specified (so you make an explicit choice).

Authentication
--------------

Two modes are supported. The default is **API key** (recommended, server-to-server, no
password):

```php
$client = new ScreenoverApi($identifier, $apiKey, $domain);
$client->auth(); // installs "Authorization: users API-Key <key>" — no network call
```

To use an **email / password login** instead (returns a JWT):

```php
$client = new ScreenoverApi($email, $password, $domain);
$client->setAuthMode(ScreenoverApi::AUTH_LOGIN);
$client->auth(); // POST /api/users/login, stores the JWT
```

You can also set a token manually with `$client->setToken($token)` and read it with
`$client->getToken()`.

For self-signed certificates (demo only): `$client->disableSecure();` (re-enable with
`$client->enableSecure();`).

Documentation
-------------

### GET

To GET a resource, use the `get` method with the resource (collection slug) you want.

```php
$client->get('media');
```

You can get a specific resource by providing its id, using one of these ways:

```php
$client->get('media', '11111111-2222-3333-4444-555555555555');
$client->get('media', array('id' => '1111...'));
$client->get('media/1111...');
```

Use the `$options` array to set your select options (legacy Mediative syntax is supported
and translated automatically):

```php
$response = $client->get('media', array(
    'where'     => 'title%%test;created<2014-11-12', // %% = like, ";" = AND
    'order'     => 'created:DESC,title',
    'fields'    => 'Media.id,Media.created,Media.title',
    'recursive' => -1,        // mapped to PayloadCMS "depth"
    'limit'     => '0,25',    // "offset,count" -> limit + page
    'locale'    => 'fr',      // new: ScreenOver is localised fr/en
));
```

Random ordering is also supported through the same option:

```php
$response = $client->get('media', array(
    'order' => 'random', // translated by the SDK to sort=random
));
```

`order=random` uses backend-native random sorting via Payload sort translation. It is not a client-side shuffle fallback.
Legacy aliases are translated too: `id:xxx`, `category:xxx`, `Category.id:xxx`.
If a legacy condition is not recognised, the SDK throws
`Screenover\Api\Exception\UnsupportedFilterException` (fail loud, no silent pass-through).

The response is the list of matching documents (array). For a single document, the
document object is returned directly.

To keep backward compatibility, `get()` returns the plain docs list by default. After a
list request, pagination metadata is available through dedicated helpers:

```php
$list = $client->get('media', array('limit' => '0,25'));
echo $client->getTotalDocs();
echo $client->getTotalPages();
var_dump($client->getPagination()); // totalDocs, totalPages, page, limit, hasNextPage, hasPrevPage
```

If you need the full Payload response envelope in one call, disable shortcut mode:

```php
$raw = $client->get('media', array(), true, false); // $shortCut = false
```

### POST

To POST and create a new resource, use the `post` method.

```php
$datas = array(
    'title'  => 'Test',
    'source' => array('type' => 'youtube', 'url' => 'https://youtu.be/xxxx'),
);
$response = $client->post('media', $datas);
echo $response['id'];
```

The active project is injected automatically on project-scoped collections (`media`,
`category`, `tags`, `styles`, ...) when you have called `setProject()`.

### PUT (update)

To update a resource, use the `put` method (sent as a `PATCH` to the backend).

```php
$datas = array('id' => '1111...', 'title' => 'Updated title');
$response = $client->put('media', $datas);
```

You can also indicate the id in the path:

```php
$response = $client->put('media/1111...', array('title' => 'Updated title'));
```

To make an update without an id check, set the `$check` flag to false.

### DELETE

To delete a resource, use the `delete` method, with one of these syntaxes:

```php
$client->delete('media', '1111...');
$client->delete('media', array('id' => '1111...'));
$client->delete('media/1111...');
```

### Uploading files

Uploading a real file (image, video, pdf...) uses a multi-step flow against Google Cloud
Storage. It is fully handled for you:

```php
$media = $client->uploadMedia('/path/to/photo.jpg', array(
    'title' => 'My photo',
));
echo $media['id'];
```

### Custom endpoints

Any custom endpoint can be reached with `call()`:

```php
$storage = $client->call('GET', 'storage');           // disk usage for the project
$client->call('POST', 'reindex');                       // reindex the current project
```

### Chyro integration

When your project is connected to a Chyro broadcast management system via webhooks,
ScreenOver automatically stores the Chyro identifiers inside each media document's
`metadata` field (`chyroMediaId` and `chyroProgramId`).

Two helpers let you look up a ScreenOver media using the Chyro ID you already have,
without needing to know the internal storage details:

```php
// Find by Chyro media_id (from a Chyro "media" webhook event)
$media = $client->findMediaByChyroId('CHY-42');

// Find by Chyro program_id (from a Chyro "program" webhook event — available
// even before the video file has been attached)
$media = $client->findMediaByChyroProgramId('PROG-7');

if ($media !== null) {
    echo $media['id'];    // ScreenOver UUID
    echo $media['title'];
} else {
    // No media found for that Chyro ID yet
}
```

Both methods:
- return the first matching media document as an associative array, or `null` if none is found;
- respect the active project scope (call `setProject()` / `setCurrentProject()` first);
- are equivalent to the following curl, which you can also use directly:

```bash
# By Chyro media ID
curl "https://DOMAIN/api/media?where[metadata.chyroMediaId][equals]=CHY-42&limit=1" \
     -H "Authorization: users API-Key YOUR_API_KEY"

# By Chyro program ID
curl "https://DOMAIN/api/media?where[metadata.chyroProgramId][equals]=PROG-7&limit=1" \
     -H "Authorization: users API-Key YOUR_API_KEY"
```

Migrating from Mediative
-------------------------

Coming from the legacy Mediative PHP wrapper? `screenover-api-php` is a **drop-in
replacement**: the public interface is kept identical, so most of your code only
needs new credentials, a project id and the `medias` → `media` rename.

The full migration procedure (step-by-step, quick-reference table, a complete
before/after script and troubleshooting) lives in its own document:

➡️ **[Migrating-from-Mediative.md](Migrating-from-Mediative.md)**

See also [`examples/index.php`](examples/index.php) for a complete CRUD scenario.

Examples
--------

The `examples/` folder contains two ready-to-run scripts:

### `examples/index.php` — live CRUD demo (network)

A complete, runnable scenario mirroring the legacy Mediative `index.php`. Fill in the
config constants at the top (`PUB`, `API_KEY`, `PROJECT`; `DOMAIN` is optional and only
needed for local dev), then run it. It performs, against the real backend:

1. `auth()` + `setProject()`
2. create a media (`post`)
3. read it back (`get`)
4. update it (`put`)
5. list medias with Mediative-style options (`where` / `order` / `limit`)
6. delete it (`delete`)
7. confirm the deletion (expects a `NotFoundException`)

```bash
php examples/index.php
```

It also shows (commented out) how to upload a real file with `uploadMedia()`.

### `examples/selftest.php` — offline self-test (no network)

A fast sanity check that validates the SDK's internal logic **without contacting the
backend**. It asserts that:

- the option translator (`OptionParser`) converts Mediative options to PayloadCMS query
  strings correctly — `where` (`%%`→`like`, `<`→`less_than`, `;`→`and`), `order`
  (`created:DESC`→`sort=-createdAt`), `fields` (`Media.id`→`select[id]=true`),
  `recursive`→`depth`, `limit` (`offset,count`→`limit`+`page`);
- API-key auth installs the token without a network call and reports the right auth mode;
- a malformed domain (a full URL) is rejected with an `AuthException`.

Run it after any change to the SDK; it exits `0` when everything passes:

```bash
php examples/selftest.php
# => ALL TESTS PASSED
```


Error handling
--------------

All failures throw exceptions:

- `Screenover\Api\Exception\AuthException` — 401/403 / missing credentials
- `Screenover\Api\Exception\NotFoundException` — 404
- `Screenover\Api\Exception\ValidationException` — 400/422
- `Screenover\Api\Exception\UnsupportedFilterException` — unsupported legacy `where` condition
- `Screenover\Api\Exception\ApiException` — base class / transport errors

```php
try {
    $client->post('media', $datas);
} catch (\Screenover\Api\Exception\ApiException $e) {
    echo $e->getMessage() . ' (HTTP ' . $e->getCode() . ')';
    var_dump($e->getResponseData());
}
```


Support
-------

Built and maintained by MorinTech. For integration help, custom development or new
projects, get in touch at theo@morintech.fr.
