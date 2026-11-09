<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
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
 * @property \Carbon\CarbonImmutable|null $subscription_requested_at
 * @property \Carbon\CarbonImmutable|null $subscription_confirmed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\SesameRun|null $currentRun
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SesameRun> $runs
 * @property-read int|null $runs_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereAccessKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereAwsAccountNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereCertificate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereCurrentRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereMaxSendRate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount wherePreset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount wherePrivateKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereRateOverrides($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSendingPaused($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSubscriptionArn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSubscriptionConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSubscriptionRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSubscriptionStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereSubscriptionToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereTimingOverrides($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereTopicArn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereTruthTokenHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameAccount whereWebhookUrl($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSesameAccount {}
}

namespace App\Models{
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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\SesameAccount $account
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun wherePreset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereSeed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SesameRun whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperSesameRun {}
}

