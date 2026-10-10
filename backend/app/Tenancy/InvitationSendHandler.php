<?php

namespace App\Tenancy;

use App\Mail\InvitationMail;
use App\Messaging\OutboxHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * invitation.send {invitation, send}. prepare mints a fresh 32-byte secret for the current send only and stores its SHA-256;
 * a resend (newer send counter), cancel, accept, expiry or an inviter who no longer qualifies cancels the event. A redelivery rotates again, so only the
 * newest emailed link works. The secret exists only in memory and in the email.
 */
final class InvitationSendHandler implements OutboxHandler
{
    public function prepare(object $event): mixed
    {
        [$id,$send] = [$event->payload['invitation'] ?? null, $event->payload['send'] ?? null];
        if (! is_string($id) || ! Str::isUuid($id) || ! is_int($send)) {
            return null;
        }
        $tenant = app(TenantContext::class)->id();
        $row = DB::table('invitations as i')->join('companies as c', fn ($j) => $j->on('c.tenant_id', '=', 'i.tenant_id')->on('c.id', '=', 'i.company_id'))
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')->where('i.tenant_id', $tenant)->where('i.id', $id)->where('i.status', 'pending')->where('i.send', $send)
            ->whereRaw('i.expires_at > clock_timestamp()')->whereRaw('hr_invitation_inviter_qualifies(i.tenant_id, i.company_id, i.invited_by, i.permissions)')->lock('FOR UPDATE OF i')->first(['i.email', 'i.expires_at', 'c.name as company', 't.name as tenant']);
        if (! $row) {
            return null;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::table('invitations')->where('tenant_id', $tenant)->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'updated_at' => now()]);

        return ['email' => $row->email, 'company' => $row->company, 'tenant' => $row->tenant, 'expires_at' => $row->expires_at,
            // Parameters travel in the fragment, which browsers never send to servers or put in Referer headers.
            'url' => rtrim((string) config('app.url'), '/').'/invitations/accept#'.http_build_query(['tenant' => $tenant, 'invitation' => $id, 'token' => $token])];
    }

    public function deliver(object $event, mixed $prepared): void
    {
        Mail::to($prepared['email'])->send(new InvitationMail($prepared['url'], $prepared['tenant'], $prepared['company'], $prepared['expires_at'], $event->id));
    }
}
