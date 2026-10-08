<?php

namespace App\Services\Chat;

use RuntimeException;

/**
 * The model (and any configured fallback) declined to answer: stop_reason "refusal".
 */
class AnswerRefusedException extends RuntimeException {}
