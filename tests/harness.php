<?php namespace ProcessWire;

/**
 * UI harness: the real module page and endpoints without an admin login
 *
 * php -S localhost:8765 -t /path/to/pw/webroot tests/harness.php
 *
 * Router for PHP's built-in server only. Static files come from the webroot. Anything else
 * bootstraps PW, makes the superuser the current user for this one request (no login, no
 * session change) and runs the module: the page itself at /, or ./find/ and ./page/ with the
 * real session, CSRF token, ajax header and status codes. The admin theme is reduced to
 * UIkit CSS + JS, so Inputfield columns do not lay out as in the real admin.
 *
 * Refuses to run under any other SAPI: reached through a web server it would hand out
 * superuser output to whoever asks. PW's .htaccess also blocks PHP files in site/modules.
 *
 * @author AT
 */

if(PHP_SAPI !== 'cli-server') {
	http_response_code(404);
	exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) return false;

$root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
//php -S reports index.php as the script, PW would then render the requested URL itself
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = '/index.php';
include "$root/index.php";

$wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID));
$adminPage = $wire->pages->get('template=admin, name=selector-tester, include=all');
if(!$adminPage->id) exit('SelectorTester is not installed on this site');
$wire->wire('page', $adminPage);
/** @var SelectorTester $m */
$m = $wire->modules->get('SelectorTester');

if(substr($path, -6) === '/find/') {
	echo $m->executeFind();
	return true;
}
if(substr($path, -6) === '/page/') {
	echo $m->executePage();
	return true;
}

$body = $m->execute();
$config = $wire->config;
$uikit = $config->urls('AdminThemeUikit');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SelectorTester harness</title>
<link rel="stylesheet" href="<?= $uikit ?>uikit-pw/pw.min.css">
<?php foreach($config->styles as $url) echo "<link rel='stylesheet' href='$url'>\n"; ?>
<script>var ProcessWire = { config: <?= json_encode($config->js(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?> }; var config = ProcessWire.config;</script>
<?php foreach($config->scripts as $url) if(strpos($url, 'SelectorTester') !== false) echo "<script src='$url'></script>\n"; ?>
<script src="<?= $uikit ?>uikit/dist/js/uikit.min.js"></script>
</head>
<body>
<div class="uk-container uk-container-expand uk-margin-top">
<h1>Selector Tester</h1>
<?= $body ?>
</div>
</body>
</html>
