<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Acceptance\Backend;

use ThieleUndKlose\Autotranslate\Tests\Acceptance\Support\BackendTester;

final class ExtensionAcceptanceCest
{
    public function _before(BackendTester $I): void
    {
        $I->useExistingSession('admin');
    }

    public function batchTranslationModuleIsRegistered(BackendTester $I): void
    {
        $I->switchToIFrame();
        $I->see('Batch Translations', '#modulemenu');
    }
}
