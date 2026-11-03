<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Choicely Partner API client.
 *
 * - `Authorization: Bearer <api_token>`, `Accept: application/json`, 30 s timeout, JSON bodies.
 * - Every path is sent with its trailing slash (the API 308s without one); redirects are never
 *   followed, so the bearer token is never re-sent anywhere.
 * - Optional `Idempotency-Key` per write; optional `choicely-partner-key` on every request
 *   (set on the Guzzle client by the Provider).
 * - Every call is logged (method, path, status, request_id, duration) through the provider's
 *   PSR-3 logger, on top of the request/response debug log of `getGuzzleHandlerStack()`; both
 *   go through {@see RedactingLogger}.
 * - A non-2xx answer throws {@see ChoicelyApiException}.
 */
class ChoicelyApi
{
    public const SEATS = '/partner/v1/seats/';
    public const PACKAGES = '/partner/v1/packages/';
    public const ARMOR_KEY_HEADER = 'choicely-partner-key';

    private Client $client;

    private string $baseUrl;

    private LoggerInterface $logger;

    private Redactor $redactor;

    /**
     * @var string[]|null Package keys, fetched once per provider instance (one provision job)
     */
    private ?array $packageKeys = null;

    public function __construct(Client $client, string $baseUrl, LoggerInterface $logger, Redactor $redactor)
    {
        $this->client = $client;
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->logger = $logger;
        $this->redactor = $redactor;
    }

    /**
     * `POST /partner/v1/seats/`.
     *
     * @param array<string, mixed> $body CreateSeatRequest
     *
     * @return array<string, mixed> Seat
     */
    public function createSeat(array $body, ?string $idempotencyKey): array
    {
        return $this->request('POST', self::SEATS, $body, $idempotencyKey);
    }

    /**
     * `GET /partner/v1/seats/{seat_id}/`.
     *
     * @return array<string, mixed> Seat
     */
    public function getSeat(string $seatId): array
    {
        return $this->request('GET', self::seatPath($seatId));
    }

    /**
     * `POST /partner/v1/seats/{seat_id}/sso/`: a fresh single-use URL on every call.
     *
     * @return array<string, mixed> LoginUrl
     */
    public function createLoginUrl(string $seatId): array
    {
        return $this->request('POST', self::seatPath($seatId) . 'sso/');
    }

    /**
     * `POST /partner/v1/seats/{seat_id}/package/`.
     *
     * @return array<string, mixed> Seat
     */
    public function changePackage(string $seatId, string $package): array
    {
        return $this->request('POST', self::seatPath($seatId) . 'package/', ['package' => $package]);
    }

    /**
     * `POST /partner/v1/seats/{seat_id}/suspend/`.
     *
     * @return array<string, mixed> Seat
     */
    public function suspend(string $seatId): array
    {
        return $this->request('POST', self::seatPath($seatId) . 'suspend/');
    }

    /**
     * `POST /partner/v1/seats/{seat_id}/unsuspend/`.
     *
     * @return array<string, mixed> Seat
     */
    public function unsuspend(string $seatId): array
    {
        return $this->request('POST', self::seatPath($seatId) . 'unsuspend/');
    }

    /**
     * `POST /partner/v1/seats/{seat_id}/terminate/`.
     *
     * @return array<string, mixed> Seat
     */
    public function terminate(string $seatId): array
    {
        return $this->request('POST', self::seatPath($seatId) . 'terminate/');
    }

    /**
     * The package keys this partner may sell (`GET /partner/v1/packages/`), fetched once.
     *
     * @return string[]
     */
    public function packageKeys(): array
    {
        if ($this->packageKeys === null) {
            $this->packageKeys = Mapper::packageKeys($this->request('GET', self::PACKAGES));
        }

        return $this->packageKeys;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> The decoded JSON body ([] for an empty one)
     *
     * @throws ChoicelyApiException
     */
    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $method = strtoupper($method);
        $path = self::withTrailingSlash($path);
        $options = [];
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }
        $sendsIdempotencyKey = $idempotencyKey !== null && $idempotencyKey !== '';
        if ($sendsIdempotencyKey) {
            $options[RequestOptions::HEADERS] = [
                'Idempotency-Key' => substr((string) preg_replace('/[\r\n]/', '', (string) $idempotencyKey), 0, 255),
            ];
        }

        $started = microtime(true);
        try {
            $response = $this->client->request($method, $this->baseUrl . $path, $options);
        } catch (Throwable $e) {
            $this->logger->error('Choicely API call failed: no response', [
                'method' => $method,
                'path' => $path,
                'status' => 0,
                'request_id' => null,
                'duration_ms' => self::elapsedMs($started),
                'transport_error' => $e->getMessage(),
            ]);

            throw new ChoicelyApiException(
                'Could not reach the website builder service. Try again shortly.',
                'network_error',
                0,
                null,
                $e
            );
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = trim($raw) !== '' ? json_decode($raw, true) : null;
        $requestId = self::requestId($response, is_array($decoded) ? $decoded : null);
        $ok = $status >= 200 && $status < 300;

        $this->logger->log($ok ? 'debug' : 'notice', sprintf('Choicely API %s %s -> %d', $method, $path, $status), [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'request_id' => $requestId,
            'duration_ms' => self::elapsedMs($started),
            'idempotency_key_sent' => $sendsIdempotencyKey,
        ]);

        if (!$ok) {
            $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
                ? $decoded['error']
                : [];
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : 'http_' . $status;
            $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
                ? $error['message']
                : sprintf('The website builder service answered HTTP %d.', $status);

            // The contract promises error.message never carries a token or an access-granting
            // URL; scrub anyway, because this string is shown to an operator.
            throw new ChoicelyApiException($this->redactor->text($message), $code, $status, $requestId);
        }

        if ($raw !== '' && !is_array($decoded)) {
            throw new ChoicelyApiException(
                'The website builder service returned a response that is not JSON.',
                'invalid_response',
                $status,
                $requestId
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function seatPath(string $seatId): string
    {
        return self::SEATS . rawurlencode($seatId) . '/';
    }

    /**
     * `/partner/v1/seats` => `/partner/v1/seats/`; a query string stays after the slash.
     */
    public static function withTrailingSlash(string $path): string
    {
        $query = '';
        $q = strpos($path, '?');
        if ($q !== false) {
            $query = substr($path, $q);
            $path = substr($path, 0, $q);
        }
        $path = '/' . ltrim($path, '/');
        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        return $path . $query;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function requestId(ResponseInterface $response, ?array $body): ?string
    {
        foreach (['X-Request-Id', 'Request-Id', 'X-Cloud-Trace-Context'] as $name) {
            $value = $response->getHeaderLine($name);
            if ($value !== '') {
                return $value;
            }
        }
        if ($body !== null && isset($body['error']['request_id']) && is_string($body['error']['request_id'])) {
            return $body['error']['request_id'];
        }

        return null;
    }

    private static function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
