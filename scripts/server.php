<?php
foreach (glob(__DIR__ . '/../ssh/*.php') as $f) {
    require_once $f;
}

foreach (glob(__DIR__ . '/../sh/*.php') as $f) {
    require_once $f;
}

const CHANNEL = 0;

function disconnect(ssh\PacketProtocol $protocol, $reason, $description)
{
    $protocol->send('bus', ssh\SSH_MSG_DISCONNECT, $reason, $description);
    die();
}

function debug()
{
    global $protocol;

    ob_start();
    foreach (func_get_args() as $arg) {
        var_dump($arg);
    }
    $str = "\r\n" . str_replace(array("\r\n", "\r", "\n"), "\r\n", ob_get_clean());

    $protocol->send('bbsu', ssh\SSH_MSG_DEBUG, 1, $str, 0);
}

function check_channel($protocol, $channel)
{
    if ($channel !== CHANNEL) {
        disconnect($protocol, ssh\SSH_DISCONNECT_BY_APPLICATION, 'Bad channel #' . $channel . '.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_CONNECTION'])) {
    header('HTTP/1.1 400 Bad Request');
    die();
}

$connections_dir = dirname(__FILE__) . '/../connections';

$connection = trim($_SERVER['HTTP_X_CONNECTION']);
$input = file_get_contents('php://input');

if ($connection !== 'connect') { // incoming data
    if (!($handle = @fopen($connections_dir . '/' . $connection, 'ab'))) {
        header('HTTP/1.1 404 Not Found');
        die();
    }

    if (!(fwrite($handle, $input) !== FALSE &&
          fclose($handle)))
    {
        header('HTTP/1.1 500 Internal Server Error');
    }
    die();
}

// new connection
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

register_shutdown_function(function ($filename, $handle) {
    fclose($handle);
    @unlink($filename);
}, $connections_dir . '/' . $connection, $input);

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
$output = fopen('ssh-utils-streamedresponse://', NULL);
$protocol = new ssh\PacketProtocol($input, $output);

$server_identification_string = 'SSH-2.0-pssh';
fwrite($output, $server_identification_string . "\r\n");

if (($client_identification_string = fgets($input)) === FALSE) {
    die();
}

if (strncmp($client_identification_string, 'SSH-2.0-', 8) !== 0 ||
    substr($client_identification_string, -2) !== "\r\n")
{
    disconnect($protocol, ssh\SSH_DISCONNECT_PROTOCOL_ERROR, 'Bad identification string.');
}

$client_identification_string =
    substr($client_identification_string, 0, strlen($client_identification_string) - 2);

try {
    ssh\kex\kex(
        $protocol,
        new ssh\kex\Side(ssh\kex\Side::SERVER, $server_identification_string), // local
        new ssh\kex\Side(ssh\kex\Side::CLIENT, $client_identification_string), // remote
        $keys
    );
} catch (\Exception $e) {
    disconnect($protocol, SSH_DISCONNECT_KEY_EXCHANGE_FAILED, $e->getMessage());
}

$dispatcher = new ssh\Dispatcher($protocol);

