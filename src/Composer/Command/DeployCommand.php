<?php

declare(strict_types = 1);

namespace Sweetchuck\GitHooks\Composer\Command;

use Composer\Command\BaseCommand;
use Sweetchuck\GitHooks\ConfigReader;
use Sweetchuck\GitHooks\GitHookManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DeployCommand extends BaseCommand
{

    /**
     * @var array
     */
    protected $result = [];

    /**
     * @var \Sweetchuck\GitHooks\ConfigReader
     */
    protected $configReader;

    /**
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    public function getInput(): InputInterface
    {
        return $this->input;
    }

    /**
     * @return $this
     */
    public function setInput(InputInterface $input)
    {
        $this->input = $input;

        return $this;
    }

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @return $this
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function __construct(string $name = null, ?ConfigReader $configReader = null)
    {
        $this->configReader = $configReader ?: new ConfigReader();
        parent::__construct($name);
    }

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        parent::configure();
        if (!$this->getName()) {
            $this->setName('git-hooks:deploy');
        }

        $this
            ->setDescription('Deploys Git hooks scripts based on the configuration')
            ->addOption(
                'symlink',
                's',
                InputOption::VALUE_NONE,
                'Symlink or copy'
            )
            ->addOption(
                'no-symlink',
                'S',
                InputOption::VALUE_NONE,
                'Symlink or copy'
            )
            ->addOption(
                'core-hooks-path',
                'p',
                InputOption::VALUE_REQUIRED,
                'Value for core.hooksPath Git config'
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this
            ->setInput($input)
            ->setOutput($output)
            ->doIt();

        return $this->result['exitCode'];
    }

    protected function doIt()
    {
        $this->result = $this
            ->getGitHookManager()
            ->deploy($this->getConfig());

        return $this;
    }

    protected function getGitHookManager(): GitHookManager
    {
        return new GitHookManager($this->getIO());
    }

    protected function getConfig(): array
    {
        $package = $this->getComposer()->getPackage();
        $extra = $package->getExtra();

        return $this
            ->configReader
            ->getConfig(
                $this->getInput(),
                $extra[$package->getName()] ?? []
            );
    }
}
