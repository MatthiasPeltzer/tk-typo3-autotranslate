<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Domain\Dto;

final readonly class Glossary
{
    public function __construct(
        public string $glossaryId,
        public int $uid,
    ) {}

    /**
     * @param array{uid: int|string, glossary_id: string} $row
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            glossaryId: (string)$row['glossary_id'],
            uid: (int)$row['uid'],
        );
    }
}
