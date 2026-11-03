<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Data;

use Upmind\ProvisionBase\Provider\DataSet\DataSet;
use Upmind\ProvisionBase\Provider\DataSet\Rules;

/**
 * Choicely Partner API credentials.
 *
 * @property-read string $api_token Partner API bearer token (per environment)
 * @property-read string $base_url Partner API base URL, e.g. https://auth.choicely.com
 * @property-read string|null $armor_key Optional partner key, sent as the `choicely-partner-key` header
 */
class Configuration extends DataSet
{
    public static function rules(): Rules
    {
        return new Rules([
            'api_token' => ['required', 'string'],
            'base_url' => ['required', 'url'],
            'armor_key' => ['nullable', 'string'],
        ]);
    }
}
