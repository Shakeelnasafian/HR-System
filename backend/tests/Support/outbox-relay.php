<?php

use App\Messaging\OutboxRelay;
use Illuminate\Contracts\Console\Kernel;

// Standalone relay process used only by the concurrent claim test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $queue, $barrier] = $argv;
config(['outbox.queue' => $queue, 'outbox.system_context' => true]);
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
echo app(OutboxRelay::class)->run(3, 1000);
