<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper;

use RuntimeException;
use Throwable;

/**
 * A non-2xx answer (or a transport failure) from the Partner API.
 *
 * getMessage() is the API's user-safe `error.message`; getDebugData() is
 * `{code, http_status, request_id}` and nothing else: never a body, a URL or a header.
 */
class ChoicelyApiException extends RuntimeException
{
    private string $errorCode;

    private int $httpStatus;

    private ?string $requestId;

    public function __construct(
        string $message,
        string $errorCode,
        int $httpStatus,
        ?string $requestId = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
    }

    /**
     * The closed-vocabulary `error.code` (or `network_error` / `invalid_response` client-side).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * HTTP status; 0 when no response was received.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array{code: string, http_status: int, request_id: string|null}
     */
    public function getDebugData(): array
    {
        return [
            'code' => $this->errorCode,
            'http_status' => $this->httpStatus,
            'request_id' => $this->requestId,
        ];
    }
}
