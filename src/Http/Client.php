<?php

namespace Screenover\Api\Http;

use Screenover\Api\Exception\ApiException;
use Screenover\Api\Exception\AuthException;
use Screenover\Api\Exception\NotFoundException;
use Screenover\Api\Exception\ValidationException;

/**
 * Thin cURL wrapper that speaks JSON with the PayloadCMS REST API.
 *
 * It centralises header management, SSL toggling and PayloadCMS error parsing
 * so the public SDK class stays focused on the Mediative-compatible interface.
 */
class Client
{
    private bool $secure = true;

    /**
     * @var array<string,string>
     */
    private array $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    public function setHeader(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function removeHeader(string $key): void
    {
        unset($this->headers[$key]);
    }

    public function enableSecure(): void
    {
        $this->secure = true;
    }

    public function disableSecure(): void
    {
        $this->secure = false;
    }

    /**
     * Perform a JSON request and return the decoded response.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     *
     * @throws ApiException        on transport errors or unexpected HTTP failures
     * @throws AuthException       on HTTP 401/403
     * @throws NotFoundException   on HTTP 404
     * @throws ValidationException on HTTP 400/422
     */
    public function request(string $method, string $url, ?array $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiException('Unable to initialise cURL session');
        }

        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->secure,
            CURLOPT_SSL_VERIFYHOST => $this->secure ? 2 : 0,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                curl_close($ch);
                throw new ApiException('Unable to encode request body as JSON: ' . json_last_error_msg());
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);
        unset($ch);

        if ($raw === false) {
            throw new ApiException('HTTP transport error: ' . $transportError);
        }

        /** @var array<string,mixed>|null $data */
        $data = $raw === '' ? [] : json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        if ($status >= 400) {
            throw $this->buildError($status, $data);
        }

        return $data;
    }

    /**
     * Upload a local file with a raw PUT request (used for GCS resumable upload URLs).
     *
     * @throws ApiException on failure
     */
    public function putFile(string $url, string $filePath, string $contentType): void
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ApiException('Unable to open file for upload: ' . $filePath);
        }

        $size = filesize($filePath);
        if ($size === false) {
            fclose($handle);
            throw new ApiException('Unable to determine file size for upload: ' . $filePath);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($handle);
            throw new ApiException('Unable to initialise cURL session for upload');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->secure,
            CURLOPT_SSL_VERIFYHOST => $this->secure ? 2 : 0,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);
        unset($ch);
        fclose($handle);

        if ($raw === false) {
            throw new ApiException('GCS upload transport error: ' . $transportError);
        }
        if ($status >= 400) {
            throw new ApiException('GCS upload failed (HTTP ' . $status . ')', $status);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function buildError(int $status, array $data): ApiException
    {
        $message = $this->extractMessage($data) ?? ('HTTP ' . $status);

        switch ($status) {
            case 401:
            case 403:
                return new AuthException($message, $status, $data);
            case 404:
                return new NotFoundException($message, $status, $data);
            case 400:
            case 422:
                return new ValidationException($message, $status, $data);
            default:
                return new ApiException($message, $status, $data);
        }
    }

    /**
     * Extract a human readable message from a PayloadCMS error response.
     *
     * @param array<string,mixed> $data
     */
    private function extractMessage(array $data): ?string
    {
        if (isset($data['errors'][0]['message']) && is_string($data['errors'][0]['message'])) {
            return $data['errors'][0]['message'];
        }
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        if (isset($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }

        return null;
    }
}
