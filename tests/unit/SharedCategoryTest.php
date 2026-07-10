<?php

declare(strict_types=1);

/**
 * Unit tests for the shared-category helpers:
 *   - resolveEffectiveCategoryId() — returns sourceCategory id for mirror categories
 *   - getMediaByCategory()         — filters media using the effective (resolved) id
 *
 * Both methods delegate to get() so URL building, project scoping and response
 * normalisation are already covered by CrudLogicTest. These tests focus on:
 *   - a normal category returning its own id unchanged
 *   - a mirror category (sourceCategory as string) resolving to the source id
 *   - a mirror category (sourceCategory as array) resolving to the source id
 *   - getMediaByCategory() querying media with the effective id
 *   - getMediaByCategory() resolving mirror ids before filtering
 */

use Screenover\Api\ScreenoverApi;

section('Shared category helpers');

// ─── resolveEffectiveCategoryId ──────────────────────────────────────────────

test('resolveEffectiveCategoryId returns its own id for a normal category', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'cat-1']]);

    $result = $api->resolveEffectiveCategoryId('cat-1');

    assertSame('cat-1', $result);
    $call = $fake->lastCall();
    assertSame('GET', $call['method']);
    assertStringContains('/category/cat-1', $call['url']);
});

test('resolveEffectiveCategoryId follows sourceCategory when it is a string id', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'mirror-1', 'sourceCategory' => 'source-1']]);

    $result = $api->resolveEffectiveCategoryId('mirror-1');

    assertSame('source-1', $result);
});

test('resolveEffectiveCategoryId follows sourceCategory when it is an array (populated)', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'mirror-2', 'sourceCategory' => ['id' => 'source-2', 'title' => 'Original']]]);

    $result = $api->resolveEffectiveCategoryId('mirror-2');

    assertSame('source-2', $result);
});

// ─── getMediaByCategory ───────────────────────────────────────────────────────

test('getMediaByCategory queries media using the category id when the category is normal', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    // first call: resolve the category
    $fake->enqueue(['doc' => ['id' => 'cat-3']]);
    // second call: list media
    $fake->enqueue(['docs' => [['id' => 'media-1'], ['id' => 'media-2']]]);

    $results = $api->getMediaByCategory('cat-3');

    assertSame([['id' => 'media-1'], ['id' => 'media-2']], $results);
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[categories.category][equals]=cat-3', $url);
});

test('getMediaByCategory resolves mirror id and queries media against the source', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'mirror-3', 'sourceCategory' => 'source-3']]);
    $fake->enqueue(['docs' => [['id' => 'media-7']]]);

    $results = $api->getMediaByCategory('mirror-3');

    assertSame([['id' => 'media-7']], $results);
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[categories.category][equals]=source-3', $url);
});

test('getMediaByCategory returns an empty list when the source category has no media', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'cat-empty']]);
    $fake->enqueue(['docs' => []]);

    $results = $api->getMediaByCategory('cat-empty');

    assertSame([], $results);
});

test('get(media) with categories.category filter auto-resolves a mirror id', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'mirror-9', 'sourceCategory' => 'source-9']]);
    $fake->enqueue(['docs' => [['id' => 'media-5']]]);

    $results = $api->get('media', ['where' => ['categories.category' => ['equals' => 'mirror-9']]]);

    assertSame([['id' => 'media-5']], $results);
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[categories.category][equals]=source-9', $url);
});

test('get(media) with categories.category filter leaves a normal id unchanged', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['doc' => ['id' => 'cat-10']]);
    $fake->enqueue(['docs' => [['id' => 'media-6']]]);

    $results = $api->get('media', ['where' => ['categories.category' => ['equals' => 'cat-10']]]);

    assertSame([['id' => 'media-6']], $results);
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[categories.category][equals]=cat-10', $url);
});

test('get(media) without a categories.category filter does not make an extra resolution call', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'media-7']]]);

    $api->get('media', ['where' => ['title' => ['equals' => 'Intro']]]);

    assertSame(1, count($fake->calls), 'Only one HTTP call expected');
});
