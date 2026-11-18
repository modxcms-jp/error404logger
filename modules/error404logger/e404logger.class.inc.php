<?php
///////////////////////////////////
//
//  Error 404 Logger
//  --------------------------------
//  class version: 0.07
//  suitable for MODx 0.9.5+
//
//  author     : Andraz Kozelj (andraz dot kozelj at amis dot net)
//  written on : 14.03.2007
//
//

class Error404Logger
{

    /** @var string */
    protected $tableName = '';

    /** @var bool reverse DNS lookup of the client IP (plugin setting resolve_host) */
    public $resolveHostEnabled = true;

    /** @var array<string, string> ip => host, for this request */
    protected $hostCache = array();

    /**
     * Length (characters) of the varchar columns.
     * A longer value is cut to this length before INSERT: on MySQL in strict mode (the default of MySQL 8)
     * a too long value fails the INSERT, and the core turns that DB error into HTTP 500 instead of the 404.
     * Tables made by an older version are widened by checkTable().
     *
     * @var array<string, int>
     */
    protected $columnLengths = array(
        'ip'      => 45,   // IPv6 (incl. IPv4-mapped) fits in 45
        'host'    => 255,  // max length of a DNS name
        'url'     => 2048,
        'referer' => 2048, // search engine referers (e.g. https://www.google.com/url?...) are often over 200
    );

    public function __construct()
    {
        $this->tableName = $this->resolveTableName();
        $this->checkTable();
    }

    protected function resolveTableName()
    {
        $tableName = evo()->getFullTableName('error_404_logger');
        if (!empty($tableName)) {
            return $tableName;
        }

        $prefix = evo()->config['table_prefix'] ?? '';
        if ($prefix === '') {
            return '';
        }

        return $prefix . 'error_404_logger';
    }

    protected function getTableName()
    {
        if (empty($this->tableName)) {
            $this->tableName = $this->resolveTableName();
        }

        return $this->tableName;
    }

    // most requested URLs (rows: url, num). $num <= 0 means all
    public function getTop($num = null)
    {
        $table = $this->getTableName();
        if ($table === '') {
            return array();
        }

        $sql = sprintf(
            'SELECT url, COUNT(url) AS num FROM %s GROUP BY url ORDER BY num DESC',
            $table
        );
        $num = (int) $num;
        if ($num > 0) {
            $sql .= ' LIMIT ' . $num;
        }
        return db()->getRows(db()->query($sql)) ?: array();
    }

    // number of all entries
    public function count()
    {
        $table = $this->getTableName();
        if ($table === '') {
            return 0;
        }

        return (int) db()->getValue(db()->select('COUNT(id)', $table));
    }

    // entries, newest first. $limit <= 0 means all
    public function getAll($offset = 0, $limit = 0)
    {
        $table = $this->getTableName();
        if ($table === '') {
            return array();
        }

        $offset = max(0, (int) $offset);
        $limit = (int) $limit;

        return db()->getRows(
            db()->select(
                '*',
                $table,
                '',
                'createdon DESC, id DESC',
                $limit > 0 ? sprintf('%d,%d', $offset, $limit) : ''
            )
        ) ?: array();
    }

    // client IP. With $remoteIPIndexName (e.g. HTTP_X_FORWARDED_FOR), the last address of that header
    public function clientIp($remoteIPIndexName = '')
    {
        $forwarded = $remoteIPIndexName ? serverv($remoteIPIndexName) : null;
        if (is_string($forwarded) && $forwarded !== '') {
            return preg_replace('/.*[, ]([^, ]+)\z/', '$1', $forwarded);
        }
        return (string) serverv('REMOTE_ADDR', '');
    }

    // add 404 query
    // $host null means resolve it here
    public function insert($ip, $host = null)
    {
        $table = $this->getTableName();
        if ($table === '') {
            return false;
        }

        $ip = (string) $ip;
        if ($host === null) {
            $host = $this->resolveHost($ip);
        }

        $fields = array(
            'url'     => $this->fix_xss_value(serverv('REQUEST_URI', '')),
            'ip'      => $ip,
            'host'    => (string) $host,
            'referer' => $this->fix_xss_value(serverv('HTTP_REFERER', '')),
        );
        foreach ($fields as $name => $value) {
            $fields[$name] = db()->escape($this->fitColumn($name, $value));
        }
        $fields['createdon'] = date('Y-m-d H:i:s');

        return db()->insert($fields, $table);
    }

    // $value cut to the length of column $name (a cut HTML entity at the end is dropped too)
    protected function fitColumn($name, $value)
    {
        $max = $this->columnLengths[$name];
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }

