<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Tests\Functional\Fixtures\TestableBatchTranslationController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional coverage of the batch-creation authorization guard. The guard
 * prevents crafted requests from queueing translations for pages or languages
 * the backend user may not access (otherwise the scheduler would translate them
 * with no per-user check).
 */
final class BatchTranslationAuthorizationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['thieleundklose/autotranslate'];

    private TestableBatchTranslationController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->setUpBackendUser(1);

        $this->subject = new TestableBatchTranslationController();
    }

    #[Test]
    public function adminMayCreateForExistingPage(): void
    {
        self::assertTrue($this->subject->exposeUserCanCreateForPage(1, 0));
    }

    #[Test]
    public function rejectsNonPositivePageId(): void
    {
        self::assertFalse($this->subject->exposeUserCanCreateForPage(0, 0));
        self::assertFalse($this->subject->exposeUserCanCreateForPage(-5, 0));
    }

    #[Test]
    public function rejectsMissingPage(): void
    {
        self::assertFalse(
            $this->subject->exposeUserCanCreateForPage(999999, 0),
            'unknown page id must be rejected'
        );
    }
}
