<?php
namespace sh;

class sh
{
    const DEFAULT_PS1 = '$ ';

    const DEFAULT_PS2 = '> ';

    /** @var array */
    private $argv;

    /** @var array */
    private $envp;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var array */
    private $descriptors;

    /** @var Executor */
    private $executor;

    /** @var bool */
    private $toplevel;

    /** @var bool */
    private $interactive;

    /** @var array */
    private $history = array();

    /** @var string */
    private $previous_command;

    /** @var array */
    private $tokens = array();

    /** @var bool */
    private $can_read = TRUE;

    /** @var array */
    private $variables = array();

    /** @var array */
    private $exported = array();

    /** @var array */
    private $functions = array();

    /**
     * Initialize
     * @param array
     * @param array
     * @param array
     * @param Executor
     * @param bool
     */
    public function __construct(array $argv, array $envp, array $descriptors, Executor $executor, $interactive = NULL)
    {
        $this->argv = $argv;
        $this->envp = $envp;

        if (!isset($this->envp['PS1'])) {
            $this->envp['PS1'] = self::DEFAULT_PS1;
        }

        if (!isset($this->envp['PS2'])) {
            $this->envp['PS2'] = self::DEFAULT_PS2;
        }

        $this->descriptors = $descriptors;
        list($this->stdin, $this->stdout, $this->stderr) = $this->descriptors;

        $this->executor = $executor;

        if (!is_bool($interactive)) {
            $interactive = FALSE;

            if (function_exists('posix_isatty') && @posix_isatty($descriptors[0])) {
                $interactive = TRUE;
            }
        }

        $this->interactive = $interactive;

        if ($this->interactive) {
            $this->descriptors[1] = fopen('sh-interactiveoutput://', NULL, FALSE,
                stream_context_create(array('sh-interactiveoutput' => array(
                    'handle' => $this->descriptors[1],
                ))));
            $this->descriptors[2] = fopen('sh-interactiveoutput://', NULL, FALSE,
                stream_context_create(array('sh-interactiveoutput' => array(
                    'handle' => $this->descriptors[2],
                ))));
        }
    }

    /** @return int */
    public function main()
    {
        try {
            $this->variables['#'] = count($this->argv) - 1;

            foreach ($this->argv as $k => $v) {
                $this->variables[$k] = $v;
            }

            for (;;) {
                try {
                    $this->interpretMoreCommands(array(), FALSE, TRUE);

                    if (!$this->interactive) {
                        break;
                    }

                } catch (SyntaxError $e) {
                    if (!$this->interactive) {
                        throw $e;
                    }

                    fwrite($this->stderr, "sh: syntax error\r\n");
                    $this->tokens = array();

                } catch (\Exception $e) {
                    throw $e;
                }
            }

            return isset($this->variables['?']) ? $this->variables['?'] : 0;

        } catch (BuiltinExit $e) {
            return $e->getExitStatus();

        } catch (BuiltinReturn $e) {
            return $e->getExitStatus();

        } catch (\Exception $e) {
            fwrite($this->stderr, $e);
            return 255;
        }
    }

    /**
     * @param string
     * @return int
     */
    public function exec($command)
    {
        try {
            $this->variables['#'] = count($this->argv) - 1;

            foreach ($this->argv as $k => $v) {
                $this->variables[$k] = $v;
            }

            $interactive = $this->interactive;
            $this->interactive = FALSE;

            $can_read = $this->can_read;
            $this->can_read = FALSE;

            $tokens = $this->tokens;
            $this->tokens = $this->tokenize($command);

            $this->interpretMoreCommands();

            $this->interactive = $interactive;
            $this->can_read = $can_read;
            $this->tokens = $tokens;

            return isset($this->variables['?']) ? $this->variables['?'] : 0;

        } catch (BuiltinExit $e) {
            return $e->getExitStatus();

        } catch (BuiltinReturn $e) {
            return $e->getExitStatus();

        } catch (\Exception $e) {
            fwrite($this->stderr, $e);
            return 255;
        }
    }

