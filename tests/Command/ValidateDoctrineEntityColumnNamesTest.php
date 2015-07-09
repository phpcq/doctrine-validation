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

namespace PhpCodeQuality\DoctrineValidation\Test\Command;

use PhpCodeQuality\DoctrineValidation\Command\ValidateDoctrineEntityColumnNames;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Class ValidateDoctrineEntityColumnNamesTest
 *
 * @package PhpCodeQuality\DoctrineValidation\Test\Command
 */
class ValidateDoctrineEntityColumnNamesTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Validate underscore_case against CamelCaseEntity.
     *
     * @dataProvider provider
     * @return void
     */
    public function testValidate($camelCaseNaming, $file, $expectSuccess)
    {
        $command = new ValidateDoctrineEntityColumnNames();

        $file = implode(DIRECTORY_SEPARATOR, [__DIR__, 'fixtures', $file]);

        require_once $file;

        $arguments = ['files' => [$file]];

        if ($camelCaseNaming) {
            $arguments['--camel-case'] = true;
        }

        $input  = new ArrayInput($arguments);
        $output = new BufferedOutput();

        $exitCode = $command->run($input, $output);

        if ($expectSuccess) {
            $this->assertEquals(0, $exitCode);
        } else {
            $this->assertNotEquals(0, $exitCode);
        }
    }

    public function provider()
    {
        return [
            [false, 'CamelCaseEntity.php', false],
            [false, 'UnderscoreCaseEntity.php', true],
            [true, 'CamelCaseEntity.php', true],
            [true, 'UnderscoreCaseEntity.php', false],
        ];
    }
}
