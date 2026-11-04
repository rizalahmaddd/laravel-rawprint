<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use RawPrint\Laravel\Agent\AgentClient;
use RuntimeException;

/**
 * Sends a document from PHP. Only useful with the "direct" driver, where
 * Laravel runs on the same PC as the printer. Retries are safe because the
 * agent ignores a repeated job_id within 10 minutes.
 */
final class PrintDocument implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [5, 15, 60];

    public function __construct(
        public string $jobId,
        public string $dataBase64,
        public string $printer = '',
        public string $docName = '',
        public int $copies = 1,
        public bool $wait = false,
        public int $waitTimeoutMs = 20000,
    ) {
    }

    public static function fromPayload(array $payload, bool $wait = false): self
    {
        return new self(
            jobId: (string) ($payload['job_id'] ?? ''),
            dataBase64: (string) ($payload['data_base64'] ?? ''),
            printer: (string) ($payload['printer'] ?? ''),
            docName: (string) ($payload['doc_name'] ?? ''),
            copies: (int) ($payload['copies'] ?? 1),
            wait: $wait,
        );
    }

    public function handle(AgentClient $client): void
    {
        $result = $client->print($this->jobId, $this->dataBase64, [
            'printer' => $this->printer,
            'doc_name' => $this->docName,
            'copies' => $this->copies,
        ]);

        if ($this->wait && ! $result->duplicate) {
            $status = $client->wait($result->jobId, $this->waitTimeoutMs);

            if ($status->failed()) {
                throw new RuntimeException($status->detail ?: 'print failed');
            }
        }
    }
}
