<?php
namespace ssh;

/**
 * Generate signature for given data
 * @param string PEM formatted private key
 * @param string
 * @return string SSH formatted signature
 */
function sign($key, $data)
{
    if (($pkeyid = openssl_pkey_get_private($key)) === FALSE) {
        throw new Error('openssl_pkey_get_private()');
    }

    if (($details = openssl_pkey_get_details($pkeyid)) === FALSE) {
        throw new Error('openssl_pkey_get_details()');
    }

    if (!openssl_sign($data, $signature, $pkeyid)) {
        throw new Error('openssl_sign()');
    }

    if ($details['type'] === OPENSSL_KEYTYPE_DSA) {
        // http://tools.ietf.org/html/draft-ietf-pkix-new-asn1-07#page-33
        //
        // ASN.1 ::= SEQUENCE {
        //               r INTEGER,
        //               s INTEGER
        //           }

        $rlen = ord(substr($signature, 3, 1));
        $r = substr($signature, 4, $rlen);
        $r = ltrim($r, "\x00");
        $r = str_pad($r, 20, "\x00", STR_PAD_LEFT);
        $slen = ord(substr($signature, 4 + $rlen + 1, 1));

        if (strlen($signature) !== 4 + $rlen + 2 + $slen) {
            throw new Error('FIXME');
        }

        $s = substr($signature, 4 + $rlen + 2, $slen);
        $s = ltrim($s, "\x00");
        $s = str_pad($s, 20, "\x00", STR_PAD_LEFT);
        $signature = $r . $s;

        $signature = format('ss', 'ssh-dss', $signature);

    } else {
        assert($details['type'] === OPENSSL_KEYTYPE_RSA);
        $signature = format('ss', 'ssh-rsa', $signature);
    }

    return $signature;
}

/**
 * Check if signature is correct
 * @param string SSH formatted public key
 * @param string
 * @param string
 * @return bool
 */
function verify($key, $data, $signature)
{
    if (($pkeyid = @openssl_pkey_get_public(ssh2pem($key))) === FALSE) {
        throw new Error('Cannot get public key.');
    }

    $details = openssl_pkey_get_details($pkeyid);

    list($keytype, $blob) = parse('ss', $signature);

    if (($details['type'] === OPENSSL_KEYTYPE_DSA && $keytype !== 'ssh-dss') ||
        ($details['type'] === OPENSSL_KEYTYPE_RSA && $keytype !== 'ssh-rsa'))
    {
        throw new Error('Key/signature type mismatch.');
    }

    if ($keytype !== 'ssh-rsa') {
        throw new Error('FIXME: currently only ssh-rsa implemented');
    }

    $verified = @openssl_verify($data, $blob, $pkeyid);
    openssl_pkey_free($pkeyid);

    return $verified === 1;
}

/**
 * Convert SSH public key format to PEM
 * @param string
 * @return string
 * FIXME: support for DSA keys
 */
function ssh2pem($data)
{
    list(,$alg_len) = unpack('N', substr($data, 0, 4));
    $alg = substr($data, 4, $alg_len);

    if ($alg !== 'ssh-rsa') {
        return FALSE;
    }

    list(,$e_len) = unpack('N', substr($data, 4 + strlen($alg), 4));
    $e = substr($data, 4 + strlen($alg) + 4, $e_len);
    list(,$n_len) = unpack('N', substr($data, 4 + strlen($alg) + 4 + strlen($e), 4));
    $n = substr($data, 4 + strlen($alg) + 4 + strlen($e) + 4, $n_len);

    // http://tools.ietf.org/html/rfc3447#appendix-A.1.1
    // http://tools.ietf.org/html/rfc2313#section-11
    //
    // ASN.1 ::= SEQUENCE {
    //               algid SEQUENCE {
    //                   id OBJECT IDENTIFIER,
    //                   null NULL
    //               },
    //               data BIT STRING {
    //                   modulus INTEGER, -- n
    //                   exponent INTEGER -- e
    //               }
    //           }

    $algid = pack('H*', '06092a864886f70d0101010500');                        // algorithm identifier (id, null)
    $algid = pack('Ca*a*', 0x30, asn1len($algid), $algid);                    // wrap it into sequence
    $data = pack('Ca*a*Ca*a*', 0x02, asn1len($n), $n, 0x02, asn1len($e), $e); // numbers
    $data = pack('Ca*a*', 0x30, asn1len($data), $data);                       // wrap it into sequence
    $data = "\x00" . $data;                                                   // don't know why, but needed
    $data = pack('Ca*a*', 0x03, asn1len($data), $data);                       // wrap it into bitstring
    $data = $algid . $data;                                                   // prepend algid
    $data = pack('Ca*a*', 0x30, asn1len($data), $data);                       // wrap it into sequence

    return "-----BEGIN PUBLIC KEY-----\n" .
           chunk_split(base64_encode($data), 64, "\n") .
           "-----END PUBLIC KEY-----\n";
}

/**
 * Returns ASN.1 formatted length for given string
 * @param string
 * @return string
 */
function asn1len($s)
{
    $len = strlen($s);

    if ($len < 0x80) {
        return chr($len);
    }

    $data = dechex($len);
    $data = pack('H*', (strlen($data) & 1 ? '0' : '') . $data);
    return chr(strlen($data) | 0x80) . $data;
}
