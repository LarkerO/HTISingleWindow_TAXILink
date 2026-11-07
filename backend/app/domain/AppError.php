<?php
declare(strict_types=1);
namespace app\domain;
final class AppError extends \RuntimeException
{
    public function __construct(
        public readonly string $key,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}
