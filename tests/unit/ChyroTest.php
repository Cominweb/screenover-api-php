<?php

declare(strict_types=1);

/**
 * Unit tests for the Chyro integration helpers:
 *   - findMediaByChyroId()     — lookup by metadata.chyroMediaId
 *   - findMediaByChyroProgramId() — lookup by metadata.chyroProgramId
 *
 * Both methods delegate to get() so URL building, project scoping and response
 * normalisation are already covered by CrudLogicTest. These tests focus on:
 *   - the correct where filter being sent for each method
 *   - limit=1 being enforced
 *   - the first doc being returned when a match exists
 *   - null being returned when the collection is empty
 */

use Screenover\Api\ScreenoverApi;

section('Chyro integration helpers');

// ─── findMediaByChyroId ───────────────────────────────────────────────────────

test('findMediaByChyroId sends the correct where filter and limit', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->findMediaByChyroId('CHY-42');

    $url = urldecode($fake->lastCall()['url']);
    assertSame('GET', $fake->lastCall()['method']);
    assertStringContains('where[metadata.chyroMediaId][equals]=CHY-42', $url);
    assertStringContains('limit=1', $url);
});

test('findMediaByChyroId returns the matching document when found', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'media-1', 'title' => 'News intro']]]);

    $result = $api->findMediaByChyroId('CHY-42');

    assertSame(['id' => 'media-1', 'title' => 'News intro'], $result);
});

test('findMediaByChyroId returns null when no document matches', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $result = $api->findMediaByChyroId('UNKNOWN');

    assertSame(null, $result);
});

test('findMediaByChyroId includes the active-project filter when a project is set', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('proj-99');
    $fake->enqueue(['docs' => []]);

    $api->findMediaByChyroId('CHY-42');

    // When the project filter is injected, both conditions are wrapped in where[and][N].
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][metadata.chyroMediaId][equals]=CHY-42', $url);
    assertStringContains('project][equals]=proj-99', $url);
});

// ─── findMediaByChyroProgramId ────────────────────────────────────────────────

test('findMediaByChyroProgramId sends the correct where filter and limit', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->findMediaByChyroProgramId('PROG-7');

    $url = urldecode($fake->lastCall()['url']);
    assertSame('GET', $fake->lastCall()['method']);
    assertStringContains('where[metadata.chyroProgramId][equals]=PROG-7', $url);
    assertStringContains('limit=1', $url);
});

test('findMediaByChyroProgramId returns the matching document when found', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'media-2', 'title' => 'Weather report']]]);

    $result = $api->findMediaByChyroProgramId('PROG-7');

    assertSame(['id' => 'media-2', 'title' => 'Weather report'], $result);
});

test('findMediaByChyroProgramId returns null when no document matches', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $result = $api->findMediaByChyroProgramId('UNKNOWN');

    assertSame(null, $result);
});

test('findMediaByChyroProgramId includes the active-project filter when a project is set', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('proj-99');
    $fake->enqueue(['docs' => []]);

    $api->findMediaByChyroProgramId('PROG-7');

    // When the project filter is injected, both conditions are wrapped in where[and][N].
    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][metadata.chyroProgramId][equals]=PROG-7', $url);
    assertStringContains('project][equals]=proj-99', $url);
});
