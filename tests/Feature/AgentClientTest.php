<?php

declare(strict_types=1);

namespace RawPrint\Laravel\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RawPrint\Laravel\Agent\AgentClient;
use RawPrint\Laravel\Agent\AgentException;
use RawPrint\Laravel\Agent\AgentUnreachableException;
use RawPrint\Laravel\Agent\NotPairedException;
use RawPrint\Laravel\Document\PaperProfile;
use RawPrint\Laravel\Tests\TestCase;

final class AgentClientTest extends TestCase
{
    public function test_print_sends_token_origin_and_payload(): void
    {
        Http::fake(['*' => Http::response([
            'job' => ['job_id' => 'inv-1', 'printer' => 'LX-310', 'state' => 'queued', 'spool_job_id' => 7],
            'duplicate' => false,
        ], 202)]);

        $client = new AgentClient('http://127.0.0.1:18310', 'secret', 'https://pos.test');
        $result = $client->print('inv-1', base64_encode('hello'), ['copies' => 2, 'doc_name' => 'Invoice']);

        $this->assertSame('inv-1', $result->jobId);
        $this->assertSame(7, $result->spoolJobId);
        $this->assertTrue($result->accepted());

        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:18310/print'
            && $request->header('X-Agent-Token') === ['secret']
            && $request->header('Origin') === ['https://pos.test']
            && $request['job_id'] === 'inv-1'
            && $request['copies'] === 2
            && $request['doc_name'] === 'Invoice');
    }

    public function test_health_returns_the_agent_state(): void
    {
        Http::fake(['*' => Http::response(['name' => 'rawprint-agent', 'paired' => true])]);

        $health = (new AgentClient())->health();

        $this->assertTrue($health['paired']);
    }

    public function test_not_paired_is_mapped_to_a_typed_exception(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'not_paired', 'message' => 'pair first'],
        ], 401)]);

        $this->expectException(NotPairedException::class);

        (new AgentClient())->print('inv-1', base64_encode('x'));
    }

    public function test_error_body_is_exposed_on_the_exception(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'printer_not_found', 'message' => 'no printer'],
        ], 404)]);

        try {
            (new AgentClient())->print('inv-1', base64_encode('x'));
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('printer_not_found', $e->errorCode);
            $this->assertSame(404, $e->status);
            $this->assertSame('no printer', $e->getMessage());
        }
    }

    public function test_invalid_base64_is_rejected_before_sending(): void
    {
        Http::fake();

        try {
            (new AgentClient())->print('inv-1', 'not base64!!');
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('invalid_payload', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_payload_size_is_checked_before_sending(): void
    {
        Http::fake();

        try {
            (new AgentClient(maxPayloadBytes: 4))->print('inv-1', base64_encode('12345'));
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('payload_too_large', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_empty_payload_is_rejected_before_sending(): void
    {
        Http::fake();

        try {
            (new AgentClient())->print('inv-1', base64_encode(''));
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('invalid_payload', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_job_id_is_validated_before_sending(): void
    {
        Http::fake();

        try {
            (new AgentClient())->print('', base64_encode('x'));
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('invalid_job', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_copies_are_validated_before_sending(): void
    {
        Http::fake();

        try {
            (new AgentClient())->print('inv-1', base64_encode('x'), ['copies' => 11]);
            $this->fail('Expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame('invalid_job', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_unreachable_agent_is_mapped_to_a_typed_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->expectException(AgentUnreachableException::class);

        (new AgentClient())->health();
    }

    public function test_wait_returns_when_the_job_is_done(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['job' => ['job_id' => 'j-1', 'state' => 'queued']], 200)
            ->push(['job' => ['job_id' => 'j-1', 'state' => 'done']], 200)]);

        $status = (new AgentClient())->wait('j-1', 1000, 1);

        $this->assertTrue($status->done());
    }

    public function test_profile_query_is_url_encoded(): void
    {
        Http::fake(['*' => Http::response(['printer' => 'LX 310', 'profile' => []])]);

        (new AgentClient())->profile('LX 310');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/profile?printer=LX%20310'));
    }

    public function test_save_profile_sends_a_put_request(): void
    {
        Http::fake(['*' => Http::response(['printer' => 'LX', 'profile' => []])]);

        (new AgentClient())->saveProfile(new PaperProfile(cpi: 10), 'LX');

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'http://127.0.0.1:18310/profile'
            && $request['printer'] === 'LX'
            && $request['profile']['cpi'] === 10);
    }
}
