<?php

namespace App\Actions\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Resolves an invitation link outside any tenant context. Validity is decided only by the SECURITY DEFINER function;
 * every unusable invitation, wrong token or malformed selector yields the same 404.
 */
final class FindInvitationLink
{
    public const GONE = 'This invitation is invalid or has expired.';

    /** @param  array{0:string,1:string,2:string}  $key  tenant, invitation, SHA-256 hex of the secret */
    public function handle(array $key): object
    {
        // Definer function: the runtime role cannot read invitations without a tenant context.
        $row = DB::selectOne('SELECT * FROM hr_preview_invitation(?::uuid, ?::uuid, ?)', $key);
        abort_unless($row, 404, self::GONE);

        return $row;
    }
}
