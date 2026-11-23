<?php

use App\Sesame\Accounts;
use App\Sesame\Ingest\AccountDirectory;
use App\Sesame\Viewer\MessageViewController;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    sesameSetUp();
    $created = Accounts::create('viewer', null, 'healthy', 1000, 7);
    AccountDirectory::forget();
    $this->akid = $created['access_key_id'];
    $this->account = $created['account'];
    $this->account->update(['viewer_key' => 'test-viewer-key']);
});

/** A Growth-shaped campaign message: HTML with a tracked link and an open pixel, a text part, one-click unsubscribe. */
function viewerMessage(bool $keep): string
{
    $email = (new Email)->from(new Address('camps@exact.test', 'EXACT Camps'))->to('athlete@simulator.amazonses.com')
        ->subject('Spring Camp — Ünïcode')
        ->html('<html><head><title>x</title></head><body><p>Héllo <a href="https://growth.test/c/abc">Register</a></p>'
            .'<img src="https://growth.test/o/tok" width="1" height="1" alt="" style="display:block;border:0" /></body></html>')
        ->text("Héllo\nRegister: https://growth.test/c/abc");
    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://growth.test/u/tok>');
    $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    if ($keep) {
        $email->getHeaders()->addTextHeader('X-Sesame-Keep-Body', '1');
    }

    return $email->toString();
}

function sendViewerMessage(object $test, bool $keep): string
{
    return sesClient($test, $test->akid)->sendEmail([
        'Destination' => ['ToAddresses' => ['athlete@simulator.amazonses.com']],
        'Content' => ['Raw' => ['Data' => viewerMessage($keep)]],
    ])['MessageId'];
}

function viewerUrl(string $account, string $message, int $expires, string $key = 'test-viewer-key'): string
{
    return "/sesame/v1/accounts/{$account}/messages/{$message}?expires={$expires}&signature="
        .MessageViewController::signature($key, $account, $message, $expires);
}

it('keeps the raw message only when the message asks for it', function (): void {
    $kept = sendViewerMessage($this, true);
    $plain = sendViewerMessage($this, false);

    $raw = DB::table('sesame_messages')->where('message_id', $kept)->value('raw');
    expect(base64_decode((string) $raw))->toContain('Register</a>')
        ->and(DB::table('sesame_messages')->where('message_id', $plain)->value('raw'))->toBeNull();
});

it('shows a kept message in both panes, with the open pixel in one pane only', function (): void {
    $id = sendViewerMessage($this, true);

    $response = $this->get(viewerUrl($this->account->id, $id, time() + 3600))->assertOk()
        ->assertSee('Spring Camp — Ünïcode')->assertSee('One-click unsubscribe')
        ->assertSee('action="https://growth.test/u/tok"', false);
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');

    preg_match_all('/srcdoc="([^"]*)"/', (string) $response->getContent(), $frames);
    [$desktop, $mobile] = array_map(fn (string $f): string => html_entity_decode($f, ENT_QUOTES), $frames[1]);
    // Both panes keep the tracked link, decoded from quoted-printable and opening in a new tab.
    expect($desktop)->toContain('<head><base target="_blank">', 'Héllo', 'href="https://growth.test/c/abc"', 'https://growth.test/o/tok')
        ->and($mobile)->toContain('href="https://growth.test/c/abc"')->not->toContain('https://growth.test/o/tok');
});

it('refuses forged, expired, other-account and bodiless links', function (): void {
    $kept = sendViewerMessage($this, true);
    $plain = sendViewerMessage($this, false);
    $other = Accounts::create('other', null, 'healthy', 1000, 8)['account'];
    $other->update(['viewer_key' => 'other-key']);

    $this->get(viewerUrl($this->account->id, $kept, time() + 3600, 'wrong-key'))->assertForbidden()->assertDontSee('Register');
    $this->get(viewerUrl($this->account->id, $kept, time() - 1))->assertStatus(410)->assertDontSee('Register');
    // A valid signature for another account does not open this account's message.
    $this->get(viewerUrl($other->id, $kept, time() + 3600, 'other-key'))->assertNotFound()->assertDontSee('Register');
    $this->get(viewerUrl($this->account->id, $plain, time() + 3600))->assertNotFound()->assertSee('without asking Sesame to keep its body');
    // An account with no viewer key refuses every link.
    $this->account->update(['viewer_key' => null]);
    $this->get(viewerUrl($this->account->id, $kept, time() + 3600))->assertForbidden();
});
