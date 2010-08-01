<?php
namespace ssh;

//
//
// FIXME: needs serious rewrite
//
//

class Server
{
    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** @var PacketProtocol */
    private $protocol;

    /** @var Authenticator */
    private $authenticator;

    /** @var array */
    private $keys;

    /** @var string */
    private $server_version = 'SSH-2.0-psshd';

    /** @ar string */
    private $client_version;

    /** @var int */
    private $channel_seq = 0;

    /** @var int */
    private $channels = array();

    /** @var array */
    static $packet_to_method = array(
        SSH_MSG_DISCONNECT                => 'processDisconnect',
        SSH_MSG_IGNORE                    => NULL,
        SSH_MSG_UNIMPLEMENTED             => 'processUimplemented',
        SSH_MSG_DEBUG                     => NULL,
        SSH_MSG_SERVICE_REQUEST           => 'processServiceRequest',
        //SSH_MSG_SERVICE_ACCEPT            => NULL,
        //SSH_MSG_KEXINIT                   => 'processKexInit',
        //SSH_MSG_NEWKEYS                   => 'processNewKeys',
        //SSH_MSG_KEXDH_INIT                => 'processKexDHInit',
        //SSH_MSG_KEXDH_REPLY               => NULL,

        SSH_MSG_USERAUTH_REQUEST          => 'processUserauthRequest',
        //SSH_MSG_USERAUTH_FAILURE          => NULL,
        //SSH_MSG_USERAUTH_SUCCESS          => NULL,
        //SSH_MSG_USERAUTH_BANNER           => NULL,
        //SSH_MSG_USERAUTH_PASSWD_CHANGEREQ => NULL,
        //SSH_MSG_USERAUTH_PK_OK            => NULL,

        //SSH_MSG_GLOBAL_REQUEST            => 'processGlobalRequest',
        //SSH_MSG_REQUEST_SUCCESS           => NULL,
        //SSH_MSG_REQUEST_FAILURE           => NULL,

        SSH_MSG_CHANNEL_OPEN              => 'processChannelOpen',
        //SSH_MSG_CHANNEL_OPEN_CONFIRMATION => NULL,
        //SSH_MSG_CHANNEL_OPEN_FAILURE      => NULL,
        SSH_MSG_CHANNEL_WINDOW_ADJUST     => 'processChannelWindowAdjust',
        SSH_MSG_CHANNEL_DATA              => 'processChannelData',
        SSH_MSG_CHANNEL_EXTENDED_DATA     => 'processChannelExtendedData',
        SSH_MSG_CHANNEL_EOF               => 'processChannelEof',
        SSH_MSG_CHANNEL_CLOSE             => 'processChannelClose',
        SSH_MSG_CHANNEL_REQUEST           => 'processChannelRequest',
        //SSH_MSG_CHANNEL_SUCCESS           => NULL,
        //SSH_MSG_CHANNEL_FAILURE           => NULL,
    );


    /**
     * Initialize
     * @param resource
     * @param resource
     */
    public function __construct($input, $output, Authenticator $authenticator, array $keys = array())
    {
        $this->input = $input;
        $this->output = $output;
        $this->protocol = new PacketProtocol($this->input, $this->output);
        $this->authenticator = $authenticator;
        $this->keys = $keys;

        fwrite($this->output, $this->server_version . "\r\n");

        for (;;) {
            $c = fread($this->input, 1);
            if ($c === "\r") {
                $c = fread($this->input, 1);
                if ($c !== "\n") {
                    return $this->disconnect(SSH_DISCONNECT_PROTOCOL_ERROR, 'no newline? eh?');
                }
                break;
            }
            $this->client_version .= $c;
        }

        if (strncmp($this->client_version, 'SSH-2.0-', 8) !== 0) {
            return $this->disconnect(SSH_DISCONNECT_PROTOCOL_ERROR, 'not SSH 2.0? sorry');
        }

        try {
            kex\kex(
                $this->protocol,
                new kex\Side(kex\Side::SERVER, $this->server_version), // local
                new kex\Side(kex\Side::CLIENT, $this->client_version), // remote
                $this->keys
            );
        } catch (\Exception $e) {
            $this->disconnect(SSH_DISCONNECT_KEY_EXCHANGE_FAILED, $e->getMessage());
        }

    }

    public function processPacket($packet)
    {
        list($packet_type) = parse('b', $packet);

        if (!isset(self::$packet_to_method[$packet_type])) {
            $this->protocol->send('bu', SSH_MSG_UNIMPLEMENTED, $this->protocol->getReceiveSeq());
            return;
        }

        if (self::$packet_to_method[$packet_type] === NULL) {
            return;
        }

        $method = self::$packet_to_method[$packet_type];

        if (!method_exists($this, $method)) {
            $this->debug('undefined method ' . $method);
            $this->protocol->send('bu', SSH_MSG_UNIMPLEMENTED, $this->protocol->getReceiveSeq());
            return;
        }

        $this->$method($packet);
    }

