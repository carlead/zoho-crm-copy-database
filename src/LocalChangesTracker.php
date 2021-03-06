<?php


namespace Carlead\Zoho\CRM\Copy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Psr\Log\LoggerInterface;

/**
 * This class is in charge of tracking local files.
 * To do so, it can add a set of triggers that observe and track changes in tables.
 */
class LocalChangesTracker
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Connection $connection
     */
    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }


    public function createTrackingTables()
    {
        $schema = new \Doctrine\DBAL\Schema\Schema();

        $localUpdate = $schema->createTable("local_update");
        $localUpdate->addColumn("table_name", 'string', ['length' => 100]);
        $localUpdate->addColumn("id", 'string', ['length' => 100]);
        $localUpdate->addColumn("field_name", 'string', ['length' => 100]);
        $localUpdate->setPrimaryKey(array("table_name", "id", "field_name"));

        $localInsert = $schema->createTable("local_insert");
        $localInsert->addColumn("table_name", 'string', ['length' => 100]);
        $localInsert->addColumn("id", 'string', ['length' => 100]);
        $localInsert->setPrimaryKey(array("table_name", "id"));

        $localDelete = $schema->createTable("local_delete");
        $localDelete->addColumn("table_name", 'string', ['length' => 100]);
        $localDelete->addColumn("id", 'string', ['length' => 100]);
        $localDelete->setPrimaryKey(array("table_name", "id"));

        $dbalTableDiffService = new DbalTableDiffService($this->connection, $this->logger);
        $dbalTableDiffService->createOrUpdateTable($localUpdate);
        $dbalTableDiffService->createOrUpdateTable($localInsert);
        $dbalTableDiffService->createOrUpdateTable($localDelete);
    }

    public function createInsertTrigger(Table $table)
    {
        $triggerName = sprintf("TRG_%s_ONINSERT", $table->getName());

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s AFTER INSERT ON `%s` 
            FOR EACH ROW
            BEGIN
              IF (NEW.lastActivityTime IS NULL) THEN
                INSERT INTO local_insert VALUES (%s, NEW.id);
                DELETE FROM local_delete WHERE table_name = %s AND id = NEW.id;
                DELETE FROM local_update WHERE table_name = %s AND id = NEW.id;
              END IF;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()));


        $this->connection->exec($sql);
    }

    public function createDeleteTrigger(Table $table)
    {
        $triggerName = sprintf("TRG_%s_ONDELETE", $table->getName());

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s BEFORE DELETE ON `%s` 
            FOR EACH ROW
            BEGIN
              INSERT INTO local_delete VALUES (%s, OLD.id);
              DELETE FROM local_insert WHERE table_name = %s AND id = OLD.id;
              DELETE FROM local_update WHERE table_name = %s AND id = OLD.id;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()), $this->connection->quote($table->getName()));


        $this->connection->exec($sql);
    }

    public function createUpdateTrigger(Table $table)
    {
        $triggerName = sprintf("TRG_%s_ONUPDATE", $table->getName());

        $innerCode = '';

        foreach ($table->getColumns() as $column) {
            $columnName = $this->connection->quoteIdentifier($column->getName());
            $innerCode .= sprintf('
                IF (NEW.%s != OLD.%s) THEN
                  REPLACE INTO local_update VALUES (%s, NEW.id, %s);
                END IF;
            ', $columnName, $columnName, $this->connection->quote($table->getName()), $this->connection->quote($column->getName()));
        }

        $sql = sprintf('
            DROP TRIGGER IF EXISTS %s;
            
            CREATE TRIGGER %s AFTER UPDATE ON `%s` 
            FOR EACH ROW
            BEGIN
              IF (NEW.lastActivityTime = OLD.lastActivityTime) THEN
            %s
              END IF;
            END;
            
            ', $triggerName, $triggerName, $table->getName(), $innerCode);


        $this->connection->exec($sql);
    }
}
