<?php
final class flags implements ArrayAccess, IteratorAggregate
{
    /** @var bool */
    private $parsed = FALSE;

    /** @var array */
    private $rest;

    /** @var string */
    private $module = NULL;

    /** @var array */
    private $modules = array();

    /** @return void */
    private function __construct() {}

    /**
     * Create flag instance
     * @param callback
     * @return self
     */
    public static function define($callback)
    {
        $instance = new self;
        $instance->module(NULL);
        call_user_func($callback, $instance);
        $instance->module(NULL);
        return $instance;
    }

    /**
     * Process command line arguments
     * @param self
     * @return array
     * */
    public static function process($instance)
    {
        $ret = $instance->parse();
        if (!$ret[0]) {
            return $ret;
        }

        return array(TRUE, NULL, NULL, $instance);
    }

    /**
     * Generate usage message
     * @return string
     */
    public function generateUsage()
    {
        if (is_file($_SERVER['argv'][0]) && !is_executable($_SERVER['argv'][0])) {
            $linestart = 'php ' . $_SERVER['argv'][0] . ' --';
        } else {
            $linestart = $_SERVER['argv'][0];
        }

        $line = $linestart;
        $more = '';

        foreach ($this->modules[NULL]->usage as $usage) {
            $params = !empty($usage->params) ? ' <' . implode('>, <', $usage->params) . '>' : '';
            $line .= ' [ -' . $usage->short . $params . ' ]';
            $more .= "\n    -" . $usage->short . $params . ', --' . $usage->long . $params . "\n" .
                '        ' . implode("\n        ", explode("\n", wordwrap($usage->text, 69))) . "\n";
        }

        if (count($this->modules) > 1) {
            $line .= ' <module> [ <module-args> ]';
            $modules = $this->modules;
            unset($modules[NULL]);

            $more .= "\n";
            foreach ($modules as $name => $module) {
                $more .= "\n$linestart $name";

                foreach ($module->usage as $usage) {
                    $params = !empty($usage->params) ? ' <' . implode('>, <', $usage->params) . '>' : '';
                    $more .= "\n    -" . $usage->short . $params . ', --' . $usage->long . $params . "\n" .
                        '        ' . implode("\n        ", explode("\n", wordwrap($usage->text, 69))) . "\n";
                }
            }
        }

        return $line . "\n" . $more;
    }

    /** @return string */
    public function getProgramName()
    {
        return $_SERVER['argv'][0];
    }

    /** @return array */
    public function getRest()
    {
        $this->checkParsed();
        return $this->rest;
    }

    /** @return string */
    public function getModule()
    {
        $this->checkParsed();
        return $this->module;
    }

    /** @return string */
    public function getModuleFlags()
    {
        $this->checkParsed();
        return $this->modules[$this->module]->values;
    }

    /**
     * Set module
     * @param string
     */
    public function module($module)
    {
        $this->checkNotParsed();

        if ($module !== NULL) {
            $module = $this->flagname($module);

            if (isset($this->modules[$module])) {
                throw new LogicException('Module ' . $module . ' already defined.');
            }
        }

        if (!isset($this->modules[$module])) {
            $this->modules[$module] = (object) array(
                'short' => array(),
                'long' => array(),
                'usage' => array(),
                'values' => array(),
            );
        }

        $this->module = $module;
    }

    /**
     * Add bool flag
     * @param string
     * @param bool
     * @param string
     */
    public function bool($name, $usage = NULL, $default = FALSE)
    {
        $this->modules[$this->module]->values[$name] = NULL;
        return $this->boolVar($this->modules[$this->module]->values[$name], $name, $usage);
    }

    /**
     * Add bool flag
     * @param string
     * @param string
     */
    public function boolVar(&$var, $name, $usage = NULL, $default = FALSE)
    {
        return $this->add(
            $var,
            $name, (bool) $default,
            array(), $usage,
            array(__CLASS__, 'parseBool'), NULL
        );
    }

