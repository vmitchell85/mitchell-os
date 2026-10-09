<?php

namespace App\Models;

use App\Services\Claude\ClaudeResult;
use App\Services\Claude\ClaudeRunStatus;
use Database\Factories\ClaudeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One invocation of the claude CLI, from any feature. Kept after the user is
 * deleted so household spend stays accurate.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $feature
 * @property string $model
 * @property ClaudeRunStatus $status
 * @property string|null $session_id
 * @property float $cost_usd
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int|null $duration_ms
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'feature', 'model', 'status', 'session_id', 'cost_usd', 'input_tokens', 'output_tokens', 'duration_ms', 'error'])]
class ClaudeRun extends Model
{
    /** @use HasFactory<ClaudeRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClaudeRunStatus::class,
            'cost_usd' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markSucceeded(ClaudeResult $result): void
    {
        $this->update([
            'status' => ClaudeRunStatus::Succeeded,
            'session_id' => $result->sessionId,
            'cost_usd' => $result->costUsd,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'duration_ms' => $result->durationMs,
        ]);
    }

    /**
     * Record the failure, along with anything Claude had already spent before it failed.
     */
    public function markFailed(string $error, float $costUsd = 0): void
    {
        $this->update([
            'status' => ClaudeRunStatus::Failed,
            'cost_usd' => $costUsd,
            'duration_ms' => (int) $this->created_at?->diffInMilliseconds(now()),
            'error' => $error,
        ]);
    }
}
