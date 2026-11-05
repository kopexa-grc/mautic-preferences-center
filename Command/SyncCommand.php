<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Command;

use MauticPlugin\KopexaPreferencesCenterBundle\Service\PreferencesCenterSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * preferences-center:sync — topics, preference page and Mautic's settings
 * from a JSON declaration (see README). Idempotent: run it on every deploy.
 */
#[AsCommand(name: 'preferences-center:sync', description: 'Set up the preference center (topics, page, settings) from a JSON declaration')]
final class SyncCommand extends Command
{
    public function __construct(private readonly PreferencesCenterSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'JSON file with the declaration')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'The declaration as JSON')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getOption('file');
        $json = $input->getOption('json');
        if (is_string($file) === is_string($json)) {
            $output->writeln('<error>Give the declaration with either --file or --json.</error>');

            return Command::INVALID;
        }
        if (is_string($file)) {
            $json = is_readable($file) ? (string) file_get_contents($file) : null;
            if (null === $json) {
                $output->writeln("<error>Cannot read $file.</error>");

                return Command::FAILURE;
            }
        }

        try {
            foreach ($this->sync->apply($this->sync->parse((string) $json), (bool) $input->getOption('dry-run')) as $line) {
                $output->writeln($line);
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
