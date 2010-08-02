<?php
namespace ssh;

class PacketProtocol
{
    /** @var array ($cipher, $mode, $key_length, $block_length) */
    private static $encryption_algorithms = array(
        '3des-cbc' => array(MCRYPT_3DES, MCRYPT_MODE_CBC, 24, 8),
        'blowfish-cbc' => array(MCRYPT_BLOWFISH, MCRYPT_MODE_CBC, 16, 8),
        'aes128-cbc' => array(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC, 16, 16),
        'cast128-cbc' => array(MCRYPT_CAST_128, MCRYPT_MODE_CBC, 16, 16),
        //'none' => NULL,
    );

    /** @var array () */
    private static $compression_algorithms = array(
        'none' => NULL,
    );

    /** @var array ($hash_hmac_algo, $digest_length, $key_length) */
    private static $mac_algorithms = array(
        'hmac-sha1' => array('sha1', 20, 20),
        'hmac-sha1-96' => array('sha1', 12, 20),
        //'hmac-md5' => array('md5', 16, 16),
        //'hmac-md5-96' => array('md5', 12, 16),
        //'none' => NULL,
    );

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /** @var string */
    private $session_id = NULL;

    /** @var array */
    private $queue = array();

    /** @var int */
    private $send_seq = -1;

    /** @var int */
    private $send_block_length = 0;

    /** @var resource */
    private $send_encryption = NULL;

    /** @var string */
    private $send_compress = NULL;

    /** @var string */
    private $send_mac_algo = NULL;

    /** @var int */
    private $send_mac_key = NULL;

    /** @var int */
    private $send_mac_length = 0;

    /** @var int */
    private $receive_seq = -1;

    /** @var int */
    private $receive_block_length = 0;

    /** @var resouce */
    private $receive_decryption = NULL;

    /** @var string */
    private $receive_uncompress = NULL;

    /** @var array */
    private $receive_mac_algo = NULL;

    /** @var string */
    private $receive_mac_key = NULL;

    /** @var int */
    private $receive_mac_length = 0;

    /**
     * Initialize
     * @param resource input
     * @param resource output; optional, if not passed data are written to resource
     * given as first argument
     */
    public function __construct($input /*, $output */)
    {
        $this->input = $input;

        if (func_num_args() < 2) {
            $this->output = $input;

        } else {
            $this->output = func_get_arg(1);
        }
    }

    /**
     * Send packet
     * @param string see format()
     * @return void
     */
    public function send($format)
    {
        ++$this->send_seq;

        $payload = call_user_func_array('ssh\\format', func_get_args());
        $block_length = max($this->send_block_length, 8);

        if ($this->send_compress) {
            $payload = $this->compress($payload);
        }
        $length = 1 + strlen($payload);

        $padlen = $block_length - (($length + 4) % $block_length);
        if ($padlen < 4) {
            $padlen += $block_length;
        }
        $length += $padlen;

        $padding = random($padlen);
        $packet = pack('NCa*a*', $length, $padlen, $payload, $padding);

        $mac = '';
        if ($this->send_mac_length > 0) {
            $mac = substr(
                hash_hmac($this->send_mac_algo, pack('Na*', $this->send_seq, $packet), $this->send_mac_key, TRUE),
                0, $this->send_mac_length);
        }

        if ($this->send_encryption) {
            $packet = mcrypt_generic($this->send_encryption, $packet);
        }

        $data = $packet . $mac;
        for (; strlen($data) > 0 &&
               ($written = fwrite($this->output, $data)) !== FALSE
             ; $data = substr($data, $written));

        if ($written === FALSE) {
            throw new WriteError;
        }
    }

    /**
     * Receive packet
     * @param bool if TRUE next call to receive() will return the same packet
     * @return string
     */
    public function receive($peek = FALSE)
    {
        if (!empty($this->queue)) {
            if ($peek) {
                return reset($this->queue);
            } else {
                return array_shift($this->queue);
            }
        }

        ++$this->receive_seq;

        $block_length = max($this->receive_block_length, 8);

        for ($data = '';
            strlen($data) < $block_length &&
                ($read = fread($this->input, $block_length - strlen($data))) !== FALSE;
            $data .= $read);

        if ($read === FALSE) {
            throw new ReadError;
        }

        if ($this->receive_decryption) {
            $data = mdecrypt_generic($this->receive_decryption, $data);
        }

        list(,$length) = unpack('N', substr($data, 0, 4));
        $data = substr($data, 4);

        $remaining_length = $length - strlen($data);
        if ($remaining_length > 0) {
            for ($remaining = '';
                strlen($remaining) < $remaining_length &&
                    ($read = fread($this->input, $remaining_length - strlen($remaining))) !== FALSE;
                $remaining .= $read);

            if ($read === FALSE) {
                throw new ReadError;
            }

            if ($this->receive_decryption) {
                $remaining = mdecrypt_generic($this->receive_decryption, $remaining);
            }

            $data .= $remaining;
        }

        if ($this->receive_mac_length > 0) {
            $mac = '';
            for ($mac = '';
                strlen($mac) < $this->receive_mac_length &&
                    ($read = fread($this->input, $this->receive_mac_length - strlen($mac))) !== FALSE;
                $mac .= $read);

            if ($read === FALSE) {
                throw new ReadError;
            }

            if (substr(
                    hash_hmac(
                        $this->receive_mac_algo,
                        pack('NNa*', $this->receive_seq, $length, $data),
                        $this->receive_mac_key,
                        TRUE
                    ),
                    0,
                    $this->receive_mac_length)
                !== $mac)
            {
                throw new MacError;
            }
        }

        list(,$padlen) = unpack('C', substr($data, 0, 1));
        $data = substr($data, 1, strlen($data) - $padlen - 1);

        if ($this->receive_uncompress) {
            $data = $this->uncompress($data);
        }

        if ($peek) {
            array_push($this->queue, $data);
        }

        return $data;
    }

