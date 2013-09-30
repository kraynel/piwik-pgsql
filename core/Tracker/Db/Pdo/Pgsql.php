<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */

namespace Piwik\Tracker\Db\Pdo;

use Exception;
use PDO;

/**
 * PDO PostgreSQL wrapper
 *
 * @package Piwik
 * @subpackage Tracker
 */
class Pgsql extends Mysql
{
    /**
     * Builds the DB object
     *
     * @param array $dbInfo
     * @param string $driverName
     */
    public function __construct($dbInfo, $driverName = 'pgsql')
    {
        parent::__construct($dbInfo, $driverName);
    }

    /**
     * Connects to the DB
     *
     * @throws Exception if there was an error connecting the DB
     */
    public function connect()
    {
        if (self::$profiling) {
            $timer = $this->initProfiler();
        }

        $this->connection = new PDO($this->dsn, $this->username, $this->password, $config = array());
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // we may want to setAttribute(PDO::ATTR_TIMEOUT ) to a few seconds (default is 60) in case the DB is locked
        // the piwik.php would stay waiting for the database... bad!
        // we delete the password from this object "just in case" it could be printed
        $this->password = '';

        if (!empty($this->charset)) {
            $sql = "SET NAMES '" . $this->charset . "'";
            $this->connection->exec($sql);
        }

        if (self::$profiling && isset($timer)) {
            $this->recordQueryProfile('connect', $timer);
        }
    }

    /**
     * Returns the last inserted ID in the DB
     * Wrapper of PDO::lastInsertId()
     * 
     * @param  String $sequenceCol Column on which the sequence is created.
     *         Pertinent for DBMS that use sequences instead of auto_increment.
     *         Zend adapter appends the "_seq" which has to be repeated here, to
     *         avoid passing different values for the argument based on which
     *         adaper (zend or piwik_tracker_db) is being used.
     * @return int
     */
    public function lastInsertId($sequenceCol=null)
    {
        $sequenceCol .= '_seq';
        return $this->connection->lastInsertId($sequenceCol);
    }

    /**
     * Test error number
     *
     * @param Exception $e
     * @param string $errno
     * @return bool
     */
    public function isErrNo($e, $errno)
    {
        // map MySQL driver-specific error codes to PostgreSQL SQLSTATE
        $map = array(
            // MySQL: Unknown database '%s'
            // PostgreSQL: database "%s" does not exist
            '1049' => '08006',

            // MySQL: Table '%s' already exists
            // PostgreSQL: relation "%s" already exists
            '1050' => '42P07',

            // MySQL: Unknown column '%s' in '%s'
            // PostgreSQL: column "%s" does not exist
            '1054' => '42703',

            // MySQL: Duplicate column name '%s'
            // PostgreSQL: column "%s" of relation "%s" already exists
            '1060' => '42701',

            // MySQL: Duplicate entry '%s' for key '%s'
            // PostgreSQL: duplicate key violates unique constraint
            '1062' => '23505',

            // MySQL: Can't DROP '%s'; check that column/key exists
            // PostgreSQL: index "%s" does not exist
            '1091' => '42704',

            // MySQL: Table '%s.%s' doesn't exist
            // PostgreSQL: relation "%s" does not exist
            '1146' => '42P01',
        );

        if (preg_match('/([0-9]{2}[0-9P][0-9]{2})/', $e->getMessage(), $match)) {
            return ($match[1] == $errno) || ($match[1] == $map[$errno]);
        }
        return false;
    }

    /**
     * Return number of affected rows in last query
     *
     * @param mixed $queryResult  Result from query()
     * @return int
     */
    public function rowCount($queryResult)
    {
        return $queryResult->rowCount();
    }
    
    /**
     *  Quote Identifier
     *
     *  Not as sophisiticated as the zend-db quoteIdentifier. Just encloses the
     *  given string in backticks and returns it.
     *
     *  @param string $identifier
     *  @return string
     */
    public function quoteIdentifier($identifier)
    {
        return '"' . $identifier . '"';
    }
}
