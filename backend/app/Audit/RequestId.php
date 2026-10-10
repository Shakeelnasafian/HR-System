<?php
namespace App\Audit;
use Illuminate\Support\Str;

/** Scoped correlation ID: one per HTTP request, artisan command or queued job (the worker forgets scoped instances per job). */
final class RequestId
{
    private ?string $id = null;
    private bool $http = false;

    /** Always server-generated; client-supplied request IDs are never stored. */
    public function assignForRequest(): string { $this->http = true; return $this->id = (string) Str::uuid(); }
    /** Console commands get a fresh ID unless they run inside an HTTP request (Artisan::call). */
    public function assignForCommand(): void { if (! $this->http) { $this->id = (string) Str::uuid(); } }
    public function current(): string { return $this->id ??= (string) Str::uuid(); }
}
