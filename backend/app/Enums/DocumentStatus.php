<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
