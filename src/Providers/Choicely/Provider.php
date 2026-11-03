<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Throwable;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Provider\DataSet\ResultData;
use Upmind\ProvisionProviders\WebsiteBuilders\Category;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountIdentifier;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\AccountInfo;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\ChangePackageParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\CreateParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\LoginResult;
use Upmind\ProvisionProviders\WebsiteBuilders\Data\UnSuspendParams;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Data\Configuration;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper\ChoicelyApi;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper\ChoicelyApiException;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper\Mapper;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper\RedactingLogger;
use Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper\Redactor;

/**
 * Choicely app-builder seats via the Choicely Partner API.
 *
 * An account is a Choicely "seat": `account_reference` is the seat id. Accounts are passwordless;
 * `login()` mints a single-use sign-in URL on every call, which must never be cached.
 */
class Provider extends Category implements ProviderInterface
{
    protected Configuration $configuration;

    protected ?ChoicelyApi $api = null;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Choicely')
            ->setDescription('Create, manage and log into Choicely app-builder seats')
            ->setLogoUrl('https://www.choicely.com/brand/logo-mark.svg');
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function create(CreateParams $params): AccountInfo
    {
        try {
            $data = $params->toArray();
            $body = Mapper::createRequestBody($data);
            $this->assertPackage($body['package']);

            $seat = $this->api()->createSeat($body, Mapper::createIdempotencyKey($data));

            return $this->accountInfo($seat, 'Account created');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function getInfo(AccountIdentifier $params): AccountInfo
    {
        try {
            $seat = $this->api()->getSeat($this->seatId($params->toArray()));

            return $this->accountInfo($seat, 'Account data obtained');
        } catch (ChoicelyApiException $e) {
            if ($e->getErrorCode() === 'seat_not_found' || $e->getHttpStatus() === 404) {
                $this->errorResult('Account not found', $e->getDebugData(), [], $e);
            }
            $this->handleException($e);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * Mints a fresh single-use URL on every call; the URL is returned byte for byte (its fragment
     * is the sign-in ticket) and is never logged.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function login(AccountIdentifier $params): LoginResult
    {
        try {
            $response = $this->api()->createLoginUrl($this->seatId($params->toArray()));

            return LoginResult::create(Mapper::loginResult($response))
                ->setMessage('Login URL generated');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            $data = $params->toArray();
            $package = Mapper::requireString($data, 'package_reference');
            $this->assertPackage($package);

            $seat = $this->api()->changePackage($this->seatId($data), $package);

            return $this->accountInfo($seat, 'Package changed');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function suspend(AccountIdentifier $params): AccountInfo
    {
        try {
            $seat = $this->api()->suspend($this->seatId($params->toArray()));

            return $this->accountInfo($seat, 'Account suspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * Unsuspends, then changes the package only when `package_reference` is given and differs
     * from the seat's current `package`.
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function unSuspend(UnSuspendParams $params): AccountInfo
    {
        try {
            $data = $params->toArray();
            $seatId = $this->seatId($data);
            $wanted = isset($data['package_reference'])
                && is_scalar($data['package_reference'])
                && (string) $data['package_reference'] !== ''
                ? (string) $data['package_reference']
                : null;
            if ($wanted !== null) {
                // Validate before unsuspending, so a bad package never leaves a half-done job.
                $this->assertPackage($wanted);
            }

            $seat = $this->api()->unsuspend($seatId);

            if ($wanted !== null && ($seat['package'] ?? null) !== $wanted) {
                $seat = $this->api()->changePackage($seatId, $wanted);
            }

            return $this->accountInfo($seat, 'Account unsuspended');
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @inheritDoc
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    public function terminate(AccountIdentifier $params): ResultData
    {
        try {
            $seat = $this->api()->terminate($this->seatId($params->toArray()));
            $result = Mapper::terminateResult($seat);

            return $this->okResult($result['message'], $result['data']);
        } catch (Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError When $package is not in the partner's catalogue
     */
    protected function assertPackage(string $package): void
    {
        $keys = $this->api()->packageKeys();
        if (!in_array($package, $keys, true)) {
            $this->errorResult(
                sprintf('Package "%s" is not available. Use one of: %s.', $package, implode(', ', $keys)),
                ['code' => 'invalid_package', 'http_status' => null, 'request_id' => null]
            );
        }
    }

    /**
     * @param array<string, mixed> $seat
     */
    protected function accountInfo(array $seat, string $message): AccountInfo
    {
        return AccountInfo::create(Mapper::accountInfo($seat))->setMessage($message);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     */
    protected function seatId(array $params): string
    {
        return Mapper::requireString($params, 'account_reference');
    }

    /**
     * Partner API errors become a user-safe error result: the API's `error.message` as the
     * message and `{code, http_status, request_id}` as data. Nothing else is passed on: never a
     * body, a header or a URL.
     *
     * @return no-return
     *
     * @throws \Upmind\ProvisionBase\Exception\ProvisionFunctionError
     * @throws \Throwable
     */
    protected function handleException(Throwable $e): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e;
        }

        if ($e instanceof ChoicelyApiException) {
            $this->errorResult($e->getMessage(), $e->getDebugData(), [], $e);
        }

        throw $e;
    }

    /**
     * Partner API client on `getGuzzleHandlerStack()`, whose PSR-3 request/response debug log
     * first goes through {@see RedactingLogger}: the bearer token, the partner key and any
     * sign-in URL never reach the log.
     */
    public function api(): ChoicelyApi
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $armorKey = is_string($this->configuration->armor_key) && trim($this->configuration->armor_key) !== ''
            ? trim($this->configuration->armor_key)
            : null;
        $redactor = new Redactor([$this->configuration->api_token, $armorKey]);

        $logger = $this->getLogger();
        if (!$logger instanceof RedactingLogger) {
            $logger = new RedactingLogger($logger, $redactor);
            $this->setLogger($logger);
        }

        $headers = [
            'User-Agent' => 'upmind/provision-provider-website-builders v1.0',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->configuration->api_token,
        ];
        if ($armorKey !== null) {
            $headers[ChoicelyApi::ARMOR_KEY_HEADER] = $armorKey;
        }

        $client = new Client([
            RequestOptions::HEADERS => $headers,
            RequestOptions::TIMEOUT => 30, // seconds
            RequestOptions::CONNECT_TIMEOUT => 10, // seconds
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false, // never re-send the bearer token anywhere
            'handler' => $this->getGuzzleHandlerStack(),
        ]);

        return $this->api = new ChoicelyApi($client, (string) $this->configuration->base_url, $logger, $redactor);
    }
}