    public function main()
    {
        for (;;) {
            if (($n = stream_select($r = array($this->input), $w = NULL, $e = NULL, 5)) === FALSE) {
                die();
            }

            if ($n < 1) { // is connection alive?
                fflush($this->output);
                continue;
            }

            $this->processPacket($this->protocol->receive());
        }
    }

    private function processDisconnect($packet)
    {
        die();
    }

    private function processUnimplemented($packet)
    {
        $this->disconnect(SSH_DISCONNECT_PROTOCOL_ERROR, 'sorry for my bad packet');
    }

    private function processServiceRequest($packet)
    {
        list($service) = parse('s', $packet);

        if ($service === 'ssh-userauth') {
            $this->protocol->send('bs', SSH_MSG_SERVICE_ACCEPT, $service);
        } else {
            $this->disconnect(SSH_DISCONNECT_SERVICE_NOT_AVAILABLE, 'does not support service ' . $service);
        }
    }

    private function processUserauthRequest($packet)
    {
        $this->authenticator->authenticate($this->protocol, $packet);
    }

    private function processChannelOpen($packet)
    {
        list($channel_type, $remote_channel, $initial_window_size, $maximum_packet_size) =
            parse('suuu', $packet);

        if ($channel_type !== 'session') {
            $this->protocol->send('buuss', SSH_MSG_CHANNEL_OPEN_FAILURE,
                $remote_channel, SSH_OPEN_UNKNOWN_CHANNEL_TYPE,
                'only session channel is supported', '');
            return;
        }

        $local_channel = $this->channel_seq++;

        $this->channels[$local_channel] = (object) array(
            'send_window_size' => $initial_window_size,
            'send_maximum_packet_size' => $maximum_packet_size,
            'recv_window_size' => 0x7fffffff,
            'recv_maximum_packet_size' => 4194304, // 4 MiB
            'local_channel' => $local_channel,
            'remote_channel' => $remote_channel,
            'closed' => FALSE,
            'eof' => FALSE,
            'handle_open' => array($this, 'handleSessionOpen'),
            'handle_data' => array($this, 'handleSessionData'),
            'handle_extended_data' => array($this, 'handleSessionExtendedData'),
            'handle_eof' => array($this, 'handleSessionEof'),
            'handle_close' => array($this, 'handleSessionClose'),
            'handle_request' => array($this, 'handleSessionRequest'),
        );

        $this->protocol->send('buuuu', SSH_MSG_CHANNEL_OPEN_CONFIRMATION,
            $remote_channel, $local_channel,
            $this->channels[$local_channel]->recv_window_size,
            $this->channels[$local_channel]->recv_maximum_packet_size);

        // FIXME
        return call_user_func($this->channels[$local_channel]->handle_open,
            $this->channels[$local_channel]);
    }

    private function processChannelWindowAdjust($packet)
    {
        list($local_channel, $bytes_to_add) = parse('uu', $packet);

        if (isset($this->channels[$local_channel])) {
            $this->channels[$local_channel]->recv_window_size += $bytes_to_add;
        }
    }

    private function processChannelData($packet)
    {
        list($local_channel, $data) = parse('us', $packet);

        if (isset($this->channels[$local_channel])) {
            // FIXME
            call_user_func($this->channels[$local_channel]->handle_data,
                $this->channels[$local_channel], $data);
        }
    }

    private function processChannelExtendedData($packet)
    {
        list($local_channel, $data_type, $data) = parse('uus', $packet);

        if (isset($this->channels[$local_channel])) {
            // FIXME
            call_user_func($this->channels[$local_channel]->handle_extended_data,
                $this->channels[$local_channel], $data, $data_type);
        }
    }

    private function processChannelEof($packet)
    {
        list($local_channel) = parse('u');

        if (isset($this->channels[$local_channel])) {
            $this->channels[$local_channel]->eof = TRUE;
            // FIXME
            call_user_func($this->channels[$local_channel]->handle_eof,
                $this->channels[$local_channel]);
        }
    }

    private function processChannelClose($packet)
    {
        list($local_channel) = parse('u');

        if (isset($this->channels[$local_channel])) {
            $this->channels[$local_channel]->closed = TRUE;

            call_user_func($this->channels[$local_channel]->handle_close,
                $this->channels[$local_channel]);
        }
    }


    private function processChannelRequest($packet)
    {
        list($local_channel, $request_type, $want_reply) = parse('usb', $packet);
        $want_reply = (bool) $want_reply;

        if (isset($this->channels[$local_channel])) {
            // FIXME
            $ok = call_user_func($this->channels[$local_channel]->handle_request,
                $this->channels[$local_channel], $request_type, $packet);

            if ($want_reply) {
                $this->protocol->send('bu',
                    $ok ? SSH_MSG_CHANNEL_SUCCESS : SSH_MSG_CHANNEL_FAILURE,
                    $local_channel);
            }
        }
    }

    private function handleSessionOpen($channel)
    {
        $channel->env = array();
        $channel->line = '';
    }