try {
    // TRANSPORT LAYER
    $dispatcher->on(ssh\SSH_MSG_DISCONNECT, function () {
        die();
    });

    $dispatcher->on(ssh\SSH_MSG_IGNORE, NULL);

    $dispatcher->on(ssh\SSH_MSG_UNIMPLEMENTED, function () use (&$protocol) {
        disconnect($protocol, ssh\SSH_DISCONNECT_PROTOCOL_ERROR, 'Sorry for my bad packet.');
    });

    $dispatcher->on(ssh\SSH_MSG_DEBUG, NULL);

    $dispatcher->on(ssh\SSH_MSG_SERVICE_REQUEST, function ($packet) use (&$protocol) {
        list($service) = ssh\parse('s', $packet);

        if ($service !== 'ssh-userauth') {
            disconnect($protocol, ssh\SSH_DISCONNECT_SERVICE_NOT_AVAILABLE, $service . ' not supported.');
        }

        $protocol->send('bs', ssh\SSH_MSG_SERVICE_ACCEPT, $service);
    });


    // AUTHENTICATION LAYER
    $dispatcher->on(ssh\SSH_MSG_USERAUTH_REQUEST, function ($packet) use (&$protocol, &$authenticator) {
        $authenticator->authenticate($protocol, $packet);
    });


    // CONNECTION LAYER

    $channel = NULL;
    $window_size = 0;
    $stdout = NULL;
    $stderr = NULL;
    $envp = array();

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_OPEN, function ($packet) use (&$protocol, &$channel, &$window_size, &$stdout, &$stderr) {
        list($channel_type, $remote_channel, $initial_window_size, $maximum_packet_size) =
            ssh\parse('suuu', $packet);

        if ($channel !== NULL) {
            $protocol->send('buuss', ssh\SSH_MSG_CHANNEL_OPEN_FAILURE,
                $remote_channel, ssh\SSH_OPEN_ADMINISTRATIVELY_PROHIBITED,
                'Only one channel at the time is supported.', '');
            return;
        }

        if ($channel_type !== 'session') {
            $protocol->send('buuss', ssh\SSH_MSG_CHANNEL_OPEN_FAILURE,
                $remote_channel, ssh\SSH_OPEN_UNKNOWN_CHANNEL_TYPE,
                'Only sessions are supported.', '');
            return;
        }

        $channel = $remote_channel;
        $window_size = $initial_window_size;

        $stdout = fopen('ssh-channelstdout://', NULL, FALSE, stream_context_create(array(
            'ssh-channelstdout' => array(
                'channel' => $channel,
                'window_size_wrapper' => (object) array('window_size' => &$window_size),
                'protocol' => $protocol,
            )
        )));

        $stderr = fopen('ssh-channelstderr://', NULL, FALSE, stream_context_create(array(
            'ssh-channelstderr' => array(
                'channel' => $channel,
                'window_size_wrapper' => (object) array('window_size' => &$window_size),
                'protocol' => $protocol,
            )
        )));

        $protocol->send('buuuu', ssh\SSH_MSG_CHANNEL_OPEN_CONFIRMATION,
            $channel, CHANNEL,
            0x7fffffff, 1048576 /* 1MiB */);
    });

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_EXTENDED_DATA, NULL);

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_WINDOW_ADJUST, NULL);

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_CLOSE, function ($packet) use (&$protocol) {
        disconnect($protocol, ssh\SSH_DISCONNECT_BY_APPLICATION, 'Bye bye.');
    });

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_REQUEST, function ($packet) use (&$protocol, &$dispatcher, &$envp, &$stdout, &$stderr, &$channel, &$window_size) {
        list($local_channel, $request_type, $want_reply) = ssh\parse('usb', $packet);
        check_channel($protocol, $local_channel);

        $exec = function ($command) use (&$stdout, &$stderr) {
            $in = fopen('php://memory', 'w+');
            fwrite($in, $command);
            fseek($in, 0);

            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');

            $sh = new \sh\sh(
                array('sh'),
                array(),
                array(
                    0 => $in,
                    1 => $out,
                    2 => $err,
                ),
                new \sh\PhpExecutor('sh\utilities', array(
                    'echo' => 'echo_',
                    'false' => 'false_',
                    'true' => 'true_',
                    '[' => 'test',
                ))
            );

            $exit_status = $sh->main();

            fseek($out, 0);
            fseek($err, 0);

            fwrite($stderr, str_replace("\n", "\r\n", stream_get_contents($err)));
            fwrite($stdout, str_replace("\n", "\r\n", stream_get_contents($out)));

            fclose($in);
            fclose($out);
            fclose($err);

            return $exit_status;
        };

        switch ($request_type) {
            case 'pty-req':
                list($term,
                    $envp['COLUMNS'], $envp['LINES'],
                    $envp['WIDTH'], $envp['HEIGHT'],
                    $modes) = ssh\parse('suuuus', $packet);

                if ($want_reply) {
                    $protocol->send('bu', ssh\SSH_MSG_CHANNEL_SUCCESS, CHANNEL);
                }
            break;

            case 'env':
                list($name, $value) = ssh\parse('ss', $packet);
                $envp[$name] = $value;

                if ($want_reply) {
                    $protocol->send('bu', ssh\SSH_MSG_CHANNEL_SUCCESS, CHANNEL);
                }
            break;

            case 'shell':
                $stdin = fopen('ssh-channelstdin://', NULL, FALSE, stream_context_create(array(
                    'ssh-channelstdin' => array(
                        'channel' => CHANNEL,
                        'dispatcher' => $dispatcher,
                    )
                )));

                if ($want_reply) {
                    $protocol->send('bu', ssh\SSH_MSG_CHANNEL_SUCCESS, CHANNEL);
                }

                fwrite($stdout, '$ ');
                $line = '';
                while (!feof($stdin)) {
                    $data = fread($stdin, 1);
                    // ^C or ^D
                    if (ord($data) === 0x03 || ord($data) === 0x04) {
                        $protocol->send('bu',
                            ssh\SSH_MSG_CHANNEL_CLOSE,
                            $channel);
                        break;

                    // backspace
                    } else if (ord($data) === 0x08) {
                        if (strlen($line) > 0) {
                            $line = substr($line, 0, -1);
                            fwrite($stdout, chr(0x08) . ' ' . chr(0x08));
                        }

                    // return
                    } else if (ord($data) === 0x0d) {
                        fwrite($stdout, "\r\n");
                        $exec($line);
                        $line = '';
                        fwrite($stdout, '$ ');

                    // escape sequences
                    } else if (ord($data) === 0x1b) {
                        // TODO

                    // just some data
                    } else {
                        $line .= $data;
                        fwrite($stdout, $data);
                    }
                }
            break;

            case 'exec':
                list($command) = ssh\parse('s', $packet);

                if ($want_reply) {
                    $protocol->send('bu', ssh\SSH_MSG_CHANNEL_SUCCESS, CHANNEL);
                }

                $exec($command);

                $protocol->send('bu',
                    ssh\SSH_MSG_CHANNEL_CLOSE,
                    $channel);
            break;

            default:
                if ($want_reply) {
                    $protocol->send('bu', ssh\SSH_MSG_CHANNEL_FAILURE, CHANNEL);
                }
        }

    });


    // MAIN LOOP
    for (;;) {
        if (($n = stream_select($r = array($input), $w = NULL, $e = NULL, 5)) === FALSE) {
            die();
        }

        if ($n < 1) { // is connection alive?
            fflush($output);
            continue;
        }

        $dispatcher->dispatch();
    }

} catch (\Exception $e) {
    disconnect($protocol, ssh\SSH_DISCONNECT_BY_APPLICATION, $e->getMessage());
}
