<?php
namespace ssh\utils;

class StreamedResponse
{
    const PROTOCOL = 'ssh-utils-streamedresponse';

    /**
     * @param string
     * @param string
     * @param int
     * @param string
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $opened_path = $path;
        return TRUE;
    }

    /**
     * @param string
     * @return int
     */
    public function stream_write($data)
    {
        echo "\x01" . $data;
        flush();
        return strlen($data);
    }

    /**
     * @param string
     * @return int
     */
    public function stream_flush()
    {
        echo "\x00";
        flush();
        return TRUE;
    }
}

stream_wrapper_register(StreamedResponse::PROTOCOL, 'ssh\utils\StreamedResponse');
