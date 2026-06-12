<?php

declare(strict_types=1);

/**
 * Unit tests for the configuration / credentials surface that is kept identical
 * to the legacy Mediative SDK: public, secret, domain, token, secure, auth mode.
 */

use Screenover\Api\ScreenoverApi;
use Screenover\Api\Exception\AuthException;

section('Config & credentials (Mediative-compatible surface)');

test('constructor keeps Mediative argument order ($public, $secret, $domain)', function () {
    $api = new ScreenoverApi('pub', 'secret', 'demo.screenover.tv');
    assertSame('pub', $api->getPublic());
    assertSame('secret', $api->getSecret());
    assertSame('demo.screenover.tv', $api->getDomain());
});

test('domain defaults to screenover.com when omitted', function () {
    $api = new ScreenoverApi('pub', 'secret');
    assertSame(ScreenoverApi::DEFAULT_DOMAIN, $api->getDomain());
    assertSame('screenover.com', $api->getDomain());
});

test('setPublic rejects empty value with Mediative message', function () {
    $e = assertThrows(AuthException::class, fn () => new ScreenoverApi('', 's', 'd.screenover.tv'));
    assertSame('Please provide your public auth token.', $e->getMessage());
});

test('setSecret rejects empty value with Mediative message', function () {
    $e = assertThrows(AuthException::class, fn () => new ScreenoverApi('p', '', 'd.screenover.tv'));
    assertSame('Please provide your secret auth token.', $e->getMessage());
});

test('setDomain rejects empty value', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    $e = assertThrows(AuthException::class, fn () => $api->setDomain(''));
    assertSame('Please provide the domain on which you would work.', $e->getMessage());
});

test('setDomain rejects a full URL (protocol/path)', function () {
    $e = assertThrows(AuthException::class, fn () => new ScreenoverApi('p', 's', 'https://x/y'));
    assertSame('Please provide the domain without path and protocol.', $e->getMessage());
});

test('setDomain accepts a plain domain', function () {
    $api = new ScreenoverApi('p', 's', 'foo.screenover.com');
    assertSame('foo.screenover.com', $api->getDomain());
});

test('setDomain accepts localhost:port for local dev', function () {
    $api = new ScreenoverApi('p', 's', 'localhost:3000');
    assertSame('localhost:3000', $api->getDomain());
});

test('setters are chainable (return $this)', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    assertTrue($api->setPublic('p2') === $api);
    assertTrue($api->setSecret('s2') === $api);
    assertTrue($api->setDomain('e.screenover.tv') === $api);
});

test('setToken rejects empty token with Mediative message', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    $e = assertThrows(AuthException::class, fn () => $api->setToken(''));
    assertSame('Please provide the token given by the API.', $e->getMessage());
});

test('getToken before auth throws Mediative message', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    $e = assertThrows(AuthException::class, fn () => $api->getToken());
    assertSame('You should set your auth token before making a request.', $e->getMessage());
});

test('setToken / getToken round-trip', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    $api->setToken('abc');
    assertSame('abc', $api->getToken());
});

test('default auth mode is API key', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    assertSame(ScreenoverApi::AUTH_API_KEY, $api->getAuthMode());
});

test('setAuthMode accepts login and apikey', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    $api->setAuthMode(ScreenoverApi::AUTH_LOGIN);
    assertSame(ScreenoverApi::AUTH_LOGIN, $api->getAuthMode());
    $api->setAuthMode(ScreenoverApi::AUTH_API_KEY);
    assertSame(ScreenoverApi::AUTH_API_KEY, $api->getAuthMode());
});

test('setAuthMode rejects an unknown mode', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    assertThrows(AuthException::class, fn () => $api->setAuthMode('nope'));
});

test('enableSecure / disableSecure are chainable', function () {
    $api = new ScreenoverApi('p', 's', 'd.screenover.tv');
    assertTrue($api->disableSecure() === $api);
    assertTrue($api->enableSecure() === $api);
});
