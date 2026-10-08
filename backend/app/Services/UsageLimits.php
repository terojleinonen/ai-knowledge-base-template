<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Caps that keep a public deployment's API spend and database growth bounded.
 */
class UsageLimits
{
    /**
     * Count a question against today's limits, or refuse it with a 429.
     */
    public function consumeQuestion(User $user, ?string $ip = null): void
    {
        $perUser = config('knowledge.limits.questions_per_user_per_day');
        $perIp = config('knowledge.limits.questions_per_ip_per_day');
        $total = config('knowledge.limits.questions_per_day');
        $day = now()->toDateString();

        if ($perUser !== null && $this->count("kb:questions:{$day}:user:{$user->id}") >= $perUser) {
            throw new HttpException(429, "You've reached today's limit of {$perUser} questions. Please come back tomorrow.");
        }

        // Guests are free to create, so per-user limits alone are easy to sidestep.
        if ($perIp !== null && $ip !== null && $this->count("kb:questions:{$day}:ip:{$ip}") >= $perIp) {
            throw new HttpException(429, "You've reached today's limit of {$perIp} questions. Please come back tomorrow.");
        }

        if ($total !== null && $this->count("kb:questions:{$day}:all") >= $total) {
            throw new HttpException(429, 'The demo has reached its daily question limit. Please come back tomorrow.');
        }

        $this->increment("kb:questions:{$day}:user:{$user->id}");
        if ($ip !== null) {
            $this->increment("kb:questions:{$day}:ip:{$ip}");
        }
        $this->increment("kb:questions:{$day}:all");
    }

    /**
     * Count a new demo guest against today's limit, or refuse it with a 429.
     */
    public function consumeGuest(): void
    {
        $max = config('knowledge.limits.guests_per_day');
        $key = 'kb:guests:'.now()->toDateString();

        if ($max !== null && $this->count($key) >= $max) {
            throw new HttpException(429, 'The demo has reached its limit of new sessions for today. Please come back tomorrow.');
        }

        $this->increment($key);
    }

    /**
     * How many more passages the user may store (null = unlimited), excluding the given document's own.
     */
    public function remainingChunks(User $user, ?int $exceptDocumentId = null): ?int
    {
        $max = config('knowledge.limits.chunks_per_user');

        if ($max === null) {
            return null;
        }

        $used = DB::table('chunks')
            ->where('user_id', $user->id)
            ->when($exceptDocumentId, fn ($q) => $q->where('document_id', '!=', $exceptDocumentId))
            ->count();

        return max(0, $max - $used);
    }

    /**
     * Whether the user is below the per-user document limit.
     */
    public function canAddDocument(User $user): bool
    {
        $max = config('knowledge.limits.documents_per_user');

        return $max === null || $user->documents()->count() < $max;
    }

    private function count(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    private function increment(string $key): void
    {
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }
}
