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
use Doctrine\ORM\Mapping\JoinColumn;
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

        $this->validateColumn($exitCode, $property);
        $this->validateJoinColumn($exitCode, $property);
    }

    /**
     * @param                     $exitCode
     * @param \ReflectionProperty $property
     */
    private function validateColumn(&$exitCode, \ReflectionProperty $property)
    {
        /** @var Column $column */
        $column = $this->annotationReader->getPropertyAnnotation($property, 'Doctrine\\ORM\\Mapping\\Column');

        if (is_null($column)) {
            return;
        }

        if ($column->name) {
            $isQuoted          = '`' === $column->name[0];
            $currentColumnName = trim($column->name, '`');
        } else {
            $isQuoted          = true;
            $currentColumnName = $property->getName();
        }

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

        $keyWord = strtoupper($currentColumnName);

        if (isset(self::$KEY_WORDS[$keyWord]) && !$isQuoted) {
            $this->output->writeln(
                sprintf(
                    '<error>The column name %s (%s:$%s) is a reserved keyword in (%s) and must be quoted or renamed!</error>',
                    $keyWord,
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    implode(', ', self::$KEY_WORDS[$keyWord])
                )
            );
            $exitCode = 1;
        }
    }

    /**
     * @param                     $exitCode
     * @param \ReflectionProperty $property
     */
    private function validateJoinColumn(&$exitCode, \ReflectionProperty $property)
    {

        /** @var JoinColumn $joinColumn */
        $joinColumn = $this->annotationReader->getPropertyAnnotation($property, 'Doctrine\\ORM\\Mapping\\JoinColumn');

        if (is_null($joinColumn) || !$joinColumn->name) {
            return;
        }

        $isQuoted = '`' === $joinColumn->name[0];

        if ($isQuoted) {
            $this->output->writeln(
                sprintf(
                    '<error>The properties %s:$%s join column name is quoted, but join columns must not be quoted!</error>',
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    $joinColumn->name
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

                for ($j = $i + 1; $j < $count and is_array($tokens[$j]); $j++) {
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

    private static $KEY_WORDS = [
        'A'                                => ['postgresql'],
        'ABORT'                            => ['postgresql', 'sqlite'],
        'ABS'                              => ['postgresql'],
        'ABSOLUTE'                         => ['postgresql'],
        'ACCESS'                           => ['postgresql'],
        'ACTION'                           => ['postgresql', 'mysql', 'sqlite'],
        'ADA'                              => ['postgresql'],
        'ADD'                              => ['postgresql', 'mysql', 'sqlite'],
        'ADMIN'                            => ['postgresql'],
        'AFTER'                            => ['postgresql', 'mysql', 'sqlite'],
        'AGAINST'                          => ['mysql'],
        'AGGREGATE'                        => ['postgresql', 'mysql'],
        'ALGORITHM'                        => ['mysql'],
        'ALIAS'                            => ['postgresql'],
        'ALL'                              => ['postgresql', 'mysql', 'sqlite'],
        'ALLOCATE'                         => ['postgresql'],
        'ALSO'                             => ['postgresql'],
        'ALTER'                            => ['postgresql', 'mysql', 'sqlite'],
        'ALWAYS'                           => ['postgresql'],
        'ANALYSE'                          => ['postgresql'],
        'ANALYZE'                          => ['postgresql', 'mysql', 'sqlite'],
        'AND'                              => ['postgresql', 'mysql', 'sqlite'],
        'ANY'                              => ['postgresql', 'mysql'],
        'ARE'                              => ['postgresql'],
        'ARRAY'                            => ['postgresql'],
        'AS'                               => ['postgresql', 'mysql', 'sqlite'],
        'ASC'                              => ['postgresql', 'mysql', 'sqlite'],
        'ASCII'                            => ['mysql'],
        'ASENSITIVE'                       => ['postgresql', 'mysql'],
        'ASSERTION'                        => ['postgresql'],
        'ASSIGNMENT'                       => ['postgresql'],
        'ASYMMETRIC'                       => ['postgresql'],
        'AT'                               => ['postgresql'],
        'ATOMIC'                           => ['postgresql'],
        'ATTACH'                           => ['sqlite'],
        'ATTRIBUTE'                        => ['postgresql'],
        'ATTRIBUTES'                       => ['postgresql'],
        'AUTHORIZATION'                    => ['postgresql'],
        'AUTOINCREMENT'                    => ['sqlite'],
        'AUTO_INCREMENT'                   => ['mysql'],
        'AVG'                              => ['postgresql', 'mysql'],
        'AVG_ROW_LENGTH'                   => ['mysql'],
        'BACKUP'                           => ['mysql'],
        'BACKWARD'                         => ['postgresql'],
        'BDB'                              => ['mysql'],
        'BEFORE'                           => ['postgresql', 'mysql', 'sqlite'],
        'BEGIN'                            => ['postgresql', 'mysql', 'sqlite'],
        'BERKELEYDB'                       => ['mysql'],
        'BERNOULLI'                        => ['postgresql'],
        'BETWEEN'                          => ['postgresql', 'mysql', 'sqlite'],
        'BIGINT'                           => ['postgresql', 'mysql'],
        'BINARY'                           => ['postgresql', 'mysql'],
        'BINLOG'                           => ['mysql'],
        'BIT'                              => ['postgresql', 'mysql'],
        'BITVAR'                           => ['postgresql'],
        'BIT_LENGTH'                       => ['postgresql'],
        'BLOB'                             => ['postgresql', 'mysql'],
        'BLOCK'                            => ['mysql'],
        'BOOL'                             => ['mysql'],
        'BOOLEAN'                          => ['postgresql', 'mysql'],
        'BOTH'                             => ['postgresql', 'mysql'],
        'BREADTH'                          => ['postgresql'],
        'BTREE'                            => ['mysql'],
        'BY'                               => ['postgresql', 'mysql', 'sqlite'],
        'BYTE'                             => ['mysql'],
        'C'                                => ['postgresql'],
        'CACHE'                            => ['postgresql', 'mysql'],
        'CALL'                             => ['postgresql', 'mysql'],
        'CALLED'                           => ['postgresql'],
        'CARDINALITY'                      => ['postgresql'],
        'CASCADE'                          => ['postgresql', 'mysql', 'sqlite'],
        'CASCADED'                         => ['postgresql', 'mysql'],
        'CASE'                             => ['postgresql', 'mysql', 'sqlite'],
        'CAST'                             => ['postgresql', 'sqlite'],
        'CATALOG'                          => ['postgresql'],
        'CATALOG_NAME'                     => ['postgresql'],
        'CEIL'                             => ['postgresql'],
        'CEILING'                          => ['postgresql'],
        'CHAIN'                            => ['postgresql', 'mysql'],
        'CHANGE'                           => ['mysql'],
        'CHANGED'                          => ['mysql'],
        'CHAR'                             => ['postgresql', 'mysql'],
        'CHARACTER'                        => ['postgresql', 'mysql'],
        'CHARACTERISTICS'                  => ['postgresql'],
        'CHARACTERS'                       => ['postgresql'],
        'CHARACTER_LENGTH'                 => ['postgresql'],
        'CHARACTER_SET_CATALOG'            => ['postgresql'],
        'CHARACTER_SET_NAME'               => ['postgresql'],
        'CHARACTER_SET_SCHEMA'             => ['postgresql'],
        'CHARSET'                          => ['mysql'],
        'CHAR_LENGTH'                      => ['postgresql'],
        'CHECK'                            => ['postgresql', 'mysql', 'sqlite'],
        'CHECKED'                          => ['postgresql'],
        'CHECKPOINT'                       => ['postgresql'],
        'CHECKSUM'                         => ['mysql'],
        'CIPHER'                           => ['mysql'],
        'CLASS'                            => ['postgresql'],
        'CLASS_ORIGIN'                     => ['postgresql'],
        'CLIENT'                           => ['mysql'],
        'CLOB'                             => ['postgresql'],
        'CLOSE'                            => ['postgresql', 'mysql'],
        'CLUSTER'                          => ['postgresql'],
        'COALESCE'                         => ['postgresql'],
        'COBOL'                            => ['postgresql'],
        'CODE'                             => ['mysql'],
        'COLLATE'                          => ['postgresql', 'mysql', 'sqlite'],
        'COLLATION'                        => ['postgresql', 'mysql'],
        'COLLATION_CATALOG'                => ['postgresql'],
        'COLLATION_NAME'                   => ['postgresql'],
        'COLLATION_SCHEMA'                 => ['postgresql'],
        'COLLECT'                          => ['postgresql'],
        'COLUMN'                           => ['postgresql', 'mysql', 'sqlite'],
        'COLUMNS'                          => ['mysql'],
        'COLUMN_NAME'                      => ['postgresql'],
        'COMMAND_FUNCTION'                 => ['postgresql'],
        'COMMAND_FUNCTION_CODE'            => ['postgresql'],
        'COMMENT'                          => ['postgresql', 'mysql'],
        'COMMIT'                           => ['postgresql', 'mysql', 'sqlite'],
        'COMMITTED'                        => ['postgresql', 'mysql'],
        'COMPACT'                          => ['mysql'],
        'COMPLETION'                       => ['postgresql'],
        'COMPRESSED'                       => ['mysql'],
        'CONCURRENT'                       => ['mysql'],
        'CONDITION'                        => ['postgresql', 'mysql'],
        'CONDITION_NUMBER'                 => ['postgresql'],
        'CONFLICT'                         => ['sqlite'],
        'CONNECT'                          => ['postgresql'],
        'CONNECTION'                       => ['postgresql', 'mysql'],
        'CONNECTION_NAME'                  => ['postgresql'],
        'CONSISTENT'                       => ['mysql'],
        'CONSTRAINT'                       => ['postgresql', 'mysql', 'sqlite'],
        'CONSTRAINTS'                      => ['postgresql'],
        'CONSTRAINT_CATALOG'               => ['postgresql'],
        'CONSTRAINT_NAME'                  => ['postgresql'],
        'CONSTRAINT_SCHEMA'                => ['postgresql'],
        'CONSTRUCTOR'                      => ['postgresql'],
        'CONTAINS'                         => ['postgresql', 'mysql'],
        'CONTEXT'                          => ['mysql'],
        'CONTINUE'                         => ['postgresql', 'mysql'],
        'CONVERSION'                       => ['postgresql'],
        'CONVERT'                          => ['postgresql', 'mysql'],
        'COPY'                             => ['postgresql'],
        'CORR'                             => ['postgresql'],
        'CORRESPONDING'                    => ['postgresql'],
        'COUNT'                            => ['postgresql'],
        'COVAR_POP'                        => ['postgresql'],
        'COVAR_SAMP'                       => ['postgresql'],
        'CPU'                              => ['mysql'],
        'CREATE'                           => ['postgresql', 'mysql', 'sqlite'],
        'CREATEDB'                         => ['postgresql'],
        'CREATEROLE'                       => ['postgresql'],
        'CREATEUSER'                       => ['postgresql'],
        'CROSS'                            => ['postgresql', 'mysql', 'sqlite'],
        'CSV'                              => ['postgresql'],
        'CUBE'                             => ['postgresql', 'mysql'],
        'CUME_DIST'                        => ['postgresql'],
        'CURRENT'                          => ['postgresql'],
        'CURRENT_DATE'                     => ['postgresql', 'mysql', 'sqlite'],
        'CURRENT_DEFAULT_TRANSFORM_GROUP'  => ['postgresql'],
        'CURRENT_PATH'                     => ['postgresql'],
        'CURRENT_ROLE'                     => ['postgresql'],
        'CURRENT_TIME'                     => ['postgresql', 'mysql', 'sqlite'],
        'CURRENT_TIMESTAMP'                => ['postgresql', 'mysql', 'sqlite'],
        'CURRENT_TRANSFORM_GROUP_FOR_TYPE' => ['postgresql'],
        'CURRENT_USER'                     => ['postgresql', 'mysql'],
        'CURSOR'                           => ['postgresql', 'mysql'],
        'CURSOR_NAME'                      => ['postgresql'],
        'CYCLE'                            => ['postgresql'],
        'DATA'                             => ['postgresql', 'mysql'],
        'DATABASE'                         => ['postgresql', 'mysql', 'sqlite'],
        'DATABASES'                        => ['mysql'],
        'DATE'                             => ['postgresql', 'mysql'],
        'DATETIME'                         => ['mysql'],
        'DATETIME_INTERVAL_CODE'           => ['postgresql'],
        'DATETIME_INTERVAL_PRECISION'      => ['postgresql'],
        'DAY'                              => ['postgresql', 'mysql'],
        'DAY_HOUR'                         => ['mysql'],
        'DAY_MICROSECOND'                  => ['mysql'],
        'DAY_MINUTE'                       => ['mysql'],
        'DAY_SECOND'                       => ['mysql'],
        'DEALLOCATE'                       => ['postgresql', 'mysql'],
        'DEC'                              => ['postgresql', 'mysql'],
        'DECIMAL'                          => ['postgresql', 'mysql'],
        'DECLARE'                          => ['postgresql', 'mysql'],
        'DEFAULT'                          => ['postgresql', 'mysql', 'sqlite'],
        'DEFAULTS'                         => ['postgresql'],
        'DEFERRABLE'                       => ['postgresql', 'sqlite'],
        'DEFERRED'                         => ['postgresql', 'sqlite'],
        'DEFINED'                          => ['postgresql'],
        'DEFINER'                          => ['postgresql', 'mysql'],
        'DEGREE'                           => ['postgresql'],
        'DELAYED'                          => ['mysql'],
        'DELAY_KEY_WRITE'                  => ['mysql'],
        'DELETE'                           => ['postgresql', 'mysql', 'sqlite'],
        'DELIMITER'                        => ['postgresql'],
        'DELIMITERS'                       => ['postgresql'],
        'DENSE_RANK'                       => ['postgresql'],
        'DEPTH'                            => ['postgresql'],
        'DEREF'                            => ['postgresql'],
        'DERIVED'                          => ['postgresql'],
        'DESC'                             => ['postgresql', 'mysql', 'sqlite'],
        'DESCRIBE'                         => ['postgresql', 'mysql'],
        'DESCRIPTOR'                       => ['postgresql'],
        'DESTROY'                          => ['postgresql'],
        'DESTRUCTOR'                       => ['postgresql'],
        'DES_KEY_FILE'                     => ['mysql'],
        'DETACH'                           => ['sqlite'],
        'DETERMINISTIC'                    => ['postgresql', 'mysql'],
        'DIAGNOSTICS'                      => ['postgresql'],
        'DICTIONARY'                       => ['postgresql'],
        'DIRECTORY'                        => ['mysql'],
        'DISABLE'                          => ['postgresql', 'mysql'],
        'DISCARD'                          => ['mysql'],
        'DISCONNECT'                       => ['postgresql'],
        'DISPATCH'                         => ['postgresql'],
        'DISTINCT'                         => ['postgresql', 'mysql', 'sqlite'],
        'DISTINCTROW'                      => ['mysql'],
        'DIV'                              => ['mysql'],
        'DO'                               => ['postgresql', 'mysql'],
        'DOMAIN'                           => ['postgresql'],
        'DOUBLE'                           => ['postgresql', 'mysql'],
        'DROP'                             => ['postgresql', 'mysql', 'sqlite'],
        'DUAL'                             => ['mysql'],
        'DUMPFILE'                         => ['mysql'],
        'DUPLICATE'                        => ['mysql'],
        'DYNAMIC'                          => ['postgresql', 'mysql'],
        'DYNAMIC_FUNCTION'                 => ['postgresql'],
        'DYNAMIC_FUNCTION_CODE'            => ['postgresql'],
        'EACH'                             => ['postgresql', 'mysql', 'sqlite'],
        'ELEMENT'                          => ['postgresql'],
        'ELSE'                             => ['postgresql', 'mysql', 'sqlite'],
        'ELSEIF'                           => ['mysql'],
        'ENABLE'                           => ['postgresql', 'mysql'],
        'ENCLOSED'                         => ['mysql'],
        'ENCODING'                         => ['postgresql'],
        'ENCRYPTED'                        => ['postgresql'],
        'END'                              => ['postgresql', 'mysql', 'sqlite'],
        'END-EXEC'                         => ['postgresql'],
        'ENGINE'                           => ['mysql'],
        'ENGINES'                          => ['mysql'],
        'ENUM'                             => ['mysql'],
        'EQUALS'                           => ['postgresql'],
        'ERRORS'                           => ['mysql'],
        'ESCAPE'                           => ['postgresql', 'mysql', 'sqlite'],
        'ESCAPED'                          => ['mysql'],
        'EVENTS'                           => ['mysql'],
        'EVERY'                            => ['postgresql'],
        'EXCEPT'                           => ['postgresql', 'sqlite'],
        'EXCEPTION'                        => ['postgresql'],
        'EXCLUDE'                          => ['postgresql'],
        'EXCLUDING'                        => ['postgresql'],
        'EXCLUSIVE'                        => ['postgresql', 'sqlite'],
        'EXEC'                             => ['postgresql'],
        'EXECUTE'                          => ['postgresql', 'mysql'],
        'EXISTING'                         => ['postgresql'],
        'EXISTS'                           => ['postgresql', 'mysql', 'sqlite'],
        'EXIT'                             => ['mysql'],
        'EXP'                              => ['postgresql'],
        'EXPANSION'                        => ['mysql'],
        'EXPLAIN'                          => ['postgresql', 'mysql', 'sqlite'],
        'EXTENDED'                         => ['mysql'],
        'EXTERNAL'                         => ['postgresql'],
        'EXTRACT'                          => ['postgresql'],
        'FAIL'                             => ['sqlite'],
        'FALSE'                            => ['postgresql', 'mysql'],
        'FAST'                             => ['mysql'],
        'FAULTS'                           => ['mysql'],
        'FETCH'                            => ['postgresql', 'mysql'],
        'FIELDS'                           => ['mysql'],
        'FILE'                             => ['mysql'],
        'FILTER'                           => ['postgresql'],
        'FINAL'                            => ['postgresql'],
        'FIRST'                            => ['postgresql', 'mysql'],
        'FIXED'                            => ['mysql'],
        'FLOAT'                            => ['postgresql', 'mysql'],
        'FLOAT4'                           => ['mysql'],
        'FLOAT8'                           => ['mysql'],
        'FLOOR'                            => ['postgresql'],
        'FLUSH'                            => ['mysql'],
        'FOLLOWING'                        => ['postgresql'],
        'FOR'                              => ['postgresql', 'mysql', 'sqlite'],
        'FORCE'                            => ['postgresql', 'mysql'],
        'FOREIGN'                          => ['postgresql', 'mysql', 'sqlite'],
        'FORTRAN'                          => ['postgresql'],
        'FORWARD'                          => ['postgresql'],
        'FOUND'                            => ['postgresql', 'mysql'],
        'FRAC_SECOND'                      => ['mysql'],
        'FREE'                             => ['postgresql'],
        'FREEZE'                           => ['postgresql'],
        'FROM'                             => ['postgresql', 'mysql', 'sqlite'],
        'FULL'                             => ['postgresql', 'mysql', 'sqlite'],
        'FULLTEXT'                         => ['mysql'],
        'FUNCTION'                         => ['postgresql', 'mysql'],
        'FUSION'                           => ['postgresql'],
        'G'                                => ['postgresql'],
        'GENERAL'                          => ['postgresql'],
        'GENERATED'                        => ['postgresql'],
        'GEOMETRY'                         => ['mysql'],
        'GEOMETRYCOLLECTION'               => ['mysql'],
        'GET'                              => ['postgresql'],
        'GET_FORMAT'                       => ['mysql'],
        'GLOB'                             => ['sqlite'],
        'GLOBAL'                           => ['postgresql', 'mysql'],
        'GO'                               => ['postgresql'],
        'GOTO'                             => ['postgresql'],
        'GRANT'                            => ['postgresql', 'mysql'],
        'GRANTED'                          => ['postgresql'],
        'GRANTS'                           => ['mysql'],
        'GREATEST'                         => ['postgresql'],
        'GROUP'                            => ['postgresql', 'mysql', 'sqlite'],
        'GROUPING'                         => ['postgresql'],
        'HANDLER'                          => ['postgresql', 'mysql'],
        'HASH'                             => ['mysql'],
        'HAVING'                           => ['postgresql', 'mysql', 'sqlite'],
        'HEADER'                           => ['postgresql'],
        'HELP'                             => ['mysql'],
        'HIERARCHY'                        => ['postgresql'],
        'HIGH_PRIORITY'                    => ['mysql'],
        'HOLD'                             => ['postgresql'],
        'HOST'                             => ['postgresql'],
        'HOSTS'                            => ['mysql'],
        'HOUR'                             => ['postgresql', 'mysql'],
        'HOUR_MICROSECOND'                 => ['mysql'],
        'HOUR_MINUTE'                      => ['mysql'],
        'HOUR_SECOND'                      => ['mysql'],
        'IDENTIFIED'                       => ['mysql'],
        'IDENTITY'                         => ['postgresql'],
        'IF'                               => ['mysql', 'sqlite'],
        'IGNORE'                           => ['postgresql', 'mysql', 'sqlite'],
        'ILIKE'                            => ['postgresql'],
        'IMMEDIATE'                        => ['postgresql', 'sqlite'],
        'IMMUTABLE'                        => ['postgresql'],
        'IMPLEMENTATION'                   => ['postgresql'],
        'IMPLICIT'                         => ['postgresql'],
        'IMPORT'                           => ['mysql'],
        'IN'                               => ['postgresql', 'mysql', 'sqlite'],
        'INCLUDING'                        => ['postgresql'],
        'INCREMENT'                        => ['postgresql'],
        'INDEX'                            => ['postgresql', 'mysql', 'sqlite'],
        'INDEXED'                          => ['sqlite'],
        'INDEXES'                          => ['mysql'],
        'INDICATOR'                        => ['postgresql'],
        'INFILE'                           => ['mysql'],
        'INFIX'                            => ['postgresql'],
        'INHERIT'                          => ['postgresql'],
        'INHERITS'                         => ['postgresql'],
        'INITIALIZE'                       => ['postgresql'],
        'INITIALLY'                        => ['postgresql', 'sqlite'],
        'INNER'                            => ['postgresql', 'mysql', 'sqlite'],
        'INNOBASE'                         => ['mysql'],
        'INNODB'                           => ['mysql'],
        'INOUT'                            => ['postgresql', 'mysql'],
        'INPUT'                            => ['postgresql'],
        'INSENSITIVE'                      => ['postgresql', 'mysql'],
        'INSERT'                           => ['postgresql', 'mysql', 'sqlite'],
        'INSERT_METHOD'                    => ['mysql'],
        'INSTANCE'                         => ['postgresql'],
        'INSTANTIABLE'                     => ['postgresql'],
        'INSTEAD'                          => ['postgresql', 'sqlite'],
        'INT'                              => ['postgresql', 'mysql'],
        'INT1'                             => ['mysql'],
        'INT2'                             => ['mysql'],
        'INT3'                             => ['mysql'],
        'INT4'                             => ['mysql'],
        'INT8'                             => ['mysql'],
        'INTEGER'                          => ['postgresql', 'mysql'],
        'INTERSECT'                        => ['postgresql', 'sqlite'],
        'INTERSECTION'                     => ['postgresql'],
        'INTERVAL'                         => ['postgresql', 'mysql'],
        'INTO'                             => ['postgresql', 'mysql', 'sqlite'],
        'INVOKER'                          => ['postgresql', 'mysql'],
        'IO'                               => ['mysql'],
        'IO_THREAD'                        => ['mysql'],
        'IPC'                              => ['mysql'],
        'IS'                               => ['postgresql', 'mysql', 'sqlite'],
        'ISNULL'                           => ['postgresql', 'sqlite'],
        'ISOLATION'                        => ['postgresql', 'mysql'],
        'ISSUER'                           => ['mysql'],
        'ITERATE'                          => ['postgresql', 'mysql'],
        'JOIN'                             => ['postgresql', 'mysql', 'sqlite'],
        'K'                                => ['postgresql'],
        'KEY'                              => ['postgresql', 'mysql', 'sqlite'],
        'KEYS'                             => ['mysql'],
        'KEY_MEMBER'                       => ['postgresql'],
        'KEY_TYPE'                         => ['postgresql'],
        'KILL'                             => ['mysql'],
        'LANCOMPILER'                      => ['postgresql'],
        'LANGUAGE'                         => ['postgresql', 'mysql'],
        'LARGE'                            => ['postgresql'],
        'LAST'                             => ['postgresql', 'mysql'],
        'LATERAL'                          => ['postgresql'],
        'LEADING'                          => ['postgresql', 'mysql'],
        'LEAST'                            => ['postgresql'],
        'LEAVE'                            => ['mysql'],
        'LEAVES'                           => ['mysql'],
        'LEFT'                             => ['postgresql', 'mysql', 'sqlite'],
        'LENGTH'                           => ['postgresql'],
        'LESS'                             => ['postgresql'],
        'LEVEL'                            => ['postgresql', 'mysql'],
        'LIKE'                             => ['postgresql', 'mysql', 'sqlite'],
        'LIMIT'                            => ['postgresql', 'mysql', 'sqlite'],
        'LINES'                            => ['mysql'],
        'LINESTRING'                       => ['mysql'],
        'LISTEN'                           => ['postgresql'],
        'LN'                               => ['postgresql'],
        'LOAD'                             => ['postgresql', 'mysql'],
        'LOCAL'                            => ['postgresql', 'mysql'],
        'LOCALTIME'                        => ['postgresql', 'mysql'],
        'LOCALTIMESTAMP'                   => ['postgresql', 'mysql'],
        'LOCATION'                         => ['postgresql'],
        'LOCATOR'                          => ['postgresql'],
        'LOCK'                             => ['postgresql', 'mysql'],
        'LOCKS'                            => ['mysql'],
        'LOGIN'                            => ['postgresql'],
        'LOGS'                             => ['mysql'],
        'LONG'                             => ['mysql'],
        'LONGBLOB'                         => ['mysql'],
        'LONGTEXT'                         => ['mysql'],
        'LOOP'                             => ['mysql'],
        'LOWER'                            => ['postgresql'],
        'LOW_PRIORITY'                     => ['mysql'],
        'M'                                => ['postgresql'],
        'MAP'                              => ['postgresql'],
        'MASTER'                           => ['mysql'],
        'MASTER_CONNECT_RETRY'             => ['mysql'],
        'MASTER_HOST'                      => ['mysql'],
        'MASTER_LOG_FILE'                  => ['mysql'],
        'MASTER_LOG_POS'                   => ['mysql'],
        'MASTER_PASSWORD'                  => ['mysql'],
        'MASTER_PORT'                      => ['mysql'],
        'MASTER_SERVER_ID'                 => ['mysql'],
        'MASTER_SSL'                       => ['mysql'],
        'MASTER_SSL_CA'                    => ['mysql'],
        'MASTER_SSL_CAPATH'                => ['mysql'],
        'MASTER_SSL_CERT'                  => ['mysql'],
        'MASTER_SSL_CIPHER'                => ['mysql'],
        'MASTER_SSL_KEY'                   => ['mysql'],
        'MASTER_USER'                      => ['mysql'],
        'MATCH'                            => ['postgresql', 'mysql', 'sqlite'],
        'MATCHED'                          => ['postgresql'],
        'MAX'                              => ['postgresql'],
        'MAXVALUE'                         => ['postgresql'],
        'MAX_CONNECTIONS_PER_HOUR'         => ['mysql'],
        'MAX_QUERIES_PER_HOUR'             => ['mysql'],
        'MAX_ROWS'                         => ['mysql'],
        'MAX_UPDATES_PER_HOUR'             => ['mysql'],
        'MAX_USER_CONNECTIONS'             => ['mysql'],
        'MEDIUM'                           => ['mysql'],
        'MEDIUMBLOB'                       => ['mysql'],
        'MEDIUMINT'                        => ['mysql'],
        'MEDIUMTEXT'                       => ['mysql'],
        'MEMBER'                           => ['postgresql'],
        'MEMORY'                           => ['mysql'],
        'MERGE'                            => ['postgresql', 'mysql'],
        'MESSAGE_LENGTH'                   => ['postgresql'],
        'MESSAGE_OCTET_LENGTH'             => ['postgresql'],
        'MESSAGE_TEXT'                     => ['postgresql'],
        'METHOD'                           => ['postgresql'],
        'MICROSECOND'                      => ['mysql'],
        'MIDDLEINT'                        => ['mysql'],
        'MIGRATE'                          => ['mysql'],
        'MIN'                              => ['postgresql'],
        'MINUTE'                           => ['postgresql', 'mysql'],
        'MINUTE_MICROSECOND'               => ['mysql'],
        'MINUTE_SECOND'                    => ['mysql'],
        'MINVALUE'                         => ['postgresql'],
        'MIN_ROWS'                         => ['mysql'],
        'MOD'                              => ['postgresql', 'mysql'],
        'MODE'                             => ['postgresql', 'mysql'],
        'MODIFIES'                         => ['postgresql', 'mysql'],
        'MODIFY'                           => ['postgresql', 'mysql'],
        'MODULE'                           => ['postgresql'],
        'MONTH'                            => ['postgresql', 'mysql'],
        'MORE'                             => ['postgresql'],
        'MOVE'                             => ['postgresql'],
        'MULTILINESTRING'                  => ['mysql'],
        'MULTIPOINT'                       => ['mysql'],
        'MULTIPOLYGON'                     => ['mysql'],
        'MULTISET'                         => ['postgresql'],
        'MUMPS'                            => ['postgresql'],
        'MUTEX'                            => ['mysql'],
        'NAME'                             => ['postgresql', 'mysql'],
        'NAMES'                            => ['postgresql', 'mysql'],
        'NATIONAL'                         => ['postgresql', 'mysql'],
        'NATURAL'                          => ['postgresql', 'mysql', 'sqlite'],
        'NCHAR'                            => ['postgresql', 'mysql'],
        'NCLOB'                            => ['postgresql'],
        'NDB'                              => ['mysql'],
        'NDBCLUSTER'                       => ['mysql'],
        'NESTING'                          => ['postgresql'],
        'NEW'                              => ['postgresql', 'mysql'],
        'NEXT'                             => ['postgresql', 'mysql'],
        'NO'                               => ['postgresql', 'mysql', 'sqlite'],
        'NOCREATEDB'                       => ['postgresql'],
        'NOCREATEROLE'                     => ['postgresql'],
        'NOCREATEUSER'                     => ['postgresql'],
        'NOINHERIT'                        => ['postgresql'],
        'NOLOGIN'                          => ['postgresql'],
        'NONE'                             => ['postgresql', 'mysql'],
        'NORMALIZE'                        => ['postgresql'],
        'NORMALIZED'                       => ['postgresql'],
        'NOSUPERUSER'                      => ['postgresql'],
        'NOT'                              => ['postgresql', 'mysql', 'sqlite'],
        'NOTHING'                          => ['postgresql'],
        'NOTIFY'                           => ['postgresql'],
        'NOTNULL'                          => ['postgresql', 'sqlite'],
        'NOWAIT'                           => ['postgresql'],
        'NO_WRITE_TO_BINLOG'               => ['mysql'],
        'NULL'                             => ['postgresql', 'mysql', 'sqlite'],
        'NULLABLE'                         => ['postgresql'],
        'NULLIF'                           => ['postgresql'],
        'NULLS'                            => ['postgresql'],
        'NUMBER'                           => ['postgresql'],
        'NUMERIC'                          => ['postgresql', 'mysql'],
        'NVARCHAR'                         => ['mysql'],
        'OBJECT'                           => ['postgresql'],
        'OCTETS'                           => ['postgresql'],
        'OCTET_LENGTH'                     => ['postgresql'],
        'OF'                               => ['postgresql', 'sqlite'],
        'OFF'                              => ['postgresql'],
        'OFFSET'                           => ['postgresql', 'mysql', 'sqlite'],
        'OIDS'                             => ['postgresql'],
        'OLD'                              => ['postgresql'],
        'OLD_PASSWORD'                     => ['mysql'],
        'ON'                               => ['postgresql', 'mysql', 'sqlite'],
        'ONE'                              => ['mysql'],
        'ONE_SHOT'                         => ['mysql'],
        'ONLY'                             => ['postgresql'],
        'OPEN'                             => ['postgresql', 'mysql'],
        'OPERATION'                        => ['postgresql'],
        'OPERATOR'                         => ['postgresql'],
        'OPTIMIZE'                         => ['mysql'],
        'OPTION'                           => ['postgresql', 'mysql'],
        'OPTIONALLY'                       => ['mysql'],
        'OPTIONS'                          => ['postgresql'],
        'OR'                               => ['postgresql', 'mysql', 'sqlite'],
        'ORDER'                            => ['postgresql', 'mysql', 'sqlite'],
        'ORDERING'                         => ['postgresql'],
        'ORDINALITY'                       => ['postgresql'],
        'OTHERS'                           => ['postgresql'],
        'OUT'                              => ['postgresql', 'mysql'],
        'OUTER'                            => ['postgresql', 'mysql', 'sqlite'],
        'OUTFILE'                          => ['mysql'],
        'OUTPUT'                           => ['postgresql'],
        'OVER'                             => ['postgresql'],
        'OVERLAPS'                         => ['postgresql'],
        'OVERLAY'                          => ['postgresql'],
        'OVERRIDING'                       => ['postgresql'],
        'OWNER'                            => ['postgresql'],
        'PACK_KEYS'                        => ['mysql'],
        'PAD'                              => ['postgresql'],
        'PAGE'                             => ['mysql'],
        'PARAMETER'                        => ['postgresql'],
        'PARAMETERS'                       => ['postgresql'],
        'PARAMETER_MODE'                   => ['postgresql'],
        'PARAMETER_NAME'                   => ['postgresql'],
        'PARAMETER_ORDINAL_POSITION'       => ['postgresql'],
        'PARAMETER_SPECIFIC_CATALOG'       => ['postgresql'],
        'PARAMETER_SPECIFIC_NAME'          => ['postgresql'],
        'PARAMETER_SPECIFIC_SCHEMA'        => ['postgresql'],
        'PARTIAL'                          => ['postgresql', 'mysql'],
        'PARTITION'                        => ['postgresql'],
        'PASCAL'                           => ['postgresql'],
        'PASSWORD'                         => ['postgresql', 'mysql'],
        'PATH'                             => ['postgresql'],
        'PERCENTILE_CONT'                  => ['postgresql'],
        'PERCENTILE_DISC'                  => ['postgresql'],
        'PERCENT_RANK'                     => ['postgresql'],
        'PHASE'                            => ['mysql'],
        'PLACING'                          => ['postgresql'],
        'PLAN'                             => ['sqlite'],
        'PLI'                              => ['postgresql'],
        'POINT'                            => ['mysql'],
        'POLYGON'                          => ['mysql'],
        'POSITION'                         => ['postgresql'],
        'POSTFIX'                          => ['postgresql'],
        'POWER'                            => ['postgresql'],
        'PRAGMA'                           => ['sqlite'],
        'PRECEDING'                        => ['postgresql'],
        'PRECISION'                        => ['postgresql', 'mysql'],
        'PREFIX'                           => ['postgresql'],
        'PREORDER'                         => ['postgresql'],
        'PREPARE'                          => ['postgresql', 'mysql'],
        'PREPARED'                         => ['postgresql'],
        'PRESERVE'                         => ['postgresql'],
        'PREV'                             => ['mysql'],
        'PRIMARY'                          => ['postgresql', 'mysql', 'sqlite'],
        'PRIOR'                            => ['postgresql'],
        'PRIVILEGES'                       => ['postgresql', 'mysql'],
        'PROCEDURAL'                       => ['postgresql'],
        'PROCEDURE'                        => ['postgresql', 'mysql'],
        'PROCESSLIST'                      => ['mysql'],
        'PROFILE'                          => ['mysql'],
        'PROFILES'                         => ['mysql'],
        'PUBLIC'                           => ['postgresql'],
        'PURGE'                            => ['mysql'],
        'QUARTER'                          => ['mysql'],
        'QUERY'                            => ['mysql', 'sqlite'],
        'QUICK'                            => ['mysql'],
        'QUOTE'                            => ['postgresql'],
        'RAID0'                            => ['mysql'],
        'RAID_CHUNKS'                      => ['mysql'],
        'RAID_CHUNKSIZE'                   => ['mysql'],
        'RAID_TYPE'                        => ['mysql'],
        'RAISE'                            => ['sqlite'],
        'RANGE'                            => ['postgresql'],
        'RANK'                             => ['postgresql'],
        'READ'                             => ['postgresql', 'mysql'],
        'READS'                            => ['postgresql', 'mysql'],
        'REAL'                             => ['postgresql', 'mysql'],
        'RECHECK'                          => ['postgresql'],
        'RECOVER'                          => ['mysql'],
        'RECURSIVE'                        => ['postgresql', 'sqlite'],
        'REDUNDANT'                        => ['mysql'],
        'REF'                              => ['postgresql'],
        'REFERENCES'                       => ['postgresql', 'mysql', 'sqlite'],
        'REFERENCING'                      => ['postgresql'],
        'REGEXP'                           => ['mysql', 'sqlite'],
        'REGR_AVGX'                        => ['postgresql'],
        'REGR_AVGY'                        => ['postgresql'],
        'REGR_COUNT'                       => ['postgresql'],
        'REGR_INTERCEPT'                   => ['postgresql'],
        'REGR_R2'                          => ['postgresql'],
        'REGR_SLOPE'                       => ['postgresql'],
        'REGR_SXX'                         => ['postgresql'],
        'REGR_SXY'                         => ['postgresql'],
        'REGR_SYY'                         => ['postgresql'],
        'REINDEX'                          => ['postgresql', 'sqlite'],
        'RELATIVE'                         => ['postgresql'],
        'RELAY_LOG_FILE'                   => ['mysql'],
        'RELAY_LOG_POS'                    => ['mysql'],
        'RELAY_THREAD'                     => ['mysql'],
        'RELEASE'                          => ['postgresql', 'mysql', 'sqlite'],
        'RELOAD'                           => ['mysql'],
        'RENAME'                           => ['postgresql', 'mysql', 'sqlite'],
        'REPAIR'                           => ['mysql'],
        'REPEAT'                           => ['mysql'],
        'REPEATABLE'                       => ['postgresql', 'mysql'],
        'REPLACE'                          => ['postgresql', 'mysql', 'sqlite'],
        'REPLICATION'                      => ['mysql'],
        'REQUIRE'                          => ['mysql'],
        'RESET'                            => ['postgresql', 'mysql'],
        'RESTART'                          => ['postgresql'],
        'RESTORE'                          => ['mysql'],
        'RESTRICT'                         => ['postgresql', 'mysql', 'sqlite'],
        'RESULT'                           => ['postgresql'],
        'RESUME'                           => ['mysql'],
        'RETURN'                           => ['postgresql', 'mysql'],
        'RETURNED_CARDINALITY'             => ['postgresql'],
        'RETURNED_LENGTH'                  => ['postgresql'],
        'RETURNED_OCTET_LENGTH'            => ['postgresql'],
        'RETURNED_SQLSTATE'                => ['postgresql'],
        'RETURNS'                          => ['postgresql', 'mysql'],
        'REVOKE'                           => ['postgresql', 'mysql'],
        'RIGHT'                            => ['postgresql', 'mysql', 'sqlite'],
        'RLIKE'                            => ['mysql'],
        'ROLE'                             => ['postgresql'],
        'ROLLBACK'                         => ['postgresql', 'mysql', 'sqlite'],
        'ROLLUP'                           => ['postgresql', 'mysql'],
        'ROUTINE'                          => ['postgresql', 'mysql'],
        'ROUTINE_CATALOG'                  => ['postgresql'],
        'ROUTINE_NAME'                     => ['postgresql'],
        'ROUTINE_SCHEMA'                   => ['postgresql'],
        'ROW'                              => ['postgresql', 'mysql', 'sqlite'],
        'ROWS'                             => ['postgresql', 'mysql'],
        'ROW_COUNT'                        => ['postgresql'],
        'ROW_FORMAT'                       => ['mysql'],
        'ROW_NUMBER'                       => ['postgresql'],
        'RTREE'                            => ['mysql'],
        'RULE'                             => ['postgresql'],
        'SAVEPOINT'                        => ['postgresql', 'mysql', 'sqlite'],
        'SCALE'                            => ['postgresql'],
        'SCHEMA'                           => ['postgresql', 'mysql'],
        'SCHEMAS'                          => ['mysql'],
        'SCHEMA_NAME'                      => ['postgresql'],
        'SCOPE'                            => ['postgresql'],
        'SCOPE_CATALOG'                    => ['postgresql'],
        'SCOPE_NAME'                       => ['postgresql'],
        'SCOPE_SCHEMA'                     => ['postgresql'],
        'SCROLL'                           => ['postgresql'],
        'SEARCH'                           => ['postgresql'],
        'SECOND'                           => ['postgresql', 'mysql'],
        'SECOND_MICROSECOND'               => ['mysql'],
        'SECTION'                          => ['postgresql'],
        'SECURITY'                         => ['postgresql', 'mysql'],
        'SELECT'                           => ['postgresql', 'mysql', 'sqlite'],
        'SELF'                             => ['postgresql'],
        'SENSITIVE'                        => ['postgresql', 'mysql'],
        'SEPARATOR'                        => ['mysql'],
        'SEQUENCE'                         => ['postgresql'],
        'SERIAL'                           => ['mysql'],
        'SERIALIZABLE'                     => ['postgresql', 'mysql'],
        'SERVER_NAME'                      => ['postgresql'],
        'SESSION'                          => ['postgresql', 'mysql'],
        'SESSION_USER'                     => ['postgresql'],
        'SET'                              => ['postgresql', 'mysql', 'sqlite'],
        'SETOF'                            => ['postgresql'],
        'SETS'                             => ['postgresql'],
        'SHARE'                            => ['postgresql', 'mysql'],
        'SHOW'                             => ['postgresql', 'mysql'],
        'SHUTDOWN'                         => ['mysql'],
        'SIGNED'                           => ['mysql'],
        'SIMILAR'                          => ['postgresql'],
        'SIMPLE'                           => ['postgresql', 'mysql'],
        'SIZE'                             => ['postgresql'],
        'SLAVE'                            => ['mysql'],
        'SMALLINT'                         => ['postgresql', 'mysql'],
        'SNAPSHOT'                         => ['mysql'],
        'SOME'                             => ['postgresql', 'mysql'],
        'SONAME'                           => ['mysql'],
        'SOUNDS'                           => ['mysql'],
        'SOURCE'                           => ['postgresql', 'mysql'],
        'SPACE'                            => ['postgresql'],
        'SPATIAL'                          => ['mysql'],
        'SPECIFIC'                         => ['postgresql', 'mysql'],
        'SPECIFICTYPE'                     => ['postgresql'],
        'SPECIFIC_NAME'                    => ['postgresql'],
        'SQL'                              => ['postgresql', 'mysql'],
        'SQLCODE'                          => ['postgresql'],
        'SQLERROR'                         => ['postgresql'],
        'SQLEXCEPTION'                     => ['postgresql', 'mysql'],
        'SQLSTATE'                         => ['postgresql', 'mysql'],
        'SQLWARNING'                       => ['postgresql', 'mysql'],
        'SQL_BIG_RESULT'                   => ['mysql'],
        'SQL_BUFFER_RESULT'                => ['mysql'],
        'SQL_CACHE'                        => ['mysql'],
        'SQL_CALC_FOUND_ROWS'              => ['mysql'],
        'SQL_NO_CACHE'                     => ['mysql'],
        'SQL_SMALL_RESULT'                 => ['mysql'],
        'SQL_THREAD'                       => ['mysql'],
        'SQL_TSI_DAY'                      => ['mysql'],
        'SQL_TSI_FRAC_SECOND'              => ['mysql'],
        'SQL_TSI_HOUR'                     => ['mysql'],
        'SQL_TSI_MINUTE'                   => ['mysql'],
        'SQL_TSI_MONTH'                    => ['mysql'],
        'SQL_TSI_QUARTER'                  => ['mysql'],
        'SQL_TSI_SECOND'                   => ['mysql'],
        'SQL_TSI_WEEK'                     => ['mysql'],
        'SQL_TSI_YEAR'                     => ['mysql'],
        'SQRT'                             => ['postgresql'],
        'SSL'                              => ['mysql'],
        'STABLE'                           => ['postgresql'],
        'START'                            => ['postgresql', 'mysql'],
        'STARTING'                         => ['mysql'],
        'STATE'                            => ['postgresql'],
        'STATEMENT'                        => ['postgresql'],
        'STATIC'                           => ['postgresql'],
        'STATISTICS'                       => ['postgresql'],
        'STATUS'                           => ['mysql'],
        'STDDEV_POP'                       => ['postgresql'],
        'STDDEV_SAMP'                      => ['postgresql'],
        'STDIN'                            => ['postgresql'],
        'STDOUT'                           => ['postgresql'],
        'STOP'                             => ['mysql'],
        'STORAGE'                          => ['postgresql', 'mysql'],
        'STRAIGHT_JOIN'                    => ['mysql'],
        'STRICT'                           => ['postgresql'],
        'STRING'                           => ['mysql'],
        'STRIPED'                          => ['mysql'],
        'STRUCTURE'                        => ['postgresql'],
        'STYLE'                            => ['postgresql'],
        'SUBCLASS_ORIGIN'                  => ['postgresql'],
        'SUBJECT'                          => ['mysql'],
        'SUBLIST'                          => ['postgresql'],
        'SUBMULTISET'                      => ['postgresql'],
        'SUBSTRING'                        => ['postgresql'],
        'SUM'                              => ['postgresql'],
        'SUPER'                            => ['mysql'],
        'SUPERUSER'                        => ['postgresql'],
        'SUSPEND'                          => ['mysql'],
        'SWAPS'                            => ['mysql'],
        'SWITCHES'                         => ['mysql'],
        'SYMMETRIC'                        => ['postgresql'],
        'SYSID'                            => ['postgresql'],
        'SYSTEM'                           => ['postgresql'],
        'SYSTEM_USER'                      => ['postgresql'],
        'TABLE'                            => ['postgresql', 'mysql', 'sqlite'],
        'TABLES'                           => ['mysql'],
        'TABLESAMPLE'                      => ['postgresql'],
        'TABLESPACE'                       => ['postgresql', 'mysql'],
        'TABLE_NAME'                       => ['postgresql'],
        'TEMP'                             => ['postgresql', 'sqlite'],
        'TEMPLATE'                         => ['postgresql'],
        'TEMPORARY'                        => ['postgresql', 'mysql', 'sqlite'],
        'TEMPTABLE'                        => ['mysql'],
        'TERMINATE'                        => ['postgresql'],
        'TERMINATED'                       => ['mysql'],
        'TEXT'                             => ['mysql'],
        'THAN'                             => ['postgresql'],
        'THEN'                             => ['postgresql', 'mysql', 'sqlite'],
        'TIES'                             => ['postgresql'],
        'TIME'                             => ['postgresql', 'mysql'],
        'TIMESTAMP'                        => ['postgresql', 'mysql'],
        'TIMESTAMPADD'                     => ['mysql'],
        'TIMESTAMPDIFF'                    => ['mysql'],
        'TIMEZONE_HOUR'                    => ['postgresql'],
        'TIMEZONE_MINUTE'                  => ['postgresql'],
        'TINYBLOB'                         => ['mysql'],
        'TINYINT'                          => ['mysql'],
        'TINYTEXT'                         => ['mysql'],
        'TO'                               => ['postgresql', 'mysql', 'sqlite'],
        'TOAST'                            => ['postgresql'],
        'TOP_LEVEL_COUNT'                  => ['postgresql'],
        'TRAILING'                         => ['postgresql', 'mysql'],
        'TRANSACTION'                      => ['postgresql', 'mysql', 'sqlite'],
        'TRANSACTIONS_COMMITTED'           => ['postgresql'],
        'TRANSACTIONS_ROLLED_BACK'         => ['postgresql'],
        'TRANSACTION_ACTIVE'               => ['postgresql'],
        'TRANSFORM'                        => ['postgresql'],
        'TRANSFORMS'                       => ['postgresql'],
        'TRANSLATE'                        => ['postgresql'],
        'TRANSLATION'                      => ['postgresql'],
        'TREAT'                            => ['postgresql'],
        'TRIGGER'                          => ['postgresql', 'mysql', 'sqlite'],
        'TRIGGERS'                         => ['mysql'],
        'TRIGGER_CATALOG'                  => ['postgresql'],
        'TRIGGER_NAME'                     => ['postgresql'],
        'TRIGGER_SCHEMA'                   => ['postgresql'],
        'TRIM'                             => ['postgresql'],
        'TRUE'                             => ['postgresql', 'mysql'],
        'TRUNCATE'                         => ['postgresql', 'mysql'],
        'TRUSTED'                          => ['postgresql'],
        'TYPE'                             => ['postgresql', 'mysql'],
        'TYPES'                            => ['mysql'],
        'UESCAPE'                          => ['postgresql'],
        'UNBOUNDED'                        => ['postgresql'],
        'UNCOMMITTED'                      => ['postgresql', 'mysql'],
        'UNDEFINED'                        => ['mysql'],
        'UNDER'                            => ['postgresql'],
        'UNDO'                             => ['mysql'],
        'UNENCRYPTED'                      => ['postgresql'],
        'UNICODE'                          => ['mysql'],
        'UNION'                            => ['postgresql', 'mysql', 'sqlite'],
        'UNIQUE'                           => ['postgresql', 'mysql', 'sqlite'],
        'UNKNOWN'                          => ['postgresql', 'mysql'],
        'UNLISTEN'                         => ['postgresql'],
        'UNLOCK'                           => ['mysql'],
        'UNNAMED'                          => ['postgresql'],
        'UNNEST'                           => ['postgresql'],
        'UNSIGNED'                         => ['mysql'],
        'UNTIL'                            => ['postgresql', 'mysql'],
        'UPDATE'                           => ['postgresql', 'mysql', 'sqlite'],
        'UPGRADE'                          => ['mysql'],
        'UPPER'                            => ['postgresql'],
        'USAGE'                            => ['postgresql', 'mysql'],
        'USE'                              => ['mysql'],
        'USER'                             => ['postgresql', 'mysql'],
        'USER_DEFINED_TYPE_CATALOG'        => ['postgresql'],
        'USER_DEFINED_TYPE_CODE'           => ['postgresql'],
        'USER_DEFINED_TYPE_NAME'           => ['postgresql'],
        'USER_DEFINED_TYPE_SCHEMA'         => ['postgresql'],
        'USER_RESOURCES'                   => ['mysql'],
        'USE_FRM'                          => ['mysql'],
        'USING'                            => ['postgresql', 'mysql', 'sqlite'],
        'UTC_DATE'                         => ['mysql'],
        'UTC_TIME'                         => ['mysql'],
        'UTC_TIMESTAMP'                    => ['mysql'],
        'VACUUM'                           => ['postgresql', 'sqlite'],
        'VALID'                            => ['postgresql'],
        'VALIDATOR'                        => ['postgresql'],
        'VALUE'                            => ['postgresql', 'mysql'],
        'VALUES'                           => ['postgresql', 'mysql', 'sqlite'],
        'VARBINARY'                        => ['mysql'],
        'VARCHAR'                          => ['postgresql', 'mysql'],
        'VARCHARACTER'                     => ['mysql'],
        'VARIABLE'                         => ['postgresql'],
        'VARIABLES'                        => ['mysql'],
        'VARYING'                          => ['postgresql', 'mysql'],
        'VAR_POP'                          => ['postgresql'],
        'VAR_SAMP'                         => ['postgresql'],
        'VERBOSE'                          => ['postgresql'],
        'VIEW'                             => ['postgresql', 'mysql', 'sqlite'],
        'VIRTUAL'                          => ['sqlite'],
        'VOLATILE'                         => ['postgresql'],
        'WARNINGS'                         => ['mysql'],
        'WEEK'                             => ['mysql'],
        'WHEN'                             => ['postgresql', 'mysql', 'sqlite'],
        'WHENEVER'                         => ['postgresql'],
        'WHERE'                            => ['postgresql', 'mysql', 'sqlite'],
        'WHILE'                            => ['mysql'],
        'WIDTH_BUCKET'                     => ['postgresql'],
        'WINDOW'                           => ['postgresql'],
        'WITH'                             => ['postgresql', 'mysql', 'sqlite'],
        'WITHIN'                           => ['postgresql'],
        'WITHOUT'                          => ['postgresql', 'sqlite'],
        'WORK'                             => ['postgresql', 'mysql'],
        'WRITE'                            => ['postgresql', 'mysql'],
        'X509'                             => ['mysql'],
        'XA'                               => ['mysql'],
        'XOR'                              => ['mysql'],
        'YEAR'                             => ['postgresql', 'mysql'],
        'YEAR_MONTH'                       => ['mysql'],
        'ZEROFILL'                         => ['mysql'],
        'ZONE'                             => ['postgresql'],
    ];
}
