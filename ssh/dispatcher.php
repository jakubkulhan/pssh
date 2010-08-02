<?php
namespace ssh;

class Dispatcher
{
    /** @var PacketProtocol */
    private $protocol;

    /** @var array */
    private $callbacks = array();

    /**
     * @param PacketProtocol
     */
    public function __construct(PacketProtocol $protocol)
    {
        $this->protocol = $protocol;
    }

    /**
     * @return PacketProtocol
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * Registers callback
     * @param int SSH message type
     * @param callback|NULL if NULL dispatcher will ignore messages of given type
     *                      and won't send SSH_MSG_UNIMPLEMENTED,
     *                      otherwise packet without leading byte (that contains 
     *                      message type) will be passed to function
     */
    public function on($type, $callback)
    {
        if ($callback !== NULL && !is_callable($callback)) {
            throw new Error('Given callback is not callable.');
        }

        $this->callbacks[$type] = $callback;
    }

    /**
     * Dispath one message
     * @param callback if not NULL, each packet will be processed with this function;
     *                 in case that function returns TRUE, packet won't be processed
     *                 by dispather, but will be returned;
     *                 function has to accept one argument (whole packet /with 
     *                 leading packet type byte) and return boolean
     *
     * @return string|NULL string if packet was catched by return filter, NULL 
     *                     otherwise
     */
    public function dispatch($return_filter = NULL)
    {
        if ($return_filter !== NULL && !is_callable($return_filter)) {
            throw new Error('Given callback is not callable.');
        }

        $packet = $this->protocol->receive();

        if ($return_filter !== NULL) {
            if ($return_filter($packet)) {
                return $packet;
            }
        }

        list($packet_type) = parse('b', $packet);

        if (!isset($this->callbacks[$packet_type])) {
            $this->protocol->send('bu', SSH_MSG_UNIMPLEMENTED, $this->protocol->getReceiveSeq());
            return NULL;
        }

        $callback = $this->callbacks[$packet_type];

        if ($callback === NULL) {
            return NULL;
        }

        $callback($packet);

        return NULL;
    }
}
