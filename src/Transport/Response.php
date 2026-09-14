<?php

declare(strict_types=1);

namespace Vinktar\Transport;

/** What ingest answered: the status (0 when nothing answered), the headers, and the raw body. */
final class Response
{
    /** @var array<string, string> lower-cased names */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $this->headers = array_change_key_case($headers, \CASE_LOWER);
    }

    public static function none(): self
    {
        return new self(0);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
