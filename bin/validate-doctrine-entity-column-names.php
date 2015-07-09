#!/usr/bin/env php
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

error_reporting(E_ALL);

function includeIfExists($file)
{
    return file_exists($file) ? include $file : false;
}
if ((!$loader = includeIfExists(__DIR__.'/../vendor/autoload.php')) && (!$loader = includeIfExists(__DIR__.'/../../../autoload.php'))) {
    echo 'You must set up the project dependencies, run the following commands:'.PHP_EOL.
        'curl -sS https://getcomposer.org/installer | php'.PHP_EOL.
        'php composer.phar install'.PHP_EOL;
    exit(1);
}

set_error_handler(
    function ($errno, $errstr, $errfile, $errline ) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
);

use PhpCodeQuality\DoctrineValidation\Command\ValidateDoctrineEntityColumnNames;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;

class ValidateDoctrineEntityColumnNamesApplication extends Application
{
    /**
     * Gets the name of the command based on input.
     *
     * @param InputInterface $input The input interface
     *
     * @return string The command name
     */
    protected function getCommandName(InputInterface $input)
    {
        return 'phpcq:validate-doctrine-entity-column-names';
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return array An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        // Keep the core default commands to have the HelpCommand
        // which is used when using the --help option
        $defaultCommands = parent::getDefaultCommands();

        $defaultCommands[] = new ValidateDoctrineEntityColumnNames();

        return $defaultCommands;
    }

    /**
     * Overridden so that the application doesn't expect the command
     * name to be the first argument.
     */
    public function getDefinition()
    {
        $inputDefinition = parent::getDefinition();
        // clear out the normal first argument, which is the command name
        $inputDefinition->setArguments();

        return $inputDefinition;
    }
}

$application = new ValidateDoctrineEntityColumnNamesApplication();
$application->setAutoExit(true);
$application->run();
