<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\Agent\PrintResult;
use RawPrint\Laravel\Jobs\PrintDocument;
use RawPrint\Laravel\Tests\TestCase;
use RuntimeException;

final class JobTest extends TestCase
{
    public function test_raw_payload_has_the_shape_the_agent_expects(): void
    {
        $payload = rawprint()->job('invoice-1')->raw("Hello\nWorld")->name('Invoice')->payload();

        $this->assertSame('invoice-1', $payload['job_id']);
        $this->assertSame('Invoice', $payload['doc_name']);
        $this->assertSame("Hello\nWorld", base64_decode($payload['data_base64'], true));
        $this->assertSame(1, $payload['copies']);
        $this->assertSame('', $payload['printer']);
    }

    public function test_view_is_rendered_with_the_configured_margins(): void
    {
        $job = rawprint()->job('invoice-2')->view('invoice', ['name' => 'Budi']);

        $bytes = $job->bytes();

        $this->assertStringContainsString('Invoice for Budi', $bytes);
        $this->assertStringStartsWith("\x1b\x40", $bytes);
    }

    public function test_document_builder_is_used_for_view_rendering(): void
    {
        $job = rawprint()->job('invoice-3')
            ->view('invoice', ['name' => 'Siti'])
            ->profile(['cpi' => 10, 'top_margin_lines' => 0, 'left_margin_cols' => 2]);

        $bytes = $job->bytes();

        $this->assertStringContainsString("\x1b\x50", $bytes);
        $this->assertStringContainsString('  Invoice for Siti', $bytes);
    }

    public function test_queue_is_rendered_by_the_blade_component(): void
    {
        rawprint()->queue(rawprint()->job('queued-1')->raw('x'));

        $html = (string) $this->blade('<x-rawprint />');

        $this->assertStringContainsString('data-rawprint-config', $html);
        $this->assertStringContainsString('queued-1', $html);
        $this->assertStringContainsString('rawprint-bridge.js', $html);
    }

    public function test_dispatch_returns_a_payload_in_browser_mode(): void
    {
        config(['rawprint.driver' => 'browser']);

        $result = rawprint()->job('browser-1')->raw('x')->dispatch();

        $this->assertIsArray($result);
        $this->assertSame('browser-1', $result['job_id']);
    }

    public function test_dispatch_sends_the_job_in_direct_mode(): void
    {
        config(['rawprint.driver' => 'direct']);

        Http::fake(['*' => Http::response([
            'job' => ['job_id' => 'direct-1', 'state' => 'queued'],
        ], 202)]);

        $result = rawprint()->job('direct-1')->raw('x')->dispatch();

        $this->assertInstanceOf(PrintResult::class, $result);
        $this->assertSame('direct-1', $result->jobId);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/print')
            && $request['job_id'] === 'direct-1');
    }

    public function test_the_agent_client_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(AgentClient::class, app(AgentClient::class));
    }

    public function test_empty_payload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        rawprint()->job('empty')->raw('')->payload();
    }

    public function test_manager_is_scoped_per_request(): void
    {
        $first = app(\RawPrint\Laravel\RawPrintManager::class);
        $this->assertSame($first, app(\RawPrint\Laravel\RawPrintManager::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(\RawPrint\Laravel\RawPrintManager::class));
    }

    public function test_queue_is_rejected_in_direct_mode(): void
    {
        config(['rawprint.driver' => 'direct']);

        $this->expectException(LogicException::class);

        rawprint()->queue(rawprint()->job('queued-direct')->raw('x'));
    }

    public function test_queued_job_throws_when_wait_reports_a_failure(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['job' => ['job_id' => 'fail-1', 'state' => 'queued']], 202)
            ->push(['job' => ['job_id' => 'fail-1', 'state' => 'error', 'detail' => 'paper out']], 200)]);

        $job = new PrintDocument('fail-1', base64_encode('x'), wait: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paper out');

        $job->handle(app(AgentClient::class));
    }

    public function test_copies_must_stay_between_one_and_ten(): void
    {
        $this->expectException(InvalidArgumentException::class);

        rawprint()->job('invoice-4')->copies(11);
    }

    public function test_job_id_must_be_short_enough(): void
    {
        $this->expectException(InvalidArgumentException::class);

        rawprint()->job('invoice-5')->id(str_repeat('a', 129));
    }

    public function test_queued_print_job_can_be_handled(): void
    {
        Http::fake(['*' => Http::response([
            'job' => ['job_id' => 'queued-direct', 'state' => 'queued'],
        ], 202)]);

        $job = PrintDocument::fromPayload([
            'job_id' => 'queued-direct',
            'data_base64' => base64_encode('x'),
            'doc_name' => 'Invoice',
        ]);

        $job->handle(app(AgentClient::class));

        Http::assertSent(fn ($request) => $request['job_id'] === 'queued-direct'
            && $request['doc_name'] === 'Invoice');
    }
}
