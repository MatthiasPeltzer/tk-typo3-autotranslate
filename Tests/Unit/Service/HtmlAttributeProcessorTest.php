<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\HtmlAttributeProcessor;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class HtmlAttributeProcessorTest extends UnitTestCase
{
    private HtmlAttributeProcessor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new HtmlAttributeProcessor();
    }

    #[Test]
    public function detectsHtml(): void
    {
        self::assertTrue($this->subject->isHtml('<p>Hello</p>'));
        self::assertFalse($this->subject->isHtml('Hello'));
    }

    #[Test]
    public function extractsLinkTitleIntoPlaceholderEntry(): void
    {
        $result = $this->subject->extractAttributes([
            'bodytext' => '<p>See <a href="#" title="Read more">here</a></p>',
        ]);

        self::assertArrayHasKey('__ATTR__1__', $result);
        self::assertSame('Read more', $result['__ATTR__1__']);
        self::assertStringContainsString('title="__ATTR__1__"', $result['bodytext']);
        self::assertStringNotContainsString('Read more', $result['bodytext']);
    }

    #[Test]
    public function leavesPlainTextAndEmptyValuesUntouched(): void
    {
        $input = [
            'header' => 'Just text',
            'empty' => '',
            'nolink' => '<p>No anchors here</p>',
        ];

        $result = $this->subject->extractAttributes($input);

        self::assertSame($input, $result, 'nothing to extract -> array is unchanged');
    }

    #[Test]
    public function roundTripRestoresTranslatedAttribute(): void
    {
        $extracted = $this->subject->extractAttributes([
            'bodytext' => '<a href="#" title="Read more">here</a>',
        ]);

        $translatedHtml = $extracted['bodytext'];
        $restored = $this->subject->restoreAttributes($translatedHtml, ['__ATTR__1__' => 'Mehr lesen']);

        self::assertStringContainsString('title="Mehr lesen"', $restored);
        self::assertStringNotContainsString('__ATTR__1__', $restored);
    }

    #[Test]
    public function reusesSinglePlaceholderForIdenticalAttributeValuesInOneField(): void
    {
        $result = $this->subject->extractAttributes([
            'bodytext' => '<a href="#a" title="Home">A</a><a href="#b" title="Home">B</a>',
        ]);

        self::assertArrayHasKey('__ATTR__1__', $result);
        self::assertArrayNotHasKey('__ATTR__2__', $result, 'identical values must share one placeholder');
        self::assertSame('Home', $result['__ATTR__1__']);
        self::assertSame(2, substr_count($result['bodytext'], 'title="__ATTR__1__"'));
    }

    #[Test]
    public function reusesSinglePlaceholderForIdenticalAttributeValuesAcrossFields(): void
    {
        $result = $this->subject->extractAttributes([
            'header' => '<a href="#" title="Home">A</a>',
            'bodytext' => '<a href="#" title="Home">B</a>',
        ]);

        self::assertArrayHasKey('__ATTR__1__', $result);
        self::assertArrayNotHasKey('__ATTR__2__', $result, 'identical values across fields must share one placeholder');
        self::assertStringContainsString('title="__ATTR__1__"', $result['header']);
        self::assertStringContainsString('title="__ATTR__1__"', $result['bodytext']);
    }

    #[Test]
    public function preservesAmpersandEntityWhileExtractingAndRestoring(): void
    {
        $extracted = $this->subject->extractAttributes([
            'bodytext' => '<p>Tom &amp; Jerry <a href="#" title="Read more">x</a></p>',
        ]);

        self::assertStringContainsString('Tom &amp; Jerry', $extracted['bodytext'], 'encoded ampersand survives extraction');

        $restored = $this->subject->restoreAttributes($extracted['bodytext'], ['__ATTR__1__' => 'Mehr lesen']);

        self::assertStringContainsString('Tom &amp; Jerry', $restored, 'encoded ampersand survives restore');
        self::assertStringContainsString('title="Mehr lesen"', $restored);
    }

    #[Test]
    public function restoreDoesNotConfusePlaceholderOneWithPlaceholderEleven(): void
    {
        // Restoring __ATTR__1__ must not corrupt __ATTR__11__ (the trailing "__"
        // guards against the prefix collision).
        $html = 'a=__ATTR__1__ b=__ATTR__11__';
        $restored = $this->subject->restoreAttributes($html, [
            '__ATTR__1__' => 'ONE',
            '__ATTR__11__' => 'ELEVEN',
        ]);

        self::assertSame('a=ONE b=ELEVEN', $restored);
    }
}
