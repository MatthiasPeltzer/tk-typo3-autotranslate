<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Acceptance\Backend;

use ThieleUndKlose\Autotranslate\Tests\Acceptance\Support\BackendTester;

final class SmokeCest
{
    public function _before(BackendTester $I): void
    {
        $I->useExistingSession('admin');
    }

    public function backendToolbarIsVisible(BackendTester $I): void
    {
        $I->seeElement('.t3js-scaffold-toolbar');
    }
}
