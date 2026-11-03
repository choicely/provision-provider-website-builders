<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper;

/**
 * Keeps credentials and login URLs out of anything logged.
 *
 * A login URL carries a single-use ticket in its fragment (`#ticket=...`): anyone holding it is
 * signed in as the customer. So a URL with a query or fragment is only ever logged as origin +
 * path, and context keys that can carry one (or a credential) are dropped whole.
 */
class Redactor
{
    public const MASK = '[REDACTED]';

    /**
     * Context keys whose value is never logged, whatever it contains.
     */
    private const SENSITIVE_KEYS = [
        'authorization', 'token', 'api_token', 'bearer', 'url', 'login_url', 'headers', 'body',
        'request_body', 'response_body', 'ticket', 'armor_key', 'choicely-partner-key',
    ];

    /**
     * @var string[]
     */
    private array $secrets;

    /**
     * @param array<string|null> $secrets Credentials to mask wherever they appear
     */
    public function __construct(array $secrets)
    {
        $this->secrets = array_values(array_filter($secrets, static function ($secret): bool {
            return is_string($secret) && $secret !== '';
        }));
    }

    /**
     * `https://studio.choicely.com/sso#ticket=...` => `https://studio.choicely.com/sso`.
     */
    public static function urlToOriginAndPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return self::MASK;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . ($parts['path'] ?? '');
    }

    /**
     * Masks every credential and header value that carries one, and cuts every URL with a
     * query or fragment down to origin + path (also in JSON-escaped form, `https:\/\/...`).
     */
    public function text(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        $text = (string) preg_replace(
            ['~(Authorization:\s*)[^\r\n]+~i', '~(choicely-partner-key:\s*)[^\r\n]+~i'],
            '$1' . self::MASK,
            $text
        );

        $text = (string) preg_replace_callback(
            '~\bhttps?://[^\s"\'<>]+~i',
            static function (array $m): string {
                if (strpbrk($m[0], '?#') === false) {
                    return $m[0];
                }

                return self::urlToOriginAndPath($m[0]) . self::MASK;
            },
            $text
        );

        $text = (string) preg_replace_callback(
            '~\bhttps?:\\\\/\\\\/[^\s"\'<>]+~i',
            static function (array $m): string {
                if (strpbrk($m[0], '?#') === false) {
                    return $m[0];
                }

                return self::urlToOriginAndPath(str_replace('\\/', '/', $m[0])) . self::MASK;
            },
            $text
        );

        // Belt and braces: a ticket is never logged, whatever surrounds it.
        return (string) preg_replace('~(ticket=)[^\s"\'&<>]+~i', '$1' . self::MASK, $text);
    }

    /**
     * Recursively scrubs a log context.
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public function context(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $out[$key] = self::MASK;
            } elseif (is_array($value)) {
                $out[$key] = $this->context($value);
            } elseif (is_string($value)) {
                $out[$key] = $this->text($value);
            } elseif ($value === null || is_scalar($value)) {
                $out[$key] = $value;
            } else {
                $out[$key] = self::MASK; // objects/resources: never stringified into a log
            }
        }

        return $out;
    }
}
