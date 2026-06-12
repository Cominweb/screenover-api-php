<?php

declare(strict_types=1);

/**
 * Unit tests for the CRUD + auth + scoping logic, exercised offline through a
 * FakeClient injected into the SDK. They verify URLs, HTTP verbs, request bodies,
 * id auto-mapping, project injection/scoping and response normalisation —
 * i.e. that the Mediative-compatible behaviour is reproduced transparently.
 */

use Screenover\Api\ScreenoverApi;
use Screenover\Api\Exception\ApiException;
use Screenover\Api\Exception\AuthException;
use Screenover\Api\Exception\NotFoundException;

section('Authentication');

test('auth() in API-key mode installs the header without a network call', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->auth();
    assertSame('KEY', $api->getToken());
    assertSame('users API-Key KEY', $fake->headers()['Authorization'] ?? null);
    assertSame([], $fake->calls, 'API-key auth must not hit the network');
});

test('auth() in login mode POSTs /users/login and stores the JWT', function () {
    $api = new ScreenoverApi('me@example.com', 'pw', 'demo.screenover.tv');
    $api->setAuthMode(ScreenoverApi::AUTH_LOGIN);
    $fake = FakeClient::into($api);
    $fake->enqueue(['token' => 'jwt-123', 'user' => ['id' => 'u1']]);

    $api->auth();

    assertSame('jwt-123', $api->getToken());
    assertSame('JWT jwt-123', $fake->headers()['Authorization'] ?? null);
    $call = $fake->lastCall();
    assertSame('POST', $call['method']);
    assertStringContains('/api/users/login', $call['url']);
    assertSame(['email' => 'me@example.com', 'password' => 'pw'], $call['body']);
});

test('auth() in login mode throws on a missing token (Invalid developer login)', function () {
    $api = new ScreenoverApi('me@example.com', 'pw', 'demo.screenover.tv');
    $api->setAuthMode(ScreenoverApi::AUTH_LOGIN);
    $fake = FakeClient::into($api);
    $fake->enqueue(['message' => 'nope']);
    $e = assertThrows(AuthException::class, fn () => $api->auth());
    assertSame('Invalid developer login', $e->getMessage());
});

test('logout() clears the token and Authorization header (login mode)', function () {
    $api = new ScreenoverApi('me@example.com', 'pw', 'demo.screenover.tv');
    $api->setAuthMode(ScreenoverApi::AUTH_LOGIN);
    $fake = FakeClient::into($api);
    $fake->enqueue(['token' => 'jwt-1']);
    $api->auth();
    $fake->enqueue([]); // logout response
    $api->logout();
    assertFalse(isset($fake->headers()['Authorization']));
    assertThrows(AuthException::class, fn () => $api->getToken());
});

section('Project scoping (replaces Mediative domain)');

test('setProject sets the x-project-id header and getProject returns it', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('pid-1');
    assertSame('pid-1', $api->getProject());
    assertSame('pid-1', $fake->headers()['x-project-id'] ?? null);
});

test('setCurrentProject is an alias of setProject', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);
    $api->setCurrentProject('pid-2');
    assertSame('pid-2', $api->getProject());
});

test('getProjects() GETs the projects collection', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'p1', 'title' => 'One']]]);
    $projects = $api->getProjects();
    assertSame([['id' => 'p1', 'title' => 'One']], $projects);
    assertStringContains('/api/projects', $fake->lastCall()['url']);
});

test('selectProject() auto-selects the only project', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'only', 'title' => 'Solo']]]);
    $selected = $api->selectProject();
    assertSame('only', $selected['id']);
    assertSame('only', $api->getProject());
});

test('selectProject($title) selects by case-insensitive title', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [
        ['id' => 'a', 'title' => 'Alpha'],
        ['id' => 'b', 'title' => 'Beta'],
    ]]);
    $selected = $api->selectProject('beta');
    assertSame('b', $selected['id']);
    assertSame('b', $api->getProject());
});

test('selectProject() throws when several projects and none chosen', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']]]);
    assertThrows(ApiException::class, fn () => $api->selectProject());
});

test('selectProject() throws NotFound for an unknown title', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'a', 'title' => 'A']]]);
    assertThrows(NotFoundException::class, fn () => $api->selectProject('ghost'));
});

test('selectProject() throws when no project is accessible', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);
    assertThrows(AuthException::class, fn () => $api->selectProject());
});

section('GET');

test('get(resource) lists documents from {docs}', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => '1'], ['id' => '2']], 'totalDocs' => 2]);
    $list = $api->get('media');
    assertSame([['id' => '1'], ['id' => '2']], $list);
    $call = $fake->lastCall();
    assertSame('GET', $call['method']);
    assertSame('https://demo.screenover.tv/api/media', $call['url']);
});

