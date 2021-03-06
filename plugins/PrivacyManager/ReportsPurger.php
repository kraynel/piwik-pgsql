<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package PrivacyManager
 */
namespace Piwik\Plugins\PrivacyManager;

use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Date;
use Piwik\Db;
use Piwik\Db\Factory;
use Piwik\DbHelper;
use Piwik\Piwik;

/**
 * Purges archived reports and metrics that are considered old.
 */
class ReportsPurger
{
    // constant used in database purging estimate to signify a table should be dropped
    const DROP_TABLE = -1;

    /**
     * The max set of rows each table scan select should query at one time.
     */
    public static $selectSegmentSize = 100000;

    /**
     * The number of months after which report/metric data is considered old.
     */
    private $deleteReportsOlderThan;

    /**
     * Whether to keep basic metrics or not.
     */
    private $keepBasicMetrics;

    /**
     * Array of period types. Reports for these periods will not be purged.
     */
    private $reportPeriodsToKeep;

    /**
     * Whether to keep reports for segments or not.
     */
    private $keepSegmentReports;

    /**
     * The maximum number of rows to delete per DELETE query.
     */
    private $maxRowsToDeletePerQuery;

    /**
     * List of metrics that should be kept when purging. If $keepBasicMetrics is true,
     * these metrics will be saved.
     */
    private $metricsToKeep;

    /**
     * Array that maps a year and month ('2012_01') with lists of archive IDs for segmented
     * archives. Used to keep segmented reports when purging.
     */
    private $segmentArchiveIds = null;

    /**
     * Constructor.
     *
     * @param int $deleteReportsOlderThan The number of months after which report/metric data
     *                                    is considered old.
     * @param bool $keepBasicMetrics Whether to keep basic metrics or not.
     * @param array $reportPeriodsToKeep Array of period types. Reports for these periods will not
     *                                   be purged.
     * @param bool $keepSegmentReports Whether to keep reports for segments or not.
     * @param array $metricsToKeep List of metrics that should be kept. if $keepBasicMetrics
     *                             is true, these metrics will be saved.
     * @param int $maxRowsToDeletePerQuery The maximum number of rows to delete per DELETE query.
     */
    public function __construct($deleteReportsOlderThan, $keepBasicMetrics, $reportPeriodsToKeep,
                                $keepSegmentReports, $metricsToKeep, $maxRowsToDeletePerQuery)
    {
        $this->deleteReportsOlderThan = $deleteReportsOlderThan;
        $this->keepBasicMetrics = $keepBasicMetrics;
        $this->reportPeriodsToKeep = $reportPeriodsToKeep;
        $this->keepSegmentReports = $keepSegmentReports;
        $this->metricsToKeep = $metricsToKeep;
        $this->maxRowsToDeletePerQuery = $maxRowsToDeletePerQuery;
    }

    /**
     * Purges old report/metric data.
     *
     * If $keepBasicMetrics is false, old numeric tables will be dropped, otherwise only
     * the metrics not in $metricsToKeep will be deleted.
     *
     * If $reportPeriodsToKeep is an empty array, old blob tables will be dropped. Otherwise,
     * specific reports will be deleted, except reports for periods in $reportPeriodsToKeep.
     *
     * @param bool $optimize If tables should be optimized after rows are deleted. Normally,
     *                       this is handled by a scheduled task.
     */
    public function purgeData($optimize = false)
    {
        $Generic = Factory::getGeneric();
        // find archive tables to purge
        list($oldNumericTables, $oldBlobTables) = $this->getArchiveTablesToPurge();

        // process blob tables first, since archive status is stored in the numeric archives
        if (!empty($oldBlobTables)) {
            // if no reports should be kept, drop tables, otherwise drop individual reports
            if (empty($this->reportPeriodsToKeep) && !$this->keepSegmentReports) {
                Db::dropTables($oldBlobTables);
            } else {
                foreach ($oldBlobTables as $table) {
                    $where = $this->getBlobTableWhereExpr($oldNumericTables, $table);
                    if (!empty($where)) {
                        $where = "WHERE $where";
                    }
                    $Generic->deleteAll($table, $where, 'idarchive ASC', $this->maxRowsToDeletePerQuery);
                }

                if ($optimize) {
                    $Generic->optimizeTables($oldBlobTables);
                }
            }
        }

        // deal with numeric tables
        if (!empty($oldNumericTables)) {
            // if keep_basic_metrics is set, empty all numeric tables of metrics to purge
            if ($this->keepBasicMetrics == 1 && !empty($this->metricsToKeep)) {
                $where = "WHERE name NOT IN ('" . implode("','", $this->metricsToKeep) . "') AND name NOT LIKE 'done%'";
                foreach ($oldNumericTables as $table) {
                    $Generic->deleteAll($table, $where, 'idarchive ASC', $this->maxRowsToDeletePerQuery);
                }

                if ($optimize) {
                    $Generic->optimizeTables($oldNumericTables);
                }
            } else { // drop numeric tables
                Db::dropTables($oldNumericTables);
            }
        }
    }

