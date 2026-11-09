<?php

use App\Mcp\SesameServer;
use App\Mcp\Tools\AccountsTool;
use App\Mcp\Tools\PresetsTool;
use App\Mcp\Tools\RunsTool;
use App\Mcp\Tools\RunSummaryTool;
use App\Mcp\Tools\RunTruthTool;
use App\Models\SesameRun;
use App\Sesame\Accounts;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\OwnershipClaimMinter;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedEnforcement;
use ArtisanBuild\BuiltForCloud\Testing\ThinHostConformance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

uses(ContractAssertions::class);

beforeEach(function (): void {
    sesameSetUp();
});

function sesameMcpBearer(): string
{
    $token = 'test_'.bin2hex(random_bytes(32));
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'sesame-test',
        'status' => CredentialStatus::Active,
        'abilities' => [OperatorAbility::McpRead->value],
        'secret_hash' => hash('sha256', $token),
    ]);

    return $token;
}

function sesameMcpCall(string $token, string $tool, array $arguments = []): TestResponse
{
    return test()->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => bin2hex(random_bytes(8)), 'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ], ['Authorization' => 'Bearer '.$token]);
}

function sesameMcpData(TestResponse $response): array
{
    $response->assertOk();
    expect($response->json('result.isError'))->not->toBeTrue();

    return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
}

