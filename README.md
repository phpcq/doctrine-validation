[![Version](http://img.shields.io/packagist/v/phpcq/doctrine-validation.svg?style=flat-square)](https://packagist.org/packages/phpcq/doctrine-validation)
[![Stable Build Status](http://img.shields.io/travis/phpcq/doctrine-validation/master.svg?style=flat-square)](https://travis-ci.org/phpcq/doctrine-validation)
[![Upstream Build Status](http://img.shields.io/travis/phpcq/doctrine-validation/develop.svg?style=flat-square)](https://travis-ci.org/phpcq/doctrine-validation)
[![License](http://img.shields.io/packagist/l/phpcq/doctrine-validation.svg?style=flat-square)](https://github.com/phpcq/doctrine-validation/blob/master/LICENSE)
[![Downloads](http://img.shields.io/packagist/dt/phpcq/doctrine-validation.svg?style=flat-square)](https://packagist.org/packages/phpcq/doctrine-validation)

Multiple validators for doctrine users
======================================

Installation
------------

Add to your `composer.json` in the `require-dev` section:

```
"phpcq/doctrine-validation": "~1.0"
```

Column name validator
---------------------

Validates the column names to make sure you have configured your entities correctly.

### Usage

Call the binary, will scan all files matching `src/Entity/*.php`:

```
./vendor/bin/validate-doctrine-entity-column-names.php
```

Optionally you can pass the path to the entity class files:

```
./vendor/bin/validate-doctrine-entity-column-names.php lib/Doctrine/Entities/*.php
```

If you prefer camelCase over underscore_case naming (which may be a bad practice for some database systems), you can enable it:

```
./vendor/bin/validate-doctrine-entity-column-names.php --camel-case
```
