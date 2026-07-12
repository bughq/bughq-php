<?php

declare(strict_types=1);

namespace BugHQ;

/**
 * Resolved client configuration.
 *
 * Accepts either explicit `project` + `key` (+ optional `host`) or a `dsn` of
 * the form `https://<ingest_key>@<host>/<project_id>`. The ingest key is
 * public - it ships in client code and is a revocable identifier, not a
 * secret.
 */
final class Config
{
    public const DEFAULT_HOST = 'https://bughq.org';

    public string $project = '';

    public string $key = '';

    public string $host = self::DEFAULT_HOST;

    public ?string $release = null;

    public string $environment = 'production';

    /** Framework tag (set by framework integrations, e.g. `laravel`). */
    public ?string $framework = null;

    /** SDK name reported in `event.sdk` (integrations override this). */
    public string $sdkName = 'bughq.php';

    public bool $enabled = true;

    /** Fraction of events to send, 0..1. */
    public float $sampleRate = 1.0;

    /** Drop repeats of the same error within this window (seconds). */
    public int $dedupeSeconds = 5;

    /** Max breadcrumbs retained per event. */
    public int $maxBreadcrumbs = 30;

    /**
     * Exception classes to drop (instanceof match), e.g.
     * `[\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class]`.
     *
     * @var list<class-string>
     */
    public array $ignoreExceptions = [];

    /**
     * Message patterns to drop - plain substrings, or regexes when delimited
     * (e.g. `'/timeout/i'`).
     *
     * @var list<string>
     */
    public array $ignoreMessages = [];

    /**
     * Inspect/mutate the payload before send; return null to drop the event.
     *
     * @var null|callable(array<string, mixed>): (array<string, mixed>|null)
     */
    public $beforeSend = null;

    /**
     * Inspect/mutate a breadcrumb before it is recorded; return null to drop.
     *
     * @var null|callable(Breadcrumb): (Breadcrumb|null)
     */
    public $beforeBreadcrumb = null;

    /** Total transport timeout (seconds). */
    public int $timeout = 5;

    /** Transport connect timeout (seconds). */
    public int $connectTimeout = 2;

    /** User-Agent the transport sends (the ingest reads it for server clients). */
    public string $userAgent = 'bughq-php/' . BugHQ::VERSION;

    /** Attach request/runtime/server contexts automatically. */
    public bool $sendDefaultContext = true;

    /** PHP error types captured by the global error handler. */
    public int $errorTypes = E_ALL;

    /** Initial tags applied to every event. @var array<string, string> */
    public array $initialTags = [];

    /**
     * Key substrings whose values are redacted from extra/contexts/breadcrumb
     * data and URL query strings before send (case-insensitive).
     *
     * @var list<string>
     */
    public array $redactKeys = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'cookie',
        'credential',
        'private_key',
        'access_key',
        'session_id',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['dsn']) && \is_string($options['dsn']) && $options['dsn'] !== '') {
            $parsed = self::parseDsn($options['dsn']);
            if ($parsed !== null) {
                $this->host = $parsed['host'];
                $this->key = $parsed['key'];
                $this->project = $parsed['project'];
            }
        }

        foreach (['project', 'key', 'host', 'release', 'environment', 'framework', 'sdkName', 'userAgent'] as $prop) {
            if (isset($options[$prop]) && \is_string($options[$prop]) && $options[$prop] !== '') {
                $this->{$prop} = $options[$prop];
            }
        }

        foreach (['enabled', 'sendDefaultContext'] as $prop) {
            if (\array_key_exists($prop, $options)) {
                $this->{$prop} = (bool) $options[$prop];
            }
        }

        foreach (['dedupeSeconds', 'maxBreadcrumbs', 'timeout', 'connectTimeout', 'errorTypes'] as $prop) {
            if (isset($options[$prop]) && \is_numeric($options[$prop])) {
                $this->{$prop} = (int) $options[$prop];
            }
        }

        if (isset($options['sampleRate']) && \is_numeric($options['sampleRate'])) {
            $this->sampleRate = max(0.0, min(1.0, (float) $options['sampleRate']));
        }

        foreach (['ignoreExceptions', 'ignoreMessages', 'initialTags', 'redactKeys'] as $prop) {
            if (isset($options[$prop]) && \is_array($options[$prop])) {
                $this->{$prop} = $options[$prop];
            }
        }

        foreach (['beforeSend', 'beforeBreadcrumb'] as $prop) {
            if (isset($options[$prop]) && \is_callable($options[$prop])) {
                $this->{$prop} = $options[$prop];
            }
        }

        $this->host = rtrim($this->host, '/');

        if ($this->project === '' || $this->key === '') {
            $this->enabled = false;
        }
    }

    /**
     * Parse a DSN of the form `https://<key>@<host>/<project>`.
     *
     * @return array{host: string, key: string, project: string}|null
     */
    public static function parseDsn(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $project = isset($parts['path']) ? trim($parts['path'], '/') : '';
        $project = explode('/', $project)[0] ?? '';
        if ($project === '') {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [
            'host' => $host,
            'key' => $parts['user'] ?? ($parts['pass'] ?? ''),
            'project' => $project,
        ];
    }
}
