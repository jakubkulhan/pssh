<?php
namespace ssh\kex;

use ssh;

class Side
{
    const SERVER = 'server';
    const CLIENT = 'client';

    /** @var string */
    public $type;

    /** @var string */
    public $identification_string;

    /** @var string */
    public $kexinit_packet;

    public function __construct($type, $identification_string = NULL, $kexinit_packet = NULL)
    {
        $this->type = $type;
        $this->identification_string = $identification_string;
        $this->kexinit_packet = $kexinit_packet;
    }
}

class Key
{
    const DSA = 'ssh-dss';
    const DSS = 'ssh-dss';
    const RSA = 'ssh-rsa';

    /** @var string */
    public $type;

    /** @var string PEM formatted private key */
    public $private;

    /** @var string SSH formatted public key*/
    public $public;

    public function __construct($type, $private = NULL, $public = NULL)
    {
        $this->type = $type;
        $this->private = $private;
        $this->public = $public;
    }
}

/**
 * @param ssh\PacketProtocol
 * @param Side
 * @param Side
 * @param mixed array of Key-s if local side is SERVER, string with expected public 
 * key if local side is CLIENT
 */
function kex(ssh\PacketProtocol $protocol, Side $local, Side $remote, $keys)
{
    $send = $local->type . '_to_' . $remote->type;
    $receive = $remote->type . '_to_' . $local->type;

    if ($local->type === Side::CLIENT) {
        $local_pk_algorithms = array('ssh-dss', 'ssh-rsa');

    } else {
        $local_pk_algorithms = array_keys($keys);
    }

    $local->kexinit_packet = ssh\format('brnnnnnnnnnnbu',
        ssh\SSH_MSG_KEXINIT,
        ssh\random(16),
        $local_kex_algorithms = array('diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1'),
        $local_pk_algorithms = array('ssh-dss', 'ssh-rsa'),
        $protocol->getEncryptionAlgorithms(),
        $protocol->getEncryptionAlgorithms(),
        $protocol->getMacAlgorithms(),
        $protocol->getMacAlgorithms(),
        $protocol->getCompressionAlgorithms(),
        $protocol->getCompressionAlgorithms(),
        array(),
        array(),
        0, 0);

    $protocol->send('r', $local->kexinit_packet);

    $remote->kexinit_packet = $remote_kexinit = $protocol->receive();
    $remote_kexinit = substr($remote_kexinit, 17 /* byte + cookie */);

    list($remote_kex_algorithms,
        $remote_pk_algorithms,
        $encryption_algorithms_client_to_server,
        $encryption_algorithms_server_to_client,
        $mac_algorithms_client_to_server,
        $mac_algorithms_server_to_client,
        $compression_algorithms_client_to_server,
        $compression_algorithms_server_to_client,
        $languages_client_to_server,
        $languages_server_to_client,
        $first_kex_packet_follows,
        $reserved) = ssh\parse('nnnnnnnnnnbu', $remote_kexinit);

    $mistakes = 0;

    $mistakes += select_algorithm($kex_algorithm,
        $local_kex_algorithms,
        $remote_kex_algorithms,
        $local, $remote, 'kex');

    $mistakes += select_algorithm($pk_algorithm,
        $local_pk_algorithms,
        $remote_pk_algorithms,
        $local, $remote, 'public key');

    select_algorithm($decryption_algorithm,
        $protocol->getEncryptionAlgorithms(),
        ${'encryption_algorithms_' . $remote->type . '_to_' . $local->type},
        $local, $remote, 'decryption');

    select_algorithm($encryption_algorithm,
        $protocol->getEncryptionAlgorithms(),
        ${'encryption_algorithms_' . $local->type . '_to_' . $remote->type},
        $local, $remote, 'encryption');

    select_algorithm($receive_mac_algorithm,
        $protocol->getMacAlgorithms(),
        ${'mac_algorithms_' . $remote->type . '_to_' . $local->type},
        $local, $remote, 'receive mac');

    select_algorithm($send_mac_algorithm,
        $protocol->getMacAlgorithms(),
        ${'mac_algorithms_' . $local->type . '_to_' . $remote->type},
        $local, $remote, 'send mac');

    select_algorithm($uncompression_algorithm,
        $protocol->getCompressionAlgorithms(),
        ${'compression_algorithms_' . $remote->type . '_to_' . $local->type},
        $local, $remote, 'uncompression');

    select_algorithm($compression_algorithm,
        $protocol->getCompressionAlgorithms(),
        ${'compression_algorithms_' . $local->type . '_to_' . $remote->type},
        $local, $remote, 'compression');

    if ($mistakes > 0 && $first_kex_packet_follows) {
        for (;;) {
            $packet = $protocol->receive();
            list($packet_type) = ssh\parse('b', $packet);

            if ($packet_type === ssh\SSH_MSG_DISCONNECT) {
                list($reason, $description) = ssh\parse('us');
                throw new Disconnected($description, $reason);
            }

            if ($packet_type === ssh\SSH_MSG_KEXDH_INIT) {
                break;
            }
        }
    }

    switch ($kex_algorithm) {
        // http://tools.ietf.org/html/rfc2409#section-6.2
        // http://tools.ietf.org/html/rfc2412#appendix-E.2
        case 'diffie-hellman-group1-sha1':
            $pbin = pack('H*', 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74' .
                 '020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437' .
                 '4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED' .
                 'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE65381FFFFFFFFFFFFFFFF');
            $random_length = 64;
        break;

        // http://tools.ietf.org/html/rfc3526#section-3
        case 'diffie-hellman-group14-sha1':
            $pbin = pack('H*', 'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74' .
                 '020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437' .
                 '4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED' .
                 'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05' .
                 '98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB' .
                 '9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B' .
                 'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF695581718' .
                 '3995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF');
            $random_length = 128;
        break;
    }

    if (!extension_loaded('gmp') && !extension_loaded('bcmath')) {
        throw new ssh\Error('No extension for mpint calculations.');
    }

    if (extension_loaded('gmp')) {
        $p = gmp_init(bin2hex($pbin), 16);

    } else if (extension_loaded('bcmath')) {
        $p = bcdebin($pbin);
    }

    if ($local->type === Side::SERVER) {
        $ybin = ssh\random($random_length);

        list($packet_type, $ebin) = ssh\parse('bm', $protocol->receive());

        if ($packet_type !== ssh\SSH_MSG_KEXDH_INIT) {
            throw new ssh\Error('Expected SSH_MSG_KEXDH_INIT, got #' . $packet_type . '.');
        }

        if (extension_loaded('gmp')) {
            $y = gmp_init(bin2hex($ybin), 16);
            $e = gmp_init(bin2hex($ebin), 16);
            $f = gmp_powm(2, $y, $p);
            $K = gmp_powm($e, $y, $p);
            $fbin = gmp_binval($f);
            $Kbin = gmp_binval($K);

        } else if (extension_loaded('bcmath')) {
            $y = bcdebin($ybin);
            $e = bcdebin($ebin);
            $f = bcpowmod(2, $y, $p);
            $K = bcpowmod($e, $y, $p);
            $fbin = bcbin($f);
            $Kbin = bcbin($K);
        }

        if (!isset($keys[$pk_algorithm])) {
            throw new ssh\Error('I do not have needed ' . $pk_algorithm . ' key.');
        }

        $key = $keys[$pk_algorithm];

        $H = sha1(
            ssh\format('sssssmmm',
                $remote->identification_string, $local->identification_string,
                $remote->kexinit_packet, $local->kexinit_packet,
                $key->public,
                $ebin, $fbin, $Kbin),
            TRUE);

        if ($protocol->getSessionId() === NULL) {
            $protocol->setSessionId($H);
        }

        $signature = ssh\sign($key->private, $H);

        $protocol->send('bsms',
            ssh\SSH_MSG_KEXDH_REPLY,
            $key->public,
            $fbin,
            $signature);

    } else {
        asset($local->type === Side::CLIENT);

        for (;;) {
            $xbin = ssh\random($random_length);
            if (extension_loaded('gmp')) {
                $x = gmp_init(bin2hex($xbin), 16);
                $cmp = gmp_cmp($x, 0);
            } else if (extension_loaded('bcmath')) {
                $x = bcdebin($xbin);
                $cmp = bccomp($x, 0);
            }

            if ($cmp > 0) { // if is x > 0
                break;
            }
        }

        if (extension_loaded('gmp')) {
            $e = gmp_powm(2, $x, $p);
            $ebin = gmp_binval($e);
        } else if (extension_loaded('bcmath')) {
            $e = bcpowmod(2, $x, $p);
            $ebin = bcbin($e);
        }

        $protocol->send('bm', ssh\SSH_MSG_KEXDH_INIT, $e);

        list($packet_type) = ssh\parse('b', $packet = $protocol->receive());
        if ($packet_type !== ssh\SSH_MSG_KEXDH_REPLY) {
            throw new ssh\Error('Expected SSH_MSG_KEXDH_REPLY, got #' . $packet_type . '.');
        }

        list($server_key, $fbin, $signature) = ssh\parse('sms', $packet);

        if ($server_key !== $keys) {
            throw new ssh\Error('Server keys do not match.');
        }

        if (extension_loaded('gmp')) {
            $f = gmp_init(bin2hex($fbin), 16);
            $K = gmp_powm($f, $x, $p);
            $Kbin = gmp_binval($K);

        } else if (extension_loaded('bcmath')) {
            $f = bcdebin($fbin);
            $K = bcpowmod($f, $x, $p);
            $Kbin = bcbin($K);
        }

        $H = sha1(
            ssh\format('sssssmmm',
                $local->identification_string, $remote->identification_string,
                $local->kexinit_packet, $remote->kexinit_packet,
                $server_key,
                $ebin, $fbin, $Kbin),
            TRUE);

        if (!ssh\verify($server_key, $H, $signature)) {
            throw new ssh\Error('Server reply cannot be verified.');
        }

        if ($protocol->getSessionId() === NULL) {
            $protocol->setSessionId($H);
        }
    }

    $iv_client_to_server = sha1(ssh\format('mrbr', $Kbin, $H, ord('A'), $protocol->getSessionId()), TRUE);
    $iv_server_to_client = sha1(ssh\format('mrbr', $Kbin, $H, ord('B'), $protocol->getSessionId()), TRUE);
    $key_client_to_server = sha1(ssh\format('mrbr', $Kbin, $H, ord('C'), $protocol->getSessionId()), TRUE);
    $key_server_to_client = sha1(ssh\format('mrbr', $Kbin, $H, ord('D'), $protocol->getSessionId()), TRUE);
    $mac_key_client_to_server = sha1(ssh\format('mrbr', $Kbin, $H, ord('E'), $protocol->getSessionId()), TRUE);
    $mac_key_server_to_client = sha1(ssh\format('mrbr', $Kbin, $H, ord('F'), $protocol->getSessionId()), TRUE);

    lengthen(${'iv_' . $send},
        $protocol->getEncryptionIVLength($encryption_algorithm),
        $Kbin, $H);
    lengthen(${'iv_' . $receive},
        $protocol->getEncryptionIVLength($decryption_algorithm),
        $Kbin, $H);
    lengthen(${'key_' . $send},
        $protocol->getEncryptionKeyLength($encryption_algorithm),
        $Kbin, $H);
    lengthen(${'key_' . $receive},
        $protocol->getEncryptionKeyLength($decryption_algorithm),
        $Kbin, $H);
    lengthen(${'mac_key' . $send},
        $protocol->getMacKeyLength($send_mac_algorithm),
        $Kbin, $H);
    lengthen(${'mac_key' . $receive},
        $protocol->getMacKeyLength($receive_mac_algorithm),
        $Kbin, $H);

    if ($local->type === Side::SERVER) {
        list($packet_type) = ssh\parse('b', $protocol->receive());
        if ($packet_type !== ssh\SSH_MSG_NEWKEYS) {
            throw new ssh\Error('Expected SSH_MSG_NEWKEYS, got #' . $packet_type . '.');
        }

        $protocol->initDecryption($decryption_algorithm, ${'key_' . $receive}, ${'iv_' . $receive});
        $protocol->initUncompression($uncompression_algorithm);
        $protocol->initReceiveMac($receive_mac_algorithm, ${'mac_key_' . $receive});

        $protocol->send('b', ssh\SSH_MSG_NEWKEYS);

        $protocol->initEncryption($encryption_algorithm, ${'key_' . $send}, ${'iv_' . $send});
        $protocol->initCompression($compression_algorithm);
        $protocol->initSendMac($send_mac_algorithm, ${'mac_key_' . $send});

    } else {
        asset($local->type === Side::CLIENT);

        $protocol->send('b', ssh\SSH_MSG_NEWKEYS);

        $protocol->initEncryption($encryption_algorithm, ${'key_' . $send}, ${'iv_' . $send});
        $protocol->initCompression($compression_algorithm);
        $protocol->initSendMac($send_mac_algorithm, ${'mac_key_' . $send});

        list($packet_type) = ssh\parse('b', $protocol->receive());
        if ($packet_type !== ssh\SSH_MSG_NEWKEYS) {
            throw new ssh\Error('Expected SSH_MSG_NEWKEYS, got #' . $packet_type . '.');
        }

        $protocol->initDecryption($decryption_algorithm, ${'key_' . $receive}, ${'iv_' . $receive});
        $protocol->initUncompression($uncompression_algorithm);
        $protocol->initReceiveMac($receive_mac_algorithm, ${'mac_key_' . $receive});
    }
}

