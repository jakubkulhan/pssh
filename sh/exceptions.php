<?php
namespace sh;

class Error extends \Exception
{
}

class SyntaxError extends Error
{
}

class BuiltinBreak extends \Exception
{
    public function getN()
    {
        return intval($this->message);
    }

    public function setN($n)
    {
        $this->message = (string) $n;
    }
}

class BuiltinContinue extends \Exception
{
    public function getN()
    {
        return intval($this->message);
    }

    public function setN($n)
    {
        $this->message = (string) $n;
    }
}

class BuiltinExit extends \Exception
{
    public function getExitStatus()
    {
        return intval($this->message);
    }
}

class BuiltinReturn extends \Exception
{
    public function getExitStatus()
    {
        return intval($this->message);
    }
}
