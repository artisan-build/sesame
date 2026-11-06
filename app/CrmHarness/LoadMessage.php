<?php

namespace App\CrmHarness;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. A personalised one-recipient Mailable of
 * about 24 KB, like Growth's fixture. The CRM's own IDs ride in metadata(),
 * which Laravel's ses-v2 transport turns into EmailTags.
 */
final class LoadMessage extends Mailable
{
    public function __construct(public readonly int $outboundId, public readonly int $contactId, public readonly string $email, public readonly string $run) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('growth@load.crm.test', 'Growth Load'),
            to: [$this->email],
            subject: "Load message {$this->outboundId}",
            metadata: ['outbound_message_id' => (string) $this->outboundId, 'contact_id' => (string) $this->contactId, 'load_run' => $this->run],
        );
    }

    public function content(): Content
    {
        $paragraph = '<p>Hello '.e($this->email).', this is synthetic load traffic for Sesame. '.str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 6).'</p>';

        return new Content(htmlString: '<html><body>'.str_repeat($paragraph, 60).'</body></html>');
    }
}
