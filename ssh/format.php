<?php
namespace ssh;


/**
 * Format
 *
 * First arguments specifies how will data be formatted:
 *
 *     b - byte, boolean
 *     u - uint32
 *     s - string
 *     m - mpint
 *     n - name-list
 *     r - raw data
 *
 * For example to create SSH_MSG_DISCONNECT packet:
 *
 *     $disconnect_packet = format('buss', SSH_MSG_DISCONNECT, SSH_DISCONNECT_BY_APPLICATION, 'description', '');
 *
 * @param string
 * @return string
 */
function format($format)
{
    $data = '';
    $args = func_get_args();
    array_shift($args);
    $ret = '';

    if (count($args) !== strlen($format)) {
        throw new \BadFunctionCallException('Expected ' . strlen($format) . ' arguments, got ' . count($args) . '.');
    }

    foreach (str_split($format) as $c) {
        $arg = array_shift($args);

        switch ($c) {
            case 'b':
                $ret .= pack('C', intval($arg));
            break;

            case 'u':
                $ret .= pack('N', intval($arg));
            break;

            case 'n':
                $arg = implode(',', (array) $arg);
            case 's':
            case 'm':
                $ret .= pack('Na*', strlen((string) $arg), (string) $arg);
            break;

            case 'r':
                $ret .= (string) $arg;
            break;

            default:
                throw new \BadFunctionCallException('Unknown formatter ' . $c . '.');
        }
    }

    return $ret;
}

/**
 * Extract formatted data and remove then from given string
 * @see format()
 * @param string
 * @param string
 * @return array|NULL
 */
function parse($format, &$data)
{
    $ret = array();

    foreach (str_split($format) as $c) {
        switch ($c) {
            case 'b':
                if (strlen($data) < 1) {
                    throw new \UnderflowException('Not enough data.');
                }

                list(,$ret[]) = unpack('C', $data);
                $data = substr($data, 1);
            break;

            case 'u':
                if (strlen($data) < 4) {
                    throw new \UnderflowException('Not enough data.');
                }

                list(,$ret[]) = unpack('N', $data);
                $data = substr($data, 4);
            break;

            case 's':
            case 'm':
            case 'n':
                if (strlen($data) < 4) {
                    throw new \UnderflowException('Not enough data.');
                }

                list(,$length) = unpack('N', $data);
                $data = substr($data, 4);

                if (strlen($data) < $length) {
                    throw new \UnderflowException('Not enough data.');
                }

                $ret[] = substr($data, 0, $length);
                $data = substr($data, $length);

                if ($c === 'n') {
                    $ret[count($ret) - 1] = explode(',', $ret[count($ret) - 1]);
                }
            break;

            default:
                throw new \BadFunctionCallException('Unknown formatter ' . $c . '.');
        }
    }

    return $ret;
}
