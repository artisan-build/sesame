<?php

use App\Livewire\Settings\Security;
use App\Livewire\Settings\TwoFactor\RecoveryCodes;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);
    Features::passkeys([
        'confirmPassword' => true,
    ]);
});

test('security settings page can be rendered', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Passkeys');
    $response->assertSee('No passkeys yet');
    $response->assertSee('Two-factor authentication');
    $response->assertSee('Enable 2FA');
});

test('security settings page requires recent password confirmation when enabled', function (?int $confirmedAt): void {
    $user = User::factory()->create();

    $request = $this->actingAs($user);

    if (is_int($confirmedAt)) {
        $request->withSession(['auth.password_confirmed_at' => $confirmedAt]);
    }

    $response = $request->get(route('security.edit'));

    $response->assertRedirect(route('password.confirm'));
})->with([
    'missing receipt' => null,
    'expired receipt' => fn (): int => time() - ((int) config('auth.password_timeout')) - 1,
]);

test('security settings page renders without two factor when feature is disabled', function (): void {
    config(['fortify.features' => []]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Update password')
        ->assertDontSee('Manage your passkeys for passwordless sign-in')
        ->assertDontSee('Add a passkey to sign in without a password')
        ->assertDontSee('Two-factor authentication');
});

test('two factor authentication disabled when confirmation abandoned between requests', function (): void {
    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user);

    $component = Livewire::test(Security::class);

    $component->assertSet('twoFactorEnabled', false);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
    ]);
});

test('password can be updated', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function (): void {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Security::class)
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('sensitive security actions refuse missing or expired password confirmation', function (string $action, string $receipt): void {
    $user = $action === 'disable'
        ? User::factory()->withTwoFactor()->create()
        : User::factory()->create();

    $passkey = $user->passkeys()->create([
        'name' => 'Security key',
        'credential_id' => fake()->uuid(),
        'credential' => [],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(Security::class);

    if ($action === 'confirmTwoFactor') {
        $component
            ->call('enable')
            ->set('code', '000000');
    }

    if ($action === 'deletePasskey') {
        $component->call('confirmDelete', $passkey->id);
    }

    $secretBefore = $user->refresh()->two_factor_secret;

    if ($receipt === 'missing') {
        session()->forget('auth.password_confirmed_at');
    } else {
        session()->put(
            'auth.password_confirmed_at',
            time() - ((int) config('auth.password_timeout')) - 1,
        );
    }

    $response = match ($action) {
        'confirmDelete' => $component->call($action, PHP_INT_MAX),
        default => $component->call($action),
    };

    $response->assertForbidden();

    $user->refresh();

    match ($action) {
        'enable' => expect($user->two_factor_secret)->toBeNull(),
        'confirmTwoFactor' => expect($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->two_factor_secret)->toBe($secretBefore),
        'disable' => expect($user->two_factor_secret)->toBe($secretBefore),
        'confirmDelete', 'deletePasskey' => $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]),
    };
})->with([
    'enable 2FA' => 'enable',
    'confirm 2FA' => 'confirmTwoFactor',
    'disable 2FA' => 'disable',
    'select a passkey for deletion' => 'confirmDelete',
    'delete a passkey' => 'deletePasskey',
])->with([
    'missing receipt' => 'missing',
    'expired receipt' => 'expired',
]);

test('recovery code actions refuse missing or expired password confirmation', function (string $action, string $receipt): void {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCodesBefore = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(RecoveryCodes::class)
        ->assertSet('recoveryCodes', [])
        ->assertDontSee('recovery-code-1');

    if ($receipt === 'missing') {
        session()->forget('auth.password_confirmed_at');
    } else {
        session()->put(
            'auth.password_confirmed_at',
            time() - ((int) config('auth.password_timeout')) - 1,
        );
    }

    $component->call($action)->assertForbidden();

    expect($user->refresh()->two_factor_recovery_codes)->toBe($recoveryCodesBefore);
})->with([
    'reveal recovery codes' => 'showRecoveryCodes',
    'regenerate recovery codes' => 'regenerateRecoveryCodes',
])->with([
    'missing receipt' => 'missing',
    'expired receipt' => 'expired',
]);

test('fresh password confirmation allows two factor actions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(Security::class)
        ->call('enable')
        ->assertOk()
        ->assertSet('showModal', true);

    expect($component->get('manualSetupKey'))
        ->not->toBeEmpty()
        ->and($component->get('qrCodeSvg'))->not->toBeEmpty();

    $secret = decrypt($user->refresh()->two_factor_secret);
    $code = (new Google2FA)->getCurrentOtp($secret);

    $component
        ->set('code', $code)
        ->call('confirmTwoFactor')
        ->assertOk();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $component
        ->call('disable')
        ->assertOk();

    expect($user->refresh()->two_factor_secret)
        ->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('fresh password confirmation allows passkey deletion', function (): void {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Security key',
        'credential_id' => fake()->uuid(),
        'credential' => [],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    Livewire::test(Security::class)
        ->call('confirmDelete', $passkey->id)
        ->assertOk()
        ->assertSet('deletingPasskeyId', $passkey->id)
        ->call('deletePasskey')
        ->assertOk();

    $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
});

test('fresh password confirmation allows recovery codes to be revealed and regenerated', function (): void {
    $user = User::factory()->withTwoFactor()->create();
    $recoveryCodesBefore = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(RecoveryCodes::class)
        ->assertDontSee('recovery-code-1')
        ->call('showRecoveryCodes')
        ->assertOk()
        ->assertSee('recovery-code-1')
        ->call('regenerateRecoveryCodes')
        ->assertOk();

    expect($user->refresh()->two_factor_recovery_codes)
        ->not->toBe($recoveryCodesBefore)
        ->and($component->get('recoveryCodes'))->toHaveCount(8);

    $component
        ->call('hideRecoveryCodes')
        ->assertOk()
        ->assertSet('recoveryCodes', [])
        ->assertSet('showRecoveryCodes', false);
});

test('a component snapshot cannot be replayed across accounts without recent password confirmation', function (): void {
    $snapshotOwner = User::factory()->withTwoFactor()->create();
    $actingUser = User::factory()->withTwoFactor()->create();

    $this->actingAs($snapshotOwner)
        ->withSession(['auth.password_confirmed_at' => time()]);

    $component = Livewire::test(Security::class);

    $this->actingAs($actingUser);
    session()->forget('auth.password_confirmed_at');

    $component
        ->call('disable')
        ->assertForbidden();

    expect($snapshotOwner->refresh()->two_factor_secret)
        ->not->toBeNull()
        ->and($actingUser->refresh()->two_factor_secret)->not->toBeNull();
});
