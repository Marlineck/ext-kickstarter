<?php

declare(strict_types=1);

/*
 * This file is part of the package stefanfroemken/ext-kickstarter.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace StefanFroemken\ExtKickstarter\Command;

use StefanFroemken\ExtKickstarter\Command\Question\ChoseExtensionKeyQuestion;
use StefanFroemken\ExtKickstarter\Information\ExtensionInformation;
use StefanFroemken\ExtKickstarter\Information\TableInformation;
use StefanFroemken\ExtKickstarter\Service\Creator\TableCreatorService;
use StefanFroemken\ExtKickstarter\Traits\CreatorInformationTrait;
use StefanFroemken\ExtKickstarter\Traits\ExtensionInformationTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class TableCommand extends Command
{
    use CreatorInformationTrait;
    use ExtensionInformationTrait;

    public function __construct(
        private readonly TableCreatorService $tableCreatorService,
        private readonly ChoseExtensionKeyQuestion $choseExtensionKeyQuestion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'extension_key',
            InputArgument::OPTIONAL,
            'Provide the extension key you want to extend.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Welcome to the TYPO3 Extension Builder');

        $io->text([
            'We are here to assist you in creating a new TCA table.',
            'Now, we will ask you a few questions to customize the controller according to your needs.',
            'Please take your time to answer them.',
        ]);

        $tableInformation = $this->askForTableInformation($io, $input);
        $this->tableCreatorService->create($tableInformation);
        $this->printCreatorInformation($tableInformation->getCreatorInformation(), $io);

        return Command::SUCCESS;
    }

    private function askForTableInformation(SymfonyStyle $io, InputInterface $input): TableInformation
    {
        $extensionInformation = $this->getExtensionInformation(
            $this->choseExtensionKeyQuestion->ask($io, $input->getArgument('extension_key')),
            $io
        );

        return new TableInformation(
            $extensionInformation,
            $this->askForTableName($io, $extensionInformation),
            (string)$io->ask('Please provide a table title'),
            'uid', // Until now, we do not have any defined columns. We set label to "uid" first as it is mandatory
            $this->askForTableColumns($io),
        );
    }

    private function askForTableName(SymfonyStyle $io, ExtensionInformation $extensionInformation): string
    {
        $prefix = $extensionInformation->getTableNamePrefix();

        do {
            $tableName = strtolower((string)$io->ask(
                'Please provide the table name. Usually the table name starts with: ' . $prefix
            ));

            // 1. Check if name is empty
            if (trim($tableName) === '') {
                $io->error('Table name must not be empty.');
                $validTableName = false;
                continue;
            }

            // 2. Check if name equals the prefix only (e.g., "tx_yyy_domain_model_")
            if ($tableName === $prefix) {
                $io->error('Table name must not be only the prefix: ' . $prefix);
                $validTableName = false;
                continue;
            }

            // 3. If name starts with prefix, accept it directly
            if (str_starts_with($tableName, $prefix)) {
                return $tableName;
            }

            // 4. Suggest prefix + name if user entered only suffix
            $suggestedName = $prefix . $tableName;
            $isTableNameConfirmed = $io->confirm(
                'Would you like to adopt the suggested table name: ' . $suggestedName . '?'
            );

            if ($isTableNameConfirmed) {
                return $suggestedName;
            }

            $validTableName = true; // user declined suggestion but provided valid name
        } while (!$validTableName);

        return $tableName;
    }

    private function askForTableColumns(SymfonyStyle $io): array
    {
        $tableColumns = [];
        $validTableColumnName = false;
        $defaultColumnName = null;

        do {
            $tableColumnName = (string)$io->ask('Enter column name we should create for you', $defaultColumnName);

            if (trim($tableColumnName) === '') {
                $io->error('Table column name must not be empty.');
                $defaultColumnName = $this->tryToCorrectColumnName($tableColumnName);
                $validTableColumnName = false;
            } elseif (preg_match('/^\d/', $tableColumnName)) {
                $io->error('Table column should not start with a number.');
                $defaultColumnName = $this->tryToCorrectColumnName($tableColumnName);
                $validTableColumnName = false;
            } elseif (preg_match('/[^a-z0-9_]/', $tableColumnName)) {
                $io->error('Table column name contains invalid chars. Please provide just letters, numbers and underscores.');
                $defaultColumnName = $this->tryToCorrectColumnName($tableColumnName);
                $validTableColumnName = false;
            } else {
                $tableColumns[$tableColumnName]['label'] = $io->ask(
                    'Please provide a label for the column',
                    ucwords(str_replace('_', ' ', $tableColumnName))
                );
                $tableColumns[$tableColumnName]['config'] = $this->askForTableColumnConfiguration($io);
                if ($io->confirm('Do you want to add another table column?')) {
                    continue;
                }
                $validTableColumnName = true;
            }
        } while (!$validTableColumnName);

        return $tableColumns;
    }

    private function tryToCorrectColumnName(string $givenColumnName): string
    {
        // Change dash to underscore
        $cleanedColumnName = str_replace('-', '_', $givenColumnName);

        // Change column name to lower camel case. Add underscores before upper case letters. BlogExample => blog_example
        $cleanedColumnName = GeneralUtility::camelCaseToLowerCaseUnderscored($cleanedColumnName);

        // Remove invalid chars
        return preg_replace('/[^a-zA-Z0-9_]/', '', $cleanedColumnName);
    }

    private function askForTableColumnConfiguration(SymfonyStyle $io): array
    {
        $tableColumnType = $io->choice(
            'Choose TCA column type',
            array_keys(TableCreatorService::TABLE_COLUMN_TYPES),
            'input'
        );

        $columnConfig = TableCreatorService::TABLE_COLUMN_TYPES[$tableColumnType] ?? [];

        if($tableColumnType === 'text') {
            $makeTextRte = $io->choice(
                'Make Textfield RTE?',
                ['YES', 'NO'], 'NO');
            if ($makeTextRte === 'YES') {
                $columnConfig['enableRichtext'] = 'true';
            }
        }

        return $columnConfig;
    }
}
