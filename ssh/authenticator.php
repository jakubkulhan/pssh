<?php
namespace ssh;

interface Authenticator
{
    /**
     * Authenticate user
     * @param PacketProtocol
     * @param string SSH_MSG_USERAUTH_REQUEST packet without first byte (without 
     * message type)
     * @return bool TRUE if user was successfully authenticater
     */
    function authenticate(PacketProtocol $protocol, $packet);

    /**
     * Returns user name of authenticated user
     * @return string|NULL NULL if user hasn't been authenticated yet or 
     * authentication failed
     */
    function getUser();

    /**
     * Returns service user is authenticated to
     * @return string|NULL NULL if user hasn't been authenticated yet or 
     * authentication failed
     */
    function getService();
}

/**
 * Authenticates user for ssh-connection with public key
 */
class ConnectionPublickeyAuthenticator implements Authenticator
{
    /** @var string */
    private $dir;

    /** @var string */
    private $user;

    /** @var string */
    private $service;

    /**
     * @param string directory with public keys' files (each file is named after 
     * user and contains key in OpenSSH format)
     */
    public function __construct($dir)
    {
        $this->dir = $dir;
    }

    /**
     * @param string
     * @return bool
     */
    public function authenticate(PacketProtocol $protocol, $packet)
    {
        if ($this->user) {
            $protocol->send('bnb', SSH_MSG_USERAUTH_FAILURE, array(), 0);
            return FALSE;
        }

        list($user, $service, $method) = parse('sss', $packet);

        if ($service !== 'ssh-connection' || $method !== 'publickey') {
            $protocol->send('bnb', SSH_MSG_USERAUTH_FAILURE, array('publickey'), 0);
            return FALSE;
        }

        list($signed, $publickey_algorithm, $publickey) = parse('bss', $packet);

        // FIXME: currently on ssh-rsa supported
        if ($publickey_algorithm !== 'ssh-rsa' ||
            (($content = @file_get_contents($this->dir . '/' . $user)) === FALSE))
        {
            $protocol->send('bnb', SSH_MSG_USERAUTH_FAILURE, array('publickey'), 0);
            return FALSE;
        }

        if (!$signed) {
            $protocol->send('bss', SSH_MSG_USERAUTH_PK_OK, $publickey_algorithm, $publickey);
            return FALSE;
        }

        list($signature) = parse('s', $packet);

        list($known_publickey_algorithm, $known_publickey, /* user@host */) = explode(' ', trim($content), 3);
        $known_publickey = base64_decode($known_publickey);

        if (!($known_publickey_algorithm === $publickey_algorithm &&
            $known_publickey === $publickey))
        {
            $protocol->send('bnb', SSH_MSG_USERAUTH_FAILURE, array('publickey'), 0);
            return FALSE;
        }

        $data = format('sbsssbss',
            $protocol->getSessionId(),
            SSH_MSG_USERAUTH_REQUEST,
            $user, $service, 'publickey',
            1, $publickey_algorithm, $publickey);

        if (verify($publickey, $data, $signature)) {
            $protocol->send('b', SSH_MSG_USERAUTH_SUCCESS);
            $this->user = $user;
            $this->service = $service;
            return TRUE;
        }

        return FALSE;
    }

    /**
     * @return string|NULL
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string|NULL
     */
    public function getService()
    {
        return $this->service;
    }
}
