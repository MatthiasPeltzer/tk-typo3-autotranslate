<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Tests\Support\AutotranslateTcaManifest;
use ThieleUndKlose\Autotranslate\Tests\Support\TcaShowitemInspector;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TcaShowitemIntegrityTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = AutotranslateTcaManifest::FUNCTIONAL_TEST_EXTENSIONS;

    private TcaShowitemInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new TcaShowitemInspector();
    }

    #[Test]
    public function customTablesHaveValidShowitemReferences(): void
    {
        $violations = [];

        foreach (AutotranslateTcaManifest::CUSTOM_TABLES as $table) {
            $types = array_keys($GLOBALS['TCA'][$table]['types'] ?? []);
            foreach ($types as $typeName) {
                $violations = [
                    ...$violations,
                    ...$this->inspector->inspectTableType($table, (string)$typeName),
                ];
            }
        }

        self::assertSame([], $violations, $this->formatViolations($violations));
    }

    /**
     * @param list<string> $violations
     */
    private function formatViolations(array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        return "TCA showitem integrity violations:\n- " . implode("\n- ", $violations);
    }
}