/**
 * @param reference
 * @param array
 * @param array
 * @param Side
 * @param Side
 * @param string
 * @return int mistakes
 */
function select_algorithm(&$ref, array $local_algorithms, array $remote_algorithms, Side $local, Side $remote, $algorithm_type)
{
    list($server_algorithms, $client_algorithms) = array($local_algorithms, $remote_algorithms);

    if ($local->type === Side::CLIENT) {
        list($server_algorithms, $client_algorithms) = array($client_algorithms, $server_algorithms);
    }

    $server_algorithms = array_flip($server_algorithms);
    $mistakes = 0;

    foreach ($client_algorithms as $algorithm) {
        if (isset($server_algorithms[$algorithm])) {
            $ref = $algorithm;
            return $mistakes;
        }

        ++$mistakes;
    }

    throw new Exception('No suitable ' . $algorithm_type . ' algorithm.');
}

/**
 * Convert GMP integer to binary
 * @param resource
 * @return string
 */
function gmp_binval($gmpint)
{
    $hex = gmp_strval($gmpint, 16);
    if (strlen($hex) & 1) {
        $hex = '0' . $hex;
    }

    $bin = pack('H*', $hex);
    if (ord($bin[0]) & 0x80) {
        $bin = "\x00" . $bin;
    }

    return $bin;
}

/**
 * Convert binary to bc integer
 * @param string
 * @return string
 */
function bcdebin($bin)
{
    $dec = '0';

    foreach (str_split($bin) as $base256digit) {
        $dec = bcadd(bcmul($dec, 256), (string) ord($base256digit));
    }

    return $dec;
}

/**
 * Convert bc integer to binary
 * @param string
 * @return string
 */
function bcbin($dec)
{
    $bin = '';

    while (bccomp($dec, 0) > 0) {
        $bin .= chr((int) bcmod($dec, 256));
        $dec = bcdiv($dec, 256, 0);
    }

    $bin = strrev($bin);

    if (ord($bin[0]) & 0x80) {
        $bin = "\x00" . $bin;
    }
}

/**
 * @param reference
 * @param int
 * @param string
 * @param string
 */
function lengthen(&$data, $length, $Kbin, $H)
{
    while (strlen($data) < $length) {
        $data .= sha1(ssh\format('mrr', $Kbin, $H, $data), TRUE);
    }

    if (strlen($data) > $length) {
        $data = substr($data, 0, $length);
    }
}
