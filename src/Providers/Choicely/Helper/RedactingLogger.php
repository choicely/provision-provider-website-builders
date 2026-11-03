<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\WebsiteBuilders\Providers\Choicely\Helper;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 decorator that scrubs every log line before it reaches the provider's logger.
 *
 * The handler stack from `getGuzzleHandlerStack()` logs whole requests and responses: the
 * `Authorization` header, the `choicely-partner-key` header and the login URL (whose fragment is
 * a single-use sign-in ticket) would otherwise reach the debug log verbatim.
 *
 * Signatures are left untyped on `$message` so this class satisfies psr/log 1, 2 and 3 alike.
 */
class RedactingLogger implements LoggerInterface
{
    private LoggerInterface $inner;

    private Redactor $redactor;

    public function __construct(LoggerInterface $inner, Redactor $redactor)
    {
        $this->inner = $inner;
        $this->redactor = $redactor;
    }

    public function getInner(): LoggerInterface
    {
        return $this->inner;
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->inner->log($level, $this->redactor->text((string) $message), $this->redactor->context($context));
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
