<?php

declare(strict_types=1);

/**
 * Unit tests for the OptionParser: the Mediative -> PayloadCMS query translation.
 * These guarantee the legacy where/order/fields/recursive/limit syntax is preserved.
 */

use Screenover\Api\Query\OptionParser;
use Screenover\Api\Exception\UnsupportedFilterException;

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

test('where: id:xxx -> filter by id (equals)', function () use ($p) {
    assertSame(
        'where[and][0][id][equals]=abc-123',
        urldecode($p->build(['where' => 'id:abc-123']))
    );
});

test('where: category:xxx -> filter the category relation', function () use ($p) {
    assertSame(
        'where[and][0][categories.category][equals]=cat-1',
        urldecode($p->build(['where' => 'category:cat-1']))
    );
});

test('where: Category.id:xxx -> filter the category relation', function () use ($p) {
    assertSame(
        'where[and][0][categories.category][equals]=cat-1',
        urldecode($p->build(['where' => 'Category.id:cat-1']))
    );
});

test('where: combined id + category filters (";" -> and)', function () use ($p) {
    assertSame(
        'where[and][0][id][equals]=m1&where[and][1][categories.category][equals]=c1',
        urldecode($p->build(['where' => 'id:m1;category:c1']))
    );
});

test('where: regression - Media.id:xxx -> id (Model. prefix stripped)', function () use ($p) {
    assertSame(
        'where[and][0][id][equals]=42',
        urldecode($p->build(['where' => 'Media.id:42']))
    );
});

test('where: an unrecognised filter throws UnsupportedFilterException', function () use ($p) {
    assertThrows(UnsupportedFilterException::class, function () use ($p) {
        $p->build(['where' => 'foobar']);
    });
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
