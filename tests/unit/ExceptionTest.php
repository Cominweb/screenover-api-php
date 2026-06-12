<?php

declare(strict_types=1);

/**
 * Unit tests for the typed exception hierarchy and the HTTP status -> exception
 * mapping. All SDK exceptions extend \Exception so legacy `catch (Exception $e)`
 * code keeps working.
 */

use Screenover\Api\Http\Client;
use Screenover\Api\Exception\ApiException;
use Screenover\Api\Exception\AuthException;
use Screenover\Api\Exception\NotFoundException;
use Screenover\Api\Exception\ValidationException;

section('Exceptions & error mapping');

test('all SDK exceptions extend \Exception (legacy catch compatibility)', function () {
    assertTrue(is_subclass_of(ApiException::class, \Exception::class));
    assertTrue(is_subclass_of(AuthException::class, ApiException::class));
    assertTrue(is_subclass_of(NotFoundException::class, ApiException::class));
    assertTrue(is_subclass_of(ValidationException::class, ApiException::class));
});

test('ApiException carries code and response data', function () {
    $e = new ApiException('boom', 500, ['errors' => [['message' => 'boom']]]);
    assertSame(500, $e->getCode());
    assertSame('boom', $e->getMessage());
    assertSame(['errors' => [['message' => 'boom']]], $e->getResponseData());
});

/**
 * Call the private Client::buildError() via reflection to assert status mapping.
 */
function buildError(int $status, array $data): ApiException
{
    $client = new Client();
    $ref = new \ReflectionMethod(Client::class, 'buildError');
    $ref->setAccessible(true);
    /** @var ApiException $e */
    $e = $ref->invoke($client, $status, $data);
    return $e;
}

test('HTTP 401 -> AuthException', function () {
    assertTrue(buildError(401, ['message' => 'no']) instanceof AuthException);
});

test('HTTP 403 -> AuthException', function () {
    assertTrue(buildError(403, []) instanceof AuthException);
});

test('HTTP 404 -> NotFoundException', function () {
    assertTrue(buildError(404, ['errors' => [['message' => 'missing']]]) instanceof NotFoundException);
});

test('HTTP 400 -> ValidationException', function () {
    assertTrue(buildError(400, []) instanceof ValidationException);
});

test('HTTP 422 -> ValidationException', function () {
    assertTrue(buildError(422, []) instanceof ValidationException);
});

test('HTTP 500 -> base ApiException', function () {
    $e = buildError(500, []);
    assertTrue($e instanceof ApiException);
    assertFalse($e instanceof AuthException);
});

test('error message extracted from errors[0].message', function () {
    assertSame('field required', buildError(400, ['errors' => [['message' => 'field required']]])->getMessage());
});

test('error message falls back to "message" then "error" then HTTP code', function () {
    assertSame('top', buildError(400, ['message' => 'top'])->getMessage());
    assertSame('legacy', buildError(400, ['error' => 'legacy'])->getMessage());
    assertSame('HTTP 418', buildError(418, [])->getMessage());
});
