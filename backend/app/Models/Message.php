<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property MessageRole $role
 * @property string $content
 * @property list<array{index: int, document_id: int, document_title: string, chunk_id: int, excerpt: string, score: float}>|null $sources
 * @property Carbon $created_at
 */
#[Fillable(['role', 'content', 'sources'])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'sources' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