    /**
     * @param array
     * @param array
     * @param array
     * @return int
     * */
    public function _exec(array $argv, array $envp, array $redirections)
    {
        $descriptors = $this->descriptors;

        if ($this->interactive) {
            $descriptors[0] = fopen('sh-interactiveinput://', NULL, FALSE,
                stream_context_create(array('sh-interactiveinput' => array(
                    'handle' => $this->stdin,
                    'out' => $this->stdout,
                ))));
        }

        foreach ($redirections as $n => $redirection) {
            if (is_array($redirection)) {
                if (($handle = @fopen($redirection[1], $redirection[0])) === FALSE) {
                    throw new Error('cannot open file ' . $redirection[1]);
                }

                $descriptors[$n] = $handle;
            } else {
                assert(is_int($redirection));
                if (!isset($descriptors[$redirection])) {
                    throw new Error('bad file descriptor ' . $redirection);
                }
                $descriptors[$n] = $descriptors[$redirection];
            }
        }

        // BREAK
        if ($argv[0] === 'break') {
            $n = 1;
            if (isset($argv[1])) {
                $n = intval($argv[1]);
            }

            throw new BuiltinBreak($n);


        // COLON
        } else if ($argv[0] === ':') {
            $this->variables['?'] = 0;

        } else if ($argv[0] === 'continue') {
            $n = 1;
            if (isset($argv[1])) {
                $n = intval($argv[1]);
            }

            throw new BuiltinContinue($n);


        // DOT
        } else if ($argv[0] === '.') {
            if (!(isset($argv[1]) && ($contents = file_get_contents($argv[1])) !== FALSE)) {
                if (!isset($argv[1])) {
                    throw new Error('.: no file given');
                } else {
                    throw new Error('.: cannot read file');
                }
            }

            $saved_can_read = $this->can_read;
            $this->can_read = FALSE;

            $saved_tokens = $this->tokens;
            $this->tokens = $this->tokenize($contents);

            $saved_descriptors = $this->descriptors;
            $this->descriptors = $descriptors;

            try {
                $this->interpretMoreCommands();

            } catch (BuiltinReturn $r) {
                $this->variables['?'] = $r->getExitStatus();
            }

            $this->can_read = $saved_can_read;
            $this->tokens = $saved_tokens;
            $this->descriptors = $saved_descriptors;


        // EVAL
        } else if ($argv[0] === 'eval') {
            $newtokens = $this->tokenize(implode(' ', array_slice($argv, 1)));
            if (end($newtokens) === ';') {
                array_pop($newtokens);
            }
            $this->tokens = array_merge(array(';'), $newtokens, $this->tokens);


        // EXEC
        } else if ($argv[0] === 'exec') {
            if (count($argv) > 1) {
                fwrite($descriptors[2], "exec: only file descriptors handling implemented\n");
                $this->variables['?'] = 255;
            } else {
                $this->descriptors = $descriptors;
                $this->variables['?'] = 0;
            }


        // EXIT
        } else if ($argv[0] === 'exit') {
            $n = 0;
            if (isset($argv[1])) {
                $n = intval($argv[1]);
            }

            throw new BuiltinExit($n);


        // EXPORT
        } else if ($argv[0] === 'export') {
            if (isset($argv[1]) && $argv[1] === '-p') {
                foreach ($this->exported as $exported => $_) {
                    if (isset($this->variables[$exported])) {
                        fprintf($descriptors[1], "export %s='%s'\n", $exported, $this->variables[$exported]);
                    } else {
                        fprintf($descriptors[1], "export %s\n", $exported);
                    }
                }
            } else {
                foreach (array_slice($argv, 1) as $variable) {
                    if (strpos($variable, '=') !== FALSE) {
                        list($variable, $value) = explode('=', $variable, 2);
                        $this->variables[$variable] = $value;
                    }

                    $this->exported[$variable] = TRUE;
                }
            }

            $this->variables['?'] = 0;


        // READONLY
        } else if ($argv[0] === 'readonly') {
            fwrite($descriptors[2], "readonly: currently not supported\n");
            $this->variables['?'] = 255;


        // RETURN
        } else if ($argv[0] === 'return') {
            $n = 0;
            if (isset($argv[1])) {
                $n = intval($argv[1]);
            }

            throw new BuiltinReturn($n);


        // SET
        } else if ($argv[0] === 'set') {
            fwrite($descriptors[2], "set: currently not supported\n");
            $this->variables['?'] = 255;


        // SHIFT
        } else if ($argv[0] === 'shift') {
            $n = 1;
            if (isset($argv[1])) {
                $n = intval($argv[1]);
            }

            for ($i = 1, $c = count($this->argv); $i < $c; ++$i) {
                unset($this->variables[$i]);
            }

            foreach (array_slice($this->argv, count($this->argv) - $this->variables['#'] + $n) as $i => $arg) {
                $this->variables[$i + 1] = $arg;
            }

            $this->variables['#'] = $this->variables['#'] - $n;
            $this->variables['?'] = 0;


        // TIMES
        } else if ($argv[0] === 'times') {
            fwrite($descriptors[2], "times: currently not supported\n");
            $this->variables['?'] = 255;


        // TRAP
        } else if ($argv[0] === 'trap') {
            fwrite($descriptors[2], "trap: currently not supported\n");
            $this->variables['?'] = 255;


        // UNSET
        } else if ($argv[0] === 'unset') {
            if (count($argv) < 2) {
                fwrite($descriptors[2], "unset: [-fv] name...\n");
                $this->variables['?'] = 255;
            } else {
                if ($argv[1] === '-f') {
                    foreach (array_slice($argv, 2) as $function) {
                        unset($this->functions[$function]);
                    }
                } else {
                    $variables = array_slice($argv, 1);
                    if ($argv[1] === '-v') {
                        $variables = array_slice($argv, 2);
                    }

                    foreach ($variables as $variable) {
                        unset($this->variables[$variable]);
                    }
                }

                $this->variables['?'] = 0;
            }


        // FUNCTION?
        } else if (isset($this->functions[$argv[0]])) {
            $saved_argv = $this->argv;
            $this->argv = $argv;

            $saved_envp = $this->envp;
            $this->envp = $envp;

            $saved_variables = $this->variables;
            $this->variables = array();

            $this->variables['#'] = count($argv) - 1;
            foreach ($argv as $k => $v) {
                $this->variables[$k] = $v;
            }

            $saved_can_read = $this->can_read;
            $this->can_read = FALSE;

            $saved_tokens = $this->tokens;
            $this->tokens = $this->functions[$argv[0]];

            $this->interpretMoreCommands();

            $saved_variables['?'] = isset($this->variables['?']) ? $this->variables['?'] : 0;

            $this->argv = $saved_argv;
            $this->envp = $saved_envp;
            $this->variables = $saved_variables;
            $this->can_read = $saved_can_read;
            $this->tokens = $saved_tokens;

            return $this->variables['?'];


        // EXTERNAL EXECUTOR
        } else {
            $this->variables['?'] = $this->executor->exec($argv, $envp, $descriptors);

            if ($this->variables['?'] === 127 && $this->interactive) {
                fwrite($this->stderr, "sh: " . $argv[0] . " not found\r\n");
            }

            return $this->variables['?'];
        }
    }

