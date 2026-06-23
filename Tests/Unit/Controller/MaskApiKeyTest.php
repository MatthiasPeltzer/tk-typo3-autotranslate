<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Controller\BatchTranslationController;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MaskApiKeyTest extends UnitTestCase
{
    private function mask(?string $key): string
    {
        $controller = new BatchTranslationController();
        $method = new \ReflectionMethod($controller, 'maskApiKey');
        $method->setAccessible(true);

        return $method->invoke($controller, $key);
    }

    #[Test]
    public function returnsPlaceholderWhenEmpty(): void
    {
        self::assertSame('(not set)', $this->mask(null));
        self::assertSame('(not set)', $this->mask(''));
    }

    #[Test]
    public function revealsOnlyLastFourCharacters(): void
    {
        $key = 'b88864fa-42c6-41e3-96ba-b41401a79d0d:fx';
        $masked = $this->mask($key);

        self::assertSame(strlen($key), strlen($masked));
        self::assertSame(substr($key, -4), substr($masked, -4), 'last 4 characters stay visible');
        self::assertStringStartsWith('****', $masked);
        self::assertStringNotContainsString('b88864fa', $masked, 'key prefix must not leak');
    }

    #[Test]
    public function fullyMasksShortKeys(): void
    {
        self::assertSame('****', $this->mask('abcd'));
        self::assertSame('***', $this->mask('abc'));
    }
}
