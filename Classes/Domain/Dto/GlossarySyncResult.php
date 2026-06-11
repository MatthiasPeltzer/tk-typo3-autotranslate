<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Domain\Dto;

final readonly class GlossarySyncResult
{
    public function __construct(
        public int $glossaryUid,
        public bool $success,
        public string $message,
        public ?string $glossaryId = null,
    ) {}
}
