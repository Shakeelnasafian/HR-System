<?php

use App\Actions\Workforce\TransitionEmployment;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Standalone restricted-role process used only by the concurrency integration test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $employment, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(TenantContext::class)->run($tenant, (int) $actor, function () use ($company, $employment) {
        $row = app(CompanyAccess::class)->find($company, 'workforce.write');
        app(TransitionEmployment::class)->handle($row, $employment, 'activate', ['version' => 1, 'reason' => 'Synthetic concurrent activation']);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
