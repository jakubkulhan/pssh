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
        $fn = '\\' . $this->namespace . '\\';

        if (isset($this->rewrites[$argv[0]])) {
            $fn .= $this->rewrites[$argv[0]];

        } else if ($argv[0] === '[') {
            $fn .= 'test';

        } else if (!function_exists($fn . $argv[0]) &&
            function_exists($fn . $argv[0] . '_'))
        {
            $fn .= $argv[0] . '_';

        } else if (!function_exists($fn . $argv[0]) &&
            function_exists($fn . str_replace('-', '_', $argv[0])))
        {
            $fn .= str_replace('-', '_', $argv[0]);

        } else {
            $fn .= $argv[0];
        }

        if (!function_exists($fn)) {
            return 127;
        }

        return $fn($argv, $envp, $descriptors);
    }
}
