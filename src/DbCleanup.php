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
        'SS_DATABASE_PORT'=>'port',
        'SS_DATABASE_NAME'=>'dbname',
        'SS_DATABASE_USERNAME'=>'user',
        'SS_DATABASE_PASSWORD'=>'password'

    ];
    /** @var array $this->defaultDsnDetails */
    protected $defaultDsnDetails = [
        'port'=>3306
    ];
    /** @var string|null $this->dbname */
    protected $dbname = null;
    /** @var bool $this->output */
    protected $outputEnabled = false;
    /** @var array $this->report */
    protected $report = [];


    /**
     * @return void
     */
    public function __construct()
    {
        $details = array_replace($this->defaultDsnDetails, $this->getDatabaseDetails());

        if (count($details) == count($this->getEssentialDetails())) {
            $this->dbname = $details['dbname'];
            $dsn = 'mysql:host='.$details['host'].';port='.$details['port'].';dbname='.$details['dbname'];
            $this->pdo = new PDO($dsn, $details['user'], $details['password']);
        }else {
            throw new \Exception('Problems with the database connection.');
        }
    }

    /**
     * @return array $essentialDetails
     */
    protected function getEssentialDetails()
    {
        $essentialDetails = [];

        foreach ($this->essentialDsnMap as $detail) {
            if (is_int($detail) || is_string($detail)) {
                $essentialDetails[] = $detail;
            }
        }

        return $essentialDetails;
    }

    /**
     * @return void
     */
    public function disableOutput()
    {
        $this->outputEnabled = false;
    }

    /**
     * @return void
     */
    public function enableOutput()
    {
        $this->outputEnabled = true;
    }

    /**
     * @param string $html
     * @param bool|null $success
     * @return void
     */
    protected function output($text, $paragraph = true, $success = null)
    {
        if ($this->outputEnabled) {
            if ($paragraph) {
                $tag = 'p';
                $prefix = '';
            }else {
                $tag = 'span';
                $prefix = '<br />';
            }

            if (is_null($success)) {
                $style = '';
            }elseif ($success) {
                $style = ' style="color:green;"';
            }else {
                $style = ' style="color:red;"';
            }

            $this->report[] = '  <'.$tag.$style.'>'.htmlentities($text).'</'.$tag.'>'.$prefix;
        }
    }

    /**
     * @return void
     */
    public function outputReport()
    {
        foreach ($this->report as $html) {
            print $html.PHP_EOL;
        }
    }

    /**
     * @return bool $successful
     */
    public function removeVersionDuplicates($versionsToKeep = 1)
    {
        $tablenames = $this->getTablenames();
        $this->output('Accessed database successfully and found '.count($tablenames).' tables.');

        $versionTables = [];
        foreach ($this->getTablenames() as $tablename) {
            if (substr($tablename, -9) == '_versions') {
                $versionTables[$tablename] = '`'.$this->dbname.'`.`'.$tablename.'`';
            }
        }
        unset($tablenames);

        $this->output('Filtered out '.count($versionTables).' version tables to clean up.');

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

            if (empty($tableQuery)) {
                unset($successful);
            }else {
                $successful = $this->pdo->exec($tableQuery);
                $allSuccessful &= $successful;
            }

            if (!isset($successful)) {
                $this->output('Skipped '.$fullEscapedTablename.' ...', false);
            }elseif ($successful) {
                $this->output('Cleaned up '.$fullEscapedTablename.' ...', false, true);
            }else {
                $this->output('Clean up failed on '.$fullEscapedTablename.' ...', false, false);
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
        $searchDirectories = ['/vendor/', '/gs4-uniprotect/', '/src/', basename(__FILE__)];

        foreach ($searchDirectories as $directory) {
            if ($path = strstr(__FILE__, $directory, true)) {
                if ($credentialsPath = realpath($path.(empty($path) ? '' : '/').$filename)) {
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
                    'user'=>SS_DATABASE_USERNAME,
                    'password'=>SS_DATABASE_PASSWORD
                ];
                if (defined('SS_DATABASE_PORT')) {
                    $details['port'] = SS_DATABASE_PORT;
                }
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
                            $value = trim(ltrim(strstr(ltrim($row), '='), '='), " \n\r\0\"");
                            $envVariables[$this->essentialDsnMap[$envVariable]] = $value;
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
