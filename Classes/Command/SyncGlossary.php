<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ThieleUndKlose\Autotranslate\Service\GlossarySyncService;

final class SyncGlossary extends Command
{
    public function __construct(
        private readonly GlossarySyncService $glossarySyncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Synchronize Autotranslate glossary records with the DeepL API.')
            ->addOption(
            'folder',
            'f',
            InputOption::VALUE_REQUIRED,
            'UID of the glossary sysfolder page to synchronize.'
        );
        $this->addOption(
            'glossary',
            'g',
            InputOption::VALUE_REQUIRED,
            'UID of a single glossary record to synchronize (requires --folder).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $folderUid = (int)$input->getOption('folder');
        if ($folderUid <= 0) {
            $output->writeln('<error>Please provide --folder with the glossary sysfolder page UID.</error>');

            return Command::INVALID;
        }

        $glossaryUid = (int)$input->getOption('glossary');
        if ($glossaryUid > 0) {
            $result = $this->glossarySyncService->syncGlossary($glossaryUid, $folderUid);
            $this->writeResult($output, $result->success, $result->message);

            return $result->success ? Command::SUCCESS : Command::FAILURE;
        }

        $results = $this->glossarySyncService->syncFolder($folderUid);
        if ($results === []) {
            $output->writeln('<comment>No glossaries found to synchronize.</comment>');

            return Command::SUCCESS;
        }

        $exitCode = Command::SUCCESS;
        foreach ($results as $result) {
            $this->writeResult($output, $result->success, $result->message);
            if (!$result->success) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    private function writeResult(OutputInterface $output, bool $success, string $message): void
    {
        $output->writeln($success ? '<info>' . $message . '</info>' : '<error>' . $message . '</error>');
    }
}
