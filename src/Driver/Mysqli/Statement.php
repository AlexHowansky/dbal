<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\Mysqli;

use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Exception\UnknownParameterType;
use Doctrine\DBAL\Driver\Mysqli\Exception\ConnectionError;
use Doctrine\DBAL\Driver\Mysqli\Exception\FailedReadingStreamOffset;
use Doctrine\DBAL\Driver\Mysqli\Exception\NonStreamResourceUsedAsLargeObject;
use Doctrine\DBAL\Driver\Mysqli\Exception\StatementError;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use mysqli;
use mysqli_stmt;

use function array_fill;
use function assert;
use function count;
use function feof;
use function fread;
use function get_resource_type;
use function is_int;
use function is_resource;
use function str_repeat;

final class Statement implements StatementInterface
{
    /** @var string[] */
    private static array $paramTypeMap = [
        ParameterType::ASCII        => 's',
        ParameterType::STRING       => 's',
        ParameterType::BINARY       => 's',
        ParameterType::BOOLEAN      => 'i',
        ParameterType::NULL         => 's',
        ParameterType::INTEGER      => 'i',
        ParameterType::LARGE_OBJECT => 'b',
    ];

    private mysqli $conn;

    private mysqli_stmt $stmt;

    /** @var mixed[] */
    private array $boundValues;

    private string $types;

    /**
     * Contains ref values for bindValue().
     *
     * @var mixed[]
     */
    private array $values = [];

    /**
     * @internal The statement can be only instantiated by its driver connection.
     *
     * @throws Exception
     */
    public function __construct(mysqli $conn, string $sql)
    {
        $this->conn = $conn;

        $stmt = $conn->prepare($sql);

        if ($stmt === false) {
            throw ConnectionError::new($this->conn);
        }

        $this->stmt = $stmt;

        $paramCount = $this->stmt->param_count;

        $this->types       = str_repeat('s', $paramCount);
        $this->boundValues = array_fill(1, $paramCount, null);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, int $type = ParameterType::STRING, ?int $length = null): void
    {
        assert(is_int($param));

        if (! isset(self::$paramTypeMap[$type])) {
            throw UnknownParameterType::new($type);
        }

        $this->boundValues[$param] =& $variable;
        $this->types[$param - 1]   = self::$paramTypeMap[$type];
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, int $type = ParameterType::STRING): void
    {
        assert(is_int($param));

        if (! isset(self::$paramTypeMap[$type])) {
            throw UnknownParameterType::new($type);
        }

        $this->values[$param]      = $value;
        $this->boundValues[$param] =& $this->values[$param];
        $this->types[$param - 1]   = self::$paramTypeMap[$type];
    }

    public function execute(?array $params = null): ResultInterface
    {
        if ($params !== null && count($params) > 0) {
            $this->bindUntypedValues($params);
        } else {
            $this->bindTypedParameters();
        }

        if (! $this->stmt->execute()) {
            throw StatementError::new($this->stmt);
        }

        return new Result($this->stmt);
    }

    /**
     * Binds parameters with known types previously bound to the statement
     *
     * @throws Exception
     */
    private function bindTypedParameters(): void
    {
        $streams = $values = [];
        $types   = $this->types;

        foreach ($this->boundValues as $parameter => $value) {
            assert(is_int($parameter));
            if (! isset($types[$parameter - 1])) {
                $types[$parameter - 1] = self::$paramTypeMap[ParameterType::STRING];
            }

            if ($types[$parameter - 1] === self::$paramTypeMap[ParameterType::LARGE_OBJECT]) {
                if (is_resource($value)) {
                    if (get_resource_type($value) !== 'stream') {
                        throw NonStreamResourceUsedAsLargeObject::new($parameter);
                    }

                    $streams[$parameter] = $value;
                    $values[$parameter]  = null;
                    continue;
                }

                $types[$parameter - 1] = self::$paramTypeMap[ParameterType::STRING];
            }

            $values[$parameter] = $value;
        }

        if (count($values) > 0) {
            $this->bindParams($types, ...$values);
        }

        $this->sendLongData($streams);
    }

    /**
     * Handle $this->_longData after regular query parameters have been bound
     *
     * @param array<int, resource> $streams
     *
     * @throws Exception
     */
    private function sendLongData(array $streams): void
    {
        foreach ($streams as $paramNr => $stream) {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    throw FailedReadingStreamOffset::new($paramNr);
                }

                if (! $this->stmt->send_long_data($paramNr - 1, $chunk)) {
                    throw StatementError::new($this->stmt);
                }
            }
        }
    }

    /**
     * Binds a array of values to bound parameters.
     *
     * @param mixed[] $values
     *
     * @throws Exception
     */
    private function bindUntypedValues(array $values): void
    {
        $params = [];
        $types  = str_repeat('s', count($values));

        foreach ($values as &$v) {
            $params[] =& $v;
        }

        $this->bindParams($types, ...$params);
    }

    /**
     * @param mixed ...$params
     *
     * @throws Exception
     */
    private function bindParams(string $types, &...$params): void
    {
        if (! $this->stmt->bind_param($types, ...$params)) {
            throw StatementError::new($this->stmt);
        }
    }
}
