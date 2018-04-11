<?php
/**
 * @package Gs4Dbcleanup
 * @author gelysis <andreas@gelysis.net>
 * @copyright ©2018, Andreas Gerhards - All rights reserved
 * @license http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause - Please check LICENSE.md for more information
 */

namespace Gs4Dbcleanup;

use PDO;


class DbCleanUp
{

    /** @var PDO|null $this->pdo */
    protected $pdo = null;
    /** @var array $this->essentialDsnMap */
    protected $essentialDsnMap = [
        'SS_DATABASE_CLASS'=>null,
        'SS_DATABASE_SERVER'=>'host',
        'SS_DATABASE_SERVER'=>'port',
        'SS_DATABASE_NAME'=>'dbname',
        'SS_DATBASE_USERNAME'=>'user',
        'SS_DATABASE_PASSWORD'=>'password'

    ];
    /** @var array $this->defaultDsnDetails */
    protected $defaultDsnDetails = [
        'port'=>3306
    ];
    /** @var string|null $this->dbname */
    protected $dbname = null;


    /**
     * @return void
     */
    public function __construct()
    {
        $details = array_replace($this->defaultDsnDetails, $this->getDatabaseDetails());
        $essentials = array_flip($this->essentialDsnMap);
        unset($essentials[null]);

        if (count($details) == count($essentials)) {
            $this->dbname = $details['dbname'];
            $dsn = 'mysql:host='.$details['host'].';port='.$details['port'].';dbname='.$details['dbname'];
            $this->pdo = new PDO($dsn, $details['user'], $details['password']);
        }else {
            throw new \Exception('Problems with the database connection.');
        }
    }

    /**
     * @return bool $successful
     */
    public function removeVersionDuplicates($versionsToKeep = 1)
    {
        $versionTables = [];
        foreach ($this->getTablenames() as $tablename) {
            if (substr($tablename, -9) == '_versions') {
                $versionTables[$tablename] = '`'.$this->dbname.'`.`'.$tablename.'`';
            }
        }

        $allSuccessful = true;
        $selectQuery = 'SELECT RecordID, count(ID) FROM :tablename GROUP BY RecordID;';
        $deleteQuery = 'DELETE FROM :tablename WHERE RecordID = :pageId ORDER BY Version ASC LIMIT :rowsToDelete;';

        foreach ($versionTables as $tablename=>$fullEscapedTablename) {
            $sqlQuery = str_replace(':tablename', $fullEscapedTablename, $selectQuery);
            $statement = $this->pdo->query($sqlQuery);
            $versionRecords = $statement->fetchAll(\PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE);

            $tableQuery = '';
            foreach ($versionRecords as $pageId=>$totalVersions) {
                $rowsToDelete = intval($totalVersions - $versionsToKeep);
                if ($rowsToDelete > 0) {
                    $pageQuery = str_replace(':tablename', $fullEscapedTablename,
                        str_replace(':pageId', $this->pdo->quote($pageId, \PDO::PARAM_INT),
                            str_replace(':rowsToDelete', $rowsToDelete, $deleteQuery)
                            )
                        );
                    $tableQuery .= $pageQuery;
                }
            }

            if (!empty($tableQuery)) {
                $successful = $this->pdo->exec($tableQuery);
                $allSuccessful &= $successful;
            }
        }

        return (bool) $allSuccessful;
    }

    /**
     * @return string[]
     */
    protected function getTablenames()
    {
        $sqlQuery = 'SHOW TABLES;';
        $tableNames = [];

        if (!is_null($this->pdo)) {
            $statement = $this->pdo->query($sqlQuery);
            foreach ($statement->fetchAll() as $id=>$tableArray) {
                $tablename = current($tableArray);
                $tableNames[$id] = (string) $tablename;
            }
        }

        return $tableNames;
    }

    /**
     * @return array $databaseDetails
     */
    protected function getDatabaseDetails()
    {
        $details = $this->getSilverStripe4DatabaseDetails();

        return $details;
    }

    /**
     * @param string $filename
     * @return string|null $environmentCredentialsFilePath
     */
    protected function findEnvironmentCredentialsFilePath($filename)
    {
        $credentialsPath = null;
        $searchDirectories = ['/vendor/', '/gs4-uniprotect/', '/src/'];

        foreach ($searchDirectories as $directory) {
            if ($path = strstr(__FILE__, '/vendor/', true)) {
                if ($credentialsPath = realpath($path.'/'.$filename)) {
                    break;
                }
            }
        }

        return $credentialsPath;
    }

    /**
     * @return array $databaseDetails
     */
    protected function getSilverStripe3DatabaseDetails()
    {
        $details = [];
        $filePath = $this->findEnvironmentCredentialsFilePath('_ss_environment.php');

        if (!empty($filePath)) {
            require_once $filePath;

            if (defined('SS_DATABASE_CLASS') && defined('SS_DATABASE_SERVER') && defined('SS_DATABASE_NAME')
              && defined('SS_DATABASE_USERNAME') && defined('SS_DATABASE_PASSWORD')) {
                $details = [
                    'host'=>SS_DATABASE_SERVER,
                    'dbname'=>SS_DATABASE_NAME,
                    'user'=>SS_DATBASE_USERNAME,
                    'password'=>SS_DATABASE_PASSWORD
                ];
            }
        }

        return $details;
    }

    /**
     * @return array $databaseDetails
     */
    protected function getSilverStripe4DatabaseDetails()
    {
        $details = [];
        $filePath = $this->findEnvironmentCredentialsFilePath('.env');

        if (!empty($filePath)) {
            $rows = file($filePath);

            $envVariables = $duplicates = [];
            foreach ($rows as $row) {
                if (strpos($row, '=') !== false) {
                    $envVariable = strstr(ltrim($row), '=', true);
                    if (isset($this->essentialDsnMap[$envVariable])) {
                        if (isset($envVariables[$envVariable])) {
                            $duplicates[] = $envVariable;
                        }else {
                            $envVariables[$envVariable] = rtrim(ltrim(strstr(ltrim($row), '='), '='));
                        }
                    }
                }
            }

            if (count($duplicates) == 0) {
                $details = $envVariables;
            }
        }

        return $details;
    }

}
