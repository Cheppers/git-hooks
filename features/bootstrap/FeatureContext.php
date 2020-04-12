<?php

declare(strict_types = 1);

use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\PyStringNode;
use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class FeatureContext implements Context
{

    /**
     * Base reference point. The directory of the behat.yml.
     *
     * @var string
     */
    protected static $projectRootDir = '';

    /**
     * Relative to static::$projectRootDir.
     *
     * @var string
     */
    protected static $fixturesDir = 'fixtures';

    /**
     * @var string
     */
    protected static $gitExecutable = 'git';

    /**
     * @var array
     */
    protected static $composer = [];

    /**
     * Random directory name somewhere in the /tmp directory.
     *
     * @var string
     */
    protected static $suitRootDir = '';

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected static $fs;

    /**
     * @BeforeSuite
     */
    public static function hookBeforeSuite()
    {
        static::$projectRootDir = getcwd();

        static::initComposer();
        static::initSuitRootDir();
        static::initFilesystem();
    }

    /**
     * @AfterSuite
     */
    public static function hookAfterSuite()
    {
        if (getenv('SWEETCHUCK_GIT_HOOKS_SKIP_AFTER_CLEANUP') === 'true') {
            return;
        }

        if (static::$fs->exists(static::$suitRootDir)) {
            static::$fs->remove(static::$suitRootDir);
        }
    }

    protected static function initFilesystem()
    {
        static::$fs = new Filesystem();
    }

    protected static function initComposer()
    {
        $fileName = static::$projectRootDir . '/composer.json';
        static::$composer = json_decode(file_get_contents($fileName), true);
        if (static::$composer === null) {
            throw new InvalidArgumentException("Composer JSON file cannot be decoded. '$fileName'");
        }
    }

    protected static function initSuitRootDir()
    {
        static::$suitRootDir = implode('/', [
            sys_get_temp_dir(),
            static::$composer['name'],
            'suit-' . static::randomId(),
        ]);
    }

    protected static function getGitTemplateDir(string $type): string
    {
        return implode('/', [
            static::$projectRootDir,
            static::$fixturesDir,
            'git-template',
            $type,
        ]);
    }

    protected static function normalizePath(string $path): string
    {
        // Remove any kind of funky unicode whitespace.
        $normalized = preg_replace('#\p{C}+|^\./#u', '', $path);

        // Remove self referring paths ("/./").
        $normalized = preg_replace('#/\.(?=/)|^\./|\./$#', '', $normalized);

        // Regex for resolving relative paths.
        $pattern = '#\/*[^/\.]+/\.\.#Uu';

        while (preg_match($pattern, $normalized)) {
            $normalized = preg_replace($pattern, '', $normalized);
        }

        if (preg_match('#/\.{2}|\.{2}/#', $normalized)) {
            throw new LogicException("Path is outside of the defined root, path: [$path], resolved: [$normalized]");
        }

        return rtrim($normalized, '/');
    }

    /**
     * Absolute directory name. This dir is under the static::$suitRootDir.
     *
     * @var string
     */
    protected $scenarioRootDir = '';

    /**
     * Current working directory.
     *
     * @var string
     */
    protected $cwd = '';

    /**
     * @var Symfony\Component\Process\Process
     */
    protected $process = null;

    /**
     * Prepares test folders in the temporary directory.
     *
     * @BeforeScenario
     */
    public function initScenarioRootDir()
    {
        $this->scenarioRootDir = static::$suitRootDir . '/scenario-' .  static::randomId();
        static::$fs->mkdir($this->scenarioRootDir);
        $this->cwd = "{$this->scenarioRootDir}/workspace";
    }

    /**
     * @AfterScenario
     */
    public function cleanScenarioRootDir()
    {
        if (getenv('SWEETCHUCK_GIT_HOOKS_SKIP_AFTER_CLEANUP') === 'true') {
            return;
        }

        if (static::$fs->exists($this->scenarioRootDir)) {
            static::$fs->remove($this->scenarioRootDir);
        }
    }

    protected static function randomId(): string
    {
        return md5((string) (microtime(true) * rand(0, 10000)));
    }

    /**
     * @Given I run git add remote :name :uri
     */
    public function doGitRemoteAdd(string $name, string $uri)
    {
        $cmd = [
            static::$gitExecutable,
            'remote',
            'add',
            $name,
            $uri,
        ];

        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I create a :type project in :dir directory
     */
    public function doCreateProjectInstance(string $dir, string $type)
    {
        $dirNormalized = $this->getWorkspacePath($dir);
        if (static::$fs->exists("$dirNormalized/composer.json")) {
            throw new LogicException("A project is already exists in: '$dirNormalized'");
        }

        $this->doCreateProjectCache($type);
        $projectCacheDir = $this->getProjectCacheDir($type);
        static::$fs->mirror($projectCacheDir, $dirNormalized);
        $this->doGitInitLocal($dir);

        $this->doExec(['composer', 'run', 'post-install-cmd']);
    }

    /**
     * @Given I am in the :dir directory
     */
    public function doChangeWorkingDirectory(string $dir)
    {
        $dirNormal = $this->getWorkspacePath($dir);

        if (strpos($dirNormal, $this->scenarioRootDir) !== 0) {
            throw new InvalidArgumentException('Out of working directory.');
        }

        static::$fs->mkdir($dirNormal);

        if (!chdir($dirNormal)) {
            throw new IOException("Failed to step into directory: '$dirNormal'.");
        }

        $this->cwd = $dirNormal;
    }

    /**
     * @Given I create a :fileName file
     */
    public function doCreateFile(string $fileName)
    {
        static::$fs->touch($this->getWorkspacePath($fileName));
    }

    /**
     * @Given I initialize a local Git repo in directory :dir
     * @Given I initialize a local Git repo in directory :dir with :tpl git template
     */
    public function doGitInitLocal(string $dir, string $tpl = 'basic')
    {
        $this->doGitInit($dir, $tpl, false);
    }

    /**
     * @Given I initialize a bare Git repo in directory :dir
     * @Given I initialize a bare Git repo in directory :dir with :ttype git template
     */
    public function doGitInitBare(string $dir, string $type = 'basic')
    {
        $dirNormalized = $this->getWorkspacePath($dir);
        if (static::$fs->exists("$dirNormalized/.git")
            || static::$fs->exists("$dirNormalized/config")
        ) {
            throw new LogicException("A git repository is already exists in: '$dirNormalized'");
        }

        $this->doCreateProjectCache($type);
        $projectCacheDir = $this->getProjectCacheDir($type);
        static::$fs->mirror($projectCacheDir, $dirNormalized);
        $this->doGitInit($dir, $type, true);

        $this->doExec(['composer', 'run', 'post-install-cmd']);
    }

    /**
     * @Given I run git add :files
     *
     * @todo NodeTable.
     */
    public function doGitAdd(string $files)
    {
        $cmd = array_merge(
            [
                static::$gitExecutable,
                'add',
                '--',
            ],
            preg_split('/, /', $files)
        );

        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git commit
     * @Given /^I run git commit -m "(?P<message>[^"]+)"$/
     */
    public function doGitCommit(?string $message = null)
    {
        $cmd = [
            static::$gitExecutable,
            'commit',
        ];

        if ($message) {
            $cmd[] = '-m';
            $cmd[] = $message;
        }

        $this->process = $this->doExec(
            $cmd,
            [
                'exitCode' => false,
            ]
        );
    }

    /**
     * @Given I run git push :remote :branch
     */
    public function doGitPush(string $remote, string $branch)
    {
        $this->process = $this->doExec(
            [
                static::$gitExecutable,
                'push',
                $remote,
                $branch,
            ],
            [
                'exitCode' => false,
            ]
        );
    }

    /**
     * @Given I commit a new :fileName file with message :message and content:
     */
    public function doGitCommitNewFileWithMessageAndContent(
        string $fileName,
        string $message,
        PyStringNode $content
    ) {
        $this->doCreateFile($fileName);
        static::$fs->dumpFile($fileName, $content);
        $this->doGitAdd($fileName);
        $this->doGitCommit($message);
    }

    /**
     * @Given I run git checkout -b :branch
     */
    public function doGitCheckoutNewBranch(string $branch)
    {
        $cmd = [
            static::$gitExecutable,
            'checkout',
            '-b',
            $branch,
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git checkout :branch -- :file
     */
    public function doGitCheckoutFile(string $branch, string $file)
    {
        $cmd = [
            static::$gitExecutable,
            'checkout',
            $branch,
            '--',
            $file,
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git checkout :branch
     */
    public function doRunGitCheckout(string $branch)
    {
        $cmd = [
            static::$gitExecutable,
            'checkout',
            $branch
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git branch :branch
     */
    public function doGitBranchCreate(string $branch)
    {
        $cmd = [
            static::$gitExecutable,
            'branch',
            $branch
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git rebase :upstream
     * @Given I run git rebase :upstream :branch
     *
     * @param string $upstream
     *   Upstream branch to compare against.
     * @param string $branch
     *   Name of the base branch.
     */
    public function doRunGitRebase(string $upstream, ?string $branch = null)
    {
        $cmd = [
            static::$gitExecutable,
            'rebase',
            $upstream,
        ];

        if ($branch) {
            $cmd[] = $branch;
        }

        $this->process = $this->doExec(
            $cmd,
            [
                'exitCode' => false,
            ]
        );
    }

    /**
     * @Given I run git merge :branch -m :message
     */
    public function doGitMerge(string $branch, string $message)
    {
        $cmd = [
            static::$gitExecutable,
            'merge',
            $branch,
            '-m',
            $message,
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given I run git merge :branch --squash -m :message
     */
    public function doGitMergeSquash(string $branch, string $message)
    {
        $cmd = [
            static::$gitExecutable,
            'merge',
            $branch,
            '--ff',
            '--squash',
            '-m',
            $message
        ];
        $this->process = $this->doExec($cmd);
    }

    /**
     * @Given /^I run git config core.editor (?P<editor>true|false)$/
     */
    public function doGitConfigSetCoreEditor(string $editor)
    {
        $this->doGitConfigSet('core.editor', $editor);
    }

    /**
     * @Given /^I wait for (?P<amount>\d+) seconds$/
     */
    public function doWait(string $amount)
    {
        sleep(intval($amount));
    }

    /**
     * @Then /^the exit code should be (?P<exitCode>\d+)$/
     */
    public function assertExitCodeEquals(string $exitCode)
    {
        Assert::assertSame(
            (int) $exitCode,
            $this->process->getExitCode(),
            "Exit codes don't match"
        );
    }

    /**
     * @Then /^the stdOut should contains the following text:$/
     *
     * @param \Behat\Gherkin\Node\PyStringNode $string
     */
    public function assertStdOutContains(PyStringNode $string)
    {
        $output = $this->trimTrailingWhitespaces($this->process->getOutput());
        $output = $this->removeColorCodes($output);

        Assert::assertStringContainsString($string->getRaw(), $output);
    }

    /**
     * @Then /^the stdErr should contains the following text:$/
     *
     * @param \Behat\Gherkin\Node\PyStringNode $string
     */
    public function assertStdErrContains(PyStringNode $string)
    {
        $output = $this->trimTrailingWhitespaces($this->process->getErrorOutput());
        $output = $this->removeColorCodes($output);

        Assert::assertStringContainsString($string->getRaw(), $output);
    }

    /**
     * @Given /^the number of commits is (?P<expected>\d+)$/
     */
    public function assertGitLogLength(string $expected)
    {
        $cmd = [
            'bash',
            '-c',
            sprintf(
                '%s log --format=%s | cat',
                static::$gitExecutable,
                '%h'
            ),
        ];
        $gitLog = $this->doExec(
            $cmd,
            [
                'exitCode' => false,
            ]
        );

        Assert::assertSame(
            (int) $expected,
            substr_count($gitLog->getOutput(), "\n")
        );
    }

    /**
     * @Given the git log is not empty
     */
    public function assertGitLogIsNotEmpty()
    {
        $cmd = [
            static::$gitExecutable,
            'log',
            '-1',
        ];

        $gitLog = $this->doExec($cmd);
        Assert::assertNotEquals('', $gitLog->getOutput());
    }

    /**
     * @Given the git log is empty
     */
    public function assertGitLogIsEmpty()
    {
        $cmd = [
            static::$gitExecutable,
            'log',
            '-1',
        ];
        $gitLog = $this->doExec($cmd);
        Assert::assertEquals('', $gitLog->getOutput());
    }

    protected function getWorkspacePath(string $path): string
    {
        $normalizedPath = static::normalizePath("{$this->cwd}/$path");
        $this->validateWorkspacePath($normalizedPath);

        return $normalizedPath;
    }

    protected function validateWorkspacePath(string $normalizedPath)
    {
        if (strpos($normalizedPath, "{$this->scenarioRootDir}/workspace") !== 0) {
            throw new InvalidArgumentException('Out of working directory.');
        }
    }

    protected function doCreateProjectCache(string $projectType)
    {
        $projectCacheDir = $this->getProjectCacheDir($projectType);
        if (static::$fs->exists($projectCacheDir)) {
            return;
        }

        $projectTemplate = implode('/', [
            static::$projectRootDir,
            'fixtures',
            'project-template',
            $projectType,
        ]);
        static::$fs->mirror($projectTemplate, $projectCacheDir);

        $composerJson = json_decode(file_get_contents("$projectCacheDir/composer.json"), true);
        $composerJson['repositories']['local']['url'] = static::$projectRootDir;
        static::$fs->dumpFile(
            "$projectCacheDir/composer.json",
            json_encode($composerJson, JSON_PRETTY_PRINT)
        );

        $composerLock = json_decode(file_get_contents("$projectCacheDir/composer.lock"), true);
        foreach ($composerLock['packages'] as $i => $package) {
            if ($package['name'] !== 'sweetchuck/git-hooks') {
                continue;
            }

            $composerLock['packages'][$i]['dist'] = [
                'type' => 'path',
                'url' => static::$projectRootDir,
                'reference' => 'abcdefg',
            ];

            static::$fs->dumpFile(
                "$projectCacheDir/composer.lock",
                json_encode($composerLock, JSON_PRETTY_PRINT)
            );

            break;
        }

        if ($projectType !== 'basic') {
            $master = implode('/', [
                static::$projectRootDir,
                'fixtures',
                'project-template',
                'basic',
            ]);
            $files = [
                '.git-hooks',
                '.gitignore',
                'RoboFile.php',
            ];
            foreach ($files as $fileName) {
                static::$fs->copy("$master/$fileName", "$projectCacheDir/$fileName");
            }
        }

        $cmd = [
            'composer',
            'install',
            '--no-interaction',
        ];

        $this->doExecCwd($projectCacheDir, $cmd);
    }

    /**
     * I initialize a Git repo.
     */
    protected function doGitInit(string $dir, string $tpl, bool $bare)
    {
        $cmd = [
            static::$gitExecutable,
            'init',
            '--template=' . static::getGitTemplateDir($tpl),
        ];
        $this->doChangeWorkingDirectory($dir);


        if ($bare) {
            $cmd[] = '--bare';
            $gitDir = '';
        } else {
            $gitDir = '.git/';
        }

        $gitInit = $this->doExec($cmd);
        $cwdReal = realpath($this->cwd);
        Assert::assertSame(
            "Initialized empty Git repository in $cwdReal/$gitDir\n",
            $gitInit->getOutput()
        );
    }

    protected function doGitConfigSet(string $name, string $value)
    {
        $cmd = [
            static::$gitExecutable,
            'config',
            $name,
            $value,
        ];

        $this->process = $this->doExec($cmd);
    }

    protected function doExecCwd(string $wd, array $cmd, array $check = []): Process
    {
        $cwdBackup = $this->cwd;
        chdir($wd);
        $return = $this->doExec($cmd, $check);
        $this->cwd = $cwdBackup;

        return $return;
    }

    protected function doExec(array $cmd, array $check = []): Process
    {
        $check += [
            'exitCode' => true,
            'stdErr' => false,
        ];

        $process = new Process($cmd);
        $process->run();
        if ($check['exitCode'] && !$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        if ($check['stdErr'] !== false) {
            Assert::assertSame($check['stdErr'], $process->getErrorOutput());
        }

        return $process;
    }

    protected function getProjectCacheDir(string $type): string
    {
        return static::$suitRootDir . "/cache/project/$type";
    }

    protected function trimTrailingWhitespaces(string $string): string
    {
        return preg_replace('/[ \t]+\n/', "\n", rtrim($string, " \t"));
    }

    protected function removeColorCodes(string $string): string
    {
        return preg_replace('/\x1B\[[0-9;]*[JKmsu]/', '', $string);
    }
}
