<?php
namespace Carlead\Zoho\CRM\Copy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Carlead\Zoho\CRM\AbstractZohoDao;
use function Stringy\create as s;
/**
 * This class is in charge of synchronizing one table of your database with Zoho records.
 */
class ZohoDatabaseCopier
{
    /**
     * @var Connection
     */
    private $connection;
    private $prefix;
    /**
     * @var ZohoChangeListener[]
     */
    private $listeners;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var LocalChangesTracker
     */
    private $localChangesTracker;
    /**
     * ZohoDatabaseCopier constructor.
     *
     * @param Connection $connection
     * @param string $prefix Prefix for the table name in DB
     * @param ZohoChangeListener[] $listeners The list of listeners called when a record is inserted or updated.
     */
    public function __construct(Connection $connection, $prefix = 'zoho_', array $listeners = [], LoggerInterface $logger = null)
    {
        $this->connection = $connection;
        $this->prefix = $prefix;
        $this->listeners = $listeners;
        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }
        $this->localChangesTracker = new LocalChangesTracker($connection, $this->logger);
    }
    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    /**
     * @param AbstractZohoDao $dao
     * @param bool            $incrementalSync Whether we synchronize only the modified files or everything.
     */
    public function copy(AbstractZohoDao $dao, $incrementalSync = true, $twoWaysSync = true)
    {
        if ($twoWaysSync === true) {
            $this->localChangesTracker->createTrackingTables();
        }
        $this->synchronizeDbModel($dao, $twoWaysSync);
        $this->copyData($dao, $incrementalSync, $twoWaysSync);
        // TODO: we need to track DELETED records in Zoho!
    }
    /**
     * Synchronizes the DB model with Zoho.
     *
     * @param AbstractZohoDao $dao
     * @param bool $twoWaysSync
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    private function synchronizeDbModel(AbstractZohoDao $dao, $twoWaysSync)
    {
        $tableName = $this->getTableName($dao);
        $this->logger->info("Synchronizing DB Model for ".$tableName);
        $schema = new Schema();
        $table = $schema->createTable($tableName);
        $flatFields = $this->getFlatFields($dao->getFields());
        $table->addColumn('id', 'string', ['length' => 100]);
        $table->setPrimaryKey(['id']);
        
        foreach ($flatFields as $field) {
            $columnName = $field['name'];
            $length = null;
            $index = false;
            // Note: full list of types available here: https://www.zoho.com/crm/help/customization/custom-fields.html
            switch ($field['type']) {
                case 'Lookup ID':
                case 'Lookup':
                    $type = 'string';
                    $length = 100;
                    $index = true;
                    break;
                case 'OwnerLookup':
                    $type = 'string';
                    $index = true;
                    $length = 25;
                    break;
                case 'Formula':
                    // Note: a Formula can return any type, but we have no way to know which type it returns...
                    $type = 'string';
                    $length = 100;
                    break;
                case 'DateTime':
                    $type = 'datetime';
                    break;
                case 'Date':
                    $type = 'date';
                    break;
                case 'DateTime':
                    $type = 'datetime';
                    break;
                case 'Boolean':
                    $type = 'boolean';
                    break;
                case 'TextArea':
                    $type = 'text';
                    break;
                case 'BigInt':
                    $type = 'bigint';
                    break;
                case 'Phone':
                case 'Auto Number':
                case 'Text':
                case 'URL':
                case 'Email':
                case 'Website':
                case 'Pick List':
                case 'Multiselect Pick List':
                    $type = 'string';
                    $length = $field['maxlength'];
                    break;
                case 'Double':
                case 'Percent':
                    $type = 'float';
                    break;
                case 'AutoNumber':
                case 'Integer':
                    $type = 'integer';
                    break;
                case 'Currency':
                case 'Decimal':
                    $type = 'decimal';
                    break;
                default:
                    throw new \RuntimeException('Unknown type "'.$field['type'].'"');
            }
            $options = [];
            if ($length) {
                $options['length'] = $length;
            }
            //$options['notnull'] = $field['req'];
            $options['notnull'] = false;
            $table->addColumn($columnName, $type, $options);
            if ($index) {
                $table->addIndex([$columnName]);
            }
        }
        
        $dbalTableDiffService = new DbalTableDiffService($this->connection, $this->logger);
        $hasChanges = $dbalTableDiffService->createOrUpdateTable($table);
        if ($twoWaysSync && $hasChanges) {
            $this->localChangesTracker->createInsertTrigger($table);
            $this->localChangesTracker->createDeleteTrigger($table);
            $this->localChangesTracker->createUpdateTrigger($table);
        }
    }
    /**
     * @param AbstractZohoDao $dao
     * @param bool            $incrementalSync Whether we synchronize only the modified files or everything.
     * @param bool            $twoWaysSync
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws \Carlead\Zoho\CRM\Exception\ZohoCRMResponseException
     */
    private function copyData(AbstractZohoDao $dao, $incrementalSync = true, $twoWaysSync = true)
    {
        $tableName = $this->getTableName($dao);
        if ($incrementalSync) {
            $this->logger->info("Copying incremental data for '$tableName'");
            // Let's get the last modification date:
            $lastActivityTime = $this->connection->fetchColumn('SELECT MAX(lastActivityTime) FROM '.$tableName);
            if ($lastActivityTime !== null) {
                $lastActivityTime = new \DateTime($lastActivityTime);
                $this->logger->info("Last activity time: ".$lastActivityTime->format('c'));
                // Let's add one second to the last activity time (otherwise, we are fetching again the last record in DB).
                $lastActivityTime->add(new \DateInterval("PT1S"));
            }
            $records = $dao->getRecords(null, null, $lastActivityTime);
            $deletedRecordIds = $dao->getDeletedRecordIds($lastActivityTime);
        } else {
            $this->logger->notice("Copying FULL data for '$tableName'");
            $records = $dao->getRecords();
            $deletedRecordIds = [];
        }
        
        $this->logger->info("Fetched ".count($records)." records");
        $table = $this->connection->getSchemaManager()->createSchema()->getTable($tableName);
        $flatFields = $this->getFlatFields($dao->getFields());
        $fieldsByName = [];
        foreach ($flatFields as $field) {
            $fieldsByName[$field['name']] = $field;
        }        
        
        /* select records in bdd for delete after if doesn't exist anymore */
        $select_delete = $this->connection->query('SELECT * FROM '.$tableName);
        $records_delete = $select_delete->fetchAll();
        foreach($records_delete as $record_delete){
            $deletedRecordIds[$record_delete['id']] = $record_delete['id'];
        }
        
        $select = $this->connection->prepare('SELECT * FROM '.$tableName.' WHERE id = :id');
        $this->connection->beginTransaction();
        foreach ($records as $record) {
            /* on supprime le vendeur du tableau des users qui vont etre supprimé */
            if (in_array($record->getZohoId(), $deletedRecordIds)) {
                /* si il sont déjà présent alors on l'enlève du tableau car après on supprimer les restants */
                $key = array_search($record->getZohoId(), $deletedRecordIds);
                unset($deletedRecordIds[$key]);
            }
            
            $data = [];
            $types = [];
            foreach ($table->getColumns() as $column) {
                $nameColumn = $column->getName();
                if ($nameColumn === 'id' || $nameColumn === 'createdTime' || $nameColumn === 'modifiedTime') {
                    continue;
                } else {
                    $field = $fieldsByName[$column->getName()];
                    $getterName = $field['getter'];
                    $data[$nameColumn] = $record->$getterName();
                    $types[$nameColumn] = $column->getType()->getName();
                }
            }
            
            $select->execute(['id' => $record->getZohoId()]);
            $result = $select->fetch(\PDO::FETCH_ASSOC);
            if ($result === false) {
                $this->logger->debug("Inserting record with ID '".$record->getZohoId()."'.");
                $data['id'] = $record->getZohoId();
                $types['id'] = 'string';
                $data['createdTime'] = $record->getCreatedTime();
                $types['createdTime'] = 'datetime';
                $data['modifiedTime'] = $record->getModifiedTime();
                $types['modifiedTime'] = 'datetime';

                switch ($tableName) {
                    case 'leads':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getOrigineConcessionnaire()));
                        $data['origineConcessionnaireID'] = $result['id'];
                        $types['origineConcessionnaireID'] = 'string';

                        $result = $this->connection->fetchAssoc('SELECT id FROM vendeurs WHERE vendorName = ?', array($record->getVendeurs()));
                        $data['vendeursID'] = $result['id'];
                        $types['vendeursID'] = 'string';
                        break;
                    case 'vendeurs':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getOrigineConcessionnaire()));
                        $data['origineConcessionnaireID'] = $result['id'];
                        $types['origineConcessionnaireID'] = 'string';

                        break;
                    case 'vendeurs_concessionnaires':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getChoixConcessionnaire()));
                        $data['choixConcessionnaireID'] = $result['id'];
                        $types['choixConcessionnaireID'] = 'string';

                        $result = $this->connection->fetchAssoc('SELECT id FROM vendeurs WHERE vendorName = ?', array($record->getChoixVendeur()));
                        $data['choixVendeurID'] = $result['id'];
                        $types['choixVendeurID'] = 'string';

                        break;
                }
                
                $this->connection->insert($tableName, $data, $types);
                foreach ($this->listeners as $listener) {
                    $listener->onInsert($data, $dao);
                }
                
            } else {
                $this->logger->debug("Updating record with ID '".$record->getZohoId()."'.");
                $identifier = ['id' => $record->getZohoId()];
                $types['id'] = 'string';
                $data['createdTime'] = $record->getCreatedTime();
                $types['createdTime'] = 'datetime';
                $data['modifiedTime'] = $record->getModifiedTime();
                $types['modifiedTime'] = 'datetime';

                switch ($tableName) {
                    case 'leads':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getOrigineConcessionnaire()));
                        $data['origineConcessionnaireID'] = $result['id'];
                        $types['origineConcessionnaireID'] = 'string';

                        $result = $this->connection->fetchAssoc('SELECT id FROM vendeurs WHERE vendorName = ?', array($record->getVendeurs()));
                        $data['vendeursID'] = $result['id'];
                        $types['vendeursID'] = 'string';
                        break;
                    case 'vendeurs':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getOrigineConcessionnaire()));
                        $data['origineConcessionnaireID'] = $result['id'];
                        $types['origineConcessionnaireID'] = 'string';

                        break;
                    case 'vendeurs_concessionnaires':
                        $result = $this->connection->fetchAssoc('SELECT id FROM concessionnaires WHERE customModule2Name = ?', array($record->getChoixConcessionnaire()));
                        $data['choixConcessionnaireID'] = $result['id'];
                        $types['choixConcessionnaireID'] = 'string';

                        $result = $this->connection->fetchAssoc('SELECT id FROM vendeurs WHERE vendorName = ?', array($record->getChoixVendeur()));
                        $data['choixVendeurID'] = $result['id'];
                        $types['choixVendeurID'] = 'string';

                        break;
                }

                $this->connection->update($tableName, $data, $identifier, $types);
                // Let's add the id for the update trigger
                $data['id'] = $record->getZohoId();
                
                foreach ($this->listeners as $listener) {
                    $listener->onUpdate($data, $result, $dao);
                }
            }
        }
        
        foreach ($deletedRecordIds as $id) {
            if ($twoWaysSync) {
                // TODO: we could detect if there are changes to be updated to the server and try to warn with a log message
                // Also, let's remove the newly created field (because of the trigger) to avoid looping back to Zoho
                $this->connection->delete('local_delete', [ 'table_name' => $tableName, 'id' => $id ]);
                $this->connection->delete('local_update', [ 'table_name' => $tableName, 'id' => $id ]);
            }
            $this->connection->delete($tableName, [ 'id' => $id ]);
        }
        $this->connection->commit();
    }
    private function getFlatFields(array $fields)
    {
        $flatFields = [];
        foreach ($fields as $cat) {
            $flatFields = array_merge($flatFields, $cat);
        }
        return $flatFields;
    }
    /**
     * Computes the name of the table based on the DAO plural module name.
     *
     * @param AbstractZohoDao $dao
     *
     * @return string
     */
    private function getTableName(AbstractZohoDao $dao)
    {
        $tableName = $this->prefix.$dao->getPluralModuleName();
        $tableName = str_replace("/", "", $tableName);
        $tableName = s($tableName)->upperCamelize()->underscored();
        return (string) $tableName;
    }
}
