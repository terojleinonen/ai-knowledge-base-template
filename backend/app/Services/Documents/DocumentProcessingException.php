<?php

namespace App\Services\Documents;

use RuntimeException;

/**
 * A permanent processing failure: retrying the job will not help, and the
 * message is safe to show to the document owner.
 */
class DocumentProcessingException extends RuntimeException {}
