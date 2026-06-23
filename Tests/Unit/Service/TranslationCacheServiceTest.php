<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationCacheServiceTest extends UnitTestCase
{
    private TranslationCacheService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // Caching is disabled by default (no extension configuration in unit context),
        // which is fine: generateCacheKey is pure and does not touch the cache backend.
        $this->subject = new TranslationCacheService();
    }

    #[Test]
    public function generateCacheKeyIsDeterministic(): void
    {
        $a = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['split_sentences' => true]);
        $b = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['split_sentences' => true]);

        self::assertSame($a, $b);
        self::assertStringStartsWith('translation_', $a);
    }

    #[Test]
    public function generateCacheKeyIsIndependentOfOptionOrder(): void
    {
        $a = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['a' => 1, 'b' => 2]);
        $b = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['b' => 2, 'a' => 1]);

        self::assertSame($a, $b, 'options are ksorted before hashing');
    }

    #[Test]
    public function generateCacheKeyIgnoresVolatileTimeoutOption(): void
    {
        $a = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['glossary' => 'x']);
        $b = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['glossary' => 'x', 'timeout' => 30]);

        self::assertSame($a, $b, 'timeout must not affect the cache key');
    }

    #[Test]
    public function generateCacheKeyVariesByTargetLanguageAndGlossary(): void
    {
        $base = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', []);
        $otherTarget = $this->subject->generateCacheKey(['Hello'], 'EN', 'FR', []);
        $withGlossary = $this->subject->generateCacheKey(['Hello'], 'EN', 'DE', ['glossary' => 'gid']);

        self::assertNotSame($base, $otherTarget);
        self::assertNotSame($base, $withGlossary);
    }

    #[Test]
    public function cacheStatisticsReportDisabledWhenCachingOff(): void
    {
        $stats = $this->subject->getCacheStatistics();

        self::assertFalse($stats['enabled']);
        self::assertSame(0, $stats['entries']);
    }
}
