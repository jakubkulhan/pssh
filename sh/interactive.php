<?php
namespace sh;

class InteractiveOutput
{
    const PROTOCOL = 'sh-interactiveoutput';

    /** @var resource */
    private $handle;

    /**
     * @param string
     * @param string
     * @param int
     * @param string
     */
    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $options = stream_context_get_options($this->context);

        if (!isset($options[self::PROTOCOL]['handle'])) {
            throw new Error('no handle given');
        }

        $this->handle = $options[self::PROTOCOL]['handle'];

        return TRUE;
    }

    /**
     * @param string
     * @return int
     */
    public function stream_write($data)
    {
        fwrite($this->handle, str_replace(array("\r\n", "\r", "\n"), "\r\n", $data));
        return strlen($data);
    }
}

stream_wrapper_register(InteractiveOutput::PROTOCOL, __NAMESPACE__ . '\InteractiveOutput');

class InteractiveInput
{
    const PROTOCOL = 'sh-interactiveinput';

    /** @var resource */
    private $handle;

    /** @var resource */
    private $out;

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

        if (!(isset($options[self::PROTOCOL]['handle']) &&
            isset($options[self::PROTOCOL]['out'])))
        {
            throw new Error('no handle or out given');
        }

        $this->handle = $options[self::PROTOCOL]['handle'];
        $this->out = $options[self::PROTOCOL]['out'];

        return TRUE;
    }

    /**
     * @return bool
     */
    public function stream_eof()
    {
        return $this->eof || feof($this->handle);
    }

    /**
     * @param int
     * @return string
     */
    public function stream_read($n)
    {
        if ($this->eof || feof($this->handle)) {
            return '';
        }

        $c = fread($this->handle, 1);

        // FIXME
        if (ord($c) === 0x04) {
            $this->eof = TRUE;
            return '';

        } else if (ord($c) === 0x0d) {
            $c = "\n";
        }

        fwrite($this->out, $c === "\n" ? "\r\n" : $c);

        return $c;
    }
}

stream_wrapper_register(InteractiveInput::PROTOCOL, __NAMESPACE__ . '\InteractiveInput');