function sesameAssertion(string $subject = 'operator-one', string $audience = 'https://sesame-deployment.test'): string
{
    config(['built-for-cloud.console.issuer' => 'https://console.test', 'built-for-cloud.console.audience' => 'https://sesame-deployment.test']);
    $key = new AsymmetricSecretKey(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $kid = 'test-'.bin2hex(random_bytes(6));
    $keyring = resolve(ConsoleKeyring::class);
    $keyring->add($kid, $key->getPublicKey()->toHexString());
    $keyring->activate($kid);
    $now = CarbonImmutable::now();

    return (new Builder)->setVersion(new Version4)->setPurpose(Purpose::public())->setKey($key)
        ->setClaims([
            'iss' => 'https://console.test', 'sub' => $subject, 'aud' => $audience,
            'iat' => $now->toAtomString(), 'nbf' => $now->toAtomString(), 'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => bin2hex(random_bytes(16)), 'display_name' => 'Test operator', 'role' => 'member', 'purpose' => 'mcp',
        ])->setFooterArray(['kid' => $kid])->toString();
}

it('uses package-owned identity, ownership and guarded delegated MCP contracts', function (): void {
    $this->assertBuiltForCloudContract();
    $this->assertBuiltForCloudHumanLifecycleContract();
    $this->assertBuiltForCloudMcpProductAdmission();
    $this->assertBuiltForCloudMcpDelegatedTools(SesameServer::class);
    expect(ThinHostConformance::sourceArtifacts(base_path()))->toBe([])
        ->and(ThinHostConformance::configurationArtifacts(config('auth')))->toBe([]);
    $meta = $this->getJson('/bfc/meta')->assertOk();
    expect($meta->json('capabilities'))->toContain('mcp-serve', 'mcp-delegated', 'mcp-effect-scoped');
    $meta->assertJsonPath('endpoints.mcp', '/mcp');

    $run = Accounts::create('conformance', 'https://crm.test/hook')['account']->current_run_id;
    McpDelegatedEnforcement::assertHonors(SesameServer::class, [
        AccountsTool::class, PresetsTool::class, RunsTool::class, RunSummaryTool::class, RunTruthTool::class,
    ], arguments: [RunSummaryTool::class => ['run_id' => $run], RunTruthTool::class => ['run_id' => $run]]);
});

it('lists exactly five content-classified read tools and refuses unauthenticated requests', function (): void {
    $body = ['jsonrpc' => '2.0', 'id' => 'list', 'method' => 'tools/list'];
    $this->postJson('/mcp', $body)->assertUnauthorized();
    $this->postJson('/mcp', $body, ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    $tools = $this->postJson('/mcp', $body, ['Authorization' => 'Bearer '.sesameMcpBearer()])->assertOk()->json('result.tools');
    expect(array_column($tools, 'name'))->toBe(['accounts', 'presets', 'runs', 'run_summary', 'run_truth']);
    foreach ($tools as $tool) {
        expect($tool['_meta'])->toMatchArray(['effect' => 'read', 'classification' => 'content'])
            ->and($tool['inputSchema']['additionalProperties'])->toBeFalse();
    }
});

it('claims ownership and enrolls the console public key without making the owner token an MCP credential', function (): void {
    [$claim] = resolve(OwnershipClaimMinter::class)->mint();
    $key = new AsymmetricSecretKey(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
    $response = $this->postJson('/bfc/ownership/claim', [
        'token' => $claim,
        'console_key' => ['key_id' => 'claim-key', 'public_key' => $key->getPublicKey()->toHexString()],
    ])->assertCreated()->assertJsonPath('product', 'Sesame');
    $owner = $response->json('owner_token');
    $this->getJson('/bfc/meta')->assertJsonPath('claimed', true);
    $this->getJson('/bfc/credentials', ['Authorization' => 'Bearer '.$owner])->assertOk();
    $this->postJson('/bfc/ownership/claim', ['token' => $claim])->assertUnauthorized();
    sesameMcpCall($owner, 'accounts')->assertUnauthorized();
    expect(DB::table('bfc_console_keys')->where('key_id', 'claim-key')->value('public_key'))->toBe($key->getPublicKey()->toHexString());
});

it('accepts verified delegated members but rejects replay, wrong audience and inactive actors', function (): void {
    $account = Accounts::create('shared', 'https://crm.test/hook')['account'];
    $assertion = sesameAssertion();
    expect(sesameMcpData(sesameMcpCall($assertion, 'accounts'))['accounts'][0]['id'])->toBe($account->id);
    sesameMcpCall($assertion, 'accounts')->assertUnauthorized();
    sesameMcpCall(sesameAssertion(audience: 'https://wrong.test'), 'accounts')->assertUnauthorized();
    expect(sesameMcpData(sesameMcpCall(sesameAssertion('operator-two'), 'accounts'))['accounts'][0]['id'])->toBe($account->id);
    DelegatedActor::query()->where('subject', 'operator-one')->sole()->deactivate();
    sesameMcpCall(sesameAssertion(), 'accounts')->assertUnauthorized();
    expect(DB::table('users')->count())->toBe(0);
});

it('paginates accounts and filtered runs without exposing simulator credentials', function (): void {
    $first = Accounts::create('first', 'https://SECRET-HOOK.test/hook')['account'];
    $second = Accounts::create('second', 'https://SECRET-HOOK.test/other')['account'];
    $token = sesameMcpBearer();
    $page = sesameMcpData(sesameMcpCall($token, 'accounts', ['limit' => 1]));
    expect($page['accounts'][0]['id'])->toBe($first->id)->and($page['next'])->toBe($first->id);
    $response = sesameMcpCall($token, 'accounts', ['after' => $page['next'], 'limit' => 1]);
    $last = sesameMcpData($response);
    expect($last['accounts'][0]['id'])->toBe($second->id)->and($last['next'])->toBeNull();
    foreach (['SECRET-HOOK', $second->access_key_id, $second->truth_token_hash, $second->subscription_token, 'private_key', 'certificate'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
    $newer = SesameRun::create(['account_id' => $first->id, 'seed' => 73, 'preset' => 'chaos', 'started_at' => now()]);
    $page = sesameMcpData(sesameMcpCall($token, 'runs', ['account_id' => $first->id, 'limit' => 1]));
    expect($page['runs'][0]['id'])->toBe($newer->id)->and($page['next'])->toBe($newer->id);
    $page = sesameMcpData(sesameMcpCall($token, 'runs', ['account_id' => $first->id, 'after' => $page['next'], 'limit' => 1]));
    expect($page['runs'][0]['id'])->toBe($first->current_run_id)->and($page['next'])->toBeNull();
});

it('returns live summaries, saved snapshots and bounded truth for the requested run only', function (): void {
    $created = Accounts::create('reports', 'https://crm.test/hook');
    $run = SesameRun::findOrFail($created['account']->current_run_id);
    $run->update(['summary' => ['snapshot' => true]]);
    $client = sesClient($this, $created['access_key_id']);
    foreach (['one', 'two', 'three'] as $name) {
        $client->sendEmail(['Destination' => ['ToAddresses' => [$name.'@load.crm.test']], 'Content' => ['Raw' => ['Data' => "From: a@load.crm.test\r\nSubject: test\r\n\r\nbody"]]]);
    }
    $foreign = Accounts::create('other-run');
    sesClient($this, $foreign['access_key_id'])->sendEmail([
        'Destination' => ['ToAddresses' => ['other-run@load.crm.test']],
        'Content' => ['Raw' => ['Data' => "From: a@load.crm.test\r\nSubject: unrelated\r\n\r\nbody"]],
    ]);
    $token = sesameMcpBearer();
    $summary = sesameMcpData(sesameMcpCall($token, 'run_summary', ['run_id' => $run->id]));
    expect($summary['summary']['sends']['accepted'])->toBe(3)->and($summary['saved_summary'])->toBe(['snapshot' => true]);
    $page = sesameMcpData(sesameMcpCall($token, 'run_truth', ['run_id' => $run->id, 'limit' => 2]));
    expect($page['messages'])->toHaveCount(2)->and($page['seed'])->toBe($run->seed);
    $last = sesameMcpData(sesameMcpCall($token, 'run_truth', ['run_id' => $run->id, 'after' => $page['next'], 'limit' => 2]));
    expect($last['messages'])->toHaveCount(1)->and($last['next'])->toBeNull();
    $destinations = array_merge(array_column($page['messages'], 'destination'), array_column($last['messages'], 'destination'));
    expect($destinations)->toEqualCanonicalizing([['one@load.crm.test'], ['two@load.crm.test'], ['three@load.crm.test']]);
    sesameMcpCall($token, 'run_summary', ['run_id' => '00000000-0000-4000-8000-000000000000'])->assertJsonPath('result.isError', true);
    expect(sesameMcpData(sesameMcpCall($token, 'presets'))['presets'])->toHaveKeys(['healthy', 'review', 'paused', 'chaos']);
});

it('rejects unknown arguments, malformed IDs and out-of-bounds or coercible limits', function (): void {
    $token = sesameMcpBearer();
    foreach ([['accounts', ['unexpected' => 1]], ['presets', ['unexpected' => 1]], ['runs', ['account_id' => 'invalid']], ['run_summary', []], ['run_truth', ['run_id' => 'invalid']], ['accounts', ['limit' => 0]], ['runs', ['limit' => 101]], ['accounts', ['limit' => '25']]] as [$tool, $arguments]) {
        sesameMcpCall($token, $tool, $arguments)->assertOk()->assertJsonPath('result.isError', true);
    }
});
