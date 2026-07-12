<?php

declare(strict_types=1);

namespace BugHQ\Tests;

use BugHQ\Breadcrumb;
use BugHQ\Client;
use BugHQ\ErrorHandler;
use BugHQ\StackTrace;
use PHPUnit\Framework\TestCase;

/**
 * Regression pins for the adversarial-review findings: double-reporting of
 * fatals, PII redaction, payload bounds, dedupe-map behavior, timestamp
 * precision, and forged stack frames.
 */
final class ReviewRegressionTest extends TestCase
{
    private MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    private function client(array $options = []): Client
    {
        return new Client(array_merge([
            'project' => 'demo',
            'key' => 'k',
            'dedupeSeconds' => 0,
        ], $options), $this->transport);
    }

    private function registerIsolated(Client $client): ErrorHandler
    {
        $handler = ErrorHandler::register($client);
        foreach (['previousErrorHandler', 'previousExceptionHandler'] as $prop) {
            $ref = new \ReflectionProperty(ErrorHandler::class, $prop);
            $ref->setValue($handler, null);
        }

        return $handler;
    }

    public function testUncaughtExceptionSetsTheShutdownSkipFlag(): void
    {
        $handler = $this->registerIsolated($this->client());

        try {
            try {
                $handler->handleException(new \RuntimeException('crash once'));
            } catch (\RuntimeException) {
                // the rethrow - PHP would now record "Uncaught RuntimeException..."
            }

            self::assertCount(1, $this->transport->payloads);

            // handleShutdown skips "Uncaught ..." fatals when this flag is set
            // (the engine state itself cannot be simulated without dying, so
            // the guard input is asserted directly).
            $ref = new \ReflectionProperty(ErrorHandler::class, 'uncaughtCaptured');
            self::assertTrue($ref->getValue($handler), 'shutdown skip flag must be set after handleException');
        } finally {
            $handler->unregister();
        }
    }

    public function testUserErrorCapturedByHandleErrorIsSkippedAtShutdown(): void
    {
        $handler = $this->registerIsolated($this->client());

        try {
            $previous = error_reporting(E_ALL);
            try {
                $handler->handleError(E_USER_ERROR, 'fatal-ish', '/app/y.php', 10);
            } finally {
                error_reporting($previous);
            }
            self::assertCount(1, $this->transport->payloads);

            // error_get_last() would still hold this error at shutdown; the
            // guard compares message|file|line, which we can't inject into
            // error_get_last() directly - verify via the recorded key instead.
            $ref = new \ReflectionProperty(ErrorHandler::class, 'lastHandledError');
            self::assertSame('fatal-ish|/app/y.php|10', $ref->getValue($handler));
        } finally {
            $handler->unregister();
        }
    }

    public function testSensitiveKeysAreRedactedEverywhere(): void
    {
        $client = $this->client();
        $client->setExtra('password', 'hunter2');
        $client->setExtra('nested', ['api_key' => 'abc', 'ok' => 'keep']);
        $client->setContext('billing', ['card_token' => 'tok_123', 'plan' => 'pro']);
        $client->addBreadcrumb(['message' => 'login', 'data' => ['authorization' => 'Bearer xyz']]);
        $client->captureException(new \RuntimeException('x'));

        $payload = $this->transport->last();
        self::assertSame('[redacted]', $payload['extra']['password']);
        self::assertSame('[redacted]', $payload['extra']['nested']['api_key']);
        self::assertSame('keep', $payload['extra']['nested']['ok']);
        self::assertSame('[redacted]', $payload['contexts']['billing']['card_token']);
        self::assertSame('pro', $payload['contexts']['billing']['plan']);
        $crumb = array_values(array_filter($payload['breadcrumbs'], static fn (array $b) => ($b['message'] ?? '') === 'login'))[0];
        self::assertSame('[redacted]', $crumb['data']['authorization']);
    }

    public function testOversizedPayloadDropsHeavySectionsButStillSends(): void
    {
        $client = $this->client();
        $client->setExtra('huge', str_repeat('x', 900_000));
        $ok = $client->captureException(new \RuntimeException('big'));

        self::assertTrue($ok);
        $payload = $this->transport->last();
        self::assertArrayNotHasKey('extra', $payload);
        self::assertSame('big', $payload['message']);
    }

    public function testMessageIsTruncated(): void
    {
        $this->client()->captureMessage(str_repeat('m', 10_000));
        self::assertSame(8192, \strlen($this->transport->last()['message']));
    }

    public function testDedupeMapStaysEmptyWhenDedupeDisabled(): void
    {
        $client = $this->client(['dedupeSeconds' => 0]);
        $client->captureException(new \RuntimeException('a'));
        $client->captureException(new \RuntimeException('b'));

        $ref = new \ReflectionProperty(Client::class, 'lastSeen');
        self::assertSame([], $ref->getValue($client));
        self::assertCount(2, $this->transport->payloads);
    }

    public function testTimestampsHaveRealMilliseconds(): void
    {
        // Two timestamps a few ms apart must not both be .000 - gmdate's `v`
        // always rendered 000, which this pins against.
        $a = Breadcrumb::now();
        usleep(5_000);
        $b = Breadcrumb::now();
        self::assertMatchesRegularExpression('/\.\d{3}Z$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testForgedFramesInExceptionMessagesAreNeutralized(): void
    {
        $evil = new \RuntimeException("looks normal\n    at attacker->fake (/evil.php:1)");
        $stack = StackTrace::format($evil);

        $lines = explode("\n", $stack);
        self::assertStringContainsString('looks normal', $lines[0]);
        self::assertStringContainsString('at attacker->fake', $lines[0], 'forged frame must stay inside the title line');
        self::assertStringNotContainsString('/evil.php', $lines[1] ?? '', 'first real frame must not be the forged one');
    }

    public function testSensitiveQueryParamsAreScrubbedFromUrl(): void
    {
        $_SERVER['HTTP_HOST'] = 'app.test';
        $_SERVER['REQUEST_URI'] = '/checkout?order=5&token=sekrit&plan=pro';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->client()->captureException(new \RuntimeException('x'));
            $url = $this->transport->last()['url'];
            self::assertStringContainsString('order=5', $url);
            self::assertStringContainsString('plan=pro', $url);
            self::assertStringNotContainsString('sekrit', $url);
        } finally {
            unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        }
    }
}
