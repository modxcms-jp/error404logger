<?php
include_once(__DIR__ . '/helpers.php');
include_once(__DIR__ . '/e404logger.class.inc.php');

$e404 = new Error404Logger();

$i = getv('do');
if ($i === 'remove' && is_string(getv('url')) && getv('url') !== '') {
    return $e404->remove(getv('url'));
}
if ($i === 'clearAll') { // clear all data
    return $e404->remove();
}
if ($i === 'clearLast' && getv('days')) { // clear data except for last N days
    return $e404->clearLast(getv('days', 7));
}

global $modx, $modx_manager_charset, $modx_lang_attribute, $modx_textdir, $manager_theme, $_style, $_lang;
include_once(MODX_CORE_PATH . 'controls/datagrid.class.php');

$ph = $e404->get_ph();

// create grid with all data
$grd = new DataGrid('', $e404->getAll(), event()->param('resultsPerPage', 20));
$grd->noRecordMsg       = 'There are no Error 404 entries! Good for you...';
$grd->cssClass          = 'grid';
$grd->columnHeaderClass = 'gridHeader';
$grd->itemClass         = 'gridItem';
$grd->altItemClass      = 'gridAltItem';
$grd->pagerClass        = 'pager';
$grd->Class             = 'page';
$grd->columns           = 'IP, host, time, URL';
$grd->fields = 'ip,host,createdon,url';
$urldecode = ($modx->config['enable_phx'] ?? null) ? ':urldecode:escape' : '';
$grd->colTypes = sprintf(
    ',,date:%s %%H:%%M:%%S,template:<a href="[+url+]" target="_blank">[+url%s+]</a>',
    $modx->toDateFormat(null, 'formatOnly'),
    $urldecode
);
if (event()->param('showReferer', 'yes') === 'yes') {
    $grd->columns .= '/referer';
    $grd->colTypes .= sprintf(
        '<br /><a href="%sindex.php?e404_redirect=[+referer+]" target="_blank">[+referer%s+]</a>',
        MODX_SITE_URL,
        $urldecode
    );
}
$grd->pagerLocation = 'top-left';

$ph['logs'] = $grd->render();

// create most wanted grid
$showTop = (int) event()->param('showTop', 20);
$ph['showing'] = ($showTop > 0 && $showTop < 10000)
    ? sprintf('<p>Showing top %s</p>', $showTop)
    : '<p>Showing all</p>';

$grd = new DataGrid(
    '',
    $e404->getTop($showTop),
    $showTop
);

$grd->noRecordMsg       = 'There are no Error 404 entries! Good for you...';
$grd->cssClass          = 'grid';
$grd->columnHeaderClass = 'gridHeader';
$grd->itemClass         = 'gridItem';
$grd->altItemClass      = 'gridAltItem';
$grd->columns           = ',count,URL';
$grd->colTypes          = 'template:<a class="red" href="#" onclick="doRemove(\'[+url+]\'); return false;">Remove</a>,integer,template:[+url' . $urldecode . '+]';
$grd->fields            = 'template,num,url';
$grd->pagerLocation = 'top-left';
$ph['showtop'] = $grd->render();

return $modx->parseText(
    file_get_contents(__DIR__ . '/template.tpl'),
    $ph
);
