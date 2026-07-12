<?php

declare(strict_types=1);

namespace BugHQ;

/**
 * Global capture: PHP errors, uncaught exceptions, and fatal shutdown
 * errors. Previous handlers are preserved and chained - installing bughq
 * never changes what the application does with an error, only reports it.
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING | E_USER_ERROR;

    private static ?self $instance = null;

    /** @var callable|null */
    private $previousErrorHandler = null;

    /** @var callable|null */
    private $previousExceptionHandler = null;

    private bool $shutdownRegistered = false;

    /** Fatal already reported via handleException - shutdown must not re-report it. */
    private bool $uncaughtCaptured = false;

    /** Last error captured by handleError, so shutdown skips the same one. */
    private ?string $lastHandledError = null;

    private function __construct(private readonly Client $client)
    {
    }

    /** Install error + exception + shutdown handlers (idempotent). */
    public static function register(Client $client): self
    {
        if (self::$instance !== null) {
            self::$instance->unregister();
        }

        $handler = new self($client);

        $handler->previousErrorHandler = set_error_handler([$handler, 'handleError']);
        $handler->previousExceptionHandler = set_exception_handler([$handler, 'handleException']);

        // register_shutdown_function cannot be unregistered - guard with a flag.
        register_shutdown_function([$handler, 'handleShutdown']);
        $handler->shutdownRegistered = true;

        self::$instance = $handler;

        return $handler;
    }

    /**
     * Restore the previous error/exception handlers - only when bughq's are
     * still the active ones. If the application installed its own handlers
     * after ours, popping the stack would silently remove THEIRS, so we leave
     * the stack alone and just stop reporting (the shutdown flag).
     */
    public function unregister(): void
    {
        if (self::currentErrorHandler() === [$this, 'handleError']) {
            restore_error_handler();
        }
        if (self::currentExceptionHandler() === [$this, 'handleException']) {
            restore_exception_handler();
        }
        $this->shutdownRegistered = false;
        if (self::$instance === $this) {
            self::$instance = null;
        }
    }

    /** The currently installed error handler (get_error_handler() on PHP 8.5+, a set/restore peek on 8.4). */
    private static function currentErrorHandler(): callable|string|array|null
    {
        if (\function_exists('get_error_handler')) {
            return get_error_handler();
        }

        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current;
    }

    /** The currently installed exception handler (get_exception_handler() on PHP 8.5+, a set/restore peek on 8.4). */
    private static function currentExceptionHandler(): callable|string|array|null
    {
        if (\function_exists('get_exception_handler')) {
            return get_exception_handler();
        }

        $current = set_exception_handler(null);
        if ($current !== null) {
            set_exception_handler($current);
        }

        return $current;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function handleError(int $severity, string $message, string $file = '', int $line = 0, array $context = []): bool
    {
        // Respect both the runtime error_reporting() mask (e.g. the @ operator
        // zeroes it) and the configured errorTypes.
        if ((error_reporting() & $severity) !== 0 && ($this->client->config->errorTypes & $severity) !== 0) {
            $exception = new \ErrorException($message, 0, $severity, $file, $line);
            $this->client->captureException($exception, ['phpSeverity' => self::severityName($severity)], self::severityLevel($severity));
            // Remember the site so a fatal-mask error (e.g. E_USER_ERROR) that
            // is still error_get_last() at shutdown is not reported twice.
            $this->lastHandledError = $message . '|' . $file . '|' . $line;
        }

        if ($this->previousErrorHandler !== null) {
            // Only an explicit `false` falls through to PHP's internal handler;
            // a void/null-returning previous handler means "handled". A (bool)
            // cast would turn null into false and re-enable internal handling
            // the previous handler intended to suppress.
            return \call_user_func($this->previousErrorHandler, $severity, $message, $file, $line, $context) !== false;
        }

        // Fall through to PHP's internal handler.
        return false;
    }

    public function handleException(\Throwable $e): void
    {
        $this->client->captureException($e, ['unhandled' => true], 'fatal');
        // PHP records the (re)throw as a fatal "Uncaught ..." E_ERROR that
        // error_get_last() still returns at shutdown - flag it as already
        // reported so handleShutdown doesn't produce a duplicate garbage event.
        $this->uncaughtCaptured = true;

        if ($this->previousExceptionHandler !== null) {
            \call_user_func($this->previousExceptionHandler, $e);

            return;
        }

        // No previous handler: mirror PHP's default behavior.
        throw $e;
    }

    public function handleShutdown(): void
    {
        if (!$this->shutdownRegistered) {
            return;
        }

        $error = error_get_last();
        if ($error === null || ($error['type'] & self::FATAL) === 0) {
            return;
        }

        // Already reported through handleException (the engine's "Uncaught ..."
        // fatal for the rethrow) or through handleError (an E_USER_ERROR that
        // stayed the last error) - don't report the same crash twice.
        if ($this->uncaughtCaptured && str_starts_with($error['message'], 'Uncaught ')) {
            return;
        }
        if ($this->lastHandledError === $error['message'] . '|' . $error['file'] . '|' . $error['line']) {
            return;
        }

        $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $this->client->captureException($exception, ['phpSeverity' => self::severityName($error['type']), 'shutdown' => true], 'fatal');
    }

    public static function severityLevel(int $severity): string
    {
        return match (true) {
            ($severity & (self::FATAL | E_RECOVERABLE_ERROR)) !== 0 => 'error',
            ($severity & (E_WARNING | E_USER_WARNING)) !== 0 => 'warning',
            default => 'info',
        };
    }

    public static function severityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }
}
