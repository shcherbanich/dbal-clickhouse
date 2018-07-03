<?php
/*
 * This file is part of the FODDBALClickHouse package -- Doctrine DBAL library
 * for ClickHouse (a column-oriented DBMS for OLAP <https://clickhouse.yandex/>)
 *
 * (c) FriendsOfDoctrine <https://github.com/FriendsOfDoctrine/>.
 *
 * For the full copyright and license inflormation, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOD\DBALClickHouse;

use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * ClickHouse Statement
 *
 * @author Mochalygin <a@mochalygin.ru>
 */
class ClickHouseStatement implements \IteratorAggregate, \Doctrine\DBAL\Driver\Statement
{
    /**
     * @var \ClickHouseDB\Client
     */
    protected $smi2CHClient;

    /**
     * @var string
     */
    protected $statement;

    /**
     * @var AbstractPlatform
     */
    protected $platform;

    /**
     * @var array|null
     */
    protected $rows;

    /**
     * Query parameters for prepared statement (key => value)
     * @var array
     */
    protected $values = [];

    /**
     * Query parameters' types for prepared statement (key => value)
     * @var array
     */
    protected $types = [];

    /**
     * @var \ArrayIterator|null
     */
    protected $iterator;

    /**
     * @var integer
     */
    private $fetchMode = FetchMode::MIXED;

