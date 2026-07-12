# bughq PHP SDK

Privacy-first error tracking for PHP. Captures exceptions, PHP errors, and
fatal shutdowns, enriches them with breadcrumbs, tags, contexts, and user
data, and reports them to [bughq](https://bughq.org).

Using Laravel? Install [`bughq/bughq-laravel`](https://github.com/bughq/bughq-laravel)
instead - it wires everything below into the framework automatically.

## Install

```bash
composer require bughq/bughq
```

## Quick start

```php
use BugHQ\BugHQ;

BugHQ::init([
    'project' => 'acme-api',        // from your bughq dashboard
    'key' => 'pk_...',              // public ingest key (safe to ship)
    // or: 'dsn' => 'https://pk_...@bughq.org/acme-api',
    'release' => '1.4.2',
    'environment' => 'production',
]);
```

That's it - uncaught exceptions, PHP errors, and fatal shutdown errors are
reported automatically (set `'captureUnhandled' => false` to opt out).

## Manual capture

```php
try {
    $charger->charge($order);
} catch (PaymentException $e) {
    BugHQ::captureException($e, ['orderId' => $order->id]);
    throw $e;
}

BugHQ::captureMessage('cache rebuild took ' . $secs . 's', 'warning');
```

## Context

```php
BugHQ::setUser(['id' => $user->id, 'email' => $user->email]);
BugHQ::setTag('plan', 'pro');
BugHQ::setContext('order', ['id' => $order->id, 'total' => $order->total]);
BugHQ::setExtra('attempt', $attempt);
BugHQ::addBreadcrumb([
    'category' => 'payment',
    'message' => 'charge submitted',
    'data' => ['gateway' => 'stripe'],
]);
// force related errors into one issue (or split one apart):
BugHQ::setFingerprint(['checkout', 'gateway-timeout']);
```

Runtime (PHP version, SAPI), server (hostname, OS), and request (method, URL,
IP, user agent) contexts are attached automatically.

## Options

| option | default | |
|---|---|---|
| `project`, `key` | - | or provide `dsn` |
| `host` | `https://bughq.org` | self-hosted ingest |
| `release`, `environment` | - / `production` | |
| `sampleRate` | `1.0` | fraction of events to send |
| `dedupeSeconds` | `5` | drop repeats of the same error site |
| `maxBreadcrumbs` | `30` | ring buffer size |
| `ignoreExceptions` | `[]` | class names, `instanceof` match |
| `ignoreMessages` | `[]` | substrings or `/regex/` |
| `beforeSend` | - | `fn (array $payload): ?array` - return `null` to drop |
| `beforeBreadcrumb` | - | `fn (Breadcrumb $b): ?Breadcrumb` |
| `errorTypes` | `E_ALL` | mask for the global error handler |
| `captureUnhandled` | `true` | install global handlers on `init()` |
| `timeout` / `connectTimeout` | `5` / `2` | transport (seconds) |

## License

MIT
