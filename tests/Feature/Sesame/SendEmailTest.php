<?php

use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Ingest\IngestBuffer;
use Aws\SesV2\Exception\SesV2Exception;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    sesameSetUp();
    $created = Accounts::create('ingest', null, 'healthy', 1000, 99);
    AccountDirectory::forget();
    $this->akid = $created['access_key_id'];
    $this->account = $created['account'];
});

function rawMime(string $to, string $extraHeaders = ''): string
{
    return "From: Growth <growth@load.crm.test>\r\nTo: {$to}\r\nSubject: Hi\r\n there\r\nMessage-ID: <m1@load.crm.test>\r\nDate: Fri, 06 Nov 2026 08:00:00 +0000\r\n{$extraHeaders}MIME-Version: 1.0\r\n\r\nBody that is never parsed\r\nFrom: not-a-header@x.test\r\n";
}

it('accepts a Laravel-shaped SendEmail through the real SDK and returns an SES-shaped MessageId', function () {
    holdDrains($this);
    $result = sesClient($this, $this->akid)->sendEmail([
        'Source' => 'growth@load.crm.test', // Laravel's v1-style key, tolerated
        'ConfigurationSetName' => 'crm-load',
        'EmailTags' => [['Name' => 'outbound_message_id', 'Value' => '17']],
        'Destination' => ['ToAddresses' => ['success+1@load.crm.test']],
        'Content' => ['Raw' => ['Data' => rawMime('success+1@load.crm.test')]],
    ]);

    // SES's shape, carrying the buffer that accepted it and its sequence number there.
    expect($result['MessageId'])->toMatch('/\A0100[0-9a-f]{12}-[0-9a-f-]{36}-[0-9a-f]{6}\z/')
        ->and(IngestBuffer::parseMessageId($result['MessageId']))->toBe(['buffer' => IngestBuffer::id(), 'seq' => 1]);

    $sends = bufferRows()['sends'];
    expect($sends)->toHaveCount(1);
    $record = $sends[0];
    expect($record['cs'])->toBe('crm-load')
        ->and($record['tags'])->toBe(['outbound_message_id' => ['17']])
        ->and($record['ch']['subject'])->toBe('Hi there') // folded header unfolded
        ->and($record['ch']['messageId'])->toBe('<m1@load.crm.test>')
        ->and(collect($record['hdr'])->pluck('value')->all())->not->toContain('not-a-header@x.test') // body not parsed
        ->and(array_column($record['ev'], 'type'))->toBe(['Send', 'Delivery']);
});

it('drains an accepted send to Postgres after the response, under the MessageId the client got', function () {
    $result = sesClient($this, $this->akid)->sendEmail([
        'Destination' => ['ToAddresses' => ['success@load.crm.test']],
        'Content' => ['Raw' => ['Data' => rawMime('success@load.crm.test')]],
    ]);

    expect(bufferRows()['sends'])->toBe([])
        ->and(DB::table('sesame_messages')->where('message_id', $result['MessageId'])->exists())->toBeTrue()
        ->and(DB::table('sesame_events')->where('message_id', $result['MessageId'])->pluck('type')->sort()->values()->all())->toBe(['Delivery', 'Send'])
        ->and((int) DB::table('sesame_buffers')->where('id', IngestBuffer::id())->value('drained_through_seq'))->toBe(1);
});

it('makes no Postgres round trip on the ingest path once the account is cached', function () {
    holdDrains($this);
    $client = sesClient($this, $this->akid);
    $send = fn (int $i) => $client->sendEmail([
        'Destination' => ['ToAddresses' => ["success+{$i}@load.crm.test"]],
        'Content' => ['Raw' => ['Data' => rawMime("success+{$i}@load.crm.test")]],
    ]);
    $send(1); // reads the account once

    DB::enableQueryLog();
    $send(2);
    $send(3);

    expect(DB::getQueryLog())->toBe([]);
});

it('drains idempotently when a drain dies between the Postgres commit and the buffer delete', function () {
    holdDrains($this);
    $client = sesClient($this, $this->akid);
    foreach ([1, 2, 3] as $i) {
        $client->sendEmail(['Destination' => ['ToAddresses' => ["load-{$i}@load.crm.test"]], 'Content' => ['Raw' => ['Data' => rawMime("load-{$i}@load.crm.test")]]]);
    }
    $pdo = new PDO('sqlite:'.config('sesame.buffer.path'));
    $copy = $pdo->query('SELECT seq, body FROM sends')->fetchAll(PDO::FETCH_ASSOC);

    IngestBuffer::drain();
    // Put the rows back, as if the delete never ran.
    foreach ($copy as $row) {
        $pdo->prepare('INSERT INTO sends (seq, body) VALUES (?, ?)')->execute([$row['seq'], $row['body']]);
    }
    IngestBuffer::drain();

    expect(DB::table('sesame_messages')->count())->toBe(3)
        ->and(DB::table('sesame_events')->count())->toBe(6)
        ->and(IngestBuffer::depth())->toBe(0);
});

it('returns SES errors the SDK maps to their exception codes', function (callable $request, string $code, int $status) {
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

it('throttles above the account max send rate and logs the refusal', function () {
    holdDrains($this);
    $this->account->forceFill(['max_send_rate' => 2])->save();
    AccountDirectory::forget();
    $client = sesClient($this, $this->akid);
    $codes = [];
    // Stay inside one wall-clock second so the window is a single bucket.
    while (microtime(true) - floor(microtime(true)) > 0.7) {
        usleep(10000);
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
        ->and(collect(bufferRows()['refusals'])->where('kind', 'throttled'))->toHaveCount(2);

    IngestBuffer::drain();
    expect(DB::table('sesame_send_refusals')->where('kind', 'throttled')->count())->toBe(2);
});

it('rejects at send time when the lottery draws Reject', function () {
    holdDrains($this);
    $this->account->forceFill(['rate_overrides' => ['reject' => 100]])->save();
    AccountDirectory::forget();

    expect(fn () => sesClient($this, $this->akid)->sendEmail(['Destination' => ['ToAddresses' => ['load-1@load.crm.test']], 'Content' => ['Raw' => ['Data' => rawMime('load-1@load.crm.test')]]]))
        ->toThrow(SesV2Exception::class, 'rejected');
    expect(collect(bufferRows()['refusals'])->pluck('kind')->all())->toBe(['rejected']);
});
