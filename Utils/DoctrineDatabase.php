<?php

namespace Littlerobinson\QueryBuilderBundle\Utils;

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Yaml\Yaml;

/**
 * Class DoctrineDatabase
 * Get Database config using Doctrine
 * @package Littlerobinson\QueryBuilder
 */
class DoctrineDatabase
{
    private $configuration;

    private $configPath;

    private $entityManager;

    private $connection;

    private $platform;

    private $schemaManager;

    private $databases;

    private $tables;

    private $databaseTitle;

    private $databaseRules;

    /**
     * DoctrineDatabase constructor.
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $rootDir             = $container->get('kernel')->getRootDir();
        $config              = Yaml::parse(file_get_contents($rootDir . '/config/config.yml'))['littlerobinson_query_builder'];
        $this->databaseTitle = $config['database']['title'];
        $this->databaseRules = $config['security']['database'];
        $this->configuration = Setup::createAnnotationMetadataConfiguration([], $config['database']['is_dev_mode']);
        $this->configPath    = $rootDir . '/config/' . $config['database']['config_path'];
        $this->entityManager = EntityManager::create($config['database']['params'], $this->configuration);
        $this->connection    = $this->entityManager->getConnection();
        $this->schemaManager = $this->connection->getSchemaManager();
        $this->tables        = $this->schemaManager->listTableNames();
        $this->databases     = $this->schemaManager->listDatabases();
        $this->platform      = $this->connection->getDatabasePlatform();
        /// Resolving "unknown database type enum requested"
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
    }

    /**
     * @return array
     */
    private function getDatabaseConfig()
    {
        $response = [];
        $tables   = $this->getTables();
        foreach ($tables as $table) {
            $response[$table] = $this->getTableColumns($table);
        }
        return $response;
    }

    /**
     * @return json|false
     */
    public function getJsonDatabaseConfig()
    {
        return json_encode($this->getDatabaseConfig());
    }

    /**
     * writeDoctrineYamlConfig method
     * Write the yaml config file for the query builder using doctrine
     * @param int $yamlInline
     * @return bool
     */
    public function writeDatabaseYamlConfig($yamlInline = 3)
    {
        /// Get existing configuration if exist
        $currentConfig = false;
        if (@file_get_contents($this->configPath)) {
            $currentConfig = Yaml::parse(file_get_contents($this->configPath));
        }

        /// Get database config array
        $data = $this->getDatabaseConfig();

        /// Put current config traduction if an existing configuration file exist
        if ($currentConfig) {
            $arrDiff       = array_diff(array_map('serialize', $currentConfig), array_map('serialize', $data));
            $tableToDelete = array_diff_key($currentConfig, $data);
            foreach ($arrDiff as $tableKey => $tableDiff) {
                /// Not take deleted table
                if (array_key_exists($tableKey, $tableToDelete)) {
                    continue;
                }
                $newTableDiff = unserialize($tableDiff);

                $data[$tableKey]['_table_translation'] = $newTableDiff['_table_translation'];
                $data[$tableKey]['_table_visibility']  = $newTableDiff['_table_visibility'];
                foreach ($newTableDiff as $fieldKey => $fieldDiff) {
                    if (!is_array($fieldDiff) || $fieldKey === '_FK' || $fieldKey === '_primary_key') {
                        continue;
                    }
                    try {
                        $data[$tableKey][$fieldKey]['_field_translation'] = $fieldDiff['_field_translation'];
                        $data[$tableKey][$fieldKey]['_field_visibility']  = $fieldDiff['_field_visibility'];
                        $data[$tableKey][$fieldKey]['name']               = $fieldDiff['name'];
                        $data[$tableKey][$fieldKey]['type']               = $fieldDiff['type'];
                        $data[$tableKey][$fieldKey]['length']             = $fieldDiff['length'];
                        $data[$tableKey][$fieldKey]['not_null']           = $fieldDiff['not_null'];
                        $data[$tableKey][$fieldKey]['definition']         = $fieldDiff['definition'];
                    } catch (\Exception $e) {
                        return false;
                    }
                }
            }
        }
        /// Add FK
        $data = $this->addForeignKeys($data);

        /// Write yaml
        $yaml = Yaml::dump($data, $yamlInline);

        $response = (@file_put_contents($this->configPath, $yaml) === false) ? false : true;

        return $response;
    }

