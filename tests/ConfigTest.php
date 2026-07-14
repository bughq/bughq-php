<?php

declare(strict_types=1);

namespace BugHQ\Tests;

use BugHQ\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParsesDsn(): void
    {
        $parsed = Config::parseDsn('https://abc123@bughq.org/acme-web-9f2c');
        self::assertSame([
            'host' => 'https://bughq.org',
            'key' => 'abc123',
            'project' => 'acme-web-9f2c',
        ], $parsed);
    }

    public function testDsnWithPortAndHttp(): void
    {
        $parsed = Config::parseDsn('http://k@localhost:3108/proj-1');
        self::assertSame('http://localhost:3108', $parsed['host']);
        self::assertSame('k', $parsed['key']);
        self::assertSame('proj-1', $parsed['project']);
    }

    public function testDsnWithoutProjectIsNull(): void
    {
        self::assertNull(Config::parseDsn('https://abc@bughq.org/'));
        self::assertNull(Config::parseDsn('not a url'));
    }

    public function testDsnFeedsConfig(): void
    {
        $config = new Config(['dsn' => 'https://k1@bughq.org/p1']);
        self::assertSame('p1', $config->project);
        self::assertSame('k1', $config->key);
        self::assertSame('https://bughq.org', $config->host);
        self::assertTrue($config->enabled);
    }

    public function testExplicitValuesOverrideDsn(): void
    {
        $config = new Config(['dsn' => 'https://k1@bughq.org/p1', 'project' => 'p2', 'host' => 'http://localhost:3108/']);
        self::assertSame('p2', $config->project);
        self::assertSame('http://localhost:3108', $config->host);
    }

    public function testKeyAloneEnables(): void
    {
        // The key is globally unique, so it alone identifies the project — a
        // bare key enables capture (no project id required).
        self::assertTrue((new Config(['key' => 'k']))->enabled);
    }

    public function testMissingKeyDisables(): void
    {
        // No key means the ingest can't resolve a project, so capture is off.
        self::assertFalse((new Config(['project' => 'p']))->enabled);
        self::assertFalse((new Config([]))->enabled);
    }

    public function testSampleRateIsClamped(): void
    {
        self::assertSame(1.0, (new Config(['sampleRate' => 7]))->sampleRate);
        self::assertSame(0.0, (new Config(['sampleRate' => -1]))->sampleRate);
    }
}
