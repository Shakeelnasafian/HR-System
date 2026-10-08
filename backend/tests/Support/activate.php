<?php
// Standalone restricted-role process used only by the concurrency integration test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $employment, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Concurrency barrier timed out.'); }
    usleep(10000);
}
try {
    app(\App\Tenancy\TenantContext::class)->run($tenant, (int)$actor, function () use ($company, $employment) {
        $request = \Illuminate\Http\Request::create('/', 'POST', ['version'=>1, 'reason'=>'Synthetic concurrent activation']);
        app(\App\Workforce\WorkforceController::class)->transition($request, $company, $employment, 'activate');
    });
    echo '200';
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
