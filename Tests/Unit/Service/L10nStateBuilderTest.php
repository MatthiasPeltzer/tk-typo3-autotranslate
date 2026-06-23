<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Service\L10nStateBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class L10nStateBuilderTest extends UnitTestCase
{
    private L10nStateBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new L10nStateBuilder();
    }

    #[Test]
    public function returnsEmptyJsonObjectWhenTableHasNoDiffSourceField(): void
    {
        $GLOBALS['TCA']['tx_autotranslate_unit_test']['ctrl'] = [];

        $result = $this->subject->build('tx_autotranslate_unit_test', 1, ['header', 'bodytext'], 10, 5);

        self::assertSame('{}', $result);
    }

    #[Test]
    public function returnsEmptyJsonObjectForUnknownTable(): void
    {
        unset($GLOBALS['TCA']['tx_autotranslate_unknown_table']);

        $result = $this->subject->build('tx_autotranslate_unknown_table', 1, ['header'], 10, 5);

        self::assertSame('{}', $result);
    }
}