    /**
     * Get database config
     * Return a json response
     * @param bool $isJsonResponse
     * @return bool|array|string JSON
     */
    public function getDatabaseYamlConfig($isJsonResponse = false)
    {
        if (@file_get_contents($this->configPath)) {
            $config = Yaml::parse(file_get_contents($this->configPath));
            if ($isJsonResponse) {
                return json_encode($config);
            }
            return $config;
        } else {
            return false;
        }
    }

    /**
     * @param string $table
     * @return \Doctrine\DBAL\Schema\Table
     */
    private function getTableDetails(string $table)
    {
        return $this->schemaManager->listTableDetails($table);
    }


    /**
     * @param string $table
     * @return array
     */
    private function getTableColumns(string $table)
    {
        $response                       = [];
        $columns                        = $this->schemaManager->listTableColumns($table);
        $primaryKey                     = $this->getPrimaryKey($table);
        $response['_table_translation'] = null;
        $response['_table_visibility']  = true;
        $response['_primary_key']       = $primaryKey;
        foreach ($columns as $key => $column) {
            $response[$key]['name']               = $column->getName();
            $response[$key]['_field_translation'] = null;
            $response[$key]['_field_visibility']  = true;
            $response[$key]['type']               = $column->getType()->getName();
            $response[$key]['default']            = $column->getDefault();
            $response[$key]['length']             = $column->getLength();
            $response[$key]['not_null']           = $column->getNotnull();
            $response[$key]['definition']         = $column->getColumnDefinition();
        }
        return $response;
    }

    /**
     * addForeignKeys method
     * @param array $datas
     * @return array|null
     */
    private function addForeignKeys(array $datas)
    {
        $listForeignKey = [];
        $listTables     = $this->getTables();

        foreach ($listTables as $table) {
            try {
                foreach ($this->getTableDetails($table)->getForeignKeys() as $key => $fk) {
                    $listForeignKey[$table][$fk->getColumns()[0]]['tableName']      = $fk->getForeignTableName();
                    $listForeignKey[$table][$fk->getColumns()[0]]['columns']        = $fk->getColumns()[0];
                    $listForeignKey[$table][$fk->getColumns()[0]]['foreignColumns'] = $fk->getForeignColumns()[0];
                    $listForeignKey[$table][$fk->getColumns()[0]]['name']           = $fk->getName();
                    $listForeignKey[$table][$fk->getColumns()[0]]['options']        = $fk->getOptions();

                    /// Update $datas
                    $datas[$table]['_FK'][$fk->getColumns()[0]] = $listForeignKey[$table][$fk->getColumns()[0]];
                }
            } catch (\Exception $e) {
                return null;
            }
        }
        return $datas;
    }

    /**
     * @param string $table
     * @return array
     */
    public function getPrimaryKey(string $table): array
    {
        if ($this->getTableDetails($table)->getPrimaryKey() === null) {
            return [];
        }
        return $this->getTableDetails($table)->getPrimaryKey()->getColumns();
    }

    /* ============================================================================================================== */
    /* ============================================== ACCESSORS ==================================================== */
    /* ============================================================================================================== */

    /**
     * @return array
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * @return \Doctrine\ORM\Configuration
     */
    public function getConfiguration(): \Doctrine\ORM\Configuration
    {
        return $this->configuration;
    }

    /**
     * @return EntityManager
     */
    public function getEntityManager(): EntityManager
    {
        return $this->entityManager;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function getConnection(): \Doctrine\DBAL\Connection
    {
        return $this->connection;
    }

    /**
     * @return \Doctrine\DBAL\Schema\AbstractSchemaManager
     */
    public function getSchemaManager(): \Doctrine\DBAL\Schema\AbstractSchemaManager
    {
        return $this->schemaManager;
    }

    /**
     * @return array
     */
    public function getDatabases(): array
    {
        return $this->databases;
    }

    /**
     * @return string
     */
    public function getDatabaseTitle(): string
    {
        return $this->databaseTitle;
    }

    /**
     * @return array
     */
    public function getDatabaseRules(): array
    {
        if (null === $this->databaseRules) {
            $this->databaseRules = [];
        }
        return $this->databaseRules;
    }

}