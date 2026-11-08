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
 * @internal    @properties &found_ref_only=Found ref only;list;yes,no;yes &count_robots=Robots count;list;yes,no;no &robots=Robots list;text;googlebot,baidu,bing,crawl &limit=Number of limit logs;int;1000 &trim=Number deleted at a time;int;100 &remoteIPIndexName=RemoteIP Index Name;text;REMOTE_ADDR &resolve_host=Reverse DNS lookup of the client IP;list;yes,no;yes
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

include_once(MODX_BASE_PATH . $e404logger_dir . 'e404logger.class.inc.php');
$e404 = new Error404Logger();
$e404->resolveHostEnabled = event()->param('resolve_host', 'yes') !== 'no';
$ip = $e404->clientIp(event()->param('remoteIPIndexName', 'REMOTE_ADDR'));

// 逆引き（gethostbyaddr()）は 404 の応答を遅らせることがあるので、
// ロボットの除外は先に User-Agent で見て、当たらないときだけホスト名で見る。
// ホスト名は 1 回だけ引き、記録にもそのまま使う（同じ IP の記録があれば、そのホスト名を使い回す）
$host = null;
if (event()->param('count_robots') === 'no') {
    $robots = array_filter(array_map('trim', explode(
        ',',
        (string) event()->param('robots', 'googlebot,baidu,msnbot')
    )));
    $userAgent = (string) serverv('HTTP_USER_AGENT', '');
    foreach ($robots as $robot) {
        if (stripos($userAgent, $robot) !== false) {
            return;
        }
    }
    $host = $e404->resolveHost($ip);
    foreach ($robots as $robot) {
        if ($host !== '' && stripos($host, $robot) !== false) {
            return;
        }
    }
}

$e404->insert($ip, $host);
$e404->purge_log(
    event()->param('limit', 1000),
    event()->param('trim', 100)
);
