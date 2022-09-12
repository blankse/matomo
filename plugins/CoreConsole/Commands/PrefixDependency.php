<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreConsole\Commands;

use Piwik\CliMulti\CliPhp;
use Piwik\Development;
use Piwik\Filesystem;
use Piwik\Http;
use Piwik\Plugin\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 */
class PrefixDependency extends ConsoleCommand
{
    const PHP_SCOPER_VERSION = '0.17.5';
    const SUPPORTED_DEPENDENCIES = [
        'twig/twig',
        'monolog/monolog',
        'symfony/monolog-bridge',
        'psr/log',
    ];

    public function isEnabled()
    {
        return Development::isEnabled();
    }

    protected function configure()
    {
        $this->setName('development:prefix-dependency');
        $this->setDescription('Prefix the namespace of every file in a composer dependency using php-scoper. Used to'
            . ' avoid collisions in environments where other third party software might use the same dependencies,'
            . ' like Matomo for Wordpress.');
        $this->addOption('php-scoper-path', null, InputOption::VALUE_REQUIRED,
            'Specify a custom path to php-scoper. If not supplied, the PHAR will be downloaded from github.');
        $this->addOption('composer-path', null, InputOption::VALUE_REQUIRED,
            'Path to composer. Required to generate a new autoloader.');
        $this->addOption('remove-originals', null, InputOption::VALUE_NONE,
            'If supplied, removes the original composer dependency after prefixing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dependenciesToPrefix = self::SUPPORTED_DEPENDENCIES;

        $composerPath = $this->getComposerPath($input);

        $phpScoperBinary = $this->downloadPhpScoperIfNeeded($input, $output);

        $this->scopeDependencies($dependenciesToPrefix, $phpScoperBinary, $output);

        $output->writeln("");
        $output->writeln("<info>Regenerating autoloader...</info>");
        $this->generatePrefixedAutoloader($dependenciesToPrefix, $composerPath, $input, $output);

        $output->writeln("<info>Done.</info>");
    }

    private function scopeDependencies($dependenciesToPrefix, $phpScoperBinary, OutputInterface $output)
    {
        $output->writeln("<info>Prefixing dependencies...</info>");
        $command = $this->getPhpScoperCommand($dependenciesToPrefix, $phpScoperBinary, $output);
        passthru($command, $returnCode);
        if ($returnCode) {
            throw new \Exception("Failed to run php-scoper! Command was: $command");
        }
    }

    private function downloadPhpScoperIfNeeded(InputInterface $input, OutputInterface $output)
    {
        $customPhpScoperPath = $input->getOption('php-scoper-path');
        if ($customPhpScoperPath) {
            return $customPhpScoperPath;
        }

        $outputPath = PIWIK_INCLUDE_PATH . '/php-scoper.phar';
        if (is_file($outputPath)) {
            $output->writeln("Found existing phar.");
            return $outputPath;
        }

        $output->writeln("Downloading php-scoper from github...");
        Http::fetchRemoteFile('https://github.com/humbug/php-scoper/releases/download/'
            . self::PHP_SCOPER_VERSION . '/php-scoper.phar', $outputPath);
        $output->writeln("...Finished.");

        return $outputPath;
    }

    private function getPhpScoperCommand($dependenciesToPrefix, $phpScoperBinary, OutputInterface $output)
    {
        $vendorPath = PIWIK_VENDOR_PATH;

        $cliPhp = new CliPhp();
        $phpBinary = $cliPhp->findPhpBinary();

        $env = 'MATOMO_DEPENDENCIES_TO_PREFIX="' . addslashes(json_encode($dependenciesToPrefix)) . '"';
        $command = 'cd ' . $vendorPath . ' && ' . $env . ' ' . $phpBinary . ' ' . $phpScoperBinary
            . ' add  --force --output-dir=./prefixed/ --config=../scoper.inc.php';

        if ($output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
            $output->writeln("<comment>php-scoper command: $command</comment>");
        }

        return $command;
    }

    private function getComposerPath(InputInterface $input)
    {
        $composerPath = $input->getOption('composer-path');
        if (empty($composerPath)) {
            throw new \InvalidArgumentException('The --composer-path option is required.');
        }

        if (!is_file($composerPath)) {
            throw new \InvalidArgumentException('--composer-path value "' . $composerPath . '" is not a file.');
        }

        return $composerPath;
    }

    private function generatePrefixedAutoloader($dependenciesToPrefix, $composerPath, InputInterface $input, OutputInterface $output)
    {
        $prefixed = "./vendor/prefixed";

        file_put_contents("$prefixed/composer.json", '{ "autoload": { "classmap": [""] } }');

        $output->writeln("Generating prefixed autoloader...");

        $composerCommand = escapeshellarg($composerPath) . " --working-dir=" . escapeshellarg($prefixed)
            . " dump-autoload --classmap-authoritative --no-interaction";
        passthru($composerCommand, $returnCode);
        if ($returnCode) {
            throw new \Exception("Failed to invoke composer! Command was: $composerCommand");
        }

        Filesystem::remove("$prefixed/autoload.php");
        Filesystem::unlinkRecursive("$prefixed/composer", true);

        Filesystem::remove("$prefixed/composer.json");

        $removeOriginal = $input->getOption('remove-originals');
        if ($removeOriginal) {
            foreach ($dependenciesToPrefix as $dependency) {
                $vendorPath = "./vendor/$dependency";
                Filesystem::unlinkRecursive($vendorPath, true);
            }
        }
    }
}
