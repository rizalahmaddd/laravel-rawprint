<?php

declare(strict_types=1);

namespace RawPrint\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use LogicException;
use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\Agent\PrintResult;
use RawPrint\Laravel\Document\Document;
use RawPrint\Laravel\Document\PaperProfile;
use RawPrint\Laravel\Pending\Job;

final class RawPrintManager
{
    /** @var array<string, array<string, mixed>> */
    private array $queued = [];

    public function __construct(
        private readonly Container $app,
        private readonly ConfigRepository $config,
    ) {
    }

    public function driver(): string
    {
        return (string) $this->config->get('rawprint.driver', 'browser');
    }

    public function isDirect(): bool
    {
        return $this->driver() === 'direct';
    }

    public function client(): AgentClient
    {
        return new AgentClient(
            baseUrl: $this->baseUrl(),
            token: $this->config->get('rawprint.token') ?: null,
            origin: $this->config->get('rawprint.origin') ?: null,
            timeout: (int) $this->config->get('rawprint.timeout', 30),
            connectTimeout: (int) $this->config->get('rawprint.connect_timeout', 2),
            maxPayloadBytes: (int) $this->config->get('rawprint.max_payload_bytes', 2 * 1024 * 1024),
        );
    }

    public function profile(): PaperProfile
    {
        return PaperProfile::fromArray((array) $this->config->get('rawprint.profile', []));
    }

    /** Live paper profile stored on the agent for a printer. */
    public function agentProfile(?string $printer = null): array
    {
        return $this->client()->profile($printer);
    }

    public function document(?PaperProfile $profile = null): Document
    {
        return Document::make(
            $profile ?? $this->profile(),
            (int) $this->config->get('rawprint.line_width', 0),
            (float) $this->config->get('rawprint.lines_per_inch', 6.0),
            (float) $this->config->get('rawprint.paper_width_mm', 203.2),
        );
    }

    public function job(?string $jobId = null): Job
    {
        return new Job($jobId, $this->profile());
    }

    /** Queues a job for the browser bridge rendered by <x-rawprint />. */
    public function queue(Job $job): Job
    {
        if ($this->isDirect()) {
            throw new LogicException('queue() is for the browser driver; use send() or dispatch() in direct mode');
        }

        $payload = $job->payload();
        $this->queued[$payload['job_id']] = $payload;

        return $job;
    }

    /** @return list<array<string, mixed>> */
    public function queued(): array
    {
        return array_values($this->queued);
    }

    public function flush(): void
    {
        $this->queued = [];
    }

    public function appName(): string
    {
        return (string) $this->config->get('rawprint.app_name', $this->config->get('app.name', 'Laravel'));
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->config->get('rawprint.base_url', 'http://127.0.0.1:18310'), '/');
    }

    public function health(): array
    {
        return $this->client()->health();
    }

    public function printers(): array
    {
        return $this->client()->printers();
    }

    public function testPrint(?string $printer = null): PrintResult
    {
        return $this->client()->testPrint($printer);
    }
}
