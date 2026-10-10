<?php
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Carbon;

/** Sent synchronously by the outbox handler; the outbox event id is the idempotency key (Message-ID and header). */
final class InvitationMail extends Mailable
{
    public function __construct(public string $url, public string $tenant, public string $company, public string $expiresAt, public string $eventId) {}

    public function envelope(): Envelope { return new Envelope(subject: 'You have been invited to '.$this->tenant); }
    public function headers(): Headers
    {
        return new Headers(messageId: 'invitation-'.$this->eventId.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            text: ['X-Idempotency-Key' => $this->eventId]);
    }
    public function content(): Content
    {
        $expires = Carbon::parse($this->expiresAt)->utc()->format('Y-m-d H:i').' UTC';
        return new Content(htmlString: '<p>You have been invited to join <strong>'.e($this->company).'</strong> in '.e($this->tenant).'.</p>'
            .'<p><a href="'.e($this->url).'">Accept the invitation</a></p><p>This link expires on '.e($expires).'. Only the most recent invitation email works. '
            .'If you did not expect this invitation, ignore this email.</p>');
    }
}
