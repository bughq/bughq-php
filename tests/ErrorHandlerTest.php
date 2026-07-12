<?php

declare(strict_types=1);

namespace BugHQ\Tests;

use BugHQ\Client;
use BugHQ\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private MockTransport $transport;

    private Client $client;

    private ?ErrorHandler $handler = null;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
        $this->client = new Client([
            'project' => 'demo',
            'key' => 'k',
            'dedupeSeconds' => 0,
        ], $this->transport);
    }

    protected function tearDown(): void
    {
        $this->handler?->unregister();
        $this->handler = null;
    }

    /**
     * Register the handler and null out the captured previous handlers so the
     * tests do not chain into PHPUnit's own global error/exception handlers.
     */
    private function register(Client $client): ErrorHandler
    {
        $handler = ErrorHandler::register($client);
        foreach (['previousErrorHandler', 'previousExceptionHandler'] as $prop) {
            $ref = new \ReflectionProperty(ErrorHandler::class, $prop);
            $ref->setValue($handler, null);
        }

        return $handler;
    }

    public function testHandleErrorCapturesRespectingMask(): void
    {
        $this->handler = $this->register($this->client);

        $previous = error_reporting(E_ALL); // PHPUnit masks non-fatals while running
        try {
            $this->handler->handleError(E_USER_WARNING, 'something odd', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertCount(1, $this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('ErrorException', $payload['type']);
        self::assertSame('something odd', $payload['message']);
        self::assertSame('warning', $payload['level']);
        self::assertSame('E_USER_WARNING', $payload['extra']['phpSeverity']);
    }

    public function testSuppressedErrorsAreNotCaptured(): void
    {
        $this->handler = $this->register($this->client);

        $previous = error_reporting(0); // simulate the @ operator
        try {
            $this->handler->handleError(E_USER_WARNING, 'suppressed', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertCount(0, $this->transport->payloads);
    }

    public function testConfiguredErrorTypesAreRespected(): void
    {
        $client = new Client([
            'project' => 'demo',
            'key' => 'k',
            'dedupeSeconds' => 0,
            'errorTypes' => E_ERROR, // warnings excluded
        ], $this->transport);
        $this->handler = $this->register($client);

        $previous = error_reporting(E_ALL); // runtime mask allows it; errorTypes must still refuse
        try {
            $this->handler->handleError(E_USER_WARNING, 'excluded by errorTypes', __FILE__, __LINE__);
        } finally {
            error_reporting($previous);
        }

        self::assertCount(0, $this->transport->payloads);
    }

    public function testHandleExceptionCapturesAsFatalAndRethrows(): void
    {
        $this->handler = $this->register($this->client);
        $boom = new \RuntimeException('uncaught');

        try {
            $this->handler->handleException($boom);
            self::fail('expected rethrow');
        } catch (\RuntimeException $e) {
            self::assertSame($boom, $e);
        }

        self::assertCount(1, $this->transport->payloads);
        $payload = $this->transport->last();
        self::assertSame('fatal', $payload['level']);
        self::assertTrue($payload['extra']['unhandled']);
    }

    public function testSeverityLevels(): void
    {
        self::assertSame('error', ErrorHandler::severityLevel(E_USER_ERROR));
        self::assertSame('warning', ErrorHandler::severityLevel(E_WARNING));
        self::assertSame('info', ErrorHandler::severityLevel(E_NOTICE));
        self::assertSame('info', ErrorHandler::severityLevel(E_DEPRECATED));
    }
}
