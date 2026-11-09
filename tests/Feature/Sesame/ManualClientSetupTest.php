<?php

use App\Models\SesameAccount;
use App\Models\SesameRun;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\Ownership;
use ArtisanBuild\BuiltForCloud\OwnershipClaimMinter;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    sesameSetUp();
    Http::preventStrayRequests();
    Queue::fake();
    [$claim] = resolve(OwnershipClaimMinter::class)->mint();
    $this->owner = $this->postJson('/bfc/ownership/claim', ['token' => $claim])->assertCreated()->json('owner_token');
    $this->headers = ['Authorization' => 'Bearer '.$this->owner];
    $this->input = ['name' => 'growth-staging', 'preset' => 'review', 'max_send_rate' => 37, 'staging_only' => true];
});

it('issues working SDK credentials and account-scoped truth without configuring or contacting a consumer', function (): void {
    config(['sesame.region' => 'eu-west-2']);
    $response = $this->postJson('/sesame/v1/clients', $this->input, $this->headers)
        ->assertCreated()->assertJsonPath('endpoint', 'https://sesame.test')->assertJsonPath('region', 'eu-west-2')
        ->assertJsonPath('preset', 'review')->assertJsonPath('max_send_rate', 37)->assertJsonPath('subscription_status', 'none');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $settings = $response->json();
    expect(array_keys($settings))->toEqualCanonicalizing([
        'account_id', 'name', 'run_id', 'endpoint', 'region', 'access_key_id', 'secret_access_key', 'truth_token',
        'topic_arn', 'certificate_url', 'subscription_status', 'preset', 'max_send_rate',
    ]);
    $account = SesameAccount::findOrFail($settings['account_id']);
    expect($account->name)->toBe('growth-staging')
        ->and($account->webhook_url)->toBeNull()
        ->and($account->subscription_token)->toBeNull()
        ->and($account->truth_token_hash)->toBe(hash('sha256', $settings['truth_token']))
        ->and($settings['certificate_url'])->toBe('https://sesame.test/sns/'.$account->id.'/cert.pem');
    $run = SesameRun::findOrFail($settings['run_id']);
    expect($run->account_id)->toBe($account->id)->and($run->preset)->toBe('review');
    foreach (['private_key', 'certificate', 'subscription_token', 'owner_token', 'truth_token_hash'] as $field) {
        expect($settings)->not->toHaveKey($field);
    }
    $this->get($settings['certificate_url'])->assertOk()->assertSee('BEGIN CERTIFICATE');
    expect(DB::table('sesame_events')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $client = new SesV2Client([
        'version' => 'latest', 'region' => $settings['region'], 'endpoint' => $settings['endpoint'], 'retries' => 0,
        'credentials' => ['key' => $settings['access_key_id'], 'secret' => $settings['secret_access_key']],
        'http_handler' => inProcessHandler($this, true),
    ]);
    $sent = $client->sendEmail([
        'Destination' => ['ToAddresses' => ['synthetic@example.test']],
        'Content' => ['Raw' => ['Data' => "From: sender@example.test\r\nSubject: Manual setup\r\n\r\nSynthetic staging message"]],
    ]);
    $this->getJson('/sesame/v1/runs/'.$run->id.'/truth', ['Authorization' => 'Bearer '.$settings['truth_token']])
        ->assertOk()->assertJsonPath('messages.0.message_id', $sent['MessageId']);
    $this->getJson('/sesame/v1/runs/'.$run->id.'/truth', $this->headers)->assertNotFound();
    $this->getJson('/sesame/v1/runs/'.$run->id.'/truth', ['Authorization' => 'Bearer '.$settings['secret_access_key']])->assertNotFound();
});

it('requires the current installation owner, not a browser session or another admin or MCP credential', function (): void {
    $this->postJson('/sesame/v1/clients', $this->input)->assertUnauthorized();
    $this->postJson('/sesame/v1/clients', $this->input, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    foreach ([CredentialPurpose::OperatorManagement, CredentialPurpose::Mcp] as $purpose) {
        $token = bin2hex(random_bytes(32));
        Credential::create([
            'kind' => CredentialKind::Bearer, 'purpose' => $purpose,
            'subject_type' => $purpose === CredentialPurpose::Mcp ? SubjectType::Installation : SubjectType::Operator,
            'subject_ref' => 'other-operator',
            'status' => CredentialStatus::Active, 'abilities' => [OperatorAbility::Admin->value, OperatorAbility::McpRead->value],
            'secret_hash' => hash('sha256', $token),
        ]);
        $this->postJson('/sesame/v1/clients', $this->input, ['Authorization' => 'Bearer '.$token])
            ->assertStatus($purpose === CredentialPurpose::OperatorManagement ? 403 : 401);
    }
    // A cookie login grants no operator bearer, even for a human admin.
    $user = new User;
    $user->forceFill(['name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'admin'])->save();
    $this->actingAs($user)->postJson('/sesame/v1/clients', $this->input)->assertUnauthorized();
    Credential::findOrFail(Ownership::current()->owner_credential_id)->update(['revoked_at' => now()]);
    $this->postJson('/sesame/v1/clients', $this->input, $this->headers)->assertUnauthorized();
    expect(SesameAccount::count())->toBe(0)->and(SesameRun::count())->toBe(0);
});

it('rejects duplicate submissions without replaying credentials or changing the original client', function (): void {
    $first = $this->postJson('/sesame/v1/clients', $this->input, $this->headers)->assertCreated()->json();
    $duplicate = $this->postJson('/sesame/v1/clients', array_replace($this->input, ['preset' => 'chaos', 'max_send_rate' => 1]), $this->headers)->assertConflict();
    foreach (['access_key_id', 'secret_access_key', 'truth_token'] as $field) {
        expect($duplicate->getContent())->not->toContain($first[$field]);
    }
    expect(SesameAccount::count())->toBe(1)->and(SesameRun::count())->toBe(1)
        ->and(SesameAccount::sole()->max_send_rate)->toBe(37)->and(SesameAccount::sole()->preset)->toBe('review');
    $this->getJson('/sesame/v1/clients', $this->headers)->assertStatus(405);
});

it('refuses unsafe or incomplete settings before creating anything', function (array $replace): void {
    $this->postJson('/sesame/v1/clients', array_replace($this->input, $replace), $this->headers)->assertUnprocessable();
    expect(SesameAccount::count())->toBe(0)->and(SesameRun::count())->toBe(0)->and(DB::table('sesame_events')->count())->toBe(0);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'missing acknowledgement' => [['staging_only' => null]],
    'production client' => [['staging_only' => false]],
    'invalid name' => [['name' => "staging\nAWS_SECRET_ACCESS_KEY=unsafe"]],
    'missing name' => [['name' => '']],
    'unknown preset' => [['preset' => 'unknown']],
    'no rate' => [['max_send_rate' => null]],
    'zero rate' => [['max_send_rate' => 0]],
    'excessive rate' => [['max_send_rate' => 100001]],
    'creation callback' => [['webhook_url' => 'https://production.example/hook']],
    'unknown field' => [['consumer_environment_id' => 'production']],
]);

it('will not issue settings for an unsafe public endpoint or invalid region', function (string $endpoint, string $region): void {
    config(['sesame.public_url' => $endpoint, 'sesame.region' => $region]);
    $this->postJson('/sesame/v1/clients', $this->input, $this->headers)->assertStatus(503);
    expect(SesameAccount::count())->toBe(0)->and(SesameRun::count())->toBe(0);
})->with([
    ['http://sesame.test', 'us-east-1'],
    ['', 'us-east-1'],
    ['https://email.us-east-1.amazonaws.com', 'us-east-1'],
    ['https://EMAIL.US-EAST-1.AMAZONAWS.COM.', 'us-east-1'],
    ['https://email.cn-north-1.amazonaws.com.cn', 'us-east-1'],
    ['https://email.us-east-1.api.aws', 'us-east-1'],
    ['https://user:pass@sesame.test', 'us-east-1'],
    ['https://sesame.test/subpath', 'us-east-1'],
    ['https://sesame.test?key=secret', 'us-east-1'],
    ['https://sesame.test#fragment', 'us-east-1'],
    ['https://sesame.test', ''],
]);

it('registers a webhook only as a separate staging-confirmed operator command and waits for verified confirmation', function (): void {
    $settings = $this->postJson('/sesame/v1/clients', $this->input, $this->headers)->assertCreated()->json();
    $this->artisan('sesame:account:subscribe', ['name' => 'growth-staging', 'webhook' => 'https://staging.test/hook'])->assertFailed();
    $this->artisan('sesame:account:subscribe', ['name' => 'growth-staging', 'webhook' => 'http://staging.test/hook', '--staging-only' => true])->assertFailed();
    expect(DB::table('sesame_events')->count())->toBe(0);
    $this->artisan('sesame:account:subscribe', ['name' => 'growth-staging', 'webhook' => 'https://staging.test/hook', '--staging-only' => true])->assertSuccessful();
    $account = SesameAccount::findOrFail($settings['account_id']);
    expect($account->webhook_url)->toBe('https://staging.test/hook')->and($account->subscription_status)->toBe('pending');
    expect(DB::table('sesame_events')->pluck('type')->all())->toBe(['SubscriptionConfirmation']);
    $this->get('/sns/'.$account->id.'/?'.http_build_query(['Action' => 'ConfirmSubscription', 'Token' => 'wrong', 'TopicArn' => $settings['topic_arn']]))->assertBadRequest();
    expect($account->fresh()->subscription_status)->toBe('pending');
    $this->get('/sns/'.$account->id.'/?'.http_build_query(['Action' => 'ConfirmSubscription', 'Token' => $account->subscription_token, 'TopicArn' => $settings['topic_arn']]))->assertOk();
    expect($account->fresh()->subscription_status)->toBe('confirmed');
});