test('get(resource, $uuid) appends the id to the path', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $uuid = '11111111-2222-3333-4444-555555555555';
    $fake->enqueue(['doc' => ['id' => $uuid, 'title' => 'x']]);
    $doc = $api->get('media', $uuid);
    assertSame($uuid, $doc['id']);
    assertSame('https://demo.screenover.tv/api/media/' . $uuid, $fake->lastCall()['url']);
});

test('get(resource, ["id"=>...]) appends the id and strips it from options', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => '42']]);
    $api->get('media', ['id' => '42']);
    assertSame('https://demo.screenover.tv/api/media/42', $fake->lastCall()['url']);
});

test('get("media/ID") inline id is preserved', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => '7']]);
    $api->get('media/7');
    assertSame('https://demo.screenover.tv/api/media/7', $fake->lastCall()['url']);
});

test('get with Mediative options builds a translated query string', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);
    $api->get('media', ['where' => 'title%%test', 'order' => 'created:DESC', 'limit' => '0,25']);
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][title][like]=test', $url);
    assertStringContains('sort=-createdAt', $url);
    assertStringContains('limit=25', $url);
});

test('get with $shortCut=false returns the raw envelope', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => '1']], 'totalDocs' => 1]);
    $raw = $api->get('media', [], true, false);
    assertArrayHasKey('totalDocs', $raw);
});

section('GET project scoping (multi-tenant)');

test('get(scoped) injects the active-project filter into the query', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $fake->enqueue(['docs' => [['id' => '1']]]);

    $api->get('media');

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][project][equals]=A', $url);
});

test('get(scoped, $id) by id is NOT filtered by project', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $uuid = '11111111-2222-3333-4444-555555555555';
    $fake->enqueue(['doc' => ['id' => $uuid]]);

    $api->get('media', $uuid);

    $url = $fake->lastCall()['url'];
    assertSame('https://demo.screenover.tv/api/media/' . $uuid, $url);
    assertFalse(strpos($url, 'project') !== false, 'no project filter on a by-id read');
});

test('get("media/ID") inline id is NOT filtered by project', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $fake->enqueue(['doc' => ['id' => '7']]);

    $api->get('media/7');

    assertFalse(strpos($fake->lastCall()['url'], 'project') !== false);
});

test('get(non-scoped) is NOT filtered by project', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $fake->enqueue(['docs' => []]);

    $api->get('projects');

    assertFalse(strpos($fake->lastCall()['url'], '%5Bproject%5D') !== false, 'projects must not be scoped');
});

test('get(scoped) without an active project is NOT filtered', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->get('media');

    assertFalse(strpos($fake->lastCall()['url'], 'project') !== false);
});

test('get(scoped, $allProjects=true) opts out of the project filter', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $fake->enqueue(['docs' => []]);

    $api->get('media', [], true, true, true); // $allProjects = true

    assertFalse(strpos($fake->lastCall()['url'], 'project') !== false);
});

test('get(scoped) keeps a user-supplied where AND adds the project filter', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('A');
    $fake->enqueue(['docs' => []]);

    $api->get('media', ['where' => 'title%%test']);

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][title][like]=test', $url);
    assertStringContains('where[and][1][project][equals]=A', $url);
});

test('two projects do not leak: get(media) after setProject(B) targets B only', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);

    $api->setProject('A');
    $fake->enqueue(['docs' => [['id' => 'a1']]]);
    $api->get('media');
    assertStringContains('project%5D%5Bequals%5D=A', $fake->lastCall()['url']);

    $api->setProject('B');
    $fake->enqueue(['docs' => [['id' => 'b1']]]);
    $api->get('media');
    assertStringContains('project%5D%5Bequals%5D=B', $fake->lastCall()['url']);
});

section('POST');

test('post(resource, datas) creates and returns the document', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'new-1', 'title' => 'Test']]);
    $media = $api->post('media', ['title' => 'Test']);
    assertSame('new-1', $media['id']);
    $call = $fake->lastCall();
    assertSame('POST', $call['method']);
    assertSame('https://demo.screenover.tv/api/media', $call['url']);
});

test('post injects the active project on a scoped collection', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('pid-9');
    $fake->enqueue(['doc' => ['id' => 'm']]);
    $api->post('media', ['title' => 'Test']);
    assertSame('pid-9', $fake->lastCall()['body']['project'] ?? null);
});

test('post does NOT inject project on a non-scoped collection', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('pid-9');
    $fake->enqueue(['doc' => ['id' => 'pr']]);
    $api->post('projects', ['title' => 'P']);
    assertFalse(isset($fake->lastCall()['body']['project']));
});

