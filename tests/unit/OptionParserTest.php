<?php

declare(strict_types=1);

/**
 * Unit tests for the OptionParser: the Mediative -> PayloadCMS query translation.
 * These guarantee the legacy where/order/fields/recursive/limit syntax is preserved.
 */

use Screenover\Api\Query\OptionParser;

section('OptionParser (Mediative query options translation)');

$p = new OptionParser();

test('where: %% -> like, < -> less_than, ";" -> and', function () use ($p) {
    assertSame(
        'where[and][0][title][like]=test&where[and][1][createdAt][less_than]=2014-11-12',
        urldecode($p->build(['where' => 'title%%test;created<2014-11-12']))
    );
});

test('where: each comparison operator maps correctly', function () use ($p) {
    assertSame('where[and][0][a][not_equals]=1', urldecode($p->build(['where' => 'a!=1'])));
    assertSame('where[and][0][a][greater_than_equal]=1', urldecode($p->build(['where' => 'a>=1'])));
    assertSame('where[and][0][a][less_than_equal]=1', urldecode($p->build(['where' => 'a<=1'])));
    assertSame('where[and][0][a][greater_than]=1', urldecode($p->build(['where' => 'a>1'])));
    assertSame('where[and][0][a][equals]=1', urldecode($p->build(['where' => 'a=1'])));
});

test('where: native PayloadCMS array is forwarded untouched', function () use ($p) {
    $built = urldecode($p->build(['where' => ['title' => ['equals' => 'x']]]));
    assertSame('where[title][equals]=x', $built);
});

test('order: created:DESC -> sort=-createdAt, asc default', function () use ($p) {
    assertSame('sort=-createdAt,title', urldecode($p->build(['order' => 'created:DESC,title'])));
});

test('order: random -> sort=random', function () use ($p) {
    assertSame('sort=random', urldecode($p->build(['order' => 'random'])));
});

test('order: random mixed with regular clauses preserves all items', function () use ($p) {
    assertSame('sort=random,-createdAt', urldecode($p->build(['order' => 'random,created:DESC'])));
});

test('order: native sort passthrough wins', function () use ($p) {
    assertSame('sort=-title', urldecode($p->build(['sort' => '-title'])));
});

test('fields: Media.id,Media.title -> select[id]=true&select[title]=true', function () use ($p) {
    assertSame(
        'select[id]=true&select[title]=true',
        urldecode($p->build(['fields' => 'Media.id,Media.title']))
    );
});

test('recursive: -1 -> depth=0 (relations disabled)', function () use ($p) {
    assertSame('depth=0', $p->build(['recursive' => -1]));
});

test('recursive: 2 -> depth=2', function () use ($p) {
    assertSame('depth=2', $p->build(['recursive' => 2]));
});

test('limit: "offset,count" -> limit + page', function () use ($p) {
    assertSame('limit=25&page=3', urldecode($p->build(['limit' => '50,25'])));
});

test('limit: single value -> limit only', function () use ($p) {
    assertSame('limit=10', $p->build(['limit' => '10']));
});

test('passthrough: locale is forwarded', function () use ($p) {
    assertSame('locale=fr', urldecode($p->build(['locale' => 'fr'])));
});

test('empty options -> empty query string', function () use ($p) {
    assertSame('', $p->build([]));
});

test('combined options build a single query string', function () use ($p) {
    $built = urldecode($p->build([
        'where' => 'title%%updated',
        'order' => 'created:DESC',
        'limit' => '0,25',
        'locale' => 'en',
    ]));
    assertStringContains('where[and][0][title][like]=updated', $built);
    assertStringContains('sort=-createdAt', $built);
    assertStringContains('limit=25', $built);
    assertStringContains('page=1', $built);
    assertStringContains('locale=en', $built);
});
