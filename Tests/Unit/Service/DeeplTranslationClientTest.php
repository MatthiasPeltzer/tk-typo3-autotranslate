<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Service;

use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use DeepL\Translator as DeeplTranslator;
use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClient;
use ThieleUndKlose\Autotranslate\Service\GlossaryService;
use ThieleUndKlose\Autotranslate\Service\TranslationCacheService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DeeplTranslationClientTest extends UnitTestCase
{
    /** @var array<int, array{texts: list<string>, options: array<string, mixed>}> */
    private array $calls = [];

    private DeeplTranslationClient $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $fakeTranslator = $this->createFakeTranslator();
        $cacheService = new TranslationCacheService(); // caching disabled without extension config
        // GlossaryService is final and unused by translateTexts(); a real instance is fine.
        $glossaryService = new GlossaryService();

        $this->subject = new class ($cacheService, $glossaryService, $fakeTranslator) extends DeeplTranslationClient {
            public function __construct(
                TranslationCacheService $cacheService,
                GlossaryService $glossaryService,
                private readonly DeeplTranslator $fakeTranslator,
            ) {
                parent::__construct($cacheService, $glossaryService);
            }

            protected function translator(?string $apiKey): DeeplTranslator
            {
                return $this->fakeTranslator;
            }
        };
    }

    private function createFakeTranslator(): DeeplTranslator
    {
        $recorder = function (array $texts, ?string $source, string $target, array $options): array {
            $this->calls[] = ['texts' => array_values($texts), 'options' => $options];

            return array_map(
                static fn(string $text): TextResult => new TextResult('T:' . $text, 'en', strlen($text)),
                array_values($texts)
            );
        };

        return new class ('00000000-0000-0000-0000-000000000000:fx', $recorder) extends DeeplTranslator {
            /** @var callable */
            private $recorder;

            public function __construct(string $authKey, callable $recorder)
            {
                parent::__construct($authKey);
                $this->recorder = $recorder;
            }

            public function translateText($texts, ?string $sourceLang, string $targetLang, array $options = []): array|TextResult
            {
                return ($this->recorder)((array)$texts, $sourceLang, $targetLang, $options);
            }
        };
    }

    #[Test]
    public function translatesAllFieldsPreservingOrder(): void
    {
        $result = $this->subject->translateTexts(
            ['header' => 'Hello', 'subheader' => 'World'],
            'tt_content',
            ['header' => 'Hello', 'subheader' => 'World'],
            'EN',
            'DE',
            'default',
            null,
            'key'
        );

        self::assertCount(2, $result);
        self::assertSame('T:Hello', $result[0]->text);
        self::assertSame('T:World', $result[1]->text);
    }

    #[Test]
    public function splitsRichtextFieldsIntoHtmlTagHandlingCall(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']['enableRichtext'] = true;

        $this->subject->translateTexts(
            ['header' => 'Hello', 'bodytext' => '<p>Rich</p>'],
            'tt_content',
            ['header' => 'Hello', 'bodytext' => '<p>Rich</p>'],
            'EN',
            'DE',
            'default',
            null,
            'key'
        );

        // One plain-text call (header) and one HTML call (bodytext).
        self::assertCount(2, $this->calls);

        $htmlCall = array_values(array_filter(
            $this->calls,
            static fn(array $call): bool => ($call['options'][TranslateTextOptions::TAG_HANDLING] ?? null) === 'html'
        ));
        self::assertCount(1, $htmlCall);
        self::assertSame(['<p>Rich</p>'], $htmlCall[0]['texts']);
    }

    #[Test]
    public function appliesFormalityOptionWhenConfigured(): void
    {
        $this->subject->translateTexts(
            ['header' => 'Hello'],
            'tt_content',
            ['header' => 'Hello'],
            'EN',
            'DE',
            'prefer_more',
            null,
            'key'
        );

        self::assertSame('prefer_more', $this->calls[0]['options'][TranslateTextOptions::FORMALITY] ?? null);
    }

    #[Test]
    public function omitsFormalityOptionForDefault(): void
    {
        $this->subject->translateTexts(
            ['header' => 'Hello'],
            'tt_content',
            ['header' => 'Hello'],
            'EN',
            'DE',
            'default',
            null,
            'key'
        );

        self::assertArrayNotHasKey(TranslateTextOptions::FORMALITY, $this->calls[0]['options']);
    }

    #[Test]
    public function returnsEmptyForNoInput(): void
    {
        self::assertSame([], $this->subject->translateTexts([], 'tt_content', [], 'EN', 'DE', 'default', null, 'key'));
        self::assertSame([], $this->calls);
    }
}
