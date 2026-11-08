<!-- Error 404 Logger: fragment shown inside the manager shell (menu / tree).
     evo.js, csrf.js, tabpane.js and the theme CSS are loaded by the shell (header.inc.php) -->
<link rel="stylesheet" href="[+site_url+]assets/modules/error404logger/e404logger.css" type="text/css">
<script>
    // The wrapper is replaced on each screen change, so listeners do not pile up.
    // Runs before csrf.js's document listener for data-evo-post, so cancelling here stops the POST.
    Evo.ready(function () {
        const root = document.querySelector('[data-e404logger]');
        if (!root) {
            return;
        }
        root.addEventListener('click', function (e) {
            const el = e.target.closest('[data-e404-confirm]');
            if (el && !window.confirm(el.getAttribute('data-e404-confirm'))) {
                e.preventDefault();
            }
        });
        const pane = document.getElementById('e404loggerPane');
        if (pane && pane.tabPane) {
            pane.tabPane.setSelectedIndex([+selected_tab+]);
        }
    });
</script>
<h1>Error 404 Logger</h1>
<div class="sectionBody e404logger" data-e404logger>
    <div id="actions">
        <ul class="actionButtons">
            <li><a href="[+clear_all_url+]" data-evo-post data-e404-confirm="Really delete ALL entries?">Clear log</a></li>
            <li><a href="[+clear_last_url+]" data-evo-post data-e404-confirm="Really delete all entries except for the last [+keepLastDays+] days?">Clear all but last [+keepLastDays+] days</a></li>
        </ul>
    </div>
    [+message+]
    <div class="tab-pane" id="e404loggerPane">
        <div class="tab-page" id="e404loggerAll">
            <h2 class="tab">All entries</h2>
            [+logs+]
        </div>
        <div class="tab-page" id="e404loggerTop">
            <h2 class="tab">Most wanted</h2>
            <p>[+showing+]</p>
            [+showtop+]
        </div>
    </div>
    <script>
        new WebFXTabPane(document.getElementById('e404loggerPane'), false);
    </script>
</div>
