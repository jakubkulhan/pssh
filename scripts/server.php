<?php
foreach (glob(__DIR__ . '/../ssh/*.php') as $f) {
    require_once $f;
}

foreach (glob(__DIR__ . '/../sh/*.php') as $f) {
    require_once $f;
}

if (($exts = glob(__DIR__ . '/ext/*.php')) !== FALSE) {
    foreach ($exts as $ext) {
        require_once $ext;
    }
}

define('CHANNEL', 0);
define('CONNECTIONS_DIR', __DIR__ . '/../connections');

function disconnect($reason, $description)
{
    global $protocol;
    $protocol->send('bus', ssh\SSH_MSG_DISCONNECT, $reason, $description);
    die();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SERVER['HTTP_X_CONNECTION'])) {
    header('HTTP/1.1 400 Bad Request');
    die();
}

$connection = $_SERVER['HTTP_X_CONNECTION'];

// HTTP tunnel incoming data
if (strncmp($_SERVER['HTTP_X_CONNECTION'], 'http=', 5) === 0) {
    if (!($handle = @fopen(CONNECTIONS_DIR . '/' . substr($_SERVER['HTTP_X_CONNECTION'], 5), 'ab'))) {
        header('HTTP/1.1 404 Not Found');
        die();
    }

    if (!(fwrite($handle, file_get_contents('php://input')) !== FALSE &&
          fclose($handle)))
    {
        header('HTTP/1.1 500 Internal Server Error');
    }

    die();
}

// new connection
$requested_types = explode(', ', $_SERVER['HTTP_X_CONNECTION']);

foreach ($requested_types as $type) {
    if (strncmp($type, 'tcp=', 4) === 0) {
        $ip = substr($type, 4);

        if (($socket = stream_socket_server("tcp://$ip:0")) === FALSE) {
            continue;
        }

        list($host, $port) = explode(':', stream_socket_get_name($socket, FALSE));

        @set_time_limit(0);
        ignore_user_abort(TRUE);
        header('X-Connection: tcp=' . $port);
        header('Connection: close');
        flush();

        if (($connection = stream_socket_accept($socket)) === FALSE) {
            fclose($socket);
            continue;
        }

        fclose($socket);

        $input = $output = $connection;

        break;

    } else if (strncmp($type, 'http', 4) === 0) {
        $id = sha1(uniqid('', TRUE));

        if (!@posix_mkfifo(CONNECTIONS_DIR . '/' . $id, 0600)) {
            header('HTTP/1.1 500 Internal Server Error');
            die();
        }

        if (!($input = @fopen(CONNECTIONS_DIR . '/' . $id, 'r+'))) {
            header('HTTP/1.1 500 Internal Server Error');
            die();
        }

        function _on_shutdown($filename, $handle) {
            fclose($handle);
            @unlink($filename);
        }

        register_shutdown_function(function ($filename, $handle) {
            fclose($handle);
            @unlink($filename);
        }, CONNECTIONS_DIR . '/' . $id, $input);

        header('X-Connection: http=' . $id);
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
        ignore_user_abort(FALSE);

        $output = fopen('ssh-utils-streamedresponse://', NULL);

        break;

    } else {
        header('HTTP/1.1 400 Bad Request');
        die();
    }
}

if (!isset($input) || !isset($output)) {
    header('HTTP/1.1 500 Internal Server Error');
    die();
}

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
$protocol = new ssh\PacketProtocol($input, $output);

$server_identification_string = 'SSH-2.0-pssh';
fwrite($output, $server_identification_string . "\r\n");

if (($client_identification_string = fgets($input)) === FALSE) {
    die();
}

if (strncmp($client_identification_string, 'SSH-2.0-', 8) !== 0 ||
    substr($client_identification_string, -2) !== "\r\n")
{
    disconnect(ssh\SSH_DISCONNECT_PROTOCOL_ERROR, 'Bad identification string.');
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
    disconnect(ssh\SSH_DISCONNECT_KEY_EXCHANGE_FAILED, $e->getMessage());
}

$dispatcher = new ssh\Dispatcher($protocol);

try {
    // TRANSPORT LAYER
    $dispatcher->on(ssh\SSH_MSG_DISCONNECT, function () {
        die();
    });

    $dispatcher->on(ssh\SSH_MSG_IGNORE, NULL);

    $dispatcher->on(ssh\SSH_MSG_UNIMPLEMENTED, function () {
        disconnect(ssh\SSH_DISCONNECT_PROTOCOL_ERROR, 'Sorry for my bad packet.');
    });

    $dispatcher->on(ssh\SSH_MSG_DEBUG, NULL);

    $dispatcher->on(ssh\SSH_MSG_SERVICE_REQUEST, function ($packet) use (&$protocol) {
        list($service) = ssh\parse('s', $packet);

        if ($service !== 'ssh-userauth') {
            disconnect(ssh\SSH_DISCONNECT_SERVICE_NOT_AVAILABLE, $service . ' not supported.');
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

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_CLOSE, function ($packet) {
        disconnect(ssh\SSH_DISCONNECT_BY_APPLICATION, 'Bye bye.');
    });

    $dispatcher->on(ssh\SSH_MSG_CHANNEL_REQUEST, function ($packet) use (&$protocol, &$dispatcher, &$envp, &$stdout, &$stderr, &$channel, &$window_size) {
        list($local_channel, $request_type, $want_reply) = ssh\parse('usb', $packet);

        if ($local_channel !== CHANNEL) {
            disconnect(ssh\SSH_DISCONNECT_BY_APPLICATION, 'Bad channel #' . $local_channel . '.');
        }

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

            case 'exec':
                list($command) = ssh\parse('s', $packet);

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

                $sh = new sh\sh(
                    array('sh'),
                    $envp,
                    array(
                        0 => $stdin,
                        1 => $stdout,
                        2 => $stderr,
                    ),
                    new sh\PhpExecutor('sh\commands'),
                    $request_type === 'shell'
                );

                if ($request_type === 'exec') {
                    $exit_status = $sh->exec($command);
                } else {
                    $exit_status = $sh->main();
                }

                $protocol->send('busbu', ssh\SSH_MSG_CHANNEL_REQUEST,
                    $channel, 'exit-status', 0, $exit_status);

                $protocol->send('bu', ssh\SSH_MSG_CHANNEL_CLOSE,
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

        if (feof($input)) {
            throw new ssh\Eof;
        }

        $dispatcher->dispatch();
    }

} catch (ssh\Eof $e) {
    die();

} catch (\Exception $e) {
    disconnect(ssh\SSH_DISCONNECT_BY_APPLICATION, $e->getMessage());
}
