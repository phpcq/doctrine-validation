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
