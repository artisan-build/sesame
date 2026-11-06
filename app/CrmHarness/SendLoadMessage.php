<?php

namespace App\CrmHarness;

use Aws\Exception\AwsException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Message;

/**
 * LOCAL CRM-COMPATIBLE HARNESS. Sends one message through the real Laravel
 * path: queued job -> Mailable -> stock ses-v2 transport -> SendEmail.
 */
final class SendLoadMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0; // bounded by the throttle backoff, not attempts

    public int $maxExceptions = 3;

    public function __construct(public readonly int $outboundId)
    {
        $this->onQueue('crm-send');
    }

    public function handle(): void
    {
        $row = DB::table('harness_outbound_messages')
            ->join('harness_contacts', 'harness_contacts.id', '=', 'harness_outbound_messages.contact_id')
            ->where('harness_outbound_messages.id', $this->outboundId)
            ->first(['harness_outbound_messages.*', 'harness_contacts.email', 'harness_contacts.suppressed_at']);
        if ($row === null || $row->state !== 'queued') {
            return; // accepted work is never resent
        }
        // Second guard behind the endpoint config: only the reserved load domain is ever addressed.
        if (! str_ends_with(strtolower($row->email), '@'.LoadContacts::DOMAIN)) {
            throw new RuntimeException('Refusing to send outside the reserved load domain.');
        }
        if ($row->suppressed_at !== null) {
            DB::table('harness_outbound_messages')->where('id', $row->id)->update(['state' => 'skipped_suppressed']);

            return;
        }
        DB::table('harness_outbound_messages')->where('id', $row->id)->whereNull('started_at')->update(['started_at' => Clock::now()]);

        Settings::configureMailer();
        try {
            $sent = Mail::mailer('crm-ses')->send(new LoadMessage($row->id, $row->contact_id, $row->email, $row->load_run));
        } catch (TransportException $e) {
            $aws = $e->getPrevious();
            if ($aws instanceof AwsException && $aws->getAwsErrorCode() === 'TooManyRequestsException') {
                // Back-pressure: release with backoff, do not fail.
                DB::table('harness_outbound_messages')->where('id', $row->id)->increment('throttled');
                $this->release(min(30, 1 + (int) $row->throttled));

                return;
            }
            DB::table('harness_outbound_messages')->where('id', $row->id)->update(['state' => 'failed', 'failure' => substr($e->getMessage(), 0, 500)]);

            return;
        }

        $original = $sent?->getOriginalMessage();
        $messageId = $original instanceof Message ? $original->getHeaders()->get('X-SES-Message-ID')?->getBodyAsString() : null;
        DB::transaction(function () use ($row, $messageId): void {
            DB::table('harness_outbound_messages')->where('id', $row->id)->update([
                'state' => 'accepted', 'provider_message_id' => $messageId, 'accepted_at' => Clock::now(),
            ]);
            if (is_string($messageId)) {
                Reconciler::apply($messageId); // events may already have arrived
            }
        });
    }
}
