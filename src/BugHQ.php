<?php

declare(strict_types=1);

namespace BugHQ;

use BugHQ\Transport\Transport;

/**
 * Static entry point holding the default client:
 *
 * ```php
 * BugHQ::init(['project' => 'acme-api', 'key' => 'pk_...']);
 * BugHQ::captureException($e);
 * ```
 *
 * `init()` also installs the global error/exception/shutdown handlers unless
 * `captureUnhandled` is set to false.
 */
final class BugHQ
{
    public const VERSION = '0.1.1';

    private static ?Client $client = null;

    private static ?ErrorHandler $handler = null;

    /**
     * @param array<string, mixed>|Config $config
     */
    public static function init(array|Config $config = [], ?Transport $transport = null): Client
    {
        $captureUnhandled = true;
        if (\is_array($config)) {
            $captureUnhandled = !\array_key_exists('captureUnhandled', $config) || (bool) $config['captureUnhandled'];
        }

        self::$client = new Client($config, $transport);

        if ($captureUnhandled && self::$client->config->enabled) {
            self::$handler = ErrorHandler::register(self::$client);
        }

        return self::$client;
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function captureException(\Throwable $e, array $extra = []): bool
    {
        return self::$client?->captureException($e, $extra) ?? false;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function captureMessage(string $message, string $level = 'info', array $extra = []): bool
    {
        return self::$client?->captureMessage($message, $level, $extra) ?? false;
    }

    /**
     * @param array<string, mixed>|Breadcrumb $crumb
     */
    public static function addBreadcrumb(array|Breadcrumb $crumb): void
    {
        self::$client?->addBreadcrumb($crumb);
    }

    /** @param array<string, mixed>|null $user */
    public static function setUser(?array $user): void
    {
        self::$client?->setUser($user);
    }

    public static function setTag(string $key, string $value): void
    {
        self::$client?->setTag($key, $value);
    }

    /** @param array<string, mixed>|null $context */
    public static function setContext(string $name, ?array $context): void
    {
        self::$client?->setContext($name, $context);
    }

    public static function setExtra(string $key, mixed $value): void
    {
        self::$client?->setExtra($key, $value);
    }

    /** @param list<string>|null $fingerprint */
    public static function setFingerprint(?array $fingerprint): void
    {
        self::$client?->setFingerprint($fingerprint);
    }

    /** Remove installed handlers and drop the default client. */
    public static function close(): void
    {
        self::$handler?->unregister();
        self::$handler = null;
        self::$client = null;
    }
}