    /**
     * Returns an array describing what data would be purged if purging were invoked.
     *
     * This function returns an array that maps table names with the number of rows
     * that will be deleted. If a table name is mapped with self::DROP_TABLE, the table
     * will be dropped.
     *
     * @return array
     */
    public function getPurgeEstimate()
    {
        $result = array();

        // get archive tables that will be purged
        list($oldNumericTables, $oldBlobTables) = $this->getArchiveTablesToPurge();

        // process blob tables first, since archive status is stored in the numeric archives
        if (empty($this->reportPeriodsToKeep) && !$this->keepSegmentReports) {
            // not keeping any reports, so drop all tables
            foreach ($oldBlobTables as $table) {
                $result[$table] = self::DROP_TABLE;
            }
        } else {
            // figure out which rows will be deleted
            foreach ($oldBlobTables as $table) {
                $rowCount = $this->getBlobTableDeleteCount($oldNumericTables, $table);
                if ($rowCount > 0) {
                    $result[$table] = $rowCount;
                }
            }
        }

        // deal w/ numeric tables
        if ($this->keepBasicMetrics == 1) {
            // figure out which rows will be deleted
            foreach ($oldNumericTables as $table) {
                $rowCount = $this->getNumericTableDeleteCount($table);
                if ($rowCount > 0) {
                    $result[$table] = $rowCount;
                }
            }
        } else {
            // not keeping any metrics, so drop the entire table
            foreach ($oldNumericTables as $table) {
                $result[$table] = self::DROP_TABLE;
            }
        }

        return $result;
    }

    /**
     * Utility function that finds every archive table whose reports are considered
     * old.
     *
     * @return array An array of two arrays. The first holds the numeric archive table
     *               names, and the second holds the blob archive table names.
     */
    private function getArchiveTablesToPurge()
    {
        // get month for which reports as old or older than, should be deleted
        // reports whose creation date <= this month will be deleted
        // (NOTE: we ignore how far we are in the current month)
        $toRemoveDate = Date::factory('today')->subMonth(1 + $this->deleteReportsOlderThan);

        // find all archive tables that are older than N months
        $oldNumericTables = array();
        $oldBlobTables = array();
        foreach (DbHelper::getTablesInstalled() as $table) {
            $type = ArchiveTableCreator::getTypeFromTableName($table);
            if ($type === false) {
                continue;
            }
            $date = ArchiveTableCreator::getDateFromTableName($table);
            list($year, $month) = explode('_', $date);

            if (self::shouldReportBePurged($year, $month, $toRemoveDate)) {
                if ($type == ArchiveTableCreator::NUMERIC_TABLE) {
                    $oldNumericTables[] = $table;
                } else {
                    $oldBlobTables[] = $table;
                }
            }
        }

        return array($oldNumericTables, $oldBlobTables);
    }

    /**
     * Returns true if a report with the given year & month should be purged or not.
     *
     * @param int $reportDateYear The year of the report in question.
     * @param int $reportDateMonth The month of the report in question.
     * @param Date $toRemoveDate The date a report must be older than in order to be purged.
     * @return bool
     */
    public static function shouldReportBePurged($reportDateYear, $reportDateMonth, $toRemoveDate)
    {
        $toRemoveYear = (int)$toRemoveDate->toString('Y');
        $toRemoveMonth = (int)$toRemoveDate->toString('m');

        return $reportDateYear < $toRemoveYear
        || ($reportDateYear == $toRemoveYear && $reportDateMonth <= $toRemoveMonth);
    }

    private function getNumericTableDeleteCount($table)
    {
        $Generic = Factory::getGeneric();
        $maxIdArchive = $Generic->getMax($table, 'idarchive');

        $sql = "SELECT COUNT(*)
                  FROM $table
                 WHERE name NOT IN ('" . implode("','", $this->metricsToKeep) . "')
                   AND name NOT LIKE 'done%'
                   AND idarchive >= ?
                   AND idarchive < ?";

        $segments = $Generic->segmentedFetchOne($sql, 0, $maxIdArchive, self::$selectSegmentSize);
        return array_sum($segments);
    }

