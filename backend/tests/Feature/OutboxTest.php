<?php

namespace Tests\Feature;

use App\Jobs\DeliverOutboxEvent;
use App\Services\Messaging\Outbox;
use App\Services\Messaging\OutboxDelivery;
use App\Services\Messaging\OutboxRelay;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\FoundationFixture;
use Tests\Support\OutboxProbeHandler;

class OutboxTest extends FoundationFixture
{
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.handlers' => ['test.probe' => OutboxProbeHandler::class], 'outbox.system_context' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $pattern) {
            foreach (glob($pattern) as $file) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function path(): string
    {
        return $this->files[] = storage_path('logs/outbox-'.Str::uuid().'.jsonl');
    }

    private function record(string $tenant, string $mode, ?string $path = null, ?string $key = null, string $type = 'test.probe'): string
    {
        return app(TenantContext::class)->run($tenant, $this->uid, fn () => Outbox::record($type, ['mode' => $mode, 'path' => $path ?? $this->path()], $key ?? (string) Str::uuid()));
    }

    private function event(string $id): object
    {
        return DB::connection('fixture')->table('outbox_events')->where('id', $id)->first();
    }

    private function attempts(string $id): array
    {
        return DB::connection('fixture')->table('outbox_attempts')->where('event_id', $id)->orderBy('attempt')->get()
            ->map(fn ($a) => [$a->attempt, $a->outcome, $a->finished_at !== null])->all();
    }

    /** Runs the relay with a fake queue and executes the dispatched jobs in-process. */
    private function relayAndDeliver(): array
    {
        Queue::fake();
        app(OutboxRelay::class)->run();

        return Queue::pushed(DeliverOutboxEvent::class)->map(fn ($job) => app(OutboxDelivery::class)->run($job->tenantId, $job->eventId, $job->attempt))->values()->all();
    }

    private function makeDue(string $id, string $column = 'available_at'): void
    {
        DB::connection('fixture')->table('outbox_events')->where('id', $id)->update([$column => DB::raw("now() - interval '1 second'")]);
    }