        return preg_replace('/&#?[a-zA-Z0-9]*\z/', '', mb_substr($value, 0, $max, 'UTF-8'));
    }

    // remove specific url from entries
    public function remove($url = null)
    {
        if (is_array($url)) {
            return 0;
        }
        // $url is the stored value as it is (the request decodes it once; do not decode it again)
        if ($url !== null && $url !== '') {
            $table = $this->getTableName();
            if ($table === '') {
                return 0;
            }

            db()->delete(
                $table,
                sprintf("url='%s'", db()->escape((string) $url))
            );
            return db()->getAffectedRows();
        }
        $table = $this->getTableName();
        if ($table === '') {
            return 0;
        }

        return db()->truncate($table);
    }

    public function clearLast($num)
    {
        $num = is_numeric($num) ? (int) $num : 0;
        if ($num <= 0) {
            return 0;
        }

        $table = $this->getTableName();
        if ($table === '') {
            return 0;
        }

        db()->delete(
            $table,
            sprintf(
                'UNIX_TIMESTAMP(createdon) < %s',
                (time() - $num * 3600 * 24)
            )
        );
        return db()->getAffectedRows();
    }

    public function fix_xss_value($value)
    {
        return str_replace(
            '&amp;',
            '&',
            evo()->hsc(is_string($value) ? $value : '')
        );
    }

    /**
     * Host name of $ip (reverse DNS). Never returns false/null.
     *
     * gethostbyaddr() can block for seconds (above all when the lookup fails), and it runs in the 404 response.
     * So the host already logged for the same IP is reused (a failed lookup is stored as the IP itself,
     * so failures are not retried either), and the result is kept for the rest of the request.
     * With $resolveHostEnabled false, no lookup is done and '' is returned.
     */
    public function resolveHost($ip)
    {
        if (!$this->resolveHostEnabled
            || !is_string($ip) || $ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        if (isset($this->hostCache[$ip])) {
            return $this->hostCache[$ip];
        }

        $host = $this->loggedHost($ip);
        if ($host === '') {
            $host = gethostbyaddr($ip);
            $host = is_string($host) ? $host : $ip;
        }

        return $this->hostCache[$ip] = $host;
    }

    // host stored by an earlier entry of the same IP ('' if none)
    protected function loggedHost($ip)
    {
        $table = $this->getTableName();
        if ($table === '') {
            return '';
        }

        $host = db()->getValue(
            db()->select(
                'host',
                $table,
                sprintf("ip='%s' AND host<>''", db()->escape($ip)),
                'id DESC',
                '1'
            )
        );

        return is_string($host) ? $host : '';
    }

    public function purge_log($limit = 1000, $trim = 100)
    {
        $limit = max(0, (int) $limit);
        $trim = max(0, (int) $trim);
        if ($limit < $trim) {
            $trim = $limit;
        }

        $table = $this->getTableName();
        if ($table === '') {
            return;
        }

        $rs = db()->select('COUNT(id) as count', $table);
        if (!$rs) {
            return;
        }

        $row = db()->getRow($rs);
        $over = (int) $row['count'] - $limit;
        if ($over <= 0) {
            return;
        }

        db()->delete(
            $table,
            '',
            'createdon ASC',
            $over + $trim
        );
        db()->query(
            sprintf(
                "OPTIMIZE TABLE %s",
                $table
            )
        );
    }

    protected function normalizeTableNameForLike($tableName)
    {
        $tableName = trim($tableName, '`');
        if (strpos($tableName, '`.`') !== false) {
            $tableName = substr($tableName, strpos($tableName, '`.`') + 3);
            $tableName = trim($tableName, '`');
        } elseif (strpos($tableName, '.') !== false) {
            $parts = explode('.', $tableName);
            $tableName = trim(end($parts), '`');
        }

        return $tableName;
    }

    public function table_exists($table_name)
    {
        $tableLike = $this->normalizeTableNameForLike($table_name);

        return db()->count(
            db()->query(
                sprintf(
                    "SHOW TABLES LIKE '%s'",
                    $tableLike
                )
            )
        );
    }

    // create table
    public function createTable()
    {
        $table = $this->getTableName();
        if ($table === '') {
            return false;
        }

        return db()->query(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                `id` int(10) unsigned NOT NULL auto_increment,
                `createdon` datetime NOT NULL,
                `ip` varchar(%d) NOT NULL,
                `host` varchar(%d) NOT NULL,
                `url` varchar(%d) NOT NULL,
                `referer` varchar(%d) NOT NULL,
                PRIMARY KEY  (`id`)
                ) ENGINE=MyISAM AUTO_INCREMENT=1 ;',
            $table,
            $this->columnLengths['ip'],
            $this->columnLengths['host'],
            $this->columnLengths['url'],
            $this->columnLengths['referer']
        ));
    }

    // check if table exists and update version
    public function checkTable()
    {
        $table = $this->getTableName();
        if ($table === '') {
            return;
        }

        if (!$this->table_exists($table)) {
            $this->createTable();
        }
        $metaData = db()->getTableMetaData($table);
        if (!is_array($metaData)) {
            return;
        }
        if (empty($metaData['referer'])) {
            db()->query(
                sprintf(
                    'ALTER TABLE %s ADD COLUMN `referer` varchar(%d) NULL AFTER `host`',
                    $table,
                    $this->columnLengths['referer']
                )
            );
        }
        $this->widenColumns($table, $metaData);
    }

    // widen the varchar columns of a table made by an older version (url / referer were varchar(200))
    protected function widenColumns($table, $metaData)
    {
        $modify = array();
        foreach ($this->columnLengths as $name => $length) {
            if (empty($metaData[$name]['Type'])
                || !preg_match('/\Avarchar\((\d+)\)/i', $metaData[$name]['Type'], $m)
                || (int) $m[1] >= $length) {
                continue;
            }
            // keep NULL / NOT NULL as it is (referer added by ALTER is NULL and may hold NULLs)
            $modify[] = sprintf(
                'MODIFY `%s` varchar(%d) %s',
                $name,
                $length,
                ($metaData[$name]['Null'] ?? '') === 'YES' ? 'NULL' : 'NOT NULL'
            );
        }
        if (!$modify) {
            return;
        }
        db()->query(sprintf('ALTER TABLE %s %s', $table, implode(', ', $modify)));
    }
}
