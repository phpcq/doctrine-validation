<?php

/**
 * This file is part of phpcq/doctrine-validation.
 *
 * (c) 2014 Tristan Lins
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    phpcq/doctrine-validation
 * @author     Tristan Lins <tristan@lins.io>
 * @copyright  Tristan Lins <tristan@lins.io>
 * @link       https://github.com/phpcq/doctrine-validation
 * @license    https://github.com/phpcq/doctrine-validation/blob/master/LICENSE MIT
 * @filesource
 */

namespace PhpCodeQuality\DoctrineValidation\Command;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\ORM\Mapping\Column;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Class ValidateDoctrineEntityColumnNames
 *
 * @package PhpCodeQuality\DoctrineValidation\Command
 */
class ValidateDoctrineEntityColumnNames extends Command
{
    /**
     * The current input interface.
     *
     * @var InputInterface
     */
    protected $input;

    /**
     * The current output interface.
     *
     * @var OutputInterface
     */
    protected $output;

    /**
     * The annotation reader.
     *
     * @var AnnotationReader
     */
    protected $annotationReader;

    /**
     * Use camelCase instead of underscore_case naming.
     *
     * @var bool
     */
    protected $useCamelCase = false;

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('phpcq:validate-doctrine-entity-column-names')
            ->setDescription('Validate the columns naming of doctrine entity.')
            ->addOption(
                'camel-case',
                'c',
                InputOption::VALUE_NONE,
                'Use camelCase instead of underscore_case naming.'
            )
            ->addArgument(
                'files',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Glob patterns to entity files.',
                ['src/Entity/*.php']
            );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        AnnotationRegistry::registerLoader('class_exists');

        $this->input            = $input;
        $this->output           = $output;
        $this->annotationReader = new AnnotationReader();
        $this->useCamelCase     = $this->input->getOption('camel-case');

        $exitCode = 0;

        $filePatterns = $this->input->getArgument('files');
        $this->scanFiles($exitCode, $filePatterns);

        return $exitCode;
    }

    /**
     * Search files matching the patterns and validate them.
     *
     * @param int      $exitCode     The exit code status.
     * @param string[] $filePatterns The file pattern.
     */
    private function scanFiles(&$exitCode, $filePatterns)
    {
        foreach ($filePatterns as $filePattern) {
            $files = glob($filePattern);

            foreach ($files as $file) {
                $this->scanFile($exitCode, new \SplFileInfo($file));
            }
        }
    }

    /**
     * Read the file and validate all classes in the file.
     *
     * @param int          $exitCode The exit code status.
     * @param \SplFileInfo $file     The php file.
     */
    private function scanFile(&$exitCode, \SplFileInfo $file)
    {
        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
            $this->output->writeln(' * Validating ' . $file->getPathname());
        }

        $classNames = $this->determineDefinedClasses($file);

        foreach ($classNames as $className) {
            $this->validateClass($exitCode, $className);
        }
    }

    /**
     * Validate all properties in the class.
     *
     * @param int    $exitCode  The exit code status.
     * @param string $className The class to validate.
     */
    private function validateClass(&$exitCode, $className)
    {
        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln('    * Validating class ' . $className);
        }

        $class      = new \ReflectionClass($className);
        $properties = $class->getProperties();

        foreach ($properties as $property) {
            $this->validateProperty($exitCode, $property);
        }
    }

    /**
     * Validate the properties column name, if it is a column.
     *
     * @param int                 $exitCode The exit code status.
     * @param \ReflectionProperty $property The property to validate.
     */
    private function validateProperty(&$exitCode, \ReflectionProperty $property)
    {
        if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $this->output->writeln('       * Validating property ' . $property->getName());
        }

        /** @var Column $column */
        $column = $this->annotationReader->getPropertyAnnotation($property, 'Doctrine\\ORM\\Mapping\\Column');

        if (is_null($column)) {
            return;
        }

        $currentColumnName  = $column->name ?: $property->getName();
        $expectedColumnName = $this->determineExpectedColumnName($currentColumnName);

        if ($currentColumnName != $expectedColumnName) {
            $this->output->writeln(
                sprintf(
                    '<error>The properties %s:$%s column name should be %s, but is %s!</error>',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    $expectedColumnName,
                    $currentColumnName
                )
            );
            $exitCode = 1;
        }
    }

    /**
     * Determine the expected underscore_case or camelCase column name.
     *
     * @param \ReflectionProperty $property
     *
     * @return mixed
     */
    private function determineExpectedColumnName($currentColumnName)
    {
        if ($this->useCamelCase) {
            $expectedColumnName = preg_replace_callback(
                '~_([a-z0-9])~',
                function ($matches) {
                    return strtoupper($matches[1]);
                },
                $currentColumnName
            );
        } else {
            $expectedColumnName = preg_replace_callback(
                '~([A-Z])~',
                function ($matches) {
                    return '_' . strtolower($matches[1]);
                },
                $currentColumnName
            );
        }

        return $expectedColumnName;
    }

    /**
     * Scan a PHP file and search for all defined classes.
     *
     * @param \SplFileInfo $file The php file.
     *
     * @return string[] The defined class names.
     */
    private function determineDefinedClasses(\SplFileInfo $file)
    {
        $phpCode   = file_get_contents($file->getRealPath());
        $namespace = null;
        $classes   = array();
        $tokens    = token_get_all($phpCode);
        $count     = count($tokens);

        for ($i = 2; $i < $count; $i++) {
            if ($tokens[$i - 2][0] == T_NAMESPACE
                && $tokens[$i - 1][0] == T_WHITESPACE
                && $tokens[$i][0] == T_STRING
            ) {
                $namespace = $tokens[$i][1];

                for ($j=$i + 1; $j<$count and is_array($tokens[$j]); $j++) {
                    $namespace .= $tokens[$j][1];
                }

                continue;
            }

            if ($tokens[$i - 2][0] == T_CLASS
                && $tokens[$i - 1][0] == T_WHITESPACE
                && $tokens[$i][0] == T_STRING
            ) {
                $className = $tokens[$i][1];

                if ($namespace) {
                    $className = $namespace . '\\' . $className;
                }

                $classes[] = $className;
            }
        }

        return $classes;
    }
}
