<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ThieleUndKlose\Autotranslate\Utility\Records;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repairs translated reference records that earlier autotranslate runs left behind:
 * duplicates of the same source record, orphans without a pointer to their default
 * language record, and pointers to records that no longer exist.
 */
final class RepairReferences extends Command
{
    private const ACTION_DELETE = 'delete';
    private const ACTION_RELINK = 'relink';

    protected function configure(): void
    {
        $this
            ->setDescription('Repair duplicated, orphaned and dangling translated reference records.')
            ->addOption(
                'apply',
                null,
                InputOption::VALUE_NONE,
                'Write the changes. Without this option the command only reports what it would do.'
            )
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit the run to the given reference tables (comma separated).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool)$input->getOption('apply');

        $tables = TranslationHelper::additionalReferenceTables();
        $onlyTables = $input->getOption('table');
        if (is_string($onlyTables) && $onlyTables !== '') {
            $tables = array_values(array_intersect($tables, GeneralUtility::trimExplode(',', $onlyTables, true)));
        }

        if ($tables === []) {
            $io->warning('No reference tables configured. Check the "additionalReferenceTables" extension setting.');

            return Command::INVALID;
        }

        if (!$apply) {
            $io->note('Dry run — nothing is written. Repeat with --apply to perform the changes.');
        }

        $changes = 0;
        foreach ($tables as $table) {
            $changes += $this->repairTable($table, $apply, $io);
        }

        if ($changes === 0) {
            $io->success('No broken translated references found.');

            return Command::SUCCESS;
        }

        if ($apply) {
            $io->success(sprintf('Repaired %d translated reference record(s).', $changes));
            $io->note('Run "referenceindex:update" afterwards to refresh sys_refindex.');
        } else {
            $io->warning(sprintf('%d translated reference record(s) would be repaired.', $changes));
        }

        return Command::SUCCESS;
    }

    /**
     * @return int Number of records that were (or would be) changed
     */
    private function repairTable(string $table, bool $apply, SymfonyStyle $io): int
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $parentField = is_string($ctrl['transOrigPointerField'] ?? null) ? $ctrl['transOrigPointerField'] : null;
        $languageField = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : null;
        $deleteField = is_string($ctrl['delete'] ?? null) ? $ctrl['delete'] : null;

        if ($parentField === null || $languageField === null || $deleteField === null) {
            $io->warning(sprintf('Skipping "%s": not a language aware, soft-deletable table.', $table));

            return 0;
        }

        $io->section($table);

        /** @var list<array{uid: int, action: string, target: int, reason: string}> $plan */
        $plan = [];
        /** @var array<string, list<int>> $byAncestor */
        $byAncestor = [];

        foreach ($this->fetchTranslatedRows($table, $languageField) as $row) {
            $uid = (int)$row['uid'];
            $languageId = (int)$row[$languageField];
            $ancestorUid = (int)$row[$parentField];

            if ($ancestorUid === 0) {
                $decision = $this->resolveOrphan($table, $row, $parentField, $languageField, $languageId);
                if ($decision !== null) {
                    $plan[] = ['uid' => $uid, ...$decision];
                }
                continue;
            }

            if (!is_array(Records::getRecord($table, $ancestorUid))) {
                $plan[] = [
                    'uid' => $uid,
                    'action' => self::ACTION_DELETE,
                    'target' => 0,
                    'reason' => sprintf('points to missing default record %d', $ancestorUid),
                ];
                continue;
            }

            $byAncestor[$ancestorUid . '/' . $languageId][] = $uid;
        }

        foreach ($byAncestor as $group => $uids) {
            if (count($uids) < 2) {
                continue;
            }
            sort($uids);
            foreach (array_slice($uids, 1) as $duplicateUid) {
                $plan[] = [
                    'uid' => $duplicateUid,
                    'action' => self::ACTION_DELETE,
                    'target' => 0,
                    'reason' => sprintf('duplicate of %d for source/language %s', $uids[0], $group),
                ];
            }
        }

        if ($plan === []) {
            $io->writeln('Nothing to repair.');

            return 0;
        }

        $rows = [];
        foreach ($plan as $entry) {
            $rows[] = [
                $entry['uid'],
                $entry['action'] === self::ACTION_RELINK
                    ? sprintf('relink to %d', $entry['target'])
                    : 'delete',
                $entry['reason'],
            ];
        }
        $io->table(['uid', 'action', 'reason'], $rows);

        if ($apply) {
            foreach ($plan as $entry) {
                $properties = $entry['action'] === self::ACTION_RELINK
                    ? [$parentField => $entry['target']]
                    : [$deleteField => 1];
                Records::updateRecord($table, $entry['uid'], $properties);
            }
        }

        return count($plan);
    }

    /**
     * Decide what to do with a translated reference that has no pointer to a default
     * language record: reconnect it when its default sibling is still untranslated,
     * drop it when that sibling already has a proper translation.
     *
     * @param array<string, mixed> $row
     * @return array{action: string, target: int, reason: string}|null
     */
    private function resolveOrphan(string $table, array $row, string $parentField, string $languageField, int $languageId): ?array
    {
        $parentInfo = TranslationHelper::resolveReferenceParent($table, $row);
        if ($parentInfo === null) {
            return null;
        }

        $parentRecord = Records::getRecord($parentInfo['table'], $parentInfo['uid']);
        $parentOrigField = TranslationHelper::translationOrigPointerField($parentInfo['table']);
        if (!is_array($parentRecord) || $parentOrigField === null) {
            return null;
        }

        // Only references sitting on a translated record can be reconnected.
        $defaultParentUid = (int)($parentRecord[$parentOrigField] ?? 0);
        if ($defaultParentUid <= 0) {
            return null;
        }

        $foreignField = $GLOBALS['TCA'][$parentInfo['table']]['columns'][$parentInfo['column']]['config']['foreign_field'] ?? '';
        if (!is_string($foreignField) || $foreignField === '') {
            return null;
        }

        $constraints = [
            $foreignField => $defaultParentUid,
            $languageField => 0,
            $parentField => 0,
        ];
        foreach (['tablenames' => $parentInfo['table'], 'fieldname' => $parentInfo['column']] as $column => $value) {
            if (array_key_exists($column, $row)) {
                $constraints[$column] = $value;
            }
        }
        if (array_key_exists('uid_local', $row)) {
            $constraints['uid_local'] = (int)$row['uid_local'];
        }

        $candidates = Records::getRecords($table, 'uid', static function (QueryBuilder $queryBuilder) use ($constraints): void {
            foreach ($constraints as $column => $value) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        $column,
                        is_int($value)
                            ? $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)
                            : $queryBuilder->createNamedParameter($value)
                    )
                );
            }
            $queryBuilder->orderBy('uid');
        });

        if (count($candidates) !== 1) {
            return null;
        }

        $sourceUid = (int)$candidates[0];
        if (Records::getRecordTranslation($table, $sourceUid, $languageId) !== null) {
            return [
                'action' => self::ACTION_DELETE,
                'target' => 0,
                'reason' => sprintf('unlinked copy, source %d is already translated', $sourceUid),
            ];
        }

        return [
            'action' => self::ACTION_RELINK,
            'target' => $sourceUid,
            'reason' => sprintf('unlinked translation of %d', $sourceUid),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTranslatedRows(string $table, string $languageField): array
    {
        $queryBuilder = Records::getQueryBuilder($table);

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->gt($languageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }
}
