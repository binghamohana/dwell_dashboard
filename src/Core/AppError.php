<?php
declare(strict_types=1);

namespace Dwell\Core;

/**
 * An error that is safe to show to the person using the app.
 * $field points the form at the input that needs attention.
 */
class AppError extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly ?string $field = null,
        public readonly ?string $errorCode = null,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'You don’t have access to that.'): self
    {
        return new self($message, 403, null, 'forbidden');
    }

    public static function notFound(string $message = 'That record doesn’t exist or isn’t available to you.'): self
    {
        return new self($message, 404, null, 'not_found');
    }

    public static function conflict(string $message, array $extra = []): self
    {
        return new self($message, 409, null, 'conflict', $extra);
    }
}
