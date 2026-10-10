<?php
// Standalone restricted-role process used only by the concurrent invitation acceptance test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $tenant, $invitation, $token, $user, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Concurrency barrier timed out.'); }
    usleep(10000);
}
try {
    // $user 'new' = create-account path (no session); otherwise the signed-in existing account.
    $password = 'synthetic-Passw0rd-123';
    $input = ['tenant'=>$tenant, 'invitation'=>$invitation, 'token'=>$token] + ($user === 'new' ? ['name'=>'Racer', 'password'=>$password, 'password_confirmation'=>$password] : []);
    $request = \Illuminate\Http\Request::create('/api/v1/invitations/accept', 'POST', $input);
    $request->headers->set('Origin', 'http://localhost'); // the SPA origin (stateful)
    app()->instance('request', $request); // security events read the current request (rebinding resets the user resolver)
    $request->setUserResolver(fn () => $user === 'new' ? null : \App\Models\User::findOrFail((int) $user)); // stands in for the session
    app(\App\Tenancy\InvitationAcceptance::class)->accept($request);
    echo '200';
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
