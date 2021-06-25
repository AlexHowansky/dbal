<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\PDO\SQLSrv;

use Doctrine\DBAL\Driver\PDO\Connection as PDOConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\Deprecations\Deprecation;
use PDO;

final class Connection implements ServerInfoAwareConnection
{
    /** @var PDOConnection */
    private $connection;

    public function __construct(PDOConnection $connection)
    {
        $this->connection = $connection;
    }

    public function prepare(string $sql): StatementInterface
    {
        return new Statement(
            $this->connection->prepare($sql)
        );
    }

    public function query(string $sql): Result
    {
        return $this->connection->query($sql);
    }

    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->connection->exec($sql);
    }

    public function lastInsertId(?string $name = null): string
    {
        if ($name === null) {
            return $this->connection->lastInsertId($name);
        }

        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/issues/4687',
            'The usage of Connection::lastInsertId() with a sequence name is deprecated.'
        );

        return $this->prepare('SELECT CONVERT(VARCHAR(MAX), current_value) FROM sys.sequences WHERE name = ?')
            ->execute([$name])
            ->fetchOne();
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function getServerVersion(): string
    {
        return $this->connection->getServerVersion();
    }

    public function getWrappedConnection(): PDO
    {
        return $this->connection->getWrappedConnection();
    }
}
