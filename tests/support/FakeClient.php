<?php

declare(strict_types=1);

use Screenover\Api\Http\Client;
use Screenover\Api\ScreenoverApi;

/**
 * In-memory HTTP client used by the offline unit tests.
 *
 * It extends the real Http\Client and overrides the network methods so the SDK
 * logic (URL building, id extraction, option translation, project injection,
 * response normalisation, the upload flow...) can be asserted without a backend.
 *
 * Every request() is recorded in $calls; the response returned is taken from a
 * programmable queue (enqueue()) or a fallback responder closure.
 */
class FakeClient extends Client
{
    /** @var array<int,array{method:string,url:string,body:array<string,mixed>|null,headers:array<string,string>}> */
    public array $calls = [];

    /** @var array<int,array{url:string,file:string,contentType:string}> */
    public array $fileUploads = [];

    /** @var array<int,array<string,mixed>> */
    private array $queue = [];

    /** @var callable|null */
    private $responder = null;

    /** @var array<string,string> */
    private array $sentHeaders = [];

    /**
     * Replace the (protected) Http\Client of a ScreenoverApi instance with a fresh
     * FakeClient, so the SDK logic can be exercised offline. Returns the fake.
     */
    public static function into(ScreenoverApi $api): self
    {
        $fake = new self();
        $ref = new \ReflectionProperty(ScreenoverApi::class, 'http');
        $ref->setAccessible(true);
        $ref->setValue($api, $fake);
        return $fake;
    }

    /**
     * Queue a response to be returned by the next request().
     *
     * @param array<string,mixed> $response
     */
    public function enqueue(array $response): void
    {
        $this->queue[] = $response;
    }

    /**
     * Set a fallback responder: fn(string $method, string $url, ?array $body): array
     */
    public function setResponder(callable $responder): void
    {
        $this->responder = $responder;
    }

    public function setHeader(string $key, string $value): void
    {
        $this->sentHeaders[$key] = $value;
        parent::setHeader($key, $value);
    }

    public function removeHeader(string $key): void
    {
        unset($this->sentHeaders[$key]);
        parent::removeHeader($key);
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->sentHeaders;
    }

    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1];
    }

    public function request(string $method, string $url, ?array $body = null): array
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'body' => $body,
            'headers' => $this->sentHeaders,
        ];

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        if ($this->responder !== null) {
            return ($this->responder)($method, $url, $body);
        }

        return [];
    }

    public function putFile(string $url, string $filePath, string $contentType): void
    {
        $this->fileUploads[] = [
            'url' => $url,
            'file' => $filePath,
            'contentType' => $contentType,
        ];
    }
}
