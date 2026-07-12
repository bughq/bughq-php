<?php

declare(strict_types=1);

namespace BugHQ;

use BugHQ\Transport\CurlTransport;
use BugHQ\Transport\Transport;

/**
 * The bughq error-tracking client.
 *
 * Captures exceptions and messages, enriches them with the scope (user,
 * tags, contexts, extras), a breadcrumb trail, request/runtime context, and
 * SDK/session metadata, then POSTs each event to the bughq ingest:
 *
 * `POST {host}/errors`, header `X-BugHQ-Key: <ingest_key>`, JSON body
 * `{ project, type, message, stack, level, url, os, framework, release,
 * environment, timestamp, user, extra, tags, contexts, breadcrumbs, sdk,
 * session, fingerprint }`.
 */
class Client
{
    public readonly Config $config;

    public readonly Scope $scope;

    private Transport $transport;

    /** @var list<Breadcrumb> */
    private array $breadcrumbs = [];

    /** @var array{id: string, startedAt: string} */
    private array $session;

    /** @var array<string, float> */
    private array $lastSeen = [];

    /**
     * @param array<string, mixed>|Config $config
     */
    public function __construct(array|Config $config = [], ?Transport $transport = null)
    {
        $this->config = $config instanceof Config ? $config : new Config($config);
        $this->transport = $transport ?? new CurlTransport();
        $this->scope = new Scope();
        $this->scope->setTags($this->config->initialTags);
        $this->session = [
            'id' => self::uuid(),
            'startedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    // --- Capture ------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    public function captureException(\Throwable $e, array $extra = [], string $level = 'error'): bool
    {
        if ($this->shouldIgnoreException($e)) {
            return false;
        }

        return $this->dispatch(
            type: StackTrace::type($e),
            message: $e->getMessage(),
            stack: StackTrace::format($e),
            level: $level,
            extra: $extra,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function captureMessage(string $message, string $level = 'info', array $extra = []): bool
    {
        return $this->dispatch(
            type: 'Message',
            message: $message,
            stack: null,
            level: $level,
            extra: $extra,
        );
    }

    // --- Breadcrumbs ----------------------------------------------------------

    /**
     * @param array<string, mixed>|Breadcrumb $crumb
     */
    public function addBreadcrumb(array|Breadcrumb $crumb): void
    {
        $breadcrumb = $crumb instanceof Breadcrumb ? $crumb : Breadcrumb::fromArray($crumb);

        if ($this->config->beforeBreadcrumb !== null) {
            $result = ($this->config->beforeBreadcrumb)($breadcrumb);
            if ($result === null) {
                return;
            }
            if ($result instanceof Breadcrumb) {
                $breadcrumb = $result;
            }
        }

        $this->breadcrumbs[] = $breadcrumb;
        $overflow = \count($this->breadcrumbs) - $this->config->maxBreadcrumbs;
        if ($overflow > 0) {
            $this->breadcrumbs = \array_slice($this->breadcrumbs, $overflow);
        }
    }

    public function clearBreadcrumbs(): void
    {
        $this->breadcrumbs = [];
    }

    // --- Scope passthroughs ---------------------------------------------------

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->scope->setUser($user);
    }

    public function setTag(string $key, string $value): void
    {
        $this->scope->setTag($key, $value);
    }

    /** @param array<string, mixed>|null $context */
    public function setContext(string $name, ?array $context): void
    {
        $this->scope->setContext($name, $context);
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->scope->setExtra($key, $value);
    }

    public function setLevel(?string $level): void
    {
        $this->scope->setLevel($level);
    }

    /** @param list<string>|null $fingerprint */
    public function setFingerprint(?array $fingerprint): void
    {
        $this->scope->setFingerprint($fingerprint);
    }

    public function setRelease(string $release): void
    {
        $this->config->release = $release;
    }

    public function setEnvironment(string $environment): void
    {
        $this->config->environment = $environment;
    }

    // --- Pipeline -------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function dispatch(string $type, string $message, ?string $stack, string $level, array $extra): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        if ($this->config->sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $this->config->sampleRate) {
            return false;
        }

        if ($this->shouldIgnoreMessage($type . ': ' . $message) || $this->shouldIgnoreMessage($message)) {
            return false;
        }

        // Dedupe identical error sites within the window.
        $topFrame = $stack !== null ? (explode("\n", $stack)[1] ?? '') : '';
        $dedupeKey = $type . '|' . $message . '|' . $topFrame;
        $now = microtime(true);
        if (isset($this->lastSeen[$dedupeKey]) && ($now - $this->lastSeen[$dedupeKey]) < $this->config->dedupeSeconds) {
            return false;
        }
        $this->lastSeen[$dedupeKey] = $now;

        $mergedExtra = array_merge($this->scope->getExtras(), $extra);
        $tags = $this->scope->getTags();
        $contexts = $this->buildContexts();

        $payload = [
            'project' => $this->config->project,
            'type' => $type,
            'message' => $message,
            'stack' => $stack,
            'level' => $this->scope->getLevel() ?? $level,
            'url' => $this->currentUrl(),
            'os' => $this->osName(),
            'framework' => $this->config->framework,
            'release' => $this->config->release,
            'environment' => $this->config->environment,
            'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'user' => $this->scope->getUser(),
            'extra' => $mergedExtra !== [] ? $mergedExtra : null,
            'tags' => $tags !== [] ? $tags : null,
            'contexts' => $contexts !== [] ? $contexts : null,
            'breadcrumbs' => $this->breadcrumbs !== []
                ? array_map(static fn (Breadcrumb $b): array => $b->toArray(), $this->breadcrumbs)
                : null,
            'sdk' => ['name' => $this->config->sdkName, 'version' => BugHQ::VERSION],
            'session' => $this->session,
            'fingerprint' => $this->scope->getFingerprint(),
        ];
        $payload = array_filter($payload, static fn ($v) => $v !== null);

        if ($this->config->beforeSend !== null) {
            $result = ($this->config->beforeSend)($payload);
            if ($result === null) {
                return false;
            }
            if (\is_array($result)) {
                $payload = $result;
            }
        }

        // Record this error as a breadcrumb so a subsequent event shows the chain.
        $this->addBreadcrumb(new Breadcrumb(
            message: $type . ': ' . $message,
            type: 'error',
            category: 'exception',
            level: $payload['level'] ?? $level,
        ));

        $status = $this->transport->send($payload, $this->config);

        return $status !== null && $status >= 200 && $status < 300;
    }

    /**
     * Auto contexts (runtime/server/request) merged under user-set contexts.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildContexts(): array
    {
        $out = [];

        if ($this->config->sendDefaultContext) {
            $out['runtime'] = array_filter([
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'sdk' => $this->config->sdkName,
                'sdkVersion' => BugHQ::VERSION,
                'framework' => $this->config->framework,
            ], static fn ($v) => $v !== null);

            $out['server'] = array_filter([
                'hostname' => gethostname() ?: null,
                'os' => PHP_OS_FAMILY,
            ], static fn ($v) => $v !== null);

            $request = $this->requestContext();
            if ($request !== []) {
                $out['request'] = $request;
            }
        }

        foreach ($this->scope->getContexts() as $name => $context) {
            $out[$name] = $context;
        }

        return $out;
    }

    /**
     * Request details for web SAPIs (empty for CLI).
     *
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return [];
        }

        return array_filter([
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'url' => $this->currentUrl(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    private function currentUrl(): ?string
    {
        if (!isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])) {
            return null;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    }

    private function osName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macOS',
            default => PHP_OS_FAMILY,
        };
    }

    private function shouldIgnoreException(\Throwable $e): bool
    {
        foreach ($this->config->ignoreExceptions as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function shouldIgnoreMessage(string $haystack): bool
    {
        foreach ($this->config->ignoreMessages as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if ($pattern[0] === '/' && @preg_match($pattern, '') !== false) {
                if (preg_match($pattern, $haystack) === 1) {
                    return true;
                }
                continue;
            }
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
