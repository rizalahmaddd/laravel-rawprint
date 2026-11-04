<?php

declare(strict_types=1);

namespace RawPrint\Laravel\View\Components;

use Illuminate\View\Component;
use RawPrint\Laravel\Pending\Job;
use RawPrint\Laravel\RawPrintManager;

final class RawPrint extends Component
{
    /** @param array<int, Job|array<string, mixed>> $jobs */
    public function __construct(
        public ?string $appName = null,
        public ?string $baseUrl = null,
        public ?bool $autoSend = null,
        public ?bool $prompt = null,
        public array $jobs = [],
    ) {
    }

    public function render()
    {
        $manager = app(RawPrintManager::class);

        $jobs = [];

        foreach (array_merge($manager->queued(), $this->normalizeJobs($this->jobs)) as $payload) {
            $jobs[$payload['job_id']] = $payload;
        }

        return app('view')->make('rawprint::components.rawprint', [
            'config' => [
                'baseUrl' => $this->baseUrl ?: $manager->baseUrl(),
                'appName' => $this->appName ?: $manager->appName(),
                'autoSend' => $this->autoSend ?? (bool) config('rawprint.auto_send', true),
                'prompt' => $this->prompt ?? (bool) config('rawprint.prompt', true),
                'jobs' => array_values($jobs),
            ],
            'sources' => [
                asset('vendor/rawprint/rawprint.js'),
                asset('vendor/rawprint/rawprint-bridge.js'),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeJobs(array $jobs): array
    {
        $out = [];

        foreach ($jobs as $job) {
            if ($job instanceof Job) {
                $out[] = $job->payload();
            } elseif (is_array($job)) {
                $out[] = $job;
            }
        }

        return $out;
    }
}
