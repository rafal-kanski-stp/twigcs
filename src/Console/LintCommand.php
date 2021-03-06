<?php

namespace FriendsOfTwig\Twigcs\Console;

use FriendsOfTwig\Twigcs\Ruleset\Official;
use FriendsOfTwig\Twigcs\Ruleset\RulesetInterface;
use FriendsOfTwig\Twigcs\TwigPort\Source;
use FriendsOfTwig\Twigcs\TwigPort\SyntaxError;
use FriendsOfTwig\Twigcs\Validator\Violation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use function class_exists;
use function sprintf;

class LintCommand extends ContainerAwareCommand
{
    const DISPLAY_BLOCKING = 'blocking';
    const DISPLAY_ALL = 'all';

    public function configure()
    {
        $this
            ->setName('lint')
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The path to scan for twig files.', ['.'])
            ->addOption('twig-version', 't', InputOption::VALUE_REQUIRED, 'The major version of twig to use.', 3)
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Excluded folder of path.', [])
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'The maximum allowed error level.', 'warning')
            ->addOption('reporter', 'r', InputOption::VALUE_REQUIRED, 'The reporter to use.', 'console')
            ->addOption('display', 'd', InputOption::VALUE_REQUIRED, 'The violations to display, "'.self::DISPLAY_ALL.'" or "'.self::DISPLAY_BLOCKING.'".', self::DISPLAY_ALL)
            ->addOption('throw-syntax-error', 'e', InputOption::VALUE_OPTIONAL, 'Throw syntax error when a template contains an invalid token.', false)
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED, 'Ruleset class to use', Official::class)
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $paths = $input->getArgument('paths');
        $exclude = $input->getOption('exclude');
        $twigVersion = $input->getOption('twig-version');

        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[$path] = [new \SplFileInfo($path)];
            } else {
                $finder = new Finder();
                $found = iterator_to_array($finder->in($path)->exclude($exclude)->name('*.twig'));
                if (!empty($found)) {
                    $files[$path] = array_merge($files[$path] ?? [], $found);
                }
            }
        }

        $violations = [];

        $ruleset = $input->getOption('ruleset');

        if (!class_exists($ruleset)) {
            throw new \InvalidArgumentException(sprintf('Ruleset class %s does not exist', $ruleset));
        }

        if (!is_subclass_of($ruleset, RulesetInterface::class)) {
            throw new \InvalidArgumentException('Ruleset class must implement '.RulesetInterface::class);
        }

        $lexer = $container->get('lexer');
        $validator = $container->get('validator');

        foreach ($files as $path => $fileList) {
            foreach ($fileList as $file) {
                $realPath = $file->getRealPath();
                $source = new Source(
                    file_get_contents($realPath),
                    $realPath,
                    str_replace(realpath($path), rtrim($path, '/'), $realPath)
                    );

                try {
                    $tokens = $lexer->tokenize($source);
                    $violations = array_merge($violations, $validator->validate(new $ruleset($twigVersion), $tokens));
                } catch (SyntaxError $e) {
                    if (false !== $input->getOption('throw-syntax-error')) {
                        throw $e;
                    }
                    $violations[] = new Violation($e->getSourcePath(), $e->getLineNo(), $e->getColumnNo(), $e->getMessage());
                }
            }
        }

        $violations = $this->filterDisplayViolations($input, $violations);

        $container->get(sprintf('reporter.%s', $input->getOption('reporter')))->report($output, $violations);

        return $this->determineExitCode($input, $violations);
    }

    private function determineExitCode(InputInterface $input, array $violations): int
    {
        $limit = $this->getSeverityLimit($input);

        foreach ($violations as $violation) {
            if ($violation->getSeverity() > $limit) {
                return 1;
            }
        }

        return 0;
    }

    private function filterDisplayViolations(InputInterface $input, array $violations): array
    {
        if (self::DISPLAY_ALL === $input->getOption('display')) {
            return $violations;
        }

        $limit = $this->getSeverityLimit($input);

        return array_filter($violations, function (Violation $violation) use ($limit) {
            return $violation->getSeverity() > $limit;
        });
    }

    private function getSeverityLimit(InputInterface $input): int
    {
        switch ($input->getOption('severity')) {
            case 'ignore':
                return Violation::SEVERITY_IGNORE - 1;
            case 'info':
                return Violation::SEVERITY_INFO - 1;
            case 'warning':
                return Violation::SEVERITY_WARNING - 1;
            case 'error':
                return Violation::SEVERITY_ERROR - 1;
            default:
                throw new \InvalidArgumentException('Invalid severity limit provided.');
        }
    }
}
