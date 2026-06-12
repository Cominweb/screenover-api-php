<?php

declare(strict_types=1);

/**
 * Live integration tests against the real ScreenOver backend.
 *
 * These run only when credentials are provided (SCREENOVER_API_KEY). They make
 * real network calls and exercise the full public surface end-to-end:
 *   auth -> project selection -> create -> read -> list (options) -> update ->
 *   delete -> 404 confirmation -> custom call() -> optional file upload.
 *
 * Provide credentials via environment variables or tests/.env:
 *   SCREENOVER_API_KEY=...        (required)
 *   SCREENOVER_IDENTIFIER=sdk     (optional)
 *   SCREENOVER_DOMAIN=...         (optional, defaults to screenover.com)
 *   SCREENOVER_PROJECT=<uuid>     (optional, auto-selected otherwise)
 *   SCREENOVER_AUTH_MODE=apikey   (optional: apikey|login)
 *   SCREENOVER_UPLOAD_FILE=/path  (optional: enables the uploadMedia test)
 *   SCREENOVER_INSECURE=1         (optional: disable SSL verify for local dev)
 */

use Screenover\Api\ScreenoverApi;
use Screenover\Api\Exception\NotFoundException;

section('LIVE integration (real backend)');

$apiKey = Credentials::get('SCREENOVER_API_KEY');
if ($apiKey === null) {
    skip('live integration suite', 'set SCREENOVER_API_KEY (or tests/.env) to enable');
    return;
}

$identifier = Credentials::get('SCREENOVER_IDENTIFIER', 'sdk-tests');
$domain = Credentials::get('SCREENOVER_DOMAIN', ScreenoverApi::DEFAULT_DOMAIN);
$authMode = Credentials::get('SCREENOVER_AUTH_MODE', ScreenoverApi::AUTH_API_KEY);

$client = new ScreenoverApi($identifier, $apiKey, $domain);
$client->setAuthMode($authMode);
if (Credentials::get('SCREENOVER_INSECURE') === '1') {
    $client->disableSecure();
}

// Shared state across the ordered live tests.
$state = ['id' => null];

test('auth() succeeds and yields a usable token', function () use ($client) {
    $client->auth();
    assertNotEmpty($client->getToken());
});

test('getProjects() returns the accessible projects', function () use ($client, &$state) {
    $projects = $client->getProjects();
    assertTrue(is_array($projects), 'projects must be an array');
    $state['projects'] = $projects;
});

test('select a project (explicit id or auto-select)', function () use ($client, &$state) {
    $explicit = Credentials::get('SCREENOVER_PROJECT');
    if ($explicit !== null && $explicit !== '') {
        $client->setCurrentProject($explicit);
    } else {
        $client->selectProject();
    }
    assertNotEmpty($client->getProject());
});

test('create a media (POST, youtube source)', function () use ($client, &$state) {
    $media = $client->post('media', [
        'title' => 'sdk-test ' . date('Y-m-d H:i:s'),
        'source' => ['type' => 'youtube', 'url' => 'https://youtu.be/dQw4w9WgXcQ'],
    ]);
    assertArrayHasKey('id', $media);
    $state['id'] = $media['id'];
});

test('read it back by id (GET)', function () use ($client, &$state) {
    if ($state['id'] === null) {
        throw new AssertionFailed('no media id from the create step');
    }
    $doc = $client->get('media', $state['id']);
    assertSame($state['id'], $doc['id']);
});

test('list medias with Mediative-style options (where/order/limit)', function () use ($client) {
    $list = $client->get('media', [
        'where' => 'title%%sdk-test',
        'order' => 'created:DESC',
        'limit' => '0,25',
    ]);
    assertTrue(is_array($list), 'list must be an array');
});

test('update it (PUT -> PATCH)', function () use ($client, &$state) {
    if ($state['id'] === null) {
        throw new AssertionFailed('no media id from the create step');
    }
    $updated = $client->put('media', ['id' => $state['id'], 'title' => 'sdk-test updated']);
    assertSame('sdk-test updated', $updated['title']);
});

test('confirm the update is persisted', function () use ($client, &$state) {
    $doc = $client->get('media', $state['id']);
    assertSame('sdk-test updated', $doc['title']);
});

test('delete it (DELETE)', function () use ($client, &$state) {
    if ($state['id'] === null) {
        throw new AssertionFailed('no media id from the create step');
    }
    $client->delete('media', $state['id']);
    assertTrue(true);
});

test('reading a deleted media raises NotFoundException', function () use ($client, &$state) {
    assertThrows(NotFoundException::class, fn () => $client->get('media', $state['id']));
});

test('custom call(): GET storage usage for the project', function () use ($client) {
    try {
        $res = $client->call('GET', 'storage');
        assertTrue(is_array($res));
    } catch (NotFoundException $e) {
        // endpoint may not be exposed on every deployment; tolerate a 404.
        assertTrue(true);
    }
});

$uploadFile = Credentials::get('SCREENOVER_UPLOAD_FILE');
if ($uploadFile !== null && is_file($uploadFile)) {
    test('uploadMedia() runs the full GCS upload flow', function () use ($client, $uploadFile) {
        $media = $client->uploadMedia($uploadFile, ['title' => 'sdk-test upload']);
        assertArrayHasKey('id', $media);
        // best-effort cleanup
        try {
            $client->delete('media', $media['id']);
        } catch (\Throwable $e) {
            // ignore cleanup failures
        }
    });
} else {
    skip('uploadMedia() live test', 'set SCREENOVER_UPLOAD_FILE to a local file to enable');
}