    /**
     * @param bool
     * @return void
     */
    private function interpretCommand($just_parse = FALSE)
    {
        // IF
        if ($this->topToken() === 'if') {
            $this->popToken();
            $this->interpretMoreCommands(array('then'), $just_parse);

            if ($this->popToken() !== 'then') {
                throw new SyntaxError('syntax error, expected then');
            }

            $skip = $this->variables['?'] == 0; // intentionally ==
            $this->interpretMoreCommands(array('elif', 'else', 'fi'), $this->variables['?'] != 0 || $just_parse);

            while ($this->topToken() === 'elif') {
                $this->popToken();

                $this->interpretMoreCommands(array('then'), $skip || $just_parse);
                if ($this->popToken() !== 'then') {
                    throw new SyntaxError('syntax error, expected then');
                }

                $this->interpretMoreCommands(array('elif', 'else', 'fi'), $this->variables['?'] != 0 || $skip || $just_parse);
                $skip = $skip || ($this->variables['?'] == 0);
            }

            if ($this->topToken() === 'else') {
                $this->popToken();
                $this->interpretMoreCommands(array('elif', 'else', 'fi'), $skip || $just_parse);
            }

            if ($this->popToken() !== 'fi') {
                throw new SyntaxError('syntax error, excepted fi');
            }


        // WHILE & UNTIL
        } else if ($this->topToken() === 'while' || $this->topToken() === 'until') {
            $loop = $this->popToken();
            $saved_tokens = $this->tokens;
            $new_tokens = NULL;

            for (;;) {
                $this->interpretMoreCommands(array('do'), $just_parse);

                if ($this->popToken() !== 'do') {
                    throw new SyntaxError('syntax error, expected while ...; do');
                }

                $ok = $just_parse || (($loop === 'while') === ($this->variables['?'] == 0)); // intentionally ==

                if ($ok || $new_tokens === NULL) {
                    $descriptors = $this->descriptors;
                    $can_read = $this->can_read;

                    try {
                        $this->interpretMoreCommands(array('done'), $just_parse || !$ok);

                        if ($this->popToken() !== 'done') {
                            throw new SyntaxError('syntax error, expected while ...; do ...; done');
                        }

                        $new_tokens = $this->tokens;

                    } catch (\Exception $e) {
                        if (!($e instanceof BuiltinBreak || $e instanceof BuiltinContinue)) {
                            throw $e;
                        }

                        if ($e->getN() > 1) {
                            $e->setN($e->getN() - 1);
                            throw $e;
                        }

                        $this->descriptors = $descriptors;
                        $this->can_read = $can_read;

                        if ($e instanceof BuiltinBreak) {
                            $just_parse = TRUE;
                        }

                        $this->tokens = $saved_tokens;
                        continue;
                    }
                }

                if ($just_parse || !$ok) {
                    break;
                }

                $this->tokens = $saved_tokens;
            }

            $this->tokens = $new_tokens;

        // FOR
        } else if ($this->topToken() === 'for') {
            $this->popToken();
            if (!preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $name = $this->popToken())) {
                throw new SyntaxError('syntax error, expected for <name>');
            }

            $items = array();
            if ($this->topToken() === 'in') {
                $this->popToken();

                while ($this->topToken() !== ';') {
                    if (($item = $this->word($just_parse)) === NULL) {
                        throw new SyntaxError('syntax error, expected for <name> in <items>');
                    }
                    $items[] = $item;

                    if ($this->topToken() !== ' ') {
                        break;
                    }
                    $this->popToken();
                }
            } else {
                $items = $this->argv;
            }

            if ($this->popToken() !== ';') {
                throw new SyntaxError('syntax error, expected for <name> in <items>;');
            }

            if ($this->popToken() !== 'do') {
                throw new SyntaxError('syntax error, expected for <name> in <items>; do');
            }

            $has_to_parse = FALSE;
            $saved_tokens = $this->tokens;

            for ($i = 0, $c = count($items); !$just_parse && $i < $c; ++$i) {
                $descriptors = $this->descriptors;
                $can_read = $this->can_read;
                try {
                    $this->variables[$name] = $items[$i];
                    $this->interpretMoreCommands(array('done'), $just_parse);

                    if ($this->popToken() !== 'done') {
                        throw new SyntaxError('syntax error, expected while ...; do ...; done');
                    }

                    if ($i + 1 < $c) {
                        $this->tokens = $saved_tokens;
                    }

                    $has_to_parse = FALSE;

                } catch (\Exception $e) {
                    if (!($e instanceof BuiltinBreak || $e instanceof BuiltinContinue)) {
                        throw $e;
                    }

                    if ($e->getN() > 1) {
                        $e->setN($e->getN() - 1);
                        throw $e;
                    }

                    $this->descriptors = $descriptors;
                    $this->can_read = $can_read;

                    if ($e instanceof BuiltinBreak) {
                        $just_parse = TRUE;
                    }

                    $this->tokens = $saved_tokens;
                    $has_to_parse = TRUE;
                }
            }

            if ($just_parse || count($items) < 1 || $has_to_parse) {
                $this->tokens = $saved_tokens;
                $this->interpretMoreCommands(array('done'), TRUE);

                if ($this->popToken() !== 'done') {
                    throw new SyntaxError('syntax error, expected for <name> in <items>; do ...; done');
                }
            }

        // CASE
        } else if ($this->topToken() === 'case') {
            $this->popToken();

            if (($word = $this->word($just_parse, array('in'))) === NULL) {
                throw new SyntaxError('syntax error, expected case <word>');
            }

            if ($this->popToken() !== 'in') {
                throw new SyntaxError('syntax error, expected case <word> in');
            }

            $skip = FALSE;
            while ($this->topToken() !== 'esac') {
                $pattern = '~^(';
                for (;;) {
                    if (!(($part = $this->word($just_parse, array('|', ')'), FALSE)) !== NULL && strlen($part) > 0)) {
                        throw new SyntaxError('syntax error, expected case <word> in ...)');
                    }

                    foreach (preg_split('~(\*|\?)~', $part, -1, PREG_SPLIT_DELIM_CAPTURE) as $subpart) {
                        if ($subpart === '*') {
                            $pattern .= '.*';
                        } else if ($subpart === '?') {
                            $pattern .= '.?';
                        } else {
                            $pattern .= preg_quote($subpart, '~');
                        }
                    }

                    if ($this->topToken() === '|') {
                        $pattern .= $this->popToken();
                        continue;
                    }

                    if ($this->topToken() === ')') {
                        break;
                    }
                }
                $pattern .= ')$~';

                if ($this->popToken() !== ')') {
                    throw new SyntaxError('syntax error, expected case <word> in ...)');
                }

                $ok = FALSE;
                if (!$skip && preg_match($pattern, $word)) {
                    $skip = $ok = TRUE;
                }

                $this->interpretMoreCommands(array(';;'), $just_parse || !$ok);
                if ($this->popToken() !== ';;') {
                    throw new SyntaxError('syntax error, expected case <word> in ...) ...;;');
                }
            }


            if ($this->popToken() !== 'esac') {
                throw new SyntaxError('syntax error, expected case <word> in ...;; esac');
            }


        // FUNCTION DEFINITION
        } else if ($this->secondToken() === '(') {
            if (!preg_match('~^[a-zA-Z_][a-zA-Z0-9_]*$~', $name = $this->popToken())) {
                throw new SyntaxError('syntax error, expected <name>');
            }

            if (!($this->popToken() === '(' && $this->popToken() === ')' && $this->popToken() === '{')) {
                throw new SyntaxError('syntax error, expected ' . $name . '() {');
            }

            $tokens = array();
            $level = 1;
            $string = FALSE;

            while ($level > 0) {
                $token = $this->popToken();

                if ($token === '"') {
                    $string = !$string;
                }

                if (!$string) {
                    if ($token === '{') {
                        ++$level;
                    }

                    if ($token === '}') {
                        --$level;
                    }
                }

                if ($level > 0) {
                    $tokens[] = $token;
                }
            }

            $this->functions[$name] = $tokens;


        // VARIABLE ASSIGNMENT OR CALL
        } else {
            // ASSIGNMENT WORD
            $just_assignment = FALSE;
            if (substr($this->topToken(), strlen($this->topToken()) - 1) === '=') { // just assignment
                $can_read = $this->can_read;
                $this->can_read = FALSE;
                $saved_tokens = $this->tokens;

                $name = $this->popToken();
                $name = substr($name, 0, strlen($name) - 1);

                if (($value = $this->word($just_parse, array(';'))) === NULL || $this->topToken() !== ';') {
                    $this->tokens = $saved_tokens;

                } else {
                    $just_assignment = TRUE;
                    $this->variables[$name] = $value;
                }

                $this->can_read = $can_read;
            }

            if (!$just_assignment) {
                $this->interpretPipeline($just_parse);

                while ($this->topToken() === '&&' || $this->topToken() === '||') {
                    $ok = $this->variables['?'] == 0;
                    $op = $this->popToken();

                    if (($op === '&&' && $ok) || ($op === '||' && !$ok)) {
                        $this->interpretPipeline($just_parse);
                    } else {
                        assert(($op === '&&' && !$ok) || ($op === '||' && $ok));
                        $this->interpretPipeline(TRUE);
                    }
                }
            }
        }