    private function handleSessionData($channel, $data)
    {
        // ^C or ^D
        if (ord($data) === 0x03 || ord($data) === 0x04) {
            $this->closeChannel($channel);

        // backspace
        } else if (ord($data) === 0x08) {
            $channel->line = substr($channel->line, 0, strlen($channel->line) - 1);
            $this->sendChannelData($channel, chr(0x08) . ' ' . chr(0x08));

        // return
        } else if (ord($data) === 0x0d) {
            $this->sendChannelData($channel, "\r\n");
            if (!empty($channel->line)) {
                $this->execChannel($channel, $channel->line);
            }
            $this->sendChannelData($channel, "$ ");
            $channel->line = '';

        // escape sequences
        } else if (ord($data) === 0x1b) {
            $this->debug(bin2hex($data));

        // just some data
        } else {
            $this->sendChannelData($channel, $data);
            $channel->line .= $data;
        }
    }

    private function handleSessionExtendedData($channel, $data, $data_type)
    {
        // FIXME
    }

    private function handleSessionEof($channel)
    {
        $this->closeChannel($channel);
    }

    private function handleSessionClose($channel)
    {
        // FIXME
    }

    private function handleSessionRequest($channel, $request_type, $packet)
    {
        switch ($request_type) {
            case 'pty-req':
                list($channel->term,
                    $channel->columns, $channel->lines,
                    $channel->width, $channel->height,
                    $channel->modes) = parse('suuuus', $packet);

                return TRUE;
            break;

            case 'env':
                list($name, $value) = parse('ss', $packet);

                $this->debug($name, $value);
                $channel->env[$name] = $value;

                return TRUE;
            break;

            case 'shell':
                $this->sendChannelData($channel, "\$ ");
                return TRUE;
            break;

            case 'exec':
                list($command) = parse('s', $packet);

                $this->execChannel($channel, $command);
                $this->eofChannel($channel);
                $this->closeChannel($channel);

                return TRUE;
            break;
        }

        return FALSE;
    }

    private function execChannel($channel, $command)
    {
        $stdin = fopen('php://memory', 'w+');
        fwrite($stdin, $command);
        fseek($stdin, 0);

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');

        $sh = new \sh\sh(
            array('sh'),
            $_SERVER,
            array(
                0 => $stdin,
                1 => $stdout,
                2 => $stderr,
            ),
            new \sh\PhpExecutor('sh\utilities', array(
                'echo' => 'echo_',
                'false' => 'false_',
                'true' => 'true_',
                '[' => 'test',
            )),
            FALSE
        );

        $exit_status = $sh->main();

        fseek($stdout, 0);
        fseek($stderr, 0);

        $this->sendChannelExtendedData($channel, SSH_EXTENDED_DATA_STDERR,
            str_replace("\n", "\r\n", stream_get_contents($stderr)));
        $this->sendChannelData($channel,
            str_replace("\n", "\r\n", stream_get_contents($stdout)));
    }

    private function sendChannelData($channel, $data)
    {
        return $this->sendChannel(
            $channel,
            format('bus',
                SSH_MSG_CHANNEL_DATA,
                $channel->remote_channel,
                $data),
            strlen($data));
    }

    private function sendChannelExtendedData($channel, $data_type, $data)
    {
        return $this->sendChannel(
            $channel,
            format('buus',
                SSH_MSG_CHANNEL_EXTENDED_DATA,
                $channel->remote_channel,
                $data_type,
                $data),
            strlen($data));
    }

    private function sendChannel($channel, $packet, $data_len)
    {
        if ($channel->closed) {
            return TRUE;
        }

        if ($channel->send_window_size < $data_len) {
            if (!$this->protocol->send('buu',
                SSH_MSG_CHANNEL_WINDOW_ADJUST,
                $channel->remote_channel,
                0x7fffffff))
            {
                return FALSE;
            }

            $channel->send_window_size += 0x7fffffff;
        }

        if (!$this->protocol->send('r', $packet))
        {
            return FALSE;
        }

        $channel->send_window_size -= $data_len;
        return TRUE;
    }

    private function closeChannel($channel)
    {
        unset($this->channels[$channel->local_channel]);

        if ($channel->closed) {
            return TRUE;
        }

        return $this->protocol->send('bu',
            SSH_MSG_CHANNEL_CLOSE,
            $channel->remote_channel);
    }

    private function eofChannel($channel)
    {
        if ($channel->closed) {
            return TRUE;
        }

        return $this->protocol->send('bu',
            SSH_MSG_CHANNEL_EOF,
            $channel->remote_channel);
    }

    private function debug()
    {
        ob_start();
        foreach (func_get_args() as $arg) {
            var_dump($arg);
        }
        $str = "\n" . ob_get_clean();
        $this->protocol->send('bbsu', SSH_MSG_DEBUG, 1, $str, 0);
    }

    private function disconnect($reason, $description)
    {
        $this->protocol->send('bus', SSH_MSG_DISCONNECT, $reason, $description);
        die();
    }
}
