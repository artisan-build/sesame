<?php

namespace App\Livewire\Settings\TwoFactor;

use App\Concerns\RequiresPasswordConfirmation;
use Exception;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RecoveryCodes extends Component
{
    use RequiresPasswordConfirmation;

    /**
     * @var array<int, string>
     */
    #[Locked]
    public array $recoveryCodes = [];

    public bool $showRecoveryCodes = false;

    /**
     * Reveal the user's recovery codes.
     */
    public function showRecoveryCodes(): void
    {
        $this->ensurePasswordIsConfirmed();

        $this->loadRecoveryCodes();

        $this->showRecoveryCodes = true;
    }

    /**
     * Hide the user's recovery codes.
     */
    public function hideRecoveryCodes(): void
    {
        $this->reset('recoveryCodes', 'showRecoveryCodes');
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generateNewRecoveryCodes): void
    {
        $this->ensurePasswordIsConfirmed();

        $generateNewRecoveryCodes(auth()->user());

        $this->loadRecoveryCodes();
    }

    /**
     * Load the recovery codes for the user.
     */
    private function loadRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactorAuthentication() && $user->two_factor_recovery_codes) {
            try {
                $recoveryCodes = json_decode((string) decrypt($user->two_factor_recovery_codes), true);

                $this->recoveryCodes = is_array($recoveryCodes) ? $recoveryCodes : [];
            } catch (Exception) {
                $this->addError('recoveryCodes', 'Failed to load recovery codes');

                $this->recoveryCodes = [];
            }
        }
    }
}
