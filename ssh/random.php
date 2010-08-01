<?php
namespace ssh;

/**
 * Generate some (pseudo-)random bytes
 * @param int
 * @return string
 */
function random($length)
{
    if (($random = openssl_random_pseudo_bytes($length)) === FALSE) {
        throw new Error('openssl_random_pseudo_bytes()');
    }

    return $random;
}
