<?php
namespace ssh;

class ChannelStdin
{
    const PROTOCOL = 'ssh-channelstdin';

    /** @var int remote channel number */
    private $channel;

    /** @var Dispatcher */
    private $dispatcher;

    /** @var bool */
    private $eof = FALSE;

    /**
     * @param string
     * @param string
     * @param int
     * @param string
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $options = stream_context_get_options($this->context);

        if (!(isset($options[self::PROTOCOL]['channel']) &&
            isset($options[self::PROTOCOL]['dispatcher']) &&
            $options[self::PROTOCOL]['dispatcher'] instanceof Dispatcher))
        {
            throw new Error('channel or dispatcher context option missing ' .
                'or dispatcher not instance of Dispatcher');
        }

        $this->channel = $options[self::PROTOCOL]['channel'];
        $this->dispatcher = $options[self::PROTOCOL]['dispatcher'];

        return TRUE;
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        return $this->eof;
    }

    /**
     * @param int
     * @return string
     */
    public function stream_read($n)
    {
        for (;;) {
            if (($n = stream_select($r = array($this->dispatcher->getProtocol()->getInputStream()),
                $w = NULL, $e = NULL, 5)) === FALSE)
            {
                throw new Error('stream_select() failed');
            }

            if ($n < 1) { // is connection alive?
                fflush($this->dispatcher->getProtocol()->getInputStream());
                continue;
            }

            $channel = $this->channel;

            $packet = $this->dispatcher->dispatch(function ($packet) use ($channel) {
                list($packet_type) = parse('b', $packet);

                if ($packet_type === SSH_MSG_CHANNEL_DATA) {
                    list($local_channel) = parse('u', $packet);
                    return $local_channel === $channel;

                } else if ($packet_type === SSH_MSG_CHANNEL_EOF) {
                    return TRUE;
                }

                return FALSE;
            });

            if ($packet !== NULL) {
                list($packet_type) = parse('b', $packet);

                if ($packet_type === SSH_MSG_CHANNEL_DATA) {
                    list($local_channel, $data) = parse('us', $packet);
                    return $data;

                } else if ($packet_type === SSH_MSG_CHANNEL_EOF) {
                    $this->eof = TRUE;
                    return "";

                } else {
                    throw new Error('Bad packet received from dispatcher.');
                }
            }
        }
    }
}

stream_wrapper_register(ChannelStdin::PROTOCOL, __NAMESPACE__ . '\ChannelStdin');

class ChannelStdout
{
    const PROTOCOL = 'ssh-channelstdout';

    /** @var int remote channel number */
    private $channel;

    /** @var reference */
    private $window_size;

    /** @var PacketProtocol */
    private $protocol;

    /**
     * @param string
     * @param string
     * @param int
     * @param string
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $options = stream_context_get_options($this->context);

        if (!(isset($options[self::PROTOCOL]['channel']) &&
            isset($options[self::PROTOCOL]['window_size_wrapper']) &&
            isset($options[self::PROTOCOL]['window_size_wrapper']->window_size) &&
            isset($options[self::PROTOCOL]['protocol']) &&
            $options[self::PROTOCOL]['protocol'] instanceof PacketProtocol))
        {
            throw new Error('channel, window_size or protocol context option missing ' .
                'or protocol not instance of PacketProtocol');
        }

        $this->channel = $options[self::PROTOCOL]['channel'];
        $this->window_size =& $options[self::PROTOCOL]['window_size_wrapper']->window_size;
        $this->protocol = $options[self::PROTOCOL]['protocol'];

        return TRUE;
    }

    /**
     * @param string
     * @return int
     */
    public function stream_write($data)
    {
        if ($this->window_size < strlen($data)) {
            $this->protocol->send('buu',
                SSH_MSG_CHANNEL_WINDOW_ADJUST,
                $this->channel,
                0x7fffffff);
        }

        $this->protocol->send('bus',
            SSH_MSG_CHANNEL_DATA,
            $this->channel,
            $data);

        $this->window_size -= strlen($data);

        return strlen($data);
    }
}

stream_wrapper_register(ChannelStdout::PROTOCOL, __NAMESPACE__ . '\ChannelStdout');

class ChannelStderr
{
    const PROTOCOL = 'ssh-channelstderr';

    /** @var int remote channel number */
    private $channel;

    /** @var reference */
    private $window_size;

    /** @var PacketProtocol */
    private $protocol;

    /**
     * @param string
     * @param string
     * @param int
     * @param string
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $options = stream_context_get_options($this->context);

        if (!(isset($options[self::PROTOCOL]['channel']) &&
            isset($options[self::PROTOCOL]['window_size_wrapper']) &&
            isset($options[self::PROTOCOL]['window_size_wrapper']->window_size) &&
            isset($options[self::PROTOCOL]['protocol']) &&
            $options[self::PROTOCOL]['protocol'] instanceof PacketProtocol))
        {
            throw new Error('channel, window_size or protocol context option missing ' .
                'or protocol not instance of PacketProtocol');
        }

        $this->channel = $options[self::PROTOCOL]['channel'];
        $this->window_size =& $options[self::PROTOCOL]['window_size_wrapper']->window_size;
        $this->protocol = $options[self::PROTOCOL]['protocol'];

        return TRUE;
    }

    /**
     * @param string
     * @return int
     */
    public function stream_write($data)
    {
        if ($this->window_size < strlen($data)) {
            $this->protocol->send('buu',
                SSH_MSG_CHANNEL_WINDOW_ADJUST,
                $this->channel,
                0x7fffffff);
        }

        $this->protocol->send('buus',
            SSH_MSG_CHANNEL_EXTENDED_DATA,
            $this->channel,
            SSH_EXTENDED_DATA_STDERR,
            $data);

        $this->window_size -= strlen($data);

        return strlen($data);
    }
}

stream_wrapper_register(ChannelStderr::PROTOCOL, __NAMESPACE__ . '\ChannelStderr');
