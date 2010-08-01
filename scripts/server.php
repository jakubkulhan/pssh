<?php
foreach (glob(__DIR__ . '/../ssh/*.php') as $f) {
    require_once $f;
}

foreach (glob(__DIR__ . '/../sh/*.php') as $f) {
    require_once $f;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_CONNECTION'])) {
    header('HTTP/1.1 400 Bad Request');
    die();
}

$connections_dir = dirname(__FILE__) . '/../connections';

$connection = trim($_SERVER['HTTP_X_CONNECTION']);
$input = file_get_contents('php://input');

switch ($connection) {
    case 'connect':
        $connection = sha1(uniqid('', TRUE));
        //if (!($input = @fopen($connections_dir . '/' . $connection, 'x+b'))) {
        if (!file_exists($connections_dir . '/' . $connection)) {
            umask(0);
            if (!posix_mkfifo($connections_dir . '/' . $connection, 0600)) {
                header('HTTP/1.1 500 Internal Server Error');
                die();
            }
        }


        if (!($input = fopen($connections_dir . '/' . $connection, 'r+'))) {
            header('HTTP/1.1 500 Internal Server Error');
            die();
        }

        stream_set_blocking($input, 0);

        function _on_shutdown($filename, $handle) {
            fclose($handle);
            @unlink($filename);
        }
        register_shutdown_function('_on_shutdown', $connections_dir . '/' . $connection, $input);

        header('X-Connection: ' . $connection);
        header('Content-Type: application/octet-stream');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }

        @ini_set('zlib.output_compression', 0);
        @ini_set('output_buffering', 0);
        @ini_set('implicit_flush', 1);

        for ($i = 0; $i < ob_get_level(); ++$i) {
            ob_end_flush();
        }

        ob_implicit_flush(1);
        @set_time_limit(0);
        ignore_user_abort(false);

        $keys = array();
        foreach (array('id_rsa', 'id_dsa') as $type) {
            $file = __DIR__ . '/../' . $type;
            if (!file_exists($file) || !file_exists($file . '.pub')) {
                continue;
            }

            $priv = file_get_contents($file);
            list($keytype, $data) = explode(' ', file_get_contents($file . '.pub'));
            $keys[$keytype] = new ssh\kex\Key($keytype, $priv, base64_decode($data));
        }

        $authenticator = new ssh\ConnectionPublickeyAuthenticator(__DIR__ . '/../users');
        $server = new ssh\Server($input, fopen('ssh-utils-streamedresponse://', NULL), $authenticator, $keys);
        $server->main();
    break;

    default: // incoming data
        if (!($handle = @fopen($connections_dir . '/' . $connection, 'ab'))) {
            header('HTTP/1.1 404 Not Found');
            die();
        }

        if (!(fwrite($handle, $input) !== FALSE &&
              fclose($handle)))
        {
            header('HTTP/1.1 500 Internal Server Error');
        }
    break;
}
