<?php

namespace Foo\Bar;

use Doctrine\ORM\Mapping\Column;

class UnderscoreCaseEntity {
    /**
     * @Column(name="some_property")
     * @var mixed
     */
    private $someProperty;
}
