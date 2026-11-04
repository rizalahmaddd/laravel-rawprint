<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Agent;

final class PrintResult
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $printer,
        public readonly string $state,
        public readonly bool $duplicate = false,
        public readonly ?int $spoolJobId = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromResponse(array $body): self
    {
        $job = (array) ($body['job'] ?? []);

        return new self(
            jobId: (string) ($job['job_id'] ?? ''),
            printer: (string) ($job['printer'] ?? ''),
            state: (string) ($job['state'] ?? 'queued'),
            duplicate: (bool) ($body['duplicate'] ?? false),
            spoolJobId: isset($job['spool_job_id']) ? (int) $job['spool_job_id'] : null,
            raw: $body,
        );
    }

    public function accepted(): bool
    {
        return in_array($this->state, ['queued', 'printing', 'done'], true);
    }
}
