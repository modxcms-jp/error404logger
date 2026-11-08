<?php

/**
 * Error 404 Logger
 *
 * Plugin logs requests that trigger an Page not found error.
 *
 * @category    plugin
 * @version     0.3
 * @license     http://www.gnu.org/copyleft/gpl.html GNU Public License (GPL)
 * @author      Andraz Kozelj (andraz dot kozelj at amis dot net) date created: 14.03.2007
 * @author      yama (https://kyms.jp)
 * @internal    @events        OnPageNotFound
 * @internal    @modx_category Manager and Admin
 * @internal    @properties &found_ref_only=Found ref only;list;yes,no;yes &count_robots=Robots count;list;yes,no;no; &robots=Robots list;text;googlebot,baidu,bing,crawl;&limit=Number of limit logs;1000 &trim=Number deleted at a time;100 &remoteIPIndexName=RemoteIP Index Name;text;REMOTE_ADDR
 * @global $modx
 * @global $remoteIPIndexName
 */

$e404logger_dir = 'assets/modules/error404logger/';
include_once(MODX_BASE_PATH . $e404logger_dir . 'helpers.php');

// 管理画面にログインしている人の 404 は記録しない
if (isset($_SESSION['mgrValidated'])) {
    return;
}

if (event()->name !== 'OnPageNotFound') {
    return;
}
if (event()->param('found_ref_only') === 'yes' && !serverv('HTTP_REFERER')) {
    return;
}

if (event()->param('count_robots') === 'no') {
    $robots = array_filter(array_map('trim', explode(
        ',',
        (string) event()->param('robots', 'googlebot,baidu,msnbot')
    )));
    $remote_addr = (string) serverv('REMOTE_ADDR', '');
    $host_name = filter_var($remote_addr, FILTER_VALIDATE_IP) !== false
        ? gethostbyaddr($remote_addr)
        : false;
    if (!is_string($host_name)) {
        $host_name = '';
    }
    foreach ($robots as $robot) {
        if (strpos($host_name, $robot) !== false) {
            return;
        }
    }
}

include_once(MODX_BASE_PATH . $e404logger_dir . 'e404logger.class.inc.php');
$e404 = new Error404Logger();
$e404->insert(event()->param('remoteIPIndexName', 'REMOTE_ADDR'));
$e404->purge_log(
    event()->param('limit', 1000),
    event()->param('trim', 100)
);