        if ($this->topToken() !== ';') {
            throw new SyntaxError('syntax error, expected ;');
        }
        $this->popToken();
    }

    /**
     * @param array
     * @param bool
     * @param bool
     * @return void
     */
    private function interpretMoreCommands(array $stop = array(), $just_parse = FALSE, $toplevel = FALSE)
    {
        $stop = array_flip($stop);
        $this->toplevel = $toplevel;
        for ($i = 0; $this->topToken() !== NULL && !isset($stop[$this->topToken()]); ++$i) {
            if ($this->topToken() === ';') {
                $can_read = $this->can_read;
                $this->can_read = FALSE;
                while ($this->topToken() === ';') {
                    $this->popToken();
                }
                $this->can_read = $can_read;
                if (empty($this->tokens)) {
                    break;
                }
            }

            $this->toplevel = FALSE;
            $this->interpretCommand($just_parse);
            $this->toplevel = $toplevel;
        }

        if ($i === 0) {
            throw new SyntaxError('syntax error, expected command');
        }
    }

    /**
     * @param bool
     * @return void
     */
    private function interpretPipeline($just_parse = FALSE)
    {
        $not = FALSE;
        if ($this->topToken() === '!') {
            $not = TRUE;
            $this->popToken();
        }

        if (!$just_parse) {
            $this->redirectStdoutToMemory($stdout);
        }

        $this->interpretSimple($just_parse);

        while ($this->topToken() === '|') {
            $this->popToken();

            if (!$just_parse) {
                $stdin = $this->descriptors[0];
                $this->descriptors[0] = $this->descriptors[1];
                fseek($this->descriptors[0], 0);
                $this->descriptors[1] = $stdout;
                $this->redirectStdoutToMemory($stdout);
            }

            $this->interpretSimple($just_parse);

            if (!$just_parse) {
                fclose($this->descriptors[0]);
                $this->descriptors[0] = $stdin;
            }
        }

        if (!$just_parse) {
            fseek($this->descriptors[1], 0);
            stream_copy_to_stream($this->descriptors[1], $stdout);
            fclose($this->descriptors[1]);
            $this->descriptors[1] = $stdout;
        }

        if (!$just_parse && $not) {
            $this->variables['?'] = (int) !$this->variables['?'];
        }
    }

    /**
     * @param bool
     * @return void
     */
    private function interpretSimple($just_parse = FALSE)
    {
        static $redir_ops;
        if ($redir_ops === NULL) {
            $redir_ops = array_flip(array('<>', '<&', '<', '>&', '>>', '>'));
        }

        $argv = array();
        $envp = $this->envp;
        $redirections = array();

        for (; substr($this->topToken(), strlen($this->topToken()) - 1) === '=';) {
            $token = $this->popToken();

            if (($value = $this->word($just_parse)) === NULL) {
                throw new SyntaxError('syntax error, expected value');
            }

            $envp[substr($token, 0, strlen($token) - 1)] = $value;

            if ($this->popToken() !== ' ') {
                throw new SyntaxError('syntax error, expected space');
            }
        }

        if (($program = $this->word($just_parse)) === NULL) {
            throw new SyntaxError('syntax error, expected program name');
        }
        $argv[0] = $program;

        while ($this->topToken() === ' ' && !isset($redir_ops[$this->secondToken()])) {
            $this->popToken();

            if (($word = $this->word($just_parse)) === NULL) {
                break;
            }

            if (isset($redir_ops[$this->topToken()]) || isset($redir_ops[$this->secondToken()])) {
                if (is_numeric($word)) {
                    $this->pushToken($word);
                    $this->pushToken(' ');
                    break;
                }
            }

            $argv[] = $word;
        }

        while ($this->topToken() === ' ') {
            $this->popToken();

            $n = NULL;
            if (is_numeric($this->topToken())) {
                $n = intval($this->popToken());
            }

            if ($this->topToken() === ' ') {
                $this->popToken();
            }

            if (!isset($redir_ops[$this->topToken()])) {
                if ($n !== NULL) {
                    $this->pushToken((string) $n);
                }
                break;
            }

            $redir_op = $this->popToken();

            if ($this->topToken() === ' ') {
                $this->popToken();
            }

            if (!(($word = $this->word($just_parse)) !== NULL &&
                !(substr($redir_op, 1, 1) !== '&' && is_numeric($word))))
            {
                throw new SyntaxError('syntax error, expected file to redirect to');
            }

            if ($n === NULL) {
                if ($redir_op[0] === '<') {
                    $n = 0;
                } else {
                    assert($redir_op[0] === '>');
                    $n = 1;
                }
            }

            switch ($redir_op) {
                case '<>':
                    $redirections[$n] = array('w+', $word);
                break;

                case '<&':
                    $redirections[$n] = intval($word);
                break;

                case '<':
                    $redirections[$n] = array('r', $word);
                break;

                case '>&':
                    $redirections[$n] = intval($word);
                break;

                case '>>':
                    $redirections[$n] = array('a', $word);
                break;

                case '>':
                    $redirections[$n] = array('w', $word);
                break;
            }
        }

        if (!$just_parse) {
            $merge_envp = array();
            foreach ($this->exported as $exported => $_) {
                if (isset($this->variables[$exported])) {
                    $merge_envp[$exported] = $this->variables[$exported];
                }
            }

            $this->_exec($argv, array_merge($this->envp, $merge_envp, $envp), $redirections);
        }
    }

    /**
     * @param bool
     * @param array
     * @param bool
     * @return string
     */
    private function word($just_parse = FALSE, array $stop = array(), $expand_wildcards = TRUE)
    {
        $stop = array_flip($stop);
        $string = FALSE;

        for ($ret = NULL; $string || (!isset($stop[$this->topToken()]) && !preg_match("~^[|&;<>()\\ \t]~", $this->topToken()));) {
            $token = $this->popToken();
            $split_token = FALSE;
            $noglob = FALSE;

            if (strncmp($token, '\\', 1) === 0 && !$string) {
                $token = substr($token, 1);
                if ($token === '*' || $token === '?') {
                    $noglob = TRUE;
                }

            } else if (strncmp($token, '\'', 1) === 0 && !$string) {
                $token = substr($token, 1, strlen($token) - 2);

            } else if ($token === '"') {
                $string = !$string;
                $token = '';

            } else if ($token === '`') {
                if ($this->secondToken() !== '`') {
                    throw new SyntaxError('syntax error, unclosed backtick');
                }

                $code = $this->popToken();
                $this->popToken();

                if (!$just_parse) {
                    $this->redirectStdoutToMemory($stdout);

                    $tokens = $this->tokens;
                    $this->tokens = $this->tokenize($code);
                    $can_read = $this->can_read;
                    $this->can_read = FALSE;

                    $this->interpretPipeline();

                    fseek($this->descriptors[1], 0, SEEK_SET);
                    $token = trim(stream_get_contents($this->descriptors[1]));
                    fclose($this->descriptors[1]);

                    $this->descriptors[1] = $stdout;
                    $this->tokens = $tokens;
                    $this->can_read = $can_read;

                    $split_token = TRUE;
                }

            } else if (strncmp($token, '$', 1) === 0) {
                $name = substr($token, 1);
                if (!$just_parse) {
                    if ($name === '*' || ($name === '@' && !$string)) {
                        $token = implode(' ', array_slice($this->argv, count($this->argv) - $this->variables['#']));
                        $split_token = !$string;

                    } else if ($name === '@' && $string) {
                        $args = array_slice($this->argv, count($this->argv) - $this->variables['#']);
                        $token = array_shift($args);

                        foreach ($args as $arg) {
                            if (preg_match('~^[|&;<>()`\\\\]~', $arg)) {
                                $arg = '\\' . $arg;
                            }

                            array_unshift($this->tokens, $arg);
                            array_unshift($this->tokens, '"');
                            array_unshift($this->tokens, ' ');
                            array_unshift($this->tokens, '"');
                        }

                    } else if (!isset($this->variables[$name])) {
                        if (!isset($this->envp[$name])) {
                            $token = '';

                        } else {
                            $token = $this->envp[$name];
                            $split_token = TRUE;
                        }

                    } else {
                        $token = $this->variables[$name];
                        $split_token = TRUE;
                    }
                }
            }

            if ($split_token && !$string) {
                $rest = preg_split("~([ \t\r\n]+)~", $token, -1, PREG_SPLIT_DELIM_CAPTURE);
                $token = array_shift($rest);


                foreach ($rest as $k => $v) {
                    if (strlen($v) < 1) {
                        unset($rest[$k]);
                    }

                    if (ctype_space($v)) {
                        $rest[$k] = ' ';
                    }

                    if (preg_match('~^[|&;<>()`\\\\]~', $v)) {
                        $rest[$k] = '\\' . $v;
                    }
                }

                $this->tokens = array_merge($rest, $this->tokens);
            }

            if (!$string &&
                !$just_parse &&
                $expand_wildcards &&
                !$noglob &&
                (strpos($token, '*') !== FALSE || strpos($token, '?') !== FALSE) &&
                (($files = glob($token)) !== FALSE))
            {
                $token = array_shift($files);
                foreach ($files as $file) {
                    array_unshift($this->tokens, $file);
                    array_unshift($this->tokens, ' ');
                }
            }

            $ret .= $token;
        }

        return $ret;
    }

    /**
     * @param resource
     * @return void
     */
    private function redirectStdoutToMemory(&$stdout)
    {
        $stdout = $this->descriptors[1];
        if (!($this->descriptors[1] = fopen('php://memory', 'w+b'))) {
            throw new Error('cannot open memory stdout');
        }
    }

    /** @return void */
    private function loadTokens()
    {
        if ($this->can_read && !feof($this->stdin)) {
            if (!$this->interactive) {
                if (($data = fgets($this->stdin)) === FALSE) {
                    throw new Error('stdin read error');
                }
            } else {
                if ($this->toplevel && strlen($this->previous_command) > 0) {
                    $this->history[] = $this->previous_command;
                    $this->previous_command = '';
                }

                $data = $saved_data = '';
                $cursor = 0;
                $hindex = count($this->history);

                fwrite($this->stdout, $this->envp[$this->toplevel ? 'PS1' : 'PS2']);

                while (!feof($this->stdin)) {
                    $c = fread($this->stdin, 1);

                    // FIXME
                    // ^C or ^D
                    if (ord($c) === 0x03 || ord($c) === 0x04) {
                        throw new BuiltinExit(0);

                    // backspace
                    } else if (ord($c) === 0x08 || ord($c) === 0x7f) {
                        if (strlen($data) > 0 && $cursor > 0) {
                            $new_data = substr($data, 0, $cursor - 1) . substr($data, $cursor);

                            fwrite($this->stdout, str_repeat("\033[D", $cursor) .
                                str_repeat(' ', strlen($data)) .
                                str_repeat("\033[D", strlen($data)) .
                                $new_data .
                                str_repeat("\033[D", strlen($data) - $cursor));
                            --$cursor;
                            $data = $new_data;
                        }

                    // return
                    } else if (ord($c) === 0x0d) {
                        fwrite($this->stdout, "\r\n");

                        if (!empty($data)) {
                            break;

                        } else {
                            fwrite($this->stdout, $this->envp[$this->toplevel ? 'PS1' : 'PS2']);
                        }

                    // escape sequences
                    } else if (ord($c) === 0x1b) {
                        if (($d = fread($this->stdin, 1)) !== '[') {
                            $data .= $d;
                            fwrite($this->stdout, $d);
                            ++$cursor;

                        } else {
                            $c = fread($this->stdin, 1);

                            if (!$this->toplevel && ($c === 'A' || $c === 'B')) {
                                fwrite($this->stdout, "[$c");
                                continue;
                            }


                            switch ($c) {
                                case 'A':
                                    if ($hindex > 0) {
                                        $saved_data = $data;

                                        if ($cursor > 0) {
                                            fwrite($this->stdout, str_repeat("\033[D", $cursor));
                                        }

                                        if (strlen($data) > 0) {
                                            fwrite($this->stdout, str_repeat(' ', strlen($data)));
                                            fwrite($this->stdout, str_repeat("\033[D", strlen($data)));
                                        }

                                        --$hindex;

                                        $data = $this->history[$hindex];
                                        fwrite($this->stdout, $data);
                                        $cursor = strlen($data);
                                    }
                                break;

                                case 'B':
                                    if ($hindex < count($this->history)) {
                                        $new_saved_data = $data;

                                        if ($cursor > 0) {
                                            fwrite($this->stdout, str_repeat("\033[D", $cursor));
                                        }

                                        if (strlen($data) > 0) {
                                            fwrite($this->stdout, str_repeat(' ', strlen($data)));
                                            fwrite($this->stdout, str_repeat("\033[D", strlen($data)));
                                        }

                                        ++$hindex;

                                        $new_data = $hindex < count($this->history)
                                                    ? $this->history[$hindex]
                                                    : $saved_data;
                                        $saved_data = $new_saved_data;

                                        $data = $new_data;
                                        fwrite($this->stdout, $data);
                                        $cursor = strlen($data);
                                    }
                                break;

                                case 'C':
                                    if ($cursor < strlen($data)) {
                                        fwrite($this->stdout, "\033[C");
                                        ++$cursor;
                                    }
                                break;

                                case 'D':
                                    if ($cursor > 0) {
                                        fwrite($this->stdout, "\033[D");
                                        --$cursor;
                                    }
                                break;
                            }
                        }

                    // just some data
                    } else {
                        $data .= $c;
                        fwrite($this->stdout, $c);
                        ++$cursor;
                    }
                }

                $this->previous_command .= $data;
            }

            $this->tokens = array_merge($this->tokens, $this->tokenize($data));
        }
    }

    /** @return string */
    private function popToken()
    {
        if (empty($this->tokens)) {
            $this->loadTokens();
        }

        if (reset($this->tokens) === FALSE) {
            return NULL;
        }

        return array_shift($this->tokens);
    }

    /** @return string */
    private function topToken()
    {
        if (empty($this->tokens)) {
            $this->loadTokens();
        }

        if (reset($this->tokens) === FALSE) {
            return NULL;
        }

        return reset($this->tokens);
    }

    /** @return string */
    private function secondToken()
    {
        while (count($this->tokens) < 2 && !feof($this->stdin)) {
            if ($this->topToken() === ';') {
                return NULL;
            }

            $this->loadTokens();
        }

        if (reset($this->tokens) === FALSE || next($this->tokens) === FALSE) {
            return NULL;
        }

        return current($this->tokens);
    }

    /**
     * @param string
     * @return void
     */
    private function pushToken($token)
    {
        array_unshift($this->tokens, $token);
    }

    /**
     * @param string
     * @return array
     */
    private function tokenize($data)
    {
        static $keywords;

        if ($keywords === NULL) {
            $keywords = array_flip(array(
                'if', 'then', 'elif', 'else', 'fi',
                'for', 'in', 'do', 'done',
                'case', 'esac',
                'while', 'until',
                '!',
            ));
        }

        $tokens = preg_split("~(
            \\\$[a-zA-Z_][a-zA-Z0-9_]* | \\\$[\$0-9@*#?$!-] |
            [a-zA-Z_][a-zA-Z0-9_]*= |
            \\# |
            (?<![a-zA-Z0-9_])(?:" . implode('|', array_keys($keywords)) . ")(?![a-zA-Z0-9_]) |
            \\\\\" | \" |
            (?:\\\\\\r\\n?|\\\\\\n|\\r\\n?|\\n)[ \t]* |
            '[^']*' |
            ;; | ; |
            && | \\|\\| |
            <> | <&? | >\\| | >> | >&? |
            \\| |
            \\\\[|&;<>()\$`\\\"' \t] |
            ` | \\( | \\) | \\{ | \\} |
            [ \t]+
        )~x", $data, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $for = FALSE;
        $case = FALSE;
        $label = FALSE;
        $string = FALSE;
        $backticks = FALSE;
        $subshells = 0;
        $comment = FALSE;
        $ret = array();
        $prev2 = NULL;
        $prev = NULL;

        while (($token = array_shift($tokens)) !== NULL) {
            $next = reset($tokens) === FALSE ? NULL : reset($tokens);

            if ($comment) {
                if (strncmp($token, "\r", 1) !== 0 &&
                    strncmp($token, "\n", 1) !== 0)
                {
                    continue;

                } else {
                    $comment = FALSE;
                }
            }

            if ($backticks === FALSE && $token === '`') {
                $backticks = '';
                $ret[] = $token;
                $prev2 = $prev;
                $prev = $token;
                continue;
            }

            if ($backticks !== FALSE && $token !== '`') {
                if ($token === '\\`') {
                    $token = '`';
                }
                $backticks .= $token;
                continue;
            }

            if ($backticks !== FALSE && $token === '`') {
                $ret[] = $backticks;
                $ret[] = '`';
                $prev2 = $backticks;
                $prev = '`';
                $backticks = FALSE;
                continue;
            }

            if ($token === '"') {
                $string = !$string;
            }

            if (!$string) {
                if ($token === '#') {
                    $comment = TRUE;
                    continue;
                }

                if (strncmp($token, "\\\r", 2) === 0 ||
                    strncmp($token, "\\\n", 2) === 0)
                {
                    continue;
                }

                if (strncmp($token, "\r", 1) === 0 ||
                    strncmp($token, "\n", 1) === 0)
                {
                    $token = ';';
                }

                if (ctype_space($token)) {
                    $token = ' ';
                }

                if ($token === '>|') {
                    $token = '>';
                }

                if ($token === 'for' &&
                        ($prev === ';' || $prev === '{' || $prev === NULL ||
                        $prev === 'then' || $prev === 'else' || $prev === 'do'))
                {
                    $for = TRUE;
                }

                if ($for && $token === ';') {
                    $for = FALSE;
                }

                if ($token === 'case' &&
                        ($prev === ';' || $prev === '{' || $prev === NULL ||
                        $prev === 'then' || $prev === 'else' || $prev === 'do'))
                {
                    $case = TRUE;
                }

                if ($token === 'esac' && $prev === ';;') {
                    $case = FALSE;
                }

                if ($case && ($prev === 'in' || $prev === ';;')) {
                    $label = TRUE;
                    if ($token === '(') {
                        continue;
                    }
                }

                if ($label && $token === ')') {
                    $label = FALSE;
                }

                if ($token === '(' && !$label && ($prev === ';' || $prev === '{' || $prev === NULL)) {
                    ++$subshells;
                }

                if ($token === ')' && !$label && ($prev === ';' || $prev === '{' || $prev === NULL)) {
                    --$subshells;
                }

                if (($token === ' ' && $prev === ' ') ||
                    ($token === ' ' && $prev === NULL) ||
                    ($token === ' ' && $prev === ';') ||
                    ($token === ' ' && ($prev === '(' || $prev === ')')) ||
                    ($token === ' ' && ($next === '(' || $next === ')')) ||
                    ($token === ' ' && ($prev === '{' || $prev === '}')) ||
                    ($token === ';' && $prev === '{') ||
                    ($token === ' ' && ($next === '{' || $next === '}')) ||
                    ($token === ' ' && isset($keywords[$prev]) && ($prev2 === ';' || $prev2 === '{' || $prev2 === NULL)) ||
                    ($token === ' ' && isset($keywords[$prev]) && isset($keywords[$prev2])) ||
                    ($token === ' ' && isset($keywords[$next]) && ($prev === ';' || $prev2 === '{' || $prev === NULL)) ||
                    ($token === ' ' && $for && $next === 'in') ||
                    ($token === ' ' && $for && $prev === 'in') ||
                    ($token === ' ' && $case && $next === 'in') ||
                    ($token === ' ' && $case && $prev === 'in') ||
                    ($token === ' ' && $case && $label) ||
                    ($token === ' ' && $case && $prev === ')') ||
                    ($token === ' ' && ($prev === ';;' || $prev === '&&' || $prev === '||' || $prev === '|')) ||
                    ($token === ' ' && ($next === ';;' || $next === '&&' || $next === '||' || $next === '|')) ||
                    //($token === ' ' && is_numeric($prev) &&
                    //    ($next === '<>' || $next === '<' || $next === '>|' || $next === '>>' || $next === '>') &&
                    //    ($prev2 !== '<>' || $prev2 !== '<' || $prev2 !== '>|' || $prev2 !== '>>' || $prev2 !== '>')) ||
                    ($token === ' ' && ($prev === '<>' || $prev === '<' || $prev === '>|' || $prev === '>>' || $prev === '>')) ||
                    ($token === ';' && $prev === ';') ||
                    ($token === ';' &&
                        ($prev === 'if' || $prev === 'then' || $prev === 'elif' || $prev === 'else' ||
                        $prev === 'for' || $prev === 'do'  ||
                        $prev === 'while' || $prev === 'until' ||
                        ($case && $prev === 'in') ||
                        $prev === '&&' || $prev === '||' || $prev === '|')))
                {
                    continue;
                }

                if ($label && ($pos = strpos($token, ')')) !== FALSE) {
                    if ($pos === 0) {
                        if ($token !== ')') {
                            array_unshift($tokens, substr($token, 1));
                            $token = ')';
                        }

                    } else {
                        list($pre, $post) = explode(')', $token, 2);
                        $token = $pre;

                        if ($post !== '') {
                            array_unshift($tokens, $post);
                        }

                        array_unshift($tokens, ')');
                    }
                }

                if ($prev !== ';' &&
                    (($case && $token === ';;') ||
                    ($subshells && $token === ')') ||
                    ($token === '}')))
                {
                    if ($prev === ' ') {
                        array_pop($ret);
                    }

                    $ret[] = $prev = ';';
                }
            }

            if ($token === ';' && $prev === ' ') {
                array_pop($ret);
                $prev = end($ret);
            }

            $ret[] = $token;
            $prev2 = $prev;
            $prev = $token;
        }

        if (!$string && $prev !== ';' && $prev !== '{') {
            if ($prev === ' ') {
                array_pop($ret);
            }
            $ret[] = ';';
        }

        return $ret;
    }
}
