<?php

declare(strict_types=1);

namespace BugHQ;

/**
 * Global scope merged into every captured event: user, tags, named context
 * blocks, extras, a level override, and a grouping-fingerprint override.
 */
final class Scope
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var array<string, mixed> */
    private array $extras = [];

    private ?string $level = null;

    /** @var list<string>|null */
    private ?array $fingerprint = null;

    /**
     * @param array<string, mixed>|null $user
     */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    public function setTag(string $key, string $value): void
    {
        $this->tags[$key] = $value;
    }

    /**
     * @param array<string, string> $tags
     */
    public function setTags(array $tags): void
    {
        foreach ($tags as $key => $value) {
            $this->tags[(string) $key] = (string) $value;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Set (or clear, with null) a named context block.
     *
     * @param array<string, mixed>|null $context
     */
    public function setContext(string $name, ?array $context): void
    {
        if ($context === null) {
            unset($this->contexts[$name]);

            return;
        }
        $this->contexts[$name] = $context;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    public function setExtra(string $key, mixed $value): void
    {
        $this->extras[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtras(): array
    {
        return $this->extras;
    }

    public function setLevel(?string $level): void
    {
        $this->level = $level;
    }

    public function getLevel(): ?string
    {
        return $this->level;
    }

    /**
     * @param list<string>|null $fingerprint
     */
    public function setFingerprint(?array $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    /**
     * @return list<string>|null
     */
    public function getFingerprint(): ?array
    {
        return $this->fingerprint;
    }

    public function clear(): void
    {
        $this->user = null;
        $this->tags = [];
        $this->contexts = [];
        $this->extras = [];
        $this->level = null;
        $this->fingerprint = null;
    }
}
