<?php

use App\CrmHarness\CrmHarnessServiceProvider;
use App\CrmHarness\LoadContacts;
use App\CrmHarness\SendLoadMessage;
use App\Sesame\Accounts;
use App\Sesame\Sns\Signer;

it('refuses to boot production with simulator config', function (string $key, string $value) {
    config([$key => $value]);

    CrmHarnessServiceProvider::guard(true);
})->with([
    ['services.ses.endpoint', 'https://sesame.test'],
    ['services.sns.cert_host_pattern', '/^sesame\.test$/'],
])->throws(RuntimeException::class, 'SES simulator config present in production.');

it('boots production without simulator config and staging with it', function () {
    config(['services.ses.endpoint' => null, 'services.sns.cert_host_pattern' => null]);
    CrmHarnessServiceProvider::guard(true);

    config(['services.ses.endpoint' => 'https://sesame.test']);
    CrmHarnessServiceProvider::guard(false);
})->throwsNoExceptions();

it('refuses to seed load contacts in production', function () {
    app()->detectEnvironment(fn () => 'production');

    LoadContacts::seed(1);
})->throws(RuntimeException::class);

it('refuses to address anything outside the reserved load domain', function () {
    $contact = DB::table('harness_contacts')->insertGetId(['email' => 'someone@example.com']);
    $id = DB::table('harness_outbound_messages')->insertGetId(['contact_id' => $contact, 'load_run' => 'x', 'dispatched_at' => now()]);

    (new SendLoadMessage($id))->handle();
})->throws(RuntimeException::class, 'reserved load domain');

it('answers 403 to a missing type header or bad signature, and 200 to a duplicate', function () {
    sesameSetUp();
    $account = Accounts::create('webhook', null, 'healthy', 10, 1)['account'];
    $envelope = Signer::sign([
        'Type' => 'Notification', 'MessageId' => (string) Str::uuid(), 'TopicArn' => $account->topic_arn,
        'Timestamp' => '2026-11-06T08:00:00.000Z', 'SigningCertURL' => $account->certificateUrl(),
        'Message' => json_encode(['eventType' => 'Send', 'mail' => ['messageId' => 'abc', 'destination' => ['a@load.crm.test']]]),
        'UnsubscribeURL' => 'https://sesame.test/x',
    ], $account->private_key);
    $post = fn (array $body, array $headers = ['x-amz-sns-message-type' => 'Notification']) => $this->call('POST', '/crm-harness/sns', [], [], [],
        $this->transformHeadersToServerVars($headers + ['Content-Type' => 'text/plain; charset=UTF-8']), json_encode($body));

    expect($post($envelope, [])->status())->toBe(403)
        ->and($post(['Signature' => base64_encode('forged')] + $envelope)->status())->toBe(403)
        ->and($post($envelope)->status())->toBe(200)
        ->and($post($envelope)->status())->toBe(200)
        ->and(DB::table('harness_sns_receipts')->count())->toBe(1)
        ->and(DB::table('harness_message_events')->count())->toBe(1);
});
