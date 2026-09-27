<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Daily caps that keep a public deployment's API spend bounded.
 */
class UsageLimits
{
    /**
     * Count a question against today's limits, or refuse it with a 429.
     */
    public function consumeQuestion(User $user): void
    {
        $perUser = config('knowledge.limits.questions_per_user_per_day');
        $total = config('knowledge.limits.questions_per_day');
        $day = now()->toDateString();

        if ($perUser !== null && $this->count("kb:questions:{$day}:user:{$user->id}") >= $perUser) {
            throw new HttpException(429, "You've reached today's limit of {$perUser} questions. Please come back tomorrow.");
        }

        if ($total !== null && $this->count("kb:questions:{$day}:all") >= $total) {
            throw new HttpException(429, 'The demo has reached its daily question limit. Please come back tomorrow.');
        }

        $this->increment("kb:questions:{$day}:user:{$user->id}");
        $this->increment("kb:questions:{$day}:all");
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