test('post keeps an explicit project field over the active one', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('pid-9');
    $fake->enqueue(['doc' => ['id' => 'm']]);
    $api->post('media', ['title' => 'Test', 'project' => 'explicit']);
    assertSame('explicit', $fake->lastCall()['body']['project']);
});

section('PUT (update -> PATCH)');

test('put(resource, ["id"=>...]) sends a PATCH to /resource/id without id in body', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'x', 'title' => 'Updated']]);
    $res = $api->put('media', ['id' => 'x', 'title' => 'Updated']);
    $call = $fake->lastCall();
    assertSame('PATCH', $call['method']);
    assertSame('https://demo.screenover.tv/api/media/x', $call['url']);
    assertFalse(isset($call['body']['id']), 'id must be moved to the path');
    assertSame('Updated', $call['body']['title']);
    assertSame('Updated', $res['title']);
});

test('put("media/ID", datas) uses the inline id', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'y']]);
    $api->put('media/y', ['title' => 'Z']);
    assertSame('https://demo.screenover.tv/api/media/y', $fake->lastCall()['url']);
});

test('put without id throws "Please provide an ID to update" (Mediative message)', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);
    $e = assertThrows(ApiException::class, fn () => $api->put('media', ['title' => 'x']));
    assertSame('Please provide an ID to update', $e->getMessage());
});

test('put with $check=false allows an update without id', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['ok' => true]]);
    $api->put('media', ['title' => 'x'], [], false);
    assertSame('PATCH', $fake->lastCall()['method']);
});

section('DELETE');

test('delete(resource, $id) sends DELETE to /resource/id', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'gone']]);
    $api->delete('media', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
    $call = $fake->lastCall();
    assertSame('DELETE', $call['method']);
    assertStringContains('/api/media/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $call['url']);
});

test('delete(resource, ["id"=>...]) works too', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue([]);
    $api->delete('media', ['id' => '5']);
    assertStringContains('/api/media/5', $fake->lastCall()['url']);
});

section('call(), reset(), close()');

test('call($method, $path, $body) hits an arbitrary endpoint', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['used' => 1024]);
    $res = $api->call('GET', 'storage');
    assertSame(['used' => 1024], $res);
    assertSame('https://demo.screenover.tv/api/storage', $fake->lastCall()['url']);
});

test('close() is a no-op returning $this (Mediative compatibility)', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);
    assertTrue($api->close() === $api);
});

test('reset() reinstalls auth + project headers on a fresh client', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);
    $api->auth();
    $api->setProject('pid-r');
    $api->reset();
    $http = $api->getHttpClient();
    // a real Http\Client is created by reset(); assert it carries the headers via a probe
    $ref = new \ReflectionProperty(get_class($http), 'headers');
    $ref->setAccessible(true);
    $headers = $ref->getValue($http);
    assertSame('users API-Key KEY', $headers['Authorization'] ?? null);
    assertSame('pid-r', $headers['x-project-id'] ?? null);
});

section('Local file upload flow (uploadMedia)');

test('uploadMedia runs the 4-step GCS flow and returns the finalised media', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('pid-u');

    // temp file to upload
    $tmp = tempnam(sys_get_temp_dir(), 'sov');
    file_put_contents($tmp, 'binary-content');

    // 1) get-upload-url, 3) post media, 4) set-upload-filename
    $fake->enqueue(['url' => 'https://gcs/signed', 'filename' => 'obj-123.bin', 'mimeType' => 'image/png']);
    $fake->enqueue(['doc' => ['id' => 'media-77', 'title' => 'My file']]);
    $fake->enqueue(['media' => ['id' => 'media-77', 'title' => 'My file', 'mimeType' => 'image/png', 'filesize' => 14]]);

    $media = $api->uploadMedia($tmp, ['title' => 'My file']);
    unlink($tmp);

    assertSame('media-77', $media['id']);
    assertSame('image/png', $media['mimeType']);

    // step 2 uploaded the binary to the signed URL
    assertSame(1, count($fake->fileUploads));
    assertSame('https://gcs/signed', $fake->fileUploads[0]['url']);
    assertSame('image/png', $fake->fileUploads[0]['contentType']);

    // verify the endpoints called
    assertStringContains('/api/media/get-upload-url', $fake->calls[0]['url']);
    assertStringContains('/api/media', $fake->calls[1]['url']);
    assertStringContains('/api/media/set-upload-filename', $fake->calls[2]['url']);
    // project injected on creation
    assertSame('pid-u', $fake->calls[1]['body']['project'] ?? null);
    assertSame('upload', $fake->calls[1]['body']['source']['type'] ?? null);
});

test('uploadMedia throws when the file does not exist', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);
    assertThrows(ApiException::class, fn () => $api->uploadMedia('/no/such/file.jpg'));
});
