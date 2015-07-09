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

namespace Foo\Bar;

use Doctrine\ORM\Mapping\Column;

class CamelCaseEntity {
    /**
     * @Column(name="someProperty")
     * @var mixed
     */
    private $someProperty;
}