    public function test_record_commits_with_the_tenant_transaction_and_is_idempotent_per_tenant(): void
    {
        try {
            app(TenantContext::class)->run($this->t1, $this->uid, function () {
                Outbox::record('test.probe', ['mode' => 'ok'], 'rolled-back');
                throw new \RuntimeException('domain failure');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::connection('fixture')->table('outbox_events')->count());
        $first = $this->record($this->t1, 'ok', null, 'same-key');
        $this->assertSame($first, $this->record($this->t1, 'cancel', null, 'same-key'));
        $this->assertThrows(fn () => $this->record($this->t1, 'ok', null, 'same-key', 'test.other'), \LogicException::class, 'another event type');
        $other = $this->record($this->t2, 'ok', null, 'same-key');
        $this->assertNotSame($first, $other);
        $this->assertSame(2, DB::connection('fixture')->table('outbox_events')->count());
        $event = $this->event($first);
        $this->assertSame(['pending', 0, $this->uid, 'ok'], [$event->status, $event->attempts, $event->actor_id, json_decode($event->payload, true)['mode']]);
        $this->assertTrue(Str::isUuid($event->correlation_id));
    }

    public function test_record_fails_closed_outside_context_and_rejects_rich_payloads_or_foreign_companies(): void
    {
        $this->assertThrows(fn () => Outbox::record('test.probe', [], 'k'), \LogicException::class, 'Missing tenant context.');
        $this->assertThrows(fn () => app(TenantContext::class)->run($this->t1, $this->uid, fn () => Outbox::record('test.probe', ['employee' => ['name' => 'Alice']], 'k')), \InvalidArgumentException::class);
        $this->assertThrows(fn () => app(TenantContext::class)->run($this->t1, $this->uid, fn () => Outbox::record('Bad Type', [], 'k')), \InvalidArgumentException::class);
        $this->assertThrows(fn () => app(TenantContext::class)->run($this->t1, $this->uid, fn () => Outbox::record('test.probe', [], 'k', $this->other)), QueryException::class);
        $this->assertSame(0, DB::connection('fixture')->table('outbox_events')->count());
    }

    public function test_system_context_is_console_only_requires_an_active_tenant_and_has_no_principal(): void
    {
        $context = app(TenantContext::class);
        $t2Event = $this->record($this->t2, 'ok');
        $context->runSystem($this->t1, function () use ($context, $t2Event) {
            $this->assertTrue($context->isSystem());
            $this->assertSame(2, DB::table('companies')->count());
            $this->assertThrows(fn () => $context->userId(), \LogicException::class, 'Missing tenant principal.');
            $this->assertThrows(fn () => app(CompanyAccess::class)->query()->count(), \LogicException::class);
            $this->assertSame(0, DB::table('outbox_events')->count());
            $this->assertSame(0, DB::table('outbox_events')->where('id', $t2Event)->update(['status' => 'cancelled', 'lease_until' => null]));
            $this->assertThrows(fn () => DB::transaction(fn () => DB::table('outbox_attempts')->insert(['tenant_id' => $this->t2, 'event_id' => $t2Event, 'attempt' => 1])), QueryException::class);
            $this->assertThrows(fn () => $context->runSystem($this->t2, fn () => null), \LogicException::class);
            Outbox::record('test.probe', ['mode' => 'ok'], 'system');
            $this->assertNull(DB::table('outbox_events')->where('dedupe_key', 'system')->value('actor_id'));
        });
        $this->assertThrows(fn () => $context->id(), \LogicException::class);
        $this->assertSame('pending', $this->event($t2Event)->status);
        $this->assertThrows(fn () => $context->run($this->t1, $this->uid, fn () => $context->runSystem($this->t1, fn () => null)), \LogicException::class);
        $this->assertThrows(fn () => $context->runSystem('not-a-uuid', fn () => null), \LogicException::class);
        $this->assertThrows(fn () => $context->runSystem((string) Str::uuid(), fn () => null), HttpException::class);
        DB::connection('fixture')->table('tenants')->where('id', $this->t2)->update(['status' => 'suspended']);
        $this->assertThrows(fn () => $context->runSystem($this->t2, fn () => null), HttpException::class);
        $console = new \ReflectionProperty(app(), 'isRunningInConsole');
        $console->setValue(app(), false); // what an HTTP (FPM) process reports
        try {
            $this->assertThrows(fn () => $context->runSystem($this->t1, fn () => null), \LogicException::class, 'reserved for console');
        } finally {
            $console->setValue(app(), null);
        }
        config(['outbox.system_context' => false]); // web processes never opt in, even when they report a console SAPI
        $this->assertThrows(fn () => $context->runSystem($this->t1, fn () => null), \LogicException::class, 'reserved for console');
    }

    public function test_concurrent_relays_claim_each_event_exactly_once(): void
    {
        $ids = [];
        foreach (range(1, 30) as $i) {
            $ids[] = $this->record($i % 2 ? $this->t1 : $this->t2, 'ok');
        }
        $barrier = storage_path('logs/outbox-relay-'.Str::uuid());
        $this->files[] = $barrier.'*';
        $queue = 'outbox-'.Str::uuid();
        $processes = [];
        try {
            foreach ([1, 2] as $_) {
                $process = new Process([PHP_BINARY, 'tests/Support/outbox-relay.php', $queue, $barrier], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 20;
            while (count(glob($barrier.'.*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'.*'), 'Both relays must reach the barrier.');
            file_put_contents($barrier, 'go');
            $claimed = array_map(function (Process $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

                return (int) $p->getOutput();
            }, $processes);
            $this->assertSame(30, array_sum($claimed));
            $rows = DB::connection('fixture')->table('outbox_attempts')->get();
            $this->assertCount(30, $rows);
            $this->assertEqualsCanonicalizing($ids, $rows->pluck('event_id')->all());
            $this->assertSame([1], $rows->pluck('attempt')->unique()->values()->all());
            $this->assertSame(30, app('queue')->connection('redis')->size($queue));
            $this->assertSame(0, app(OutboxRelay::class)->run(), 'Leased events are not claimed again.');
        } finally {
            app('queue')->connection('redis')->clear($queue);
        }
    }

    public function test_successful_delivery_runs_handler_outside_transactions_and_marks_delivered(): void
    {
        $id = $this->record($this->t1, 'ok', $path = $this->path());
        $this->assertSame(['delivered'], $this->relayAndDeliver());
        $event = $this->event($id);
        $this->assertSame(['delivered', 1, null, null], [$event->status, $event->attempts, $event->lease_until, $event->last_error]);
        $this->assertNotNull($event->delivered_at);
        $this->assertSame([[1, 'delivered', true]], $this->attempts($id));
        $line = json_decode(file_get_contents($path), true);
        $this->assertSame([$this->t1, 2, $id, 1, false, 0, 0], [$line['tenant'], $line['companies'], $line['event'], $line['attempt'], $line['context'], $line['outside'], $line['level']]);
        $this->assertSame([], $this->relayAndDeliver());
        $this->assertSame(0, Artisan::call('hr:outbox-status'));
        $this->assertStringContainsString('delivered', Artisan::output());
        $this->assertStringNotContainsString($path, Artisan::output());
    }

    public function test_failures_back_off_then_fail_permanently_with_a_critical_alert(): void
    {
        config(['outbox.max_attempts' => 3, 'outbox.backoff_base' => 10]);
        Log::spy();
        $id = $this->record($this->t1, 'throw');
        $this->assertSame(['retry'], $this->relayAndDeliver());
        $event = $this->event($id);
        $this->assertSame(['pending', 1, null, 'RuntimeException'], [$event->status, $event->attempts, $event->lease_until, $event->last_error]);
        $this->assertTrue(DB::connection('fixture')->selectOne('SELECT available_at > now() + interval \'5 seconds\' AS v FROM outbox_events WHERE id = ?', [$id])->v);
        $this->assertSame([], $this->relayAndDeliver(), 'Backoff delays the next claim.');
        $this->makeDue($id);
        $this->assertSame(['retry'], $this->relayAndDeliver());
        $this->assertTrue(DB::connection('fixture')->selectOne('SELECT available_at > now() + interval \'15 seconds\' AS v FROM outbox_events WHERE id = ?', [$id])->v);
        $this->makeDue($id);
        $this->assertSame(['failed'], $this->relayAndDeliver());
        $this->assertSame(['failed', 3, null], [$this->event($id)->status, $this->event($id)->attempts, $this->event($id)->lease_until]);
        $this->assertSame([[1, 'retry', true], [2, 'retry', true], [3, 'failed', true]], $this->attempts($id));
        $this->assertSame(0, DB::connection('fixture')->table('outbox_attempts')->where('error', 'like', '%alice%')->count());
        Log::shouldHaveReceived('critical')->once()->with('Outbox event failed permanently.', ['tenant_id' => $this->t1, 'event_id' => $id, 'type' => 'test.probe']);
        $this->makeDue($id);
        $this->assertSame([], $this->relayAndDeliver());

        $unknown = $this->record($this->t1, 'ok', null, null, 'test.unknown');
        $this->assertSame(['failed'], $this->relayAndDeliver());
        $this->assertSame([[1, 'failed', true]], $this->attempts($unknown));
        $this->assertSame('Unknown outbox event type.', $this->event($unknown)->last_error);
    }

    public function test_prepare_returning_null_cancels_without_delivery(): void
    {
        $id = $this->record($this->t1, 'cancel', $path = $this->path());
        $this->assertSame(['cancelled'], $this->relayAndDeliver());
        $this->assertSame('cancelled', $this->event($id)->status);
        $this->assertSame([[1, 'cancelled', true]], $this->attempts($id));
        $this->assertFileDoesNotExist($path);
    }

    public function test_stale_jobs_for_an_older_attempt_are_no_ops(): void
    {
        $id = $this->record($this->t1, 'ok', $path = $this->path());
        Queue::fake();
        app(OutboxRelay::class)->run();
        $this->makeDue($id, 'lease_until'); // the first job never ran before its lease expired
        $this->assertSame(['delivered'], $this->relayAndDeliver());
        $delivery = app(OutboxDelivery::class);
        $this->assertSame('stale', $delivery->run($this->t1, $id, 1));
        $this->assertSame('stale', $delivery->run($this->t1, $id, 2), 'A duplicate of the acknowledged attempt is a no-op too.');
        $this->assertSame('stale', $delivery->run($this->t2, $id, 2), 'Another tenant cannot address the event.');
        $this->assertCount(1, file($path));
        $this->assertSame([[1, 'abandoned', true], [2, 'delivered', true]], $this->attempts($id));
    }

    public function test_a_job_whose_lease_is_nearly_expired_does_not_start_delivery(): void
    {
        config(['outbox.prepare_margin' => 30]);
        $id = $this->record($this->t1, 'ok', $path = $this->path());
        Queue::fake();
        app(OutboxRelay::class)->run();
        DB::connection('fixture')->table('outbox_events')->where('id', $id)->update(['lease_until' => DB::raw("now() + interval '10 seconds'")]);
        $this->assertSame('stale', app(OutboxDelivery::class)->run($this->t1, $id, 1));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([[1, null, false]], $this->attempts($id));
        config(['outbox.prepare_margin' => 5]);
        $this->assertSame('delivered', app(OutboxDelivery::class)->run($this->t1, $id, 1));
    }

    /** Claims the event and runs it in a real worker process that dies after the receiver saw it, before the ack. */
    private function crashOnce(string $id): void
    {
        $queue = 'outbox-'.Str::uuid();
        config(['outbox.queue' => $queue]);
        try {
            $this->assertSame(1, app(OutboxRelay::class)->run());
            $worker = new Process([PHP_BINARY, 'tests/Support/outbox-worker.php', $queue], base_path(), ['APP_ENV' => 'testing']);
            $worker->setTimeout(60);
            $worker->run();
            $this->assertSame(3, $worker->getExitCode(), $worker->getErrorOutput());
        } finally {
            app('queue')->connection('redis')->clear($queue);
            config(['outbox.queue' => null]);
        }
    }

    public function test_crash_on_the_last_attempt_fails_and_alerts_after_lease_expiry(): void
    {
        config(['outbox.max_attempts' => 1]);
        $id = $this->record($this->t1, 'crash-once', $path = $this->path());
        $this->crashOnce($id);
        Log::spy();
        $this->assertSame([], $this->relayAndDeliver(), 'Still leased to the crashed attempt.');
        Log::shouldNotHaveReceived('critical');
        $this->makeDue($id, 'lease_until');
        $this->assertSame([], $this->relayAndDeliver());
        $event = $this->event($id);
        $this->assertSame(['failed', 1, null], [$event->status, $event->attempts, $event->lease_until]);
        $this->assertSame([[1, 'abandoned', true]], $this->attempts($id));
        $this->assertCount(1, file($path));
        Log::shouldHaveReceived('critical')->once()->with('Outbox event failed permanently.', ['tenant_id' => $this->t1, 'event_id' => $id, 'type' => 'test.probe']);
    }

    public function test_crash_between_delivery_and_ack_is_redelivered_after_lease_expiry(): void
    {
        $id = $this->record($this->t1, 'crash-once', $path = $this->path());
        $this->crashOnce($id);
        $this->assertCount(1, file($path));
        $this->assertSame([[1, null, false]], $this->attempts($id));
        $this->assertSame('pending', $this->event($id)->status);
        $this->assertSame([], $this->relayAndDeliver(), 'Still leased to the crashed attempt.');
        $this->makeDue($id, 'lease_until');
        $this->assertSame(['delivered'], $this->relayAndDeliver());
        $lines = array_map(fn ($l) => json_decode($l, true), file($path));
        $this->assertSame([[$id, 1], [$id, 2]], array_map(fn ($l) => [$l['event'], $l['attempt']], $lines), 'Receivers see the same event id twice and deduplicate on it.');
        $this->assertSame([[1, 'abandoned', true], [2, 'delivered', true]], $this->attempts($id));
    }

    public function test_long_lived_worker_alternating_tenants_does_not_leak_context(): void
    {
        $path = $this->path();
        $queue = 'outbox-'.Str::uuid();
        $plan = [[$this->t1, 'ok'], [$this->t2, 'ok'], [$this->t1, 'throw'], [$this->t2, 'ok'], [$this->t1, 'ok']];
        $ids = array_map(fn ($p) => $this->record($p[0], $p[1], $path), $plan);
        try {
            foreach ($plan as [$tenant]) {
                foreach (app(OutboxRelay::class)->claim($tenant, 1) as $row) {
                    DeliverOutboxEvent::dispatch($tenant, $row->event_id, $row->attempt)->onQueue($queue);
                }
            }
            (new Process([PHP_BINARY, 'tests/Support/outbox-worker.php', $queue], base_path(), ['APP_ENV' => 'testing']))->setTimeout(60)->mustRun();
        } finally {
            app('queue')->connection('redis')->clear($queue);
        }
        $rows = array_map(fn ($l) => json_decode($l, true), file($path));
        $this->assertSame([[$this->t1, 2], [$this->t2, 1], [$this->t2, 1], [$this->t1, 2]], array_map(fn ($r) => [$r['tenant'], $r['companies']], $rows));
        $this->assertSame([[false, 0, 0]], array_values(array_unique(array_map(fn ($r) => [$r['context'], $r['outside'], $r['level']], $rows), SORT_REGULAR)));
        $this->assertCount(1, array_unique(array_column($rows, 'pid')));
        $this->assertSame(['delivered', 'delivered', 'pending', 'delivered', 'delivered'], array_map(fn ($id) => $this->event($id)->status, $ids));
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
