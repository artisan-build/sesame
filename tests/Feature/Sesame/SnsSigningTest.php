<?php

use App\Models\SesameAccount;
use App\Sesame\Accounts;
use App\Sesame\Sns\Signer;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Str;

beforeEach(function () {
    sesameSetUp();
    $this->account = Accounts::create('signing', null, 'healthy', 100, 1)['account'];
});

function envelopeFor(SesameAccount $account, string $type): array
{
    $common = [
        'Type' => $type,
        'MessageId' => (string) Str::uuid(),
        'TopicArn' => $account->topic_arn,
        'Timestamp' => '2026-11-06T08:00:00.000Z',
        'SigningCertURL' => $account->certificateUrl(),
    ];

    return match ($type) {
        'Notification' => $common + ['Message' => json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'x']]), 'UnsubscribeURL' => 'https://sesame.test/sns/x/?Action=Unsubscribe'],
        default => $common + ['Message' => "line one\nline two", 'Token' => bin2hex(random_bytes(16)), 'SubscribeURL' => 'https://sesame.test/sns/x/?Action=ConfirmSubscription'],
    };
}

it('signs every SNS message type in a form the real AWS validator accepts', function (string $type) {
    $signed = Signer::sign(envelopeFor($this->account, $type), $this->account->private_key);

    $message = Message::fromJsonString(json_encode($signed));
    app(MessageValidator::class)->validate($message);

    expect($signed['SignatureVersion'])->toBe('2');
})->with(['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation']);

it('signs notifications with a Subject too', function () {
    $signed = Signer::sign(envelopeFor($this->account, 'Notification') + ['Subject' => 'Amazon SES Email Event Notification'], $this->account->private_key);

    app(MessageValidator::class)->validate(Message::fromJsonString(json_encode($signed)));
})->throwsNoExceptions();

it('fails validation when a signed field is altered', function () {
    $signed = Signer::sign(envelopeFor($this->account, 'Notification'), $this->account->private_key);
    $signed['Message'] = str_replace('Delivery', 'Bounce', $signed['Message']);

    app(MessageValidator::class)->validate(Message::fromJsonString(json_encode($signed)));
})->throws(InvalidSnsMessageException::class, 'signature is invalid');

it('fails validation when signed by another account', function () {
    $other = Accounts::create('other', null, 'healthy', 100, 1)['account'];
    $signed = Signer::sign(envelopeFor($this->account, 'Notification'), $other->private_key);

    app(MessageValidator::class)->validate(Message::fromJsonString(json_encode($signed)));
})->throws(InvalidSnsMessageException::class);

it('is rejected by a validator still bound to amazonaws.com', function () {
    $signed = Signer::sign(envelopeFor($this->account, 'Notification'), $this->account->private_key);

    (new MessageValidator(fn () => $this->account->certificate))->validate(Message::fromJsonString(json_encode($signed)));
})->throws(InvalidSnsMessageException::class, 'invalid domain');

it('serves the account certificate over the .pem URL', function () {
    $path = parse_url($this->account->certificateUrl(), PHP_URL_PATH);
    expect($path)->toEndWith('/cert.pem');

    $this->get($path)->assertOk()->assertSee('BEGIN CERTIFICATE', false);
});
