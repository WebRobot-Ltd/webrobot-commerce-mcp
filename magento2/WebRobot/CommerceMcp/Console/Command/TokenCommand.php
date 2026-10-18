<?php
declare(strict_types=1);

namespace WebRobot\CommerceMcp\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WebRobot\CommerceMcp\Model\Config;

/**
 * bin/magento webrobot:mcp:token — generate a new MCP token (revokes the previous one) and print it once.
 */
class TokenCommand extends Command
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('webrobot:mcp:token')
            ->setDescription('Generate a new WebRobot Commerce MCP token (revokes the previous one) and print it once.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->config->regenerateToken());
        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln(
                '<comment>Copy it now: only its hash is stored. The previous token no longer works.</comment>'
            );
        }
        return Command::SUCCESS;
    }
}
