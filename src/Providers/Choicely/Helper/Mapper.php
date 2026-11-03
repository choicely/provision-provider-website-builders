<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper;

use Upmind\ProvisionBase\Exception\ProvisionFunctionError;

/**
 * Pure mappings between the category's data sets and the Partner API's shapes.
 */
class Mapper
{
    private const IDEMPOTENCY_PREFIX = 'upmind-create-';

    /**
     * CreateParams => CreateSeatRequest. Ignored on purpose: billing_cycle_months (wholesale is
     * monthly), password (accounts are passwordless), permissions, site_builder_user_id.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     *
     * @throws ProvisionFunctionError
     */
    public static function createRequestBody(array $params): array
    {
        $body = [
            'package' => self::requireString($params, 'package_reference'),
            'customer' => [
                'email' => self::requireString($params, 'customer_email'),
                'name' => self::requireString($params, 'customer_name'),
            ],
            'partner_customer_ref' => self::requireString($params, 'customer_id'),
        ];
        $optional = [
            'domain_name' => 'site_domain',
            'language_code' => 'language',
        ];
        foreach ($optional as $from => $to) {
            if (isset($params[$from]) && is_scalar($params[$from]) && (string) $params[$from] !== '') {
                $body[$to] = (string) $params[$from];
            }
        }
        $extra = self::extra($params);
        if (isset($extra['region']) && is_string($extra['region']) && $extra['region'] !== '') {
            $body['region'] = $extra['region'];
        }
        if (
            isset($extra['order_reference'])
            && is_scalar($extra['order_reference'])
            && (string) $extra['order_reference'] !== ''
        ) {
            $body['partner_order_ref'] = (string) $extra['order_reference'];
        }

        return $body;
    }

    /**
     * An Idempotency-Key only when the job carries a stable reference in `extra`
     * (`idempotency_key`, else `order_reference`); otherwise none.
     *
     * @param array<string, mixed> $params
     */
    public static function createIdempotencyKey(array $params): ?string
    {
        $extra = self::extra($params);
        foreach (['idempotency_key', 'order_reference'] as $key) {
            if (isset($extra[$key]) && is_scalar($extra[$key]) && (string) $extra[$key] !== '') {
                return self::IDEMPOTENCY_PREFIX . (string) $extra[$key];
            }
        }

        return null;
    }

    /**
     * Seat => AccountInfo data.
     *
     * @param array<string, mixed> $seat
     *
     * @return array<string, mixed>
     */
    public static function accountInfo(array $seat): array
    {
        $apps = isset($seat['apps']) && is_array($seat['apps']) ? $seat['apps'] : [];

        return [
            'account_reference' => isset($seat['seat_id']) ? (string) $seat['seat_id'] : null,
            'package_reference' => isset($seat['package']) ? (string) $seat['package'] : null,
            // A terminated seat cannot be used either; `extra.status` tells the two apart.
            'suspended' => in_array($seat['status'] ?? null, ['suspended', 'terminated'], true),
            'domain_name' => isset($seat['site_domain']) ? (string) $seat['site_domain'] : null,
            'site_count' => isset($apps['count']) ? (int) $apps['count'] : null,
            'is_published' => isset($apps['published']) ? ((int) $apps['published']) > 0 : null,
            'extra' => [
                'status' => $seat['status'] ?? null,
                'region' => $seat['region'] ?? null,
                'sso_mode' => $seat['sso_mode'] ?? null,
            ],
        ];
    }

    /**
     * LoginUrl => LoginResult data. `url` byte for byte: the ticket is in the fragment; never
     * parsed, re-encoded or stripped.
     *
     * @param array<string, mixed> $response
     *
     * @return array{login_url: string}
     *
     * @throws ProvisionFunctionError
     */
    public static function loginResult(array $response): array
    {
        if (!isset($response['url']) || !is_string($response['url']) || $response['url'] === '') {
            throw ProvisionFunctionError::create('The website builder service did not return a login URL.')
                ->withData(['code' => 'invalid_response', 'http_status' => 200, 'request_id' => null]);
        }

        return ['login_url' => $response['url']];
    }

    /**
     * Terminated Seat => [message, data].
     *
     * @param array<string, mixed> $seat
     *
     * @return array{message: string, data: array<string, mixed>}
     */
    public static function terminateResult(array $seat): array
    {
        $until = isset($seat['data_retained_until']) && is_string($seat['data_retained_until'])
            ? $seat['data_retained_until']
            : null;

        return [
            'message' => $until !== null
                ? sprintf('Seat terminated; data retained until %s', $until)
                : 'Seat terminated',
            'data' => [
                'account_reference' => $seat['seat_id'] ?? null,
                'data_retained_until' => $until,
            ],
        ];
    }

    /**
     * PackageList => its keys.
     *
     * @param array<string, mixed> $list
     *
     * @return string[]
     */
    public static function packageKeys(array $list): array
    {
        $keys = [];
        foreach (is_array($list['items'] ?? null) ? $list['items'] : [] as $item) {
            if (is_array($item) && isset($item['key']) && is_string($item['key'])) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws ProvisionFunctionError
     */
    public static function requireString(array $params, string $field): string
    {
        $value = $params[$field] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw ProvisionFunctionError::create(sprintf('The %s field is required.', $field))
                ->withData(['code' => 'invalid_request', 'field' => $field]);
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private static function extra(array $params): array
    {
        return isset($params['extra']) && is_array($params['extra']) ? $params['extra'] : [];
    }
}
