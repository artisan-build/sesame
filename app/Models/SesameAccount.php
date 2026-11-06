<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A simulated SES account. Its access key ID selects it on SendEmail; it owns
 * a preset, rate limit, seed (through its current run), webhook and signing key.
 *
 * @property string $id
 * @property string $name
 * @property string $access_key_id
 * @property string $truth_token_hash
 * @property string $preset
 * @property array<string, float>|null $rate_overrides
 * @property array<string, array<int, int>>|null $timing_overrides
 * @property int $max_send_rate
 * @property bool $sending_paused
 * @property string $aws_account_number
 * @property string $topic_arn
 * @property string|null $webhook_url
 * @property string $subscription_status
 * @property string|null $subscription_arn
 * @property string|null $subscription_token
 * @property string $private_key
 * @property string $certificate
 * @property string|null $current_run_id
 */
class SesameAccount extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['private_key', 'truth_token_hash', 'subscription_token'];

    #[\Override]
    protected function casts(): array
    {
        return [
            'rate_overrides' => 'array',
            'timing_overrides' => 'array',
            'sending_paused' => 'boolean',
            'private_key' => 'encrypted',
            'subscription_requested_at' => 'datetime',
            'subscription_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<SesameRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(SesameRun::class, 'account_id');
    }

    /** @return BelongsTo<SesameRun, $this> */
    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(SesameRun::class, 'current_run_id');
    }

    public function certificateUrl(): string
    {
        return rtrim((string) config('sesame.public_url'), '/').'/sns/'.$this->id.'/cert.pem';
    }
}
