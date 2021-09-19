<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Tests\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchemaDiffTest extends TestCase
{
    public function testSchemaDiffToSql(): void
    {
        $diff     = $this->createSchemaDiff();
        $platform = $this->createPlatform(true);

        $sql = $diff->toSql($platform);

        self::assertEquals([
            'create_schema',
            'drop_orphan_fk',
            'alter_seq',
            'drop_seq',
            'create_seq',
            'create_table',
            'create_foreign_key',
            'drop_table',
            'alter_table',
        ], $sql);
    }

    public function testSchemaDiffToSaveSql(): void
    {
        $diff     = $this->createSchemaDiff();
        $platform = $this->createPlatform(false);

        $sql = $diff->toSaveSql($platform);

        $expected = ['create_schema', 'alter_seq', 'create_seq', 'create_table', 'create_foreign_key', 'alter_table'];

        self::assertEquals($expected, $sql);
    }

    /**
     * @return AbstractPlatform&MockObject
     */
    private function createPlatform(bool $unsafe)
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->expects(self::exactly(1))
            ->method('getCreateSchemaSQL')
            ->with('foo_ns')
            ->will(self::returnValue('create_schema'));
        if ($unsafe) {
            $platform->expects(self::exactly(1))
                 ->method('getDropSequenceSql')
                 ->with('baz_seq')
                 ->will(self::returnValue('drop_seq'));
        }

        $platform->expects(self::exactly(1))
                 ->method('getAlterSequenceSql')
                 ->with(self::isInstanceOf(Sequence::class))
                 ->will(self::returnValue('alter_seq'));
        $platform->expects(self::exactly(1))
                 ->method('getCreateSequenceSql')
                 ->with(self::isInstanceOf(Sequence::class))
                 ->will(self::returnValue('create_seq'));
        if ($unsafe) {
            $platform->expects(self::exactly(1))
                     ->method('getDropTableSql')
                     ->with('bar_table')
                     ->will(self::returnValue('drop_table'));
        }

        $platform->expects(self::exactly(1))
                 ->method('getCreateTableSql')
                 ->with(self::isInstanceOf(Table::class))
                 ->will(self::returnValue(['create_table']));
        $platform->expects(self::exactly(1))
                 ->method('getCreateForeignKeySQL')
                 ->with(self::isInstanceOf(ForeignKeyConstraint::class))
                 ->will(self::returnValue('create_foreign_key'));
        $platform->expects(self::exactly(1))
                 ->method('getAlterTableSql')
                 ->with(self::isInstanceOf(TableDiff::class))
                 ->will(self::returnValue(['alter_table']));
        if ($unsafe) {
            $platform->expects(self::exactly(1))
                     ->method('getDropForeignKeySql')
                     ->will(self::returnValue('drop_orphan_fk'));
        }

        $platform->expects(self::exactly(1))
                ->method('supportsSchemas')
                ->will(self::returnValue(true));
        $platform->expects(self::exactly(1))
                ->method('supportsSequences')
                ->will(self::returnValue(true));
        $platform->expects(self::exactly(2))
                ->method('supportsForeignKeyConstraints')
                ->will(self::returnValue(true));

        return $platform;
    }

    public function createSchemaDiff(): SchemaDiff
    {
        $diff                              = new SchemaDiff();
        $diff->newNamespaces['foo_ns']     = 'foo_ns';
        $diff->removedNamespaces['bar_ns'] = 'bar_ns';
        $diff->changedSequences[]          = new Sequence('foo_seq');
        $diff->newSequences[]              = new Sequence('bar_seq');
        $diff->removedSequences[]          = new Sequence('baz_seq');
        $diff->newTables['foo_table']      = new Table('foo_table');
        $diff->removedTables['bar_table']  = new Table('bar_table');
        $diff->changedTables['baz_table']  = new TableDiff('baz_table');
        $diff->newTables['foo_table']->addColumn('foreign_id', 'integer');
        $diff->newTables['foo_table']->addForeignKeyConstraint('foreign_table', ['foreign_id'], ['id']);
        $diff->orphanedForeignKeys['local_table'][] = new ForeignKeyConstraint(
            ['id'],
            'foreign_table',
            ['id']
        );

        return $diff;
    }
}
