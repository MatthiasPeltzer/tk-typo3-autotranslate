<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\TranslationScopeResolver;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationScopeResolverTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private TranslationScopeResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TranslationScopeResolver();
    }

    private function enableChangedFieldsOnly(bool $enabled): void
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['translateChangedFieldsOnly' => $enabled]);
        GeneralUtility::addInstance(ExtensionConfiguration::class, $extensionConfiguration);
    }

    #[Test]
    public function resolveSingleTargetLanguageIdHandlesSingleMultiAndEmpty(): void
    {
        self::assertNull($this->subject->resolveSingleTargetLanguageId(null));
        self::assertNull($this->subject->resolveSingleTargetLanguageId(''));
        self::assertNull($this->subject->resolveSingleTargetLanguageId('1,2'), 'multiple languages -> not single');
        self::assertSame(2, $this->subject->resolveSingleTargetLanguageId('2'));
    }

    #[Test]
    public function resolveColumnsToTranslateReturnsAllWhenChangedFieldsOnlyDisabled(): void
    {
        $this->enableChangedFieldsOnly(false);

        self::assertSame(
            ['header', 'bodytext'],
            $this->subject->resolveColumnsToTranslate(['header', 'bodytext'], 'update', ['bodytext' => 'x'])
        );
    }

    #[Test]
    public function resolveColumnsToTranslateReturnsAllForNewRecords(): void
    {
        $this->enableChangedFieldsOnly(true);

        self::assertSame(
            ['header', 'bodytext'],
            $this->subject->resolveColumnsToTranslate(['header', 'bodytext'], 'new', null)
        );
    }

    #[Test]
    public function resolveColumnsToTranslateIntersectsChangedFieldsOnUpdate(): void
    {
        $this->enableChangedFieldsOnly(true);

        $result = $this->subject->resolveColumnsToTranslate(
            ['header', 'bodytext'],
            'update',
            ['bodytext' => 'new value', 'tstamp' => 123]
        );

        self::assertSame(['bodytext'], $result, 'only configured + changed (ignoring tstamp) survive');
    }

    #[Test]
    public function resolveColumnsForRecordSkipsCustomFieldNotChanged(): void
    {
        $this->enableChangedFieldsOnly(false);

        $existingTranslation = ['l10n_state' => json_encode(['header' => 'custom'])];

        $result = $this->subject->resolveColumnsForRecord(
            ['header', 'bodytext'],
            $existingTranslation,
            'update',
            ['bodytext' => 'changed'] // header NOT among changed source fields
        );

        self::assertSame(['bodytext'], $result, 'custom header skipped because its source did not change');
    }

    #[Test]
    public function resolveColumnsForRecordKeepsCustomFieldWhenSourceChanged(): void
    {
        $this->enableChangedFieldsOnly(false);

        $existingTranslation = ['l10n_state' => json_encode(['header' => 'custom'])];

        $result = $this->subject->resolveColumnsForRecord(
            ['header', 'bodytext'],
            $existingTranslation,
            'update',
            ['header' => 'changed', 'bodytext' => 'changed']
        );

        self::assertSame(['header', 'bodytext'], $result, 'custom header re-translated because its source changed');
    }
}
