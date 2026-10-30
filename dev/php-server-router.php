<?php
// Router for the PHP built-in server so pretty permalinks work (dev only):
//   SBR_WP_DIR=/path/to/wp php -S localhost:8089 -t /path/to/wp dev/php-server-router.php
$root = getenv('SBR_WP_DIR') ?: __DIR__ . '/../.wp';
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (strpos($path, '..') === false && is_file($root . $path)) {
    if (substr($path, -4) !== '.php') {
        return false; // static file, served by php -S from the docroot (symlinks followed)
    }
    chdir(dirname($root . $path));
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['SCRIPT_FILENAME'] = $root . $path;
    require $root . $path;
    return true;
}
if (is_dir($root . $path) && is_file(rtrim($root . $path, '/') . '/index.php')) {
    chdir(rtrim($root . $path, '/'));
    $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
    require rtrim($root . $path, '/') . '/index.php';
    return true;
}
chdir($root);
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