    /**
     * @return string
     */
    public function getSessionId()
    {
        return $this->session_id;
    }

    /**
     * @param string
     */
    public function setSessionId($session_id)
    {
        if ($this->session_id !== NULL) {
            throw new Error('Session ID already set.');
        }

        $this->session_id = $session_id;
    }

    /**
     * @return resource
     */
    public function getInputStream()
    {
        return $this->input;
    }

    /**
     * @return resource
     */
    public function getOutputStream()
    {
        return $this->output;
    }

    /**
     * @param array|string
     * @return void
     */
    public function enqueue($packets)
    {
        if (!is_array($packets)) {
            $packets = func_get_args();
        }

        $this->queue = array_merge($this->queue, $packets);
    }

    /**
     * @param string
     * @return string
     */
    private function compress($data)
    {
        return $data;
    }

    /**
     * @param string
     * @return string
     */
    private function uncompress($data)
    {
        return $data;
    }

    /**
     * @param string
     * @param string
     * @param string
     * @return bool
     */
    public function initEncryption($algorithm, $key, $iv)
    {
        list($this->send_encryption, $this->send_block_length) =
            $this->initXcryption($algorithm, $key, $iv);
    }

    /**
     * @param string
     * @param string
     * @param string
     */
    public function initDecryption($algorithm, $key, $iv)
    {
        list($this->receive_decryption, $this->receive_block_length) =
            $this->initXcryption($algorithm, $key, $iv);
    }

    /**
     * @param string
     * @param string
     * @param string
     */
    private function initXcryption($algorithm, $key, $iv)
    {
        if (!isset(self::$encryption_algorithms[$algorithm])) {
            throw new \BadFunctionCallException('Uknown algorithm ' . $algorithm . '.');
        }

        if (self::$encryption_algorithms[$algorithm] === NULL) {
            $module = NULL;
            $block_length = 0;

        } else {
            list($cipher, $mode, $key_length, $block_length) =
                self::$encryption_algorithms[$algorithm];

            if (($module = mcrypt_module_open($cipher, '', $mode, '')) === FALSE) {
                return FALSE;
            }

            if (mcrypt_generic_init($module, $key, $iv) < 0) {
                mcrypt_module_close($module);
                return FALSE;
            }
        }

        return array($module, $block_length);
    }

    /**
     * @param string
     * @return bool
     */
    public function initCompression($algorithm)
    {
        return TRUE;
    }

    /**
     * @param string
     * @return bool
     */
    public function initUncompression($algorithm)
    {
        return TRUE;
    }

    /**
     * @param string
     * @return bool
     */
    public function initSendMac($algorithm, $key)
    {
        if (!isset(self::$mac_algorithms[$algorithm])) {
            return FALSE;
        }

        if (self::$mac_algorithms[$algorithm] === NULL) {
            $this->send_mac_algo = $this->send_mac_key = NULL;
            $this->send_mac_length = 0;
            return TRUE;
        }

        list($this->send_mac_algo, $this->send_mac_length,) =
            self::$mac_algorithms[$algorithm];
        $this->send_mac_key = $key;

        return TRUE;
    }

    /**
     * @param string
     * @return bool
     */
    public function initReceiveMac($algorithm, $key)
    {
        if (!isset(self::$mac_algorithms[$algorithm])) {
            return FALSE;
        }

        if (self::$mac_algorithms[$algorithm] === NULL) {
            $this->receive_mac_algo = $this->receive_mac_key = NULL;
            $this->receive_mac_length = 0;
            return TRUE;
        }

        list($this->receive_mac_algo, $this->receive_mac_length,) =
            self::$mac_algorithms[$algorithm];
        $this->receive_mac_key = $key;

        return TRUE;
    }

    /**
     * @return array
     */
    public function getEncryptionAlgorithms()
    {
        return array_keys(self::$encryption_algorithms);
    }

    /**
     * @param string
     * @return int|NULL
     */
    public function getEncryptionKeyLength($algorithm)
    {
        if (!isset(self::$encryption_algorithms[$algorithm])) {
            return NULL;
        }

        list(,,$key_length,) = self::$encryption_algorithms[$algorithm];
        return $key_length;
    }

    /**
     * @param string
     * @return int|NULL
     */
    public function getEncryptionIVLength($algorithm)
    {
        if (!isset(self::$encryption_algorithms[$algorithm])) {
            return NULL;
        }

        list(,,,$block_length) = self::$encryption_algorithms[$algorithm];
        return $block_length;
    }

    /**
     * @param string
     * @return int|NULL
     */
    public function getMacKeyLength($algorithm)
    {
        if (!isset(self::$mac_algorithms[$algorithm])) {
            return NULL;
        }

        list(,,$key_length) = self::$mac_algorithms[$algorithm];
        return $key_length;
    }

    /**
     * @return array
     */
    public function getCompressionAlgorithms()
    {
        return array_keys(self::$compression_algorithms);
    }

    /**
     * @return array
     */
    public function getMacAlgorithms()
    {
        return array_keys(self::$mac_algorithms);
    }

    /**
     * @return int
     */
    public function getSendSeq()
    {
        return $this->send_seq;
    }

    /**
     * @return int
     */
    public function getReceiveSeq()
    {
        return $this->receive_seq;
    }
}
