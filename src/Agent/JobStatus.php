<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Agent;

final class JobStatus
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $state,
        public readonly string $printer = '',
        public readonly ?string $detail = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromResponse(array $body): self
    {
        $job = (array) ($body['job'] ?? []);

        return new self(
            jobId: (string) ($job['job_id'] ?? ''),
            state: (string) ($job['state'] ?? 'queued'),
            printer: (string) ($job['printer'] ?? ''),
            detail: isset($job['detail']) ? (string) $job['detail'] : null,
            raw: $body,
        );
    }

    public function done(): bool
    {
        return $this->state === 'done';
    }

    public function failed(): bool
    {
        return $this->state === 'error';
    }

    public function pending(): bool
    {
        return in_array($this->state, ['queued', 'printing'], true);
    }
}
