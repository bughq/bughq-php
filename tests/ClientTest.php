<?php

declare(strict_types=1);

namespace BugHQ\Tests;

use BugHQ\Breadcrumb;
use BugHQ\Client;
use BugHQ\StackTrace;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
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
            'key' => 'k_123',
            'host' => 'http://localhost:3108',
            'dedupeSeconds' => 0,
        ], $options), $this->transport);
    }

    public function testCaptureExceptionSendsTheIngestContract(): void
    {
        $client = $this->client(['framework' => 'laravel', 'release' => '1.2.3', 'environment' => 'staging']);
        $ok = $client->captureException(new \RuntimeException('boom at checkout'));

        self::assertTrue($ok);
        $payload = $this->transport->last();
        self::assertSame('demo', $payload['project']);
        self::assertSame('RuntimeException', $payload['type']);
        self::assertSame('boom at checkout', $payload['message']);
        self::assertSame('error', $payload['level']);
        self::assertSame('laravel', $payload['framework']);
        self::assertSame('1.2.3', $payload['release']);
        self::assertSame('staging', $payload['environment']);
        self::assertStringContainsString('RuntimeException: boom at checkout', $payload['stack']);
        self::assertStringContainsString('    at ', $payload['stack']);
        self::assertMatchesRegularExpression('/\(\S+:\d+\)/', $payload['stack']);
        self::assertSame('bughq.php', $payload['sdk']['name']);
        self::assertNotEmpty($payload['session']['id']);
        self::assertNotEmpty($payload['timestamp']);
        self::assertSame('php', $payload['contexts']['runtime']['name']);
    }

    public function testCaptureMessage(): void
    {
        $this->client()->captureMessage('deploy finished', 'warning');
        $payload = $this->transport->last();
        self::assertSame('Message', $payload['type']);
        self::assertSame('deploy finished', $payload['message']);
        self::assertSame('warning', $payload['level']);
        self::assertArrayNotHasKey('stack', $payload);
    }

    public function testReportThrowableCapturesAnException(): void
    {
        $ok = $this->client()->report(new \TypeError('kaboom'), ['orderId' => 42]);

        self::assertTrue($ok);
        $payload = $this->transport->last();
        self::assertSame('TypeError', $payload['type']);
        self::assertSame('kaboom', $payload['message']);
        self::assertSame('error', $payload['level']);
        self::assertSame(42, $payload['extra']['orderId']);
    }

    public function testReportStringCapturesAnErrorLevelMessage(): void
    {
        $this->client()->report('checkout failed');

        $payload = $this->transport->last();
        self::assertSame('Message', $payload['type']);
        self::assertSame('checkout failed', $payload['message']);
        self::assertSame('error', $payload['level']);
    }

    public function testScopeRidesAlong(): void
    {
        $client = $this->client(['initialTags' => ['region' => 'us']]);
        $client->setUser(['id' => 7, 'email' => 'a@b.co']);
        $client->setTag('plan', 'pro');
        $client->setContext('order', ['id' => 'ord_1', 'total' => 42]);
        $client->setExtra('attempt', 3);
        $client->captureException(new \LogicException('x'));

        $payload = $this->transport->last();
        self::assertSame(['id' => 7, 'email' => 'a@b.co'], $payload['user']);
        self::assertSame('pro', $payload['tags']['plan']);
        self::assertSame('us', $payload['tags']['region']);
        self::assertSame('ord_1', $payload['contexts']['order']['id']);
        self::assertSame(3, $payload['extra']['attempt']);
    }

    public function testLevelAndFingerprintOverrides(): void
    {
        $client = $this->client();
        $client->setLevel('fatal');
        $client->setFingerprint(['checkout', 'timeout']);
        $client->captureException(new \RuntimeException('x'));

        $payload = $this->transport->last();
        self::assertSame('fatal', $payload['level']);
        self::assertSame(['checkout', 'timeout'], $payload['fingerprint']);
    }

    public function testBreadcrumbsAttachOldestFirstAndRing(): void
    {
        $client = $this->client(['maxBreadcrumbs' => 2]);
        $client->addBreadcrumb(['message' => 'a']);
        $client->addBreadcrumb(['message' => 'b']);
        $client->addBreadcrumb(new Breadcrumb(message: 'c', category: 'test'));
        $client->captureException(new \RuntimeException('x'));

        $crumbs = $this->transport->last()['breadcrumbs'];
        self::assertSame(['b', 'c'], array_column($crumbs, 'message'));
    }

    public function testBeforeBreadcrumbCanDrop(): void
    {
        $client = $this->client([
            'beforeBreadcrumb' => static fn (Breadcrumb $b) => $b->category === 'secret' ? null : $b,
        ]);
        $client->addBreadcrumb(['message' => 'x', 'category' => 'secret']);
        $client->addBreadcrumb(['message' => 'y', 'category' => 'ok']);
        $client->captureException(new \RuntimeException('x'));

        $messages = array_column($this->transport->last()['breadcrumbs'], 'message');
        self::assertContains('y', $messages);
        self::assertNotContains('x', $messages);
    }

    public function testBeforeSendCanDropAndMutate(): void
    {
        $drop = $this->client(['beforeSend' => static fn (array $p) => null]);
        self::assertFalse($drop->captureException(new \RuntimeException('nope')));
        self::assertCount(0, $this->transport->payloads);

        $mutate = $this->client(['beforeSend' => static function (array $p): array {
            $p['message'] = 'redacted';

            return $p;
        }]);
        $mutate->captureException(new \RuntimeException('secret'));
        self::assertSame('redacted', $this->transport->last()['message']);
    }

    public function testIgnoreExceptionsByClass(): void
    {
        $client = $this->client(['ignoreExceptions' => [\LogicException::class]]);
        self::assertFalse($client->captureException(new \LogicException('skip')));
        // subclasses match too (instanceof)
        self::assertFalse($client->captureException(new \DomainException('skip')));
        self::assertTrue($client->captureException(new \RuntimeException('keep')));
        self::assertCount(1, $this->transport->payloads);
    }

    public function testIgnoreMessagesSubstringAndRegex(): void
    {
        $client = $this->client(['ignoreMessages' => ['ResizeObserver', '/timeout/i']]);
        self::assertFalse($client->captureException(new \RuntimeException('ResizeObserver loop')));
        self::assertFalse($client->captureException(new \RuntimeException('Connection TIMEOUT reached')));
        self::assertTrue($client->captureException(new \RuntimeException('real bug')));
        self::assertCount(1, $this->transport->payloads);
    }

    public function testSampleRateZeroDropsEverything(): void
    {
        $client = $this->client(['sampleRate' => 0]);
        self::assertFalse($client->captureException(new \RuntimeException('x')));
        self::assertCount(0, $this->transport->payloads);
    }

    public function testDisabledWithoutCredentials(): void
    {
        $client = new Client([], $this->transport);
        self::assertFalse($client->captureException(new \RuntimeException('x')));
        self::assertCount(0, $this->transport->payloads);
    }

    public function testDedupesSameSiteWithinWindow(): void
    {
        $client = $this->client(['dedupeSeconds' => 60]);
        $e = new \RuntimeException('same');
        self::assertTrue($client->captureException($e));
        self::assertFalse($client->captureException($e));
        self::assertCount(1, $this->transport->payloads);
    }

    public function testStackTraceFormatsCausedByChain(): void
    {
        $inner = new \InvalidArgumentException('bad input');
        $outer = new \RuntimeException('wrapper', 0, $inner);
        $stack = StackTrace::format($outer);

        self::assertStringContainsString('RuntimeException: wrapper', $stack);
        self::assertStringContainsString('Caused by: InvalidArgumentException: bad input', $stack);
    }
}
