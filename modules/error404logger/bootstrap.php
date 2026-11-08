<?php
include_once(__DIR__ . '/helpers.php');
include_once(__DIR__ . '/e404logger.class.inc.php');

// 表は evolution-jp 1.3 の DataTable で描く（旧 DataGrid は本体から消えた）
$dataTableClass = MODX_CORE_PATH . 'controls/data_table.class.php';
if (!is_file($dataTableClass)) {
    return '<p>Error 404 Logger requires evolution-jp 1.3 or later (controls/data_table.class.php was not found).</p>';
}
include_once($dataTableClass);

$e404 = new Error404Logger();

$moduleId = (int) getv('id', 0);
$keepLastDays = max(1, (int) event()->param('keepLastDays', 7));
$perPage = max(0, (int) event()->param('resultsPerPage', 20));
$showTop = max(0, (int) event()->param('showTop', 20));
$showReferer = event()->param('showReferer', 'yes') === 'yes';
$baseParams = array('a' => 112, 'id' => $moduleId);

// 状態を変える操作は POST だけで受ける（管理画面の POST は本体が CSRF トークンを確かめる）
$message = '';
$tab = getv('tab') === 'top' ? 'top' : 'all';
if (serverv('REQUEST_METHOD') === 'POST') {
    $do = getv('do');
    if ($do === 'remove' && is_string(getv('url')) && getv('url') !== '') {
        $message = sprintf('Removed %d entries.', (int) $e404->remove(getv('url')));
    } elseif ($do === 'clearAll') {
        $e404->remove();
        $message = 'Removed all entries.';
    } elseif ($do === 'clearLast') {
        $message = sprintf(
            'Removed %d entries older than %d days.',
            (int) $e404->clearLast($keepLastDays),
            $keepLastDays
        );
    }
}

$actionUrl = function (array $params) use ($baseParams) {
    return 'index.php?' . http_build_query($baseParams + $params, '', '&');
};

// 保存した値は hsc() 済み（& だけ戻してある）なので、戻してから出す側でエスケープする
$storedText = function ($value) {
    return htmlspecialchars_decode(is_scalar($value) ? (string) $value : '', ENT_QUOTES);
};
// http(s) の URL かサイト内のパス（/ で始まる）だけをリンクにする
$linkHtml = function ($url, $siteRelative) {
    $text = rawurldecode($url);
    if ($siteRelative && preg_match('@\A/(?!/)@', $url)) {
        $href = rtrim(MODX_SITE_URL, '/') . $url;
    } elseif (preg_match('@\Ahttps?://@i', $url)) {
        $href = $url;
    } else {
        return hsc($text);
    }
    return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
        hsc($href),
        hsc($text)
    );
};

// all entries
$links = DataTableLinks::fromRequest('index.php', $baseParams, array());
$pager = new DataTablePager($e404->count(), $perPage, $links->page());
$columns = array(
    array('key' => 'ip', 'label' => 'IP', 'class' => 'is-shrink'),
    array('key' => 'host', 'label' => 'host'),
    array(
        'key' => 'createdon',
        'label' => 'time',
        'class' => 'is-shrink',
        'format' => function ($value) {
            $time = strtotime((string) $value);
            return $time === false ? (string) $value : evo()->toDateFormat($time);
        },
    ),
    array(
        'key' => 'url',
        'label' => $showReferer ? 'URL / referer' : 'URL',
        'class' => 'e404logger-url',
        'html' => true,
        'format' => function ($value, array $row) use ($storedText, $linkHtml, $showReferer) {
            $html = $linkHtml($storedText($value), true);
            $referer = $storedText($row['referer'] ?? '');
            if ($showReferer && $referer !== '') {
                $html .= '<br>' . $linkHtml($referer, false);
            }
            return $html;
        },
    ),
);
$table = new DataTable($columns, array(
    'id' => 'e404logger-all',
    'rowClasses' => array('gridItem', 'gridAltItem'),
    'emptyMessage' => 'There are no Error 404 entries! Good for you...',
));
$nav = $pager->render($links, array('label' => 'Pagination'));
$logs = $nav . $table->render($e404->getAll($pager->offset(), $pager->limit())) . $nav;

// most wanted
$columns = array(
    array(
        'key' => 'url',
        'label' => '',
        'class' => 'is-shrink',
        'html' => true,
        'format' => function ($value) use ($actionUrl, $storedText) {
            return sprintf(
                '<a class="red" href="%s" data-evo-post data-e404-confirm="%s">Remove</a>',
                hsc($actionUrl(array('do' => 'remove', 'tab' => 'top', 'url' => (string) $value))),
                hsc(sprintf("Really delete entries for '%s'?", rawurldecode($storedText($value))))
            );
        },
    ),
    array('key' => 'num', 'label' => 'count', 'class' => 'is-shrink'),
    array(
        'key' => 'url',
        'label' => 'URL',
        'class' => 'e404logger-url',
        'html' => true,
        'format' => function ($value) use ($storedText, $linkHtml) {
            return $linkHtml($storedText($value), true);
        },
    ),
);
$table = new DataTable($columns, array(
    'id' => 'e404logger-top',
    'rowClasses' => array('gridItem', 'gridAltItem'),
    'emptyMessage' => 'There are no Error 404 entries! Good for you...',
));
$showtop = $table->render($e404->getTop($showTop));

$ph = array(
    'site_url' => MODX_SITE_URL,
    'message' => $message !== '' ? '<p class="success">' . hsc($message) . '</p>' : '',
    'clear_all_url' => hsc($actionUrl(array('do' => 'clearAll'))),
    'clear_last_url' => hsc($actionUrl(array('do' => 'clearLast'))),
    'keepLastDays' => $keepLastDays,
    'selected_tab' => $tab === 'top' ? 1 : 0,
    'logs' => $logs,
    'showing' => $showTop > 0 ? sprintf('Showing top %d', $showTop) : 'Showing all',
    'showtop' => $showtop,
);

// strtr() は差し込んだ値を走査し直さないので、ログの値の中の [+…+] は展開されない
$pairs = array();
foreach ($ph as $k => $v) {
    $pairs['[+' . $k . '+]'] = (string) $v;
}
return strtr(file_get_contents(__DIR__ . '/template.tpl'), $pairs);
