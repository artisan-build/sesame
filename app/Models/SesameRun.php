<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One load run against a simulated account. The seed is recorded so a
 * failing run replays exactly.
 *
 * @property string $id
 * @property string $account_id
 * @property int $seed
 * @property string $preset
 * @property string|null $label
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property array<string, mixed>|null $summary
 *
 * @mixin IdeHelperSesameRun
 */
class SesameRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<SesameAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SesameAccount::class, 'account_id');
    }
}
