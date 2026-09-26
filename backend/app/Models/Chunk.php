<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property int $user_id
 * @property int $position
 * @property string $content
 * @property string $embedding
 */
#[Fillable(['document_id', 'user_id', 'position', 'content', 'embedding'])]
#[Hidden(['embedding'])]
class Chunk extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
