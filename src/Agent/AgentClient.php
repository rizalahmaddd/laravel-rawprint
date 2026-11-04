<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RawPrint\Laravel\Document\PaperProfile;

final class AgentClient
{
    public function __construct(
        private readonly string $baseUrl = 'http://127.0.0.1:18310',
        private readonly ?string $token = null,
        private readonly ?string $origin = null,
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 2,
        private readonly int $maxPayloadBytes = 2 * 1024 * 1024,
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function health(): array
    {
        return $this->request('GET', '/health', [], 2);
    }

    public function printers(): array
    {
        return $this->request('GET', '/printers');
    }

    public function profile(?string $printer = null): array
    {
        $path = '/profile';

        if ($printer !== null && $printer !== '') {
            $path .= '?printer='.rawurlencode($printer);
        }

        return $this->request('GET', $path);
    }

    public function saveProfile(PaperProfile|array $profile, ?string $printer = null): array
    {
        if ($profile instanceof PaperProfile) {
            $profile = $profile->toArray();
        }

        return $this->request('PUT', '/profile', [
            'printer' => $printer ?? '',
            'profile' => $profile,
        ]);
    }

    /**
     * @param  array{printer?: string, doc_name?: string, copies?: int}  $options
     */
    public function print(string $jobId, string $dataBase64, array $options = []): PrintResult
    {
        if ($jobId === '' || strlen($jobId) > 128) {
            throw new AgentException('job_id must be between 1 and 128 characters', 'invalid_job');
        }

        $copies = (int) ($options['copies'] ?? 1);

        if ($copies < 1 || $copies > 10) {
            throw new AgentException('copies must be between 1 and 10', 'invalid_job');
        }

        $this->assertPayload($dataBase64);

        return PrintResult::fromResponse($this->request('POST', '/print', [
            'job_id' => $jobId,
            'data_base64' => $dataBase64,
            'printer' => (string) ($options['printer'] ?? ''),
            'doc_name' => (string) ($options['doc_name'] ?? ''),
            'copies' => $copies,
        ]));
    }

    public function job(string $jobId): JobStatus
    {
        return JobStatus::fromResponse(
            $this->request('GET', '/jobs/'.rawurlencode($jobId)),
        );
    }

    /**
     * Polls the agent until the job is done, failed or the timeout expires.
     * A job still queued after the timeout is returned as-is, because that
     * usually means the printer is off or out of paper.
     */
    public function wait(
        string $jobId,
        int $timeoutMs = 20000,
        int $intervalMs = 1000,
        ?callable $onProgress = null,
    ): JobStatus {
        $deadline = microtime(true) + max(0, $timeoutMs) / 1000;

        while (true) {
            $status = $this->job($jobId);

            if ($onProgress !== null) {
                $onProgress($status);
            }

            if ($status->done() || $status->failed()) {
                return $status;
            }

            if (microtime(true) >= $deadline) {
                return $status;
            }

            usleep(max(1, $intervalMs) * 1000);
        }
    }

    public function testPrint(?string $printer = null): PrintResult
    {
        return PrintResult::fromResponse($this->request('POST', '/test-print', [
            'printer' => $printer ?? '',
        ]));
    }

    private function request(string $method, string $path, array $body = [], ?int $timeout = null): array
    {
        $headers = ['Accept' => 'application/json'];

        if ($this->token !== null && $this->token !== '') {
            $headers['X-Agent-Token'] = $this->token;
        }

        if ($this->origin !== null && $this->origin !== '') {
            $headers['Origin'] = $this->origin;
        }

        $pending = Http::withHeaders($headers)
            ->connectTimeout($this->connectTimeout)
            ->timeout($timeout ?? $this->timeout);

        try {
            $response = match ($method) {
                'GET' => $pending->get($this->baseUrl().$path),
                'POST' => $pending->post($this->baseUrl().$path, $body),
                'PUT' => $pending->put($this->baseUrl().$path, $body),
                'DELETE' => $pending->delete($this->baseUrl().$path, $body),
                default => throw new AgentException('Unsupported HTTP method '.$method),
            };
        } catch (ConnectionException $e) {
            throw new AgentUnreachableException(
                'RawPrint Agent is not reachable at '.$this->baseUrl(),
                'agent_unreachable',
                0,
                [],
                $e,
            );
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if ($response->successful()) {
            return $data;
        }

        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $code = (string) ($error['code'] ?? 'http_'.$response->status());
        $message = (string) ($error['message'] ?? $response->reason());

        if ($response->status() === 401 || $code === 'not_paired') {
            throw new NotPairedException($message, $code, $response->status(), $data);
        }

        throw new AgentException($message, $code, $response->status(), $data);
    }

    private function assertPayload(string $dataBase64): void
    {
        $decoded = base64_decode($dataBase64, true);

        if ($decoded === false) {
            throw new AgentException('data_base64 is not valid base64', 'invalid_payload');
        }

        if ($decoded === '') {
            throw new AgentException('print payload is empty', 'invalid_payload');
        }

        if (strlen($decoded) > $this->maxPayloadBytes) {
            throw new AgentException(
                sprintf('payload exceeds %d bytes', $this->maxPayloadBytes),
                'payload_too_large',
            );
        }
    }
}
