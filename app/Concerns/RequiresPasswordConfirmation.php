<?php

namespace App\Concerns;

trait RequiresPasswordConfirmation
{
    protected function ensurePasswordIsConfirmed(): void
    {
        $confirmedAt = session('auth.password_confirmed_at');

        abort_unless(
            is_int($confirmedAt)
                && (time() - $confirmedAt) < (int) config('auth.password_timeout', 10800),
            403,
        );
    }
}