    /**
     * Add int flag
     * @param string
     * @param string
     * @param int
     */
    public function int($name, $usage = NULL, $default = NULL)
    {
        $this->modules[$this->module]->values[$name] = NULL;
        return $this->intVar($this->modules[$this->module]->values[$name], $name, $usage, $default);
    }

    /**
     * Add int flag
     * @param mixed
     * @param string
     * @param string
     */
    public function intVar(&$var, $name, $usage = NULL, $default = NULL)
    {
        return $this->add(
            $var,
            $name, intval($default),
            array($this->flagname($name)), $usage,
            array(__CLASS__, 'parseOneArgument'), 'intval'
        );
    }

    /**
     * Add float flag
     * @param string
     * @param string
     * @param int
     */
    public function float($name, $usage = NULL, $default = NULL)
    {
        $this->modules[$this->module]->values[$name] = NULL;
        return $this->floatVar($this->modules[$this->module]->values[$name], $name, $usage, $default);
    }

    /**
     * Add float flag
     * @param mixed
     * @param string
     * @param string
     */
    public function floatVar(&$var, $name, $usage = NULL, $default = NULL)
    {
        return $this->add(
            $var,
            $name, floatval($default),
            array($this->flagname($name)), $usage,
            array(__CLASS__, 'parseOneArgument'), 'floatval'
        );
    }

    /**
     * Add string flag
     * @param string
     * @param string
     * @param int
     */
    public function string($name, $usage = NULL, $default = NULL)
    {
        $this->modules[$this->module]->values[$name] = NULL;
        return $this->stringVar($this->modules[$this->module]->values[$name], $name, $usage, $default);
    }

    /**
     * Add float flag
     * @param mixed
     * @param string
     * @param string
     */
    public function stringVar(&$var, $name, $usage = NULL, $default = NULL)
    {
        return $this->add(
            $var,
            $name, (string) $default,
            array($this->flagname($name)), $usage,
            array(__CLASS__, 'parseOneArgument'), NULL
        );
    }

    /**
     * Add flag
     * @param mixed
     * @param string
     * @param mixed
     * @param array
     * @param callback
     * @param callback
     */
    public function add(&$var, $name, $default, array $params, $usage, $parse, $filter)
    {
        $this->checkNotParsed();

        $var = $default;
        $short = $name[0];
        $long = $this->flagname($name);

        if (isset($this->modules[$this->module]->short[$short])) {
            throw new LogicException('Flag -' . $short .
                ($this->module !== NULL ? ' in module ' . $this->module : '') .
                ' already defined.');
        }

        if (isset($this->modules[$this->module]->long[$long])) {
            throw new LogicException('Flag --' . $long .
                ($this->module !== NULL ? ' in module ' . $this->module : '') .
                ' already defined.');
        }

        if (!is_callable($parse)) {
            throw new LogicException('Given parse callback not callable.');
        }

        if ($filter !== NULL && !is_callable($filter)) {
            throw new LogicException('Given filter callback not callable.');
        }

        $this->modules[$this->module]->short[$short] = array(&$var, $parse, $filter);
        $this->modules[$this->module]->long[$long] = array(&$var, $parse, $filter);

        if ($usage !== NULL) {
            $this->modules[$this->module]->usage[] = (object) array(
                'short' => $short,
                'long' => $long,
                'params' => $params,
                'text' => $usage,
            );
        }
    }

    /** @return array */
    private function parse()
    {
        $this->checkNotParsed();

        $args = new ArrayIterator(array_slice($_SERVER['argv'], 1));

        $ret = $this->parseFlags(NULL, $args);
        if (!$ret[0]) {
            $this->parsed = TRUE;
            return $ret;
        }

        $this->module = NULL;
        if (count($this->modules) > 1 && $args->valid()) {
            $this->module = $args->current();
            $args->next();

            if (!isset($this->modules[$this->module])) {
                $this->parsed = TRUE;
                return array(FALSE, $this->module, NULL, NULL);
            }

            $ret = $this->parseFlags($this->module, $args);
        }

        while ($args->valid()) {
            $this->rest[] = $args->current();
            $args->next();
        }

        $this->parsed = TRUE;
        return $ret;
    }

