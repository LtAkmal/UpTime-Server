<?php

namespace Pterodactyl\Uptime\Services;

/**
 * A rejected agent request: a stable error code for the agent, an HTTP status and
 * optional public data (chain head, last sequence, server time).
 */
class IngestException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status, string $message, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return ['error' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
