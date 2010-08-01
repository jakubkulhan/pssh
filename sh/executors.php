<?php
namespace sh;

interface Executor
{
    function exec(array $argv, array $envp, array $descriptors);
}

/**
 * Executes functions from given namespace
 */
class PhpExecutor implements Executor
{
    /** @var string */
    private $namespace;

    /** @var array */
    private $rewrites;

    /**
     * @param string
     * @param array
     */
    public function __construct($namespace, array $rewrites = array())
    {
        $this->namespace = trim($namespace, '\\');
        $this->rewrites = $rewrites;
    }

    /**
     * @param array
     * @param array
     * @param array
     */
    public function exec(array $argv, array $envp, array $descriptors)
    {
        if (!function_exists($fn = '\\' . $this->namespace . '\\' .
                (isset($this->rewrites[$argv[0]]) ? $this->rewrites[$argv[0]] : $argv[0]))) {
            return 127;
        }

        return $fn($argv, $envp, $descriptors);
    }
}