    /**
     * Parses flags from given arguments
     * @param string
     * @param Iterator
     */
    private function parseFlags($module, Iterator $args)
    {
        while ($args->valid()) {
            $arg = $args->current();

            if (!($arg !== '--' && $arg[0] === '-' && isset($arg[1]))) {
                break;

            } else {
                if ($arg[1] === '-') {
                    $names = array(substr($arg, 2));
                    $opts = $this->modules[$module]->long;
                } else {
                    $names = str_split(substr($arg, 1));
                    $opts = $this->modules[$module]->short;
                }

                for ($i = 0, $c = count($names), $last = $c - 1; $i < $c; ++$i) {
                    $name = $names[$i];

                    if (!isset($opts[$name])) {
                        return array(FALSE, $module, $name, NULL);
                    }

                    list($ok, $opts[$name][0]) = call_user_func(
                        $opts[$name][1],
                        $i === $last ? $args : new ArrayIterator(array())
                    );

                    if (!$ok) {
                        return array(FALSE, $module, $name, NULL);
                    }

                    if ($opts[$name][2] !== NULL) {
                        $opts[$name][0] = call_user_func($opts[$name][2], $opts[$name][0]);
                    }
                }
            }

            $args->next();
        }

        return array(TRUE, NULL, NULL, NULL);
    }

    /**
     * Flag is present
     * @param Iterator
     * @return array [ok, result]
     */
    public static function parseBool(Iterator $args)
    {
        return array(TRUE, TRUE);
    }

    /**
     * Get value of flag
     * @param Iterator
     * @return string
     */
    public static function parseOneArgument(Iterator $args)
    {
        $args->next();

        if (!$args->valid()) {
            return array(FALSE, NULL);
        }

        return array(TRUE, $args->current());
    }

    /**
     * Get parsed flag value
     * @param string
     * @return mixed
     */
    public function offsetGet($flagname)
    {
        $this->checkParsed();
        return $this->modules[NULL]->values[$flagname];
    }

    /**
     * Get parsed flag value
     * @param string
     * @return mixed
     */
    public function __get($flagname)
    {
        return $this->offsetGet($flagname);
    }

    /**
     * Check if flag exists
     * @param string
     * @return bool
     */
    public function offsetExists($flagname)
    {
        $this->checkParsed();
        return isset($this->modules[NULL]->values[$flagname]);
    }

    /**
     * Check if flag exists
     * @param string
     * @return bool
     */
    public function __isset($flagname)
    {
        return $this->offsetExists($flagname);
    }

    /**
     */
    public function offsetSet($flagname, $value)
    {
        $this->checkParsed();
        throw new LogicException('You cannot change parsed flags.');
    }

    /**
     */
    public function offsetUnset($flagname)
    {
        $this->checkParsed();
        throw new LogicException('You cannot change parsed flags.');
    }

    /**
     * Iterate over values
     * @return Iterator
     */
    public function getIterator()
    {
        $this->checkParsed();
        return new ArrayIterator($this->modules[NULL]->values);
    }

    /** @return bool */
    private function checkParsed()
    {
        if (!$this->parsed) {
            throw new LogicException('Flags hasn\'t been parsed yet.');
        }

        return TRUE;
    }

    /** @return bool */
    private function checkNotParsed()
    {
        if ($this->parsed) {
            throw new LogicException('Flags has been already parsed.');
        }

        return TRUE;
    }

    /**
     * @param string
     * @return string
     */
    private function flagname($name)
    {
        return str_replace('_', '-', $name);
    }
}
