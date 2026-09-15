<?php

namespace ChurchCRM\Plugins\MosGov\Data;

/**
 * Thrown when a governance data operation cannot be completed.
 *
 * The message is always safe to display to an administrator: it never
 * contains SQL, connection details or other internals. Detailed context
 * (previous exception) is available for logging only.
 */
class GovDataException extends \RuntimeException
{
    /** @var array<string, string> per-field validation errors, if any */
    private array $errors;

    public function __construct(string $message, array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errors = $errors;
    }

    /**
     * @return array<string, string> field => error message
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
