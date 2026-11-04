<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests\Feature;

use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\Tests\TestCase;

/**
 * Runs against a real agent. Start one with:
 *
 *   go run ./cmd/rawprint-agent run -config dev/config.json \
 *     -file-spool ./out -file-printers "EPSON LX-310 ESC/P"
 *
 * then:
 *
 *   RAWPRINT_LIVE_URL=http://127.0.0.1:18310 RAWPRINT_LIVE_TOKEN=... \
 *     vendor/bin/phpunit --filter LiveAgentTest
 */
final class LiveAgentTest extends TestCase
{
    public function test_health_print_and_job_status(): void
    {
        $url = getenv('RAWPRINT_LIVE_URL');
        $token = getenv('RAWPRINT_LIVE_TOKEN');

        if ($url === false || $url === '' || $token === false || $token === '') {
            $this->markTestSkipped('Set RAWPRINT_LIVE_URL and RAWPRINT_LIVE_TOKEN to run against a live agent.');
        }

        $client = new AgentClient($url, $token, 'https://pos.test', maxPayloadBytes: 2 * 1024 * 1024);

        $health = $client->health();
        $this->assertSame('rawprint-agent', $health['name']);
        $this->assertTrue($health['paired']);

        $payload = "Hello line 1\r\nHello line 2\r\n\f";
        $result = $client->print('php-e2e-'.bin2hex(random_bytes(4)), base64_encode($payload), [
            'printer' => 'EPSON LX-310 ESC/P',
            'doc_name' => 'Laravel E2E',
        ]);

        $this->assertTrue($result->accepted());

        $status = $client->wait($result->jobId, 5000, 50);

        $this->assertTrue($status->done(), 'job state: '.$status->state.' '.($status->detail ?? ''));
    }
}
