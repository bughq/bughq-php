<?php

declare(strict_types=1);

namespace BugHQ\Transport;

use BugHQ\Config;

interface Transport
{
    /**
     * Deliver one event payload to the ingest. Implementations must never
     * throw - reporting must never break the host application. Returns the
     * HTTP status code, or null when delivery failed outright.
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload, Config $config): ?int;
}
