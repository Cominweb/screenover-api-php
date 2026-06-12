<?php

namespace Screenover\Api\Exception;

/**
 * Base exception for every error raised by the ScreenOver API wrapper.
 */
class ApiException extends \Exception
{
    /**
     * @var array<string,mixed>|null Raw decoded error payload returned by the API, when available.
     */
    protected ?array $responseData;

    /**
     * @param array<string,mixed>|null $responseData
     */
    public function __construct(string $message, int $code = 0, ?array $responseData = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->responseData = $responseData;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
