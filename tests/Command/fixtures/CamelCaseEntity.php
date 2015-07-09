<?php

namespace Foo\Bar;

use Doctrine\ORM\Mapping\Column;

class CamelCaseEntity {
    /**
     * @Column(name="someProperty")
     * @var mixed
     */
    private $someProperty;
}
