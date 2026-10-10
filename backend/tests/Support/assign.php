<?php

use App\Actions\Workforce\AddAssignment;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Standalone restricted-role process used only by the concurrent reporting-line test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $employment, $manager, $date, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(TenantContext::class)->run($tenant, (int) $actor, function () use ($company, $employment, $manager, $date) {
        app(CompanyAccess::class)->find($company, 'workforce.write');
        app(AddAssignment::class)->handle($company, $employment, ['version' => 1, 'reason' => 'Synthetic concurrent reporting change', 'effective_from' => $date, 'manager_employment_id' => $manager]);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '201';
} catch (ValidationException $e) {
    echo '422';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
