<?php
namespace ssh;

class Error extends \Exception
{
}

class WriteError extends Error
{
}

class ReadError extends Error
{
}

class MacError extends Error
{
}

class Disconnected extends \Exception
{
}
