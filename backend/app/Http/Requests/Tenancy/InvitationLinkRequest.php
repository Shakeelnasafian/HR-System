<?php

namespace App\Http\Requests\Tenancy;

use App\Actions\Tenancy\FindInvitationLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Unauthenticated invitation link (SPA session + CSRF, rate limited by IP). Nothing is validated up front: key() checks
 * the origin (403) and then the selector shape (404); acceptance validates name and password only for new accounts,
 * after the link and the signed-in account were checked.
 */
class InvitationLinkRequest extends FormRequest
{
    public function rules(): array
    {
        return [];
    }

    /** @return array{0:string,1:string,2:string} tenant, invitation, SHA-256 hex of the secret */
    public function key(): array
    {
        // Only the SPA origin gets a session and CSRF verification; refuse anything else instead of skipping CSRF.
        abort_unless(EnsureFrontendRequestsAreStateful::fromFrontend($this), 403, 'Open the invitation link in the application.');
        [$t,$i,$k] = [$this->input('tenant'), $this->input('invitation'), $this->input('token')];
        abort_unless(is_string($t) && Str::isUuid($t) && is_string($i) && Str::isUuid($i) && is_string($k) && preg_match('/^[A-Za-z0-9_-]{43}$/', $k), 404, FindInvitationLink::GONE);

        return [Str::lower($t), Str::lower($i), hash('sha256', $k)];
    }
}
