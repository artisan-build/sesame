<?php

use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use Aws\SesV2\Exception\SesV2Exception;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    sesameSetUp();
    $created = Accounts::create('ingest', null, 'healthy', 1000, 99);
    AccountDirectory::write();
    $this->akid = $created['access_key_id'];
    $this->account = $created['account'];
});

function rawMime(string $to, string $extraHeaders = ''): string
{
    return "From: Growth <growth@load.crm.test>\r\nTo: {$to}\r\nSubject: Hi\r\n there\r\nMessage-ID: <m1@load.crm.test>\r\nDate: Fri, 06 Nov 2026 08:00:00 +0000\r\n{$extraHeaders}MIME-Version: 1.0\r\n\r\nBody that is never parsed\r\nFrom: not-a-header@x.test\r\n";
}

it('accepts a Laravel-shaped SendEmail through the real SDK and returns an SES-shaped MessageId', function (): void {
    $result = sesClient($this, $this->akid)->sendEmail([
        'Source' => 'growth@load.crm.test', // Laravel's v1-style key, tolerated
        'ConfigurationSetName' => 'crm-load',
        'EmailTags' => [['Name' => 'outbound_message_id', 'Value' => '17']],
        'Destination' => ['ToAddresses' => ['success+1@load.crm.test']],
        'Content' => ['Raw' => ['Data' => rawMime('success+1@load.crm.test')]],
    ]);

    expect($result['MessageId'])->toMatch('/\A0100[0-9a-f]{12}-[0-9a-f-]{36}-000000\z/');

    $records = walRecords();
    expect($records)->toHaveCount(1);
    $record = $records[0];
    expect($record['id'])->toBe($result['MessageId'])
        ->and($record['cs'])->toBe('crm-load')
        ->and($record['tags'])->toBe(['outbound_message_id' => ['17']])
        ->and($record['ch']['subject'])->toBe('Hi there') // folded header unfolded
        ->and($record['ch']['messageId'])->toBe('<m1@load.crm.test>')
        ->and(collect($record['hdr'])->pluck('value')->all())->not->toContain('not-a-header@x.test') // body not parsed
        ->and(array_column($record['ev'], 'type'))->toBe(['Send', 'Delivery']);
});

it('makes no database write on the ingest path', function (): void {
    DB::enableQueryLog();
    sesClient($this, $this->akid)->sendEmail([
        'Destination' => ['ToAddresses' => ['success@load.crm.test']],
        'Content' => ['Raw' => ['Data' => rawMime('success@load.crm.test')]],
    ]);

    expect(collect(DB::getQueryLog())->pluck('query')->reject(fn ($q): bool => str_starts_with(strtolower(ltrim($q)), 'select'))->all())->toBe([]);
});

it('returns SES errors the SDK maps to their exception codes', function (callable $request, string $code, int $status): void {
    try {
        $request($this);
        $this->fail('expected an SES error');
    } catch (SesV2Exception $e) {
        expect($e->getAwsErrorCode())->toBe($code)->and($e->getStatusCode())->toBe($status);
    }
})->with([
    'unknown access key' => [fn ($t) => sesClient($t, 'AKIAUNKNOWN000000000')->sendEmail(['Destination' => ['ToAddresses' => ['a@load.crm.test']], 'Content' => ['Raw' => ['Data' => rawMime('a@load.crm.test')]]]), 'UnrecognizedClientException', 403],
    'malformed address' => [fn ($t) => sesClient($t, $t->akid)->sendEmail(['Destination' => ['ToAddresses' => ['not an address']], 'Content' => ['Raw' => ['Data' => rawMime('x')]]]), 'BadRequestException', 400],
    'missing recipient' => [fn ($t) => sesClient($t, $t->akid)->sendEmail(['Destination' => ['ToAddresses' => []], 'Content' => ['Raw' => ['Data' => "Subject: x\r\n\r\nbody"]]]), 'BadRequestException', 400],
]);

it('throttles above the account max send rate and logs the refusal', function (): void {
    $this->account->forceFill(['max_send_rate' => 2])->save();
    AccountDirectory::write();
    $client = sesClient($this, $this->akid);
    $codes = [];
    // Stay inside one wall-clock second so the window is a single bucket.
    while (microtime(true) - floor(microtime(true)) > 0.7) {
        Sleep::usleep(10000);
    }
    for ($i = 0; $i < 4; $i++) {
        try {
            $client->sendEmail(['Destination' => ['ToAddresses' => ["load-{$i}@load.crm.test"]], 'Content' => ['Raw' => ['Data' => rawMime("load-{$i}@load.crm.test")]]]);
            $codes[] = 'ok';
        } catch (SesV2Exception $e) {
            $codes[] = $e->getAwsErrorCode();
        }
    }

    expect($codes)->toBe(['ok', 'ok', 'TooManyRequestsException', 'TooManyRequestsException'])
        ->and(collect(walRecords())->where('k', 'throttled'))->toHaveCount(2);
});

it('rejects at send time when the lottery draws Reject', function (): void {
    $this->account->forceFill(['rate_overrides' => ['reject' => 100]])->save();
    AccountDirectory::write();

    expect(fn () => sesClient($this, $this->akid)->sendEmail(['Destination' => ['ToAddresses' => ['load-1@load.crm.test']], 'Content' => ['Raw' => ['Data' => rawMime('load-1@load.crm.test')]]]))
        ->toThrow(SesV2Exception::class, 'rejected');
    expect(collect(walRecords())->pluck('k')->all())->toBe(['rejected']);
});