    /**
     * @param \ClickHouseDB\Client $client
     * @param string $statement
     * @param AbstractPlatform $platform
     */
    public function __construct(\ClickHouseDB\Client $client, $statement, AbstractPlatform $platform)
    {
        $this->smi2CHClient = $client;
        $this->statement = $statement;
        $this->platform = $platform;
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator(): \ArrayIterator
    {
        if (!$this->iterator) {
            $this->iterator = new \ArrayIterator($this->rows ?: []);
        }

        return $this->iterator;
    }

    /**
     * {@inheritDoc}
     */
    public function closeCursor()
    {
        $this->rows = null;
        $this->iterator = null;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function columnCount()
    {
        return $this->rows
            ? \count(current($this->rows))
            : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null)
    {
        $this->fetchMode = $this->assumeFetchMode($fetchMode);

        return true;
    }

    /**
     * @param int|null $fetchMode
     * @return int
     */
    protected function assumeFetchMode($fetchMode = null)
    {
        $mode = $fetchMode ?: $this->fetchMode;
        if (!\in_array($mode, [
            FetchMode::ASSOCIATIVE,
            FetchMode::NUMERIC,
            FetchMode::STANDARD_OBJECT,
            \PDO::FETCH_KEY_PAIR,
        ], true)) {
            $mode = FetchMode::MIXED;
        }

        return $mode;
    }

    /**
     * {@inheritDoc}
     */
    public function fetch($fetchMode = null, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        $data = $this->getIterator()->current();

        if (null === $data) {
            return false;
        }

        $this->getIterator()->next();

        if (FetchMode::NUMERIC === $this->assumeFetchMode($fetchMode)) {
            return array_values($data);
        }

        if (FetchMode::MIXED === $this->assumeFetchMode($fetchMode)) {
            return array_values($data) + $data;
        }

        if (FetchMode::STANDARD_OBJECT === $this->assumeFetchMode($fetchMode)) {
            return (object)$data;
        }

        if (\PDO::FETCH_KEY_PAIR === $this->assumeFetchMode($fetchMode)) {
            if (\count($data) < 2) {
                throw new \Exception('To fetch in \PDO::FETCH_KEY_PAIR mode, result set must contain at least 2 columns');
            }

            return [array_shift($data) => array_shift($data)];
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
    {
        if (FetchMode::NUMERIC === $this->assumeFetchMode($fetchMode)) {
            return array_map(
                'array_values',
                $this->rows
            );
        }

        if (FetchMode::MIXED === $this->assumeFetchMode($fetchMode)) {
            return array_map(
                function ($row) {
                    return array_values($row) + $row;
                },
                $this->rows
            );
        }

        if (FetchMode::STANDARD_OBJECT === $this->assumeFetchMode($fetchMode)) {
            return array_map(
                function ($row) {
                    return (object)$row;
                },
                $this->rows
            );
        }

        if (\PDO::FETCH_KEY_PAIR === $this->assumeFetchMode($fetchMode)) {
            return array_map(
                function ($row) {
                    if (\count($row) < 2) {
                        throw new \Exception('To fetch in \PDO::FETCH_KEY_PAIR mode, result set must contain at least 2 columns');
                    }

                    return [array_shift($row) => array_shift($row)];
                },
                $this->rows
            );
        }

        return $this->rows;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchColumn($columnIndex = 0)
    {
        if ($elem = $this->fetch(FetchMode::NUMERIC)) {
            return $elem[$columnIndex] ?? $elem[0];
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function bindValue($param, $value, $type = null)
    {
        $this->values[$param] = $value;
        $this->types[$param] = $type;
    }

    /**
     * {@inheritDoc}
     */
    public function bindParam($column, &$variable, $type = null, $length = null)
    {
        $this->values[$column] = &$variable;
        $this->types[$column] = $type;
    }

    public function errorCode()
    {
        throw new ClickHouseException('You need to implement ClickHouseStatement::' . __METHOD__ . '()');
    }

    public function errorInfo()
    {
        throw new ClickHouseException('You need to implement ClickHouseStatement::' . __METHOD__ . '()');
    }

    /**
     * {@inheritDoc}
     */
    public function execute($params = null)
    {
        $hasZeroIndex = false;
        if (\is_array($params)) {
            $this->values = array_replace($this->values, $params);//TODO array keys must be all strings or all integers?
            $hasZeroIndex = array_key_exists(0, $params);
        }

        $sql = $this->statement;

        if ($hasZeroIndex) {
            $statementParts = explode('?', $sql);
            array_walk($statementParts, function (&$part, $key) {
                if (array_key_exists($key, $this->values)) {
                    $part .= $this->getTypedParam($key);
                }
            });
            $sql = implode('', $statementParts);
        } else {
            foreach (array_keys($this->values) as $key) {
                $sql = preg_replace(
                    '/(' . (\is_int($key) ? '\?' : ':' . $key) . ')/i',
                    $this->getTypedParam($key),
                    $sql,
                    1
                );
            }
        }

        $this->processViaSMI2($sql);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function rowCount()
    {
        return 1; // ClickHouse do not return amount of inserted rows, so we will return 1
    }

    /**
     * @return string
     */
    public function getSql()
    {
        return $this->statement;
    }

    /**
     * Specific SMI2 ClickHouse lib statement execution
     * If you want to use any other lib for working with CH -- just update this method
     *
     * @param string $sql
     */
    protected function processViaSMI2($sql)
    {
        $sql = trim($sql);

        $this->rows =
            0 === stripos($sql, 'select') ||
            0 === stripos($sql, 'show') ||
            0 === stripos($sql, 'describe') ?
                $this->smi2CHClient->select($sql)->rows() :
                $this->smi2CHClient->write($sql)->rows();
    }

    /**
     * @param string|int $key
     * @throws ClickHouseException
     * @return int|null|string
     */
    protected function getTypedParam($key)
    {
        $type = $this->types[$key] ?? null;

        // if param type was not setted - trying to get db-type by php-var-type
        if (null === $type) {
            if (\is_bool($this->values[$key])) {
                $type = ParameterType::BOOLEAN;
            } else {
                if (\is_int($this->values[$key]) || \is_float($this->values[$key])) {
                    $type = ParameterType::INTEGER;
                } else {
                    if (\is_array($this->values[$key])) {

                        /*
                         * ClickHouse Arrays
                         */
                        $values = $this->values[$key];
                        if (\is_int(current($values)) || \is_float(current($values))) {
                            array_map(
                                function ($value) {
                                    if (!\is_int($value) && !\is_float($value)) {
                                        throw new ClickHouseException('Array values must all be int/float or string, mixes not allowed');
                                    }
                                },
                                $values
                            );
                        } else {
                            $values = array_map([$this->platform, 'quoteStringLiteral'], $values);
                        }

                        return '[' . implode(', ', $values) . ']';
                    }
                }
            }
        }

        if (ParameterType::NULL === $type) {
            throw new ClickHouseException('NULLs are not supported by ClickHouse');
        }

        if (ParameterType::INTEGER === $type) {
            return $this->values[$key];
        }

        if (ParameterType::BOOLEAN === $type) {
            return (int)(bool)$this->values[$key];
        }

        return $this->platform->quoteStringLiteral($this->values[$key]);
    }
}
