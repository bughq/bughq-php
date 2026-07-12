<?php

declare(strict_types=1);

namespace BugHQ;

/**
 * Formats PHP throwables into the `at func (file:line)` stack shape the
 * bughq ingest fingerprints and displays:
 *
 *     RuntimeException: payment failed
 *         at App\Billing\Charger->charge (/app/Billing/Charger.php:42)
 *         at App\Http\CheckoutController->store (/app/Http/CheckoutController.php:18)
 *
 * The ingest derives the grouping fingerprint from the error type, the
 * normalized message, and the top application (non-vendor) frame, and pulls
 * the issue "culprit" from the same frame - so the `at` prefix and the
 * trailing `:line` matter.
 */
final class StackTrace
{
    private const MAX_FRAMES = 50;

    private const MAX_CHAINED = 5;

    public static function format(\Throwable $e): string
    {
        $sections = [];
        $current = $e;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_CHAINED) {
            $header = $depth === 0
                ? self::title($current)
                : 'Caused by: ' . self::title($current);
            $sections[] = $header . "\n" . self::frames($current);
            $current = $current->getPrevious();
            $depth++;
        }

        return implode("\n", $sections);
    }

    public static function title(\Throwable $e): string
    {
        return self::type($e) . ': ' . $e->getMessage();
    }

    /** The exception class name without a leading backslash. */
    public static function type(\Throwable $e): string
    {
        return ltrim($e::class, '\\');
    }

    private static function frames(\Throwable $e): string
    {
        $lines = [];

        // The throw site is the top frame - getTrace() starts at the caller.
        $lines[] = sprintf('    at %s (%s:%d)', self::throwSiteLabel($e), $e->getFile(), $e->getLine());

        foreach (\array_slice($e->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '{unknown}');
            $file = $frame['file'] ?? '{internal}';
            $line = $frame['line'] ?? 0;
            $lines[] = sprintf('    at %s (%s:%d)', $fn, $file, $line);
        }

        return implode("\n", $lines);
    }

    /**
     * Label for the throw site: the function that threw when the trace knows
     * it, else `{throw}`.
     */
    private static function throwSiteLabel(\Throwable $e): string
    {
        $trace = $e->getTrace();
        if (isset($trace[0]['function'])) {
            return ($trace[0]['class'] ?? '') . ($trace[0]['type'] ?? '') . $trace[0]['function'];
        }

        return '{throw}';
    }
}
