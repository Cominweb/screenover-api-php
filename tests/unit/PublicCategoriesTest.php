<?php

declare(strict_types=1);

use Screenover\Api\Exception\ApiException;
use Screenover\Api\ScreenoverApi;

section('Public categories helper');

test('getPublicCategories() enforces visibility=public', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->getPublicCategories();

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('/api/category', $url);
    assertStringContains('where[visibility][equals]=public', $url);
});

test('getPublicCategories() keeps project scoping when active project is set', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $api->setProject('proj-1');
    $fake->enqueue(['docs' => []]);

    $api->getPublicCategories();

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('visibility][equals]=public', $url);
    assertStringContains('project][equals]=proj-1', $url);
});

test('getPublicCategories() keeps standard list options', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->getPublicCategories([
        'order' => 'created:DESC',
        'limit' => '0,25',
    ]);

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('sort=-createdAt', $url);
    assertStringContains('limit=25', $url);
    assertStringContains('page=1', $url);
    assertStringContains('visibility][equals]=public', $url);
});

test('getPublicCategories() merges legacy string where with visibility filter', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->getPublicCategories(['where' => 'title%%news']);

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][title][like]=news', $url);
    assertStringContains('where[and][1][visibility][equals]=public', $url);
});

test('getPublicCategories() merges native where array with visibility filter', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => []]);

    $api->getPublicCategories(['where' => ['title' => ['like' => 'news']]]);

    $url = urldecode($fake->lastCall()['url']);
    assertStringContains('where[and][0][title][like]=news', $url);
    assertStringContains('where[and][1][visibility][equals]=public', $url);
});

test('getPublicCategories() returns the same list shape as get()', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    $fake = FakeClient::into($api);
    $fake->enqueue(['docs' => [['id' => 'cat-1', 'title' => 'News']]]);

    $categories = $api->getPublicCategories();

    assertSame([['id' => 'cat-1', 'title' => 'News']], $categories);
});

test('getPublicCategories() throws on invalid where option type', function () {
    $api = new ScreenoverApi('id', 'KEY', 'demo.screenover.tv');
    FakeClient::into($api);

    assertThrows(ApiException::class, function () use ($api): void {
        $api->getPublicCategories(['where' => 42]);
    });
});

