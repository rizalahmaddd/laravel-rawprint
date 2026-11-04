<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Pending;

use InvalidArgumentException;
use LogicException;
use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\Agent\JobStatus;
use RawPrint\Laravel\Agent\PrintResult;
use RawPrint\Laravel\Document\Document;
use RawPrint\Laravel\Document\PaperProfile;
use RawPrint\Laravel\RawPrintManager;

final class Job
{
    private string $jobId;

    private string $docName = '';

    private string $printer = '';

    private int $copies = 1;

    private ?string $bytes = null;

    private ?PaperProfile $profile = null;

    private ?string $view = null;

    private ?Document $document = null;

    /** @var array<string, mixed> */
    private array $viewData = [];

    public function __construct(?string $jobId = null, private readonly ?PaperProfile $defaultProfile = null)
    {
        $this->jobId = $jobId ?: 'job-'.bin2hex(random_bytes(12));
    }

    public static function make(?string $jobId = null, ?PaperProfile $defaultProfile = null): self
    {
        return new self($jobId, $defaultProfile);
    }

    public function id(string $jobId): self
    {
        if ($jobId === '' || strlen($jobId) > 128) {
            throw new InvalidArgumentException('job_id must be between 1 and 128 characters');
        }

        $this->jobId = $jobId;

        return $this;
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function name(string $docName): self
    {
        $this->docName = $docName;

        return $this;
    }

    public function printer(string $printer): self
    {
        $this->printer = $printer;

        return $this;
    }

    public function copies(int $copies): self
    {
        if ($copies < 1 || $copies > 10) {
            throw new InvalidArgumentException('copies must be between 1 and 10');
        }

        $this->copies = $copies;

        return $this;
    }

    public function profile(PaperProfile|array $profile): self
    {
        $this->profile = $profile instanceof PaperProfile
            ? $profile
            : PaperProfile::fromArray($profile);

        return $this;
    }

    public function document(Document $document): self
    {
        $this->document = $document;
        $this->bytes = null;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function view(string $view, array $data = []): self
    {
        $this->view = $view;
        $this->viewData = $data;
        $this->bytes = null;

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->bytes = $bytes;
        $this->view = null;
        $this->document = null;

        return $this;
    }

    public function bytes(): string
    {
        if ($this->bytes === null) {
            $this->bytes = $this->render();
        }

        if ($this->bytes === '') {
            throw new InvalidArgumentException('print payload is empty');
        }

        return $this->bytes;
    }

    private function render(): string
    {
        if ($this->document !== null) {
            return $this->document->bytes();
        }

        if ($this->view !== null) {
            $document = Document::make($this->profile ?? $this->defaultProfile);
            $document->text(app('view')->make($this->view, $this->viewData)->render());

            return $document->bytes();
        }

        if ($this->bytes !== null) {
            return $this->bytes;
        }

        throw new LogicException('Set a document, view or raw bytes on the print job first');
    }

    public function base64(): string
    {
        return base64_encode($this->bytes());
    }

    /** @return array{job_id: string, doc_name: string, printer: string, data_base64: string, copies: int} */
    public function payload(): array
    {
        return [
            'job_id' => $this->jobId,
            'doc_name' => $this->docName !== '' ? $this->docName : $this->jobId,
            'printer' => $this->printer,
            'data_base64' => $this->base64(),
            'copies' => $this->copies,
        ];
    }

    public function send(?AgentClient $client = null): PrintResult
    {
        $client ??= app(RawPrintManager::class)->client();

        return $client->print($this->jobId, $this->base64(), [
            'printer' => $this->printer,
            'doc_name' => $this->docName !== '' ? $this->docName : $this->jobId,
            'copies' => $this->copies,
        ]);
    }

    public function sendAndWait(
        ?AgentClient $client = null,
        int $timeoutMs = 20000,
        int $intervalMs = 1000,
    ): JobStatus {
        $client ??= app(RawPrintManager::class)->client();

        return $client->wait(
            $this->send($client)->jobId,
            $timeoutMs,
            $intervalMs,
        );
    }

    /**
     * Follows the configured driver: a direct request from PHP, or a payload
     * the browser bridge sends to the agent on this PC.
     *
     * @return array|PrintResult
     */
    public function dispatch(): array|PrintResult
    {
        $manager = app(RawPrintManager::class);

        return $manager->isDirect()
            ? $this->send($manager->client())
            : $this->payload();
    }
}
