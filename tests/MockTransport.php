<?php

declare(strict_types=1);

namespace BugHQ\Tests;

use BugHQ\Config;
use BugHQ\Transport\Transport;

/** Captures outgoing payloads instead of sending them. */
final class MockTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public int $status = 201;

    public function send(array $payload, Config $config): ?int
    {
        $this->payloads[] = $payload;

        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return $this->payloads[\count($this->payloads) - 1] ?? [];
    }
}
