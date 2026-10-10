<?php

namespace App\Console\Commands;

use App\Services\Messaging\OutboxRelay;
use Illuminate\Console\Command;

class RelayOutbox extends Command
{
    protected $signature = 'hr:outbox-relay {--batch=} {--max-per-tenant=}';

    protected $description = 'Lease due outbox events per active tenant and dispatch delivery jobs';

    public function handle(OutboxRelay $relay): void
    {
        $option = fn (string $name) => $this->option($name) ? (int) $this->option($name) : null;
        $this->line('Dispatched '.$relay->run($option('batch'), $option('max-per-tenant')).' outbox event(s).');
    }
}
