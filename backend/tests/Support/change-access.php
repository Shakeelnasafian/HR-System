<?php

use App\Actions\Tenancy\ReplaceCompanyAccess;
use App\Services\Tenancy\CompanyAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $membership, $permissions, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(TenantContext::class)->run($tenant, (int) $actor, function () use ($company, $membership, $permissions) {
        // What CompanyAccessController::update does after its request authorized and validated: lock the company, run the action.
        $locked = app(CompanyAccess::class)->find($company, 'access.manage', true);
        app(ReplaceCompanyAccess::class)->handle($locked, $membership, ['version' => 1, 'reason' => 'Synthetic concurrent access review', 'permissions' => json_decode($permissions, true, 512, JSON_THROW_ON_ERROR)]);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