    private function getBlobTableDeleteCount($oldNumericTables, $table)
    {
        $Generic = Factory::getGeneric();
        $maxIdArchive = $Generic->getMax($table, 'idarchive');

        $sql = "SELECT COUNT(*)
                  FROM $table
                 WHERE " . $this->getBlobTableWhereExpr($oldNumericTables, $table) . "
                   AND idarchive >= ?
                   AND idarchive < ?";

        $segments = $Generic->segmentedFetchOne($sql, 0, $maxIdArchive, self::$selectSegmentSize);
        return array_sum($segments);
    }

    /** Returns SQL WHERE expression used to find reports that should be purged. */
    private function getBlobTableWhereExpr($oldNumericTables, $table)
    {
        $where = "";
        if (!empty($this->reportPeriodsToKeep)) // if keeping reports
        {
            $where = "period NOT IN (" . implode(',', $this->reportPeriodsToKeep) . ")";

            // if not keeping segments make sure segments w/ kept periods are also deleted
            if (!$this->keepSegmentReports) {
                $this->findSegmentArchives($oldNumericTables);
                $archiveIds = $this->segmentArchiveIds[ArchiveTableCreator::getDateFromTableName($table)];

                if (!empty($archiveIds)) {
                    $where .= " OR idarchive IN (" . implode(',', $archiveIds) . ")";
                }
            }

            $where = "($where)";
        }
        return $where;
    }

    /**
     * If we're going to keep segmented reports, we need to know which archives are
     * for segments. This info is only in the numeric tables, so we must query them.
     */
    private function findSegmentArchives($numericTables)
    {
        if (!is_null($this->segmentArchiveIds)) {
            return;
        }

        $Generic = Factory::getGeneric();
        foreach ($numericTables as $table) {
            $tableDate = ArchiveTableCreator::getDateFromTableName($table);

            $maxIdArchive = $Generic->getMax($table, 'idarchive');

            $sql = "SELECT idarchive
                      FROM $table
                     WHERE name != 'done'
                       AND name LIKE 'done_%.%'
                       AND idarchive >= ?
                       AND idarchive < ?";

            $this->segmentArchiveIds[$tableDate] = array();
            foreach ($Generic->segmentedFetchAll($sql, 0, $maxIdArchive, self::$selectSegmentSize) as $row) {
                $this->segmentArchiveIds[$tableDate][] = $row['idarchive'];
            }
        }
    }

    /**
     * Utility function. Creates a new instance of ReportsPurger with the supplied array
     * of settings.
     *
     * $settings must contain the following keys:
     * -'delete_reports_older_than': The number of months after which reports/metrics are
     *                               considered old.
     * -'delete_reports_keep_basic_metrics': 1 if basic metrics should be kept, 0 if otherwise.
     * -'delete_reports_keep_day_reports': 1 if daily reports should be kept, 0 if otherwise.
     * -'delete_reports_keep_week_reports': 1 if weekly reports should be kept, 0 if otherwise.
     * -'delete_reports_keep_month_reports': 1 if monthly reports should be kept, 0 if otherwise.
     * -'delete_reports_keep_year_reports': 1 if yearly reports should be kept, 0 if otherwise.
     * -'delete_reports_keep_range_reports': 1 if range reports should be kept, 0 if otherwise.
     * -'delete_reports_keep_segment_reports': 1 if reports for segments should be kept, 0 if otherwise.
     * -'delete_logs_max_rows_per_query': Maximum number of rows to delete in one DELETE query.
     */
    public static function make($settings, $metricsToKeep)
    {
        return new ReportsPurger(
            $settings['delete_reports_older_than'],
            $settings['delete_reports_keep_basic_metrics'] == 1,
            self::getReportPeriodsToKeep($settings),
            $settings['delete_reports_keep_segment_reports'] == 1,
            $metricsToKeep,
            $settings['delete_logs_max_rows_per_query']
        );
    }

    /**
     * Utility function that returns an array period values based on the 'delete_reports_keep_*'
     * settings. The period values returned are the integer values stored in the DB.
     *
     * @param array $settings The settings to use.
     * @return array An array of period values that should be kept when purging old data.
     */
    private static function getReportPeriodsToKeep($settings)
    {
        $keepReportPeriods = array();
        foreach (Piwik::$idPeriods as $strPeriod => $intPeriod) {
            $optionName = "delete_reports_keep_{$strPeriod}_reports";
            if ($settings[$optionName] == 1) {
                $keepReportPeriods[] = $intPeriod;
            }
        }
        return $keepReportPeriods;
    }
}
