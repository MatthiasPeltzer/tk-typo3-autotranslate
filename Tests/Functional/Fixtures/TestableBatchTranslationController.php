<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional\Fixtures;

use ThieleUndKlose\Autotranslate\Controller\BatchTranslationBaseController;

/**
 * Test-only subclass exposing the protected authorization guard.
 */
final class TestableBatchTranslationController extends BatchTranslationBaseController
{
    public function exposeUserCanCreateForPage(int $pid, int $sysLanguageUid): bool
    {
        return $this->userCanCreateForPage($pid, $sysLanguageUid);
    }
}
