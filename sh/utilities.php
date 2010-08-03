<?php
namespace sh\commands;

function basename(array $argv, array $envp, array $descriptors)
{
    if (!isset($argv[1])) {
        fwrite($descriptors[2], "basename: argument not given\n");
        return 1;
    }

    if (isset($argv[2])) {
        fwrite($descriptors[1], \basename($argv[1], $argv[2]) . "\n");
    } else {
        fwrite($descriptors[1], \basename($argv[1]) . "\n");
    }
    return 0;
}

function cat(array $argv, array $envp, array $descriptors)
{
    if ($argv[1] === '-u') {
        $argv = array_slice($argv, 1);
    }

    $status = 0;
    foreach (array_slice($argv, 1) as $file) {
        if ($file === '-') {
            $handle = $descriptors[0];
        } else {
            if (($handle = @fopen($file, 'rb')) === FALSE) {
                $status = 1;
                fwrite($descriptors[2], "cat: file $file does not exist\n");
                continue;
            }
        }

        while (!feof($handle)) {
            fwrite($descriptors[1], fread($handle, 8192));
        }
    }

    return $status;
}

function cd(array $argv, array $envp, array $descriptors)
{
    return (int) !@\chdir($argv[1]);
}

function chmod(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $recursive = FALSE;
    if (isset($argv[0]) && $argv[0] === '-R') {
        $recursive = TRUE;
        array_shift($argv);
    }

    if (count($argv) < 2) {
        fwrite($descriptors[2], "chmod: too few arguments\n");
        return 1;
    }

    if (sprintf('%o', $mode = intval($argv[0], 8)) !== $argv[0]) {
        fwrite($descriptors[2], "chmod: bad permissions\n");
        return 1;
    }
    array_shift($argv);

    $pathname = array_shift($argv);

    $pathnames = array();

    if ($recursive) {
        try {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($pathname), \RecursiveIteratorIterator::CHILD_FIRST) as $entry)
            {
                if ($entry->getBasename() === '.' || $entry->getBasename() === '..') {
                    continue;
                } else {
                    $pathnames[] = $entry->getPathname();
                }
            }
        } catch (\Exception $e) {
            fwrite($descriptors[2], "chmod: bad directory\n");
            return 1;
        }
    }

    $pathnames[] = $pathname;

    $status = 0;
    foreach ($pathnames as $pathname) {
        if (!@\chmod($pathname, $mode)) {
            fprintf($descriptors[2], "chmod: cannot change permissions on file %s to %o\n", $pathname, $mode);
            $status = 1;
        }
    }

    return $status;
}

// FIXME: only -R option is supported
function cp(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $recursive = FALSE;
    if (isset($argv[0]) && ($argv[0] === '-R' || $argv[0] === '-r')) {
        $recursive = TRUE;
        array_shift($argv);
    }

    if (count($argv) !== 2) {
        fwrite($descriptors[2], "cp: bad number of arguments\n");
        return 1;
    }

    $src = rtrim(array_shift($argv), '/');
    $dst = rtrim(array_shift($argv), '/');
    $copy_single = FALSE;

    if ($recursive) {
        if (\file_exists($dst) && \is_file($dst)) {
            fwrite($descriptors[2], "cp: destination {$dst} is not a directory\n");
            return 1;
        }

        $newname = \basename($src);
        if (!\file_exists($dst) && \is_dir($src)) {
            if (!(\file_exists(\dirname($dst)) && \is_dir(\dirname($dst)))) {
                fwrite($descriptors[2], "cp: destination directory does not exist\n");
                return 1;
            }
            $newname = \basename($dst);
            $dst = \dirname($dst);
        }

        if (!\is_dir($dst) && \is_dir($src)) {
            fwrite($descriptors[2], "cp: destination {$dst} is not a directory\n");
            return 1;
        }

        if (!\is_dir($src)) {
            $copy_single = TRUE;

        } else {
            try {
                if (!@\mkdir($dst . '/' . $newname, 0755) && !\is_dir($dst . '/' . $newname)) {
                    fwrite($descriptors[2], "cp: cannot create directory {$dst}/{$newname}\n");
                    return 1;
                }


                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($src), \RecursiveIteratorIterator::SELF_FIRST) as $entry)
                {
                    if ($entry->getBasename() === '.' || $entry->getBasename() === '..') {
                        continue;

                    } else {
                        if (strncmp($entry->getPathname(), $src . '/',
                            $len = \strlen($src . '/')) !== 0)
                        {
                            fwrite($descriptors[2], "cp: internal error\n");
                            return 1;
                        }

                        $relative = substr($entry->getPathname(), $len);

                        if ($entry->isDir()) {
                            if (!@\mkdir($dst . '/' . $newname . '/' . $relative, 0755) &&
                                !\is_dir($dst . '/' . $newname . '/' . $relative))
                            {
                                fwrite($descriptors[2], "cp: cannot create directory {$dst}/{$newname}/{$relative}\n");
                                return 1;
                            }

                        } else {
                            if (!@\copy($entry->getPathname(), $dst . '/' . $newname . '/' . $relative)) {
                                fwrite($descriptors[2], "cp: cannot copy file {$dst}/{$newname}/{$relative}\n");
                                return 1;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                fwrite($descriptors[2], "cp: cannot open source directory {$src}\n");
                return 1;
            }
        }
    }

    if (!$recursive || $copy_single) {
        if (is_dir($src)) {
            fwrite($descriptors[2], "cp: source {$src} is a directory, but no -R given\n");
            return 1;
        }

        if (is_dir($dst)) {
            $dst = $dst . '/' . \basename($src);
        }

        if (!@\copy($src, $dst)) {
            fwrite($descriptors[2], "cp: cannot copy file {$src} to {$dst}\n");
            return 1;
        }
    }

    return 0;
}

function dirname(array $argv, array $envp, array $descriptors)
{
    if (!isset($argv[1])) {
        fwrite($descriptors[2], "basename: argument not given\n");
        return 1;
    }

    fwrite($descriptors[1], \dirname($argv[1]) . "\n");
    return 0;
}

function echo_(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $newline = TRUE;
    if (isset($argv[0]) && $argv[0] === '-n') {
        $newline = FALSE;
        array_shift($argv);
    }

    fwrite($descriptors[1], implode(' ', $argv));

    if ($newline) {
        fwrite($descriptors[1], "\n");
    }

    return 0;
}

function expr(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $expr = array();
    foreach ($argv as $arg) {
        if ($arg === '|') {
            $arg = '||';
        } else if ($arg === '&') {
            $arg = '&&';
        } else if ($arg === '=') {
            $arg = '==';
        } else if ($arg === ':') {
            fwrite($descriptors[2], "expr: matching currently not supported\n");
            return 1;
        } else if (is_numeric($arg)) {
            $arg = intval($arg);
        } else if ($arg !== '>' && $arg !== '>=' && $arg !== '<' && $arg !== '<=' &&
            $arg !== '!=' && $arg !== '+' && $arg !== '-' && $arg !== '*' && $arg !== '/' &&
            $arg !== '%' && $arg !== '(' && $arg !== ')')
        {
            $arg = var_export($arg, TRUE);
        }

        $expr[] = $arg;
    }

    $result = @eval('return ' . implode(' ', $expr) . ';'); // FIXME: eval i evil

    if (is_bool($result)) {
        fwrite($descriptors[1], ((int) !$result) . "\n");
        return (int) !$result;

    } else {
        fwrite($descriptors[1], ((int) $result) . "\n");
        return 0;
    }
}

function false_(array $argv, array $envp, array $descriptors)
{
    return 1;
}

// FIXME: only -v and -i are supported
function grep(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $not = FALSE;
    $case_insensitive = FALSE;

    while (isset($argv[0]) && $argv[0][0] === '-') {
        switch ($option = array_shift($argv)) {
            case '-v':
                $not = TRUE;
            break;

            case '-i':
                $case_insensitive = TRUE;
            break;

            default:
                fwrite($descriptors[2], "grep: {$option} is currently not supported\n");
                return 1;
        }
    }

    if (empty($argv)) {
        fwrite($descriptors[2], "grep: pattern missing\n");
        return 1;
    }

    $pattern = array_shift($argv);

    if (empty($argv)) {
        $argv[] = '-';
    }

    $status = 0;

    foreach ($argv as $pathname) {
        if ($pathname === '-') {
            $handle = $descriptors[0];
        } else {
            if (!($handle = @fopen($pathname, 'r'))) {
                fwrite($descriptors[2], "grep: cannot open file {$pathname}\n");
                $status = 1;
                continue;
            }
        }

        while (($line = fgets($handle)) !== FALSE) {
            // intentionally !=
            if ($not != preg_match('~' .
                preg_quote($pattern, '~') . '~' . ($case_insensitive ? 'i' : ''), $line))
            {
                fwrite($descriptors[1], $line);
            }
        }
    }

    return $status;
}

function head(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $n = 10;
    if (isset($argv[0]) && $argv[0] === '-n' && isset($argv[1])) {
        $n = intval($argv[1]);
        array_shift($argv);
        array_shift($argv);
    }

    $handle = $descriptors[0];
    if (isset($argv[0])) {
        if (!($handle = @fopen($argv[0], 'r'))) {
            fwrite($descriptors[2], "head: cannot open file {$argv[0]} for reading\n");
            return 1;
        }
    }

    for (; $n > 0 && !feof($handle) && ($line = fgets($handle)) !== FALSE; --$n) {
        fwrite($descriptors[1], $line);
    }

    return 0;
}

// FIXME: only -s is supported
function ln(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $symbolic = FALSE;

    while (isset($argv[0]) && $argv[0][0] === '-') {
        switch ($option = array_shift($argv)) {
            case '-s':
                $symbolic = TRUE;
            break;

            default:
                fwrite($descriptors[2], "ln: {$option} is currently not supported\n");
                return 1;
        }
    }

    if (count($argv) < 2) {
        fwrite($descriptors[2], "ln: too few arguments\n");
        return 1;
    }

    list($src, $dst) = $argv;

    if ($symbolic) {
        $result = @\symlink($src, $dst);
    } else {
        $result = @\link($src, $dst);
    }

    if (!$result) {
        fwrite($descriptors[2], "ln: creation of link failed\n");
        return 1;
    }

    return 0;
}

// FIXME: only -a, -A and -l supported
// FIXME: ls -l *.php etc.
function ls(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $dot_and_dot_dot = FALSE;
    $all = FALSE;
    $long = FALSE;

    while (isset($argv[0]) && $argv[0][0] === '-') {
        switch ($option = array_shift($argv)) {
            case '-A':
                $all = TRUE;
                $dot_and_dot_dot = FALSE;
            break;

            case '-a':
                $all = TRUE;
                $dot_and_dot_dot = TRUE;
            break;

            case '-l':
                $long = TRUE;
            break;

            default:
                fwrite($descriptors[2], "ls: {$option} is currently not supported\n");
                return 1;
        }
    }

    if (empty($argv)) {
        $argv[] = '.';
    }

    $first = TRUE;
    foreach ($argv as $pathname) {
        if (\is_dir($pathname)) {
            if (!$first) {
                fwrite($descriptors[1], "\n");
            }

            if ($first) {
                $first = FALSE;
            }

            if (count($argv) > 1) {
                fwrite($descriptors[1], "$pathname:\n");
            }

            $total = 0;
            $files = array();

            foreach (new \DirectoryIterator($pathname) as $entry) {
                if ((!$all && substr($entry->getBasename(), 0, 1) === '.') ||
                    (($entry->getBasename() === '.' ||
                        $entry->getBasename() === '..') && !$dot_and_dot_dot))
                {
                    continue;
                }

                $files[$entry->getBasename()] = ($long ? _ls_format_long($entry->getPathname()) : $entry->getBasename());

                if (!($stat = @\stat($entry->getPathname()))) {
                    fwrite($descriptors[2], "ls: cannot stat " . $entry->getPathname() . "\n");
                    return -1;
                }

                if (isset($stat['blocks'])) {
                    $total += $stat['blocks'];
                }
            }

            $total /= 2;
            ksort($files);

            if ($long) {
                $maxnlink = 0;
                $maxuser = 0;
                $maxgroup = 0;
                $maxsize = 0;
                $maxmonth = 0;
                $maxday = 0;
                $maxyear = 0;

                foreach ($files as $file) {
                    list($mode, $nlink, $user, $group, $size, $month, $day, $year, $rest) = preg_split('~\s+~', $file, 9);

                    $maxnlink = max(strlen($nlink), $maxnlink);
                    $maxuser = max(strlen($user), $maxuser);
                    $maxgroup = max(strlen($group), $maxgroup);
                    $maxsize = max(strlen($size), $maxsize);
                    $maxmonth = max(strlen($month), $maxmonth);
                    $maxday = max(strlen($day), $maxday);
                    $maxyear = max(strlen($year), $maxyear);
                }

                foreach ($files as &$file) {
                    list($mode, $nlink, $user, $group, $size, $month, $day, $year, $rest) = preg_split('~\s+~', $file, 9);

                    $nlink = str_pad($nlink, $maxnlink, ' ', STR_PAD_RIGHT);
                    $user = str_pad($user, $maxuser, ' ', STR_PAD_RIGHT);
                    $group = str_pad($group, $maxgroup, ' ', STR_PAD_RIGHT);
                    $size = str_pad($size, $maxsize, ' ', STR_PAD_LEFT);
                    $month = str_pad($month, $maxmonth, ' ', STR_PAD_RIGHT);
                    $day = str_pad($day, $maxday, ' ', STR_PAD_LEFT);
                    $year = str_pad($year, $maxyear, ' ', STR_PAD_RIGHT);

                    $file = "$mode $nlink $user $group $size $month $day $year $rest";
                }

                fwrite($descriptors[1], "total $total\n");
            }

            fwrite($descriptors[1], implode("\n", $files) . "\n");

        } else {
            fwrite($descriptors[1], ($long ? _ls_format_long($pathname) : $pathname) . "\n");
        }
    }

    return 0;
}

function _ls_format_long($file)
{
    static $types = array(
        0140000 => 's',
        0120000 => 'l',
        0100000 => '-',
        0060000 => 'b',
        0040000 => 'd',
        0020000 => 'c',
        0010000 => 'p',
    );

    $ret = '';

    if (!($stat = \lstat($file))) {
        return \basename($file);
    }
    $stat = (object) $stat;

    if (isset($types[$stat->mode & 0170000])) {
        $ret .= $types[$stat->mode & 0170000];
    } else {
        $ret .= 'u';
    }

    $ret .= $stat->mode & 00400 ? 'r' : '-';
    $ret .= $stat->mode & 00200 ? 'w' : '-';
    $ret .= $stat->mode & 00100 ? ($stat->mode & 04000 ? 's' : 'x') : ($stat->mode & 04000 ? 'S' : '-');

    $ret .= $stat->mode & 00040 ? 'r' : '-';
    $ret .= $stat->mode & 00020 ? 'w' : '-';
    $ret .= $stat->mode & 00010 ? ($stat->mode & 02000 ? 's' : 'x') : ($stat->mode & 02000 ? 'S' : '-');

    $ret .= $stat->mode & 00004 ? 'r' : '-';
    $ret .= $stat->mode & 00002 ? 'w' : '-';
    $ret .= $stat->mode & 00001 ? ($stat->mode & 01000 ? 't' : 'x') : ($stat->mode & 01000 ? 'T' : '-');

    $ret .= ' ' . $stat->nlink;
    $ret .= ' ' . $stat->uid;
    $ret .= ' ' . $stat->gid;
    $ret .= ' ' . $stat->size;

    if ($stat->mtime > time() - 15552000 /* approximately 6 months */) {
        $ret .= strftime(' %b %e %H:%M', $stat->mtime);
    } else {
        $ret .= strftime(' %b %e %Y', $stat->mtime);
    }

    $ret .= ' ' . \basename($file);

    if (($stat->mode & 0170000) === 0120000) {
        $ret .= ' -> ' . readlink($file);
    }

    return $ret;
}

function mkdir(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $recursive = FALSE;
    if (isset($argv[0]) && $argv[0] === '-p') {
        $recursive = TRUE;
        array_shift($argv);
    }

    $mode = 0755;
    if (isset($argv[0]) && $argv[0] === '-m' && isset($argv[1])) {
        $mode = intval($argv[1], 8);
        array_shift($argv);
        array_shift($argv);
    }

    if (!isset($argv[0])) {
        fwrite($descriptors[2], "mkdir: directory not given\n");
        return 1;
    }

    if (!@\mkdir($argv[0], $mode, $recursive)) {
        fwrite($descriptors[2], "mkdir: cannot create directory {$argv[0]}\n");
        return 1;
    }

    return 0;
}

function mkfifo(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!function_exists('posix_mkfifo')) {
        fwrite($descriptors[2], "mkfifo: cannot access mkfifo() function\n");
        return 1;
    }

    $mode = 0755;
    if (isset($argv[0]) && $argv[0] === '-m' && isset($argv[1])) {
        $mode = intval($argv[1], 8);
        array_shift($argv);
        array_shift($argv);
    }

    if (!isset($argv[0])) {
        fwrite($descriptors[2], "mkfifo: pipe not given\n");
        return 1;
    }

    if (!@\posix_mkfifo($argv[0], $mode)) {
        fwrite($descriptors[2], "mkfifo: cannot create pipe {$argv[0]}\n");
        return 1;
    }

    return 0;
}

// FIXME: -f and -i options
function mv(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!isset($argv[0]) || !isset($argv[1])) {
        fwrite($descriptors[2], "mv: too few arguments\n");
        return 1;
    }

    if (!@\rename($argv[0], $argv[1])) {
        fwrite($descriptors[2], "mv: cannot move file {$argv[0]} to {$argv[1]}\n");
        return 1;
    }

    return 0;
}

function printf(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!isset($argv[0]) || !isset($argv[1])) {
        fwrite($descriptors[2], "printf: too few arguments\n");
        return 1;
    }

    fprintf($descriptors[1], str_replace(
        array('\n', '\r', '\t', '\a', '\v', '\f', '\\\\'),
        array("\n", "\r", "\t", "\a", "\v", "\f", "\\"),
        $argv[0]
    ), $argv[1]);

    return 0;
}

function pwd(array $argv, array $envp, array $descriptors)
{
    fwrite($descriptors[1], getcwd() . "\n");
    return (int) (getcwd() === FALSE);
}

// FIXME: -i option, better handling of -f
function rm(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $recursive = FALSE;
    $force = FALSE;

    while (isset($argv[0]) && $argv[0][0] === '-') {
        $option = substr(array_shift($argv), 1);
        foreach (str_split($option) as $flag) {
            switch ($flag) {
                case 'R':
                case 'r':
                    $recursive = TRUE;
                break;

                case 'f':
                    $force = TRUE;
                break;

                default:
                    fwrite($descriptors[2], "rm: -{$flag} is currently not supported\n");
                    return 1;
            }
        }
    }

    $status = 0;
    foreach ($argv as $file) {
        if (\is_dir($file)) {
            if (!$recursive) {
                fwrite($descriptors[2], "rm: {$file} is a directory\n");
                $status = 1;
                continue;
            }

            try {
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($file), \RecursiveIteratorIterator::CHILD_FIRST) as $entry)
                {
                    if ($entry->getBasename() === '.' || $entry->getBasename() === '..') {
                        continue;
                    } else if ($entry->isDir()) {
                        if (!@\rmdir($entry->getPathname())) {
                            throw new \Exception;
                        }
                    } else {
                        if (!@\unlink($entry->getPathname())) {
                            throw new \Exception;
                        }
                    }
                }

                if (!@\rmdir($file)) {
                    throw new \Exception;
                }

            } catch (\Exception $e) {
                fwrite($descriptors[2], "rm: cannot remove directory {$file}\n");
                $status = 1;
            }

        } else {
            if (!@\unlink($file) && !$force) {
                fwrite($descriptors[2], "rm: cannot remove file {$file}\n");
                $status = 1;
            }
        }
    }

    return $status;
}

// FIXME: -p option
function rmdir(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!isset($argv[0])) {
        fwrite($descriptors[2], "rmdir: too few arguments\n");
        return 1;
    }

    if (!@\rmdir($argv[0])) {
        fwrite($descriptors[2], "rmdir: cannot remove directory {$argv[0]}\n");
        return 1;
    }

    return 0;
}

function sleep(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!isset($argv[0])) {
        fwrite($descriptors[2], "sleep: too few arguments\n");
        return 1;
    }

    if (@\sleep(intval($argv[0])) !== 0) {
        fwrite($descriptors[2], "sleep: internal error\n");
        return 1;
    }

    return 0;
}

// FIXME: -c and -f options
function tail(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $from_end = TRUE;
    $n = 10;
    if (isset($argv[0]) && $argv[0] === '-n' && isset($argv[1])) {
        if ($argv[1][0] === '+') {
            $from_end = FALSE;
            $n = intval($argv[1]) - 1;

        } else {
            $n = intval(ltrim($argv[1], '-'));
        }

        array_shift($argv);
        array_shift($argv);
    }

    $handle = $descriptors[0];
    if (isset($argv[0])) {
        if (!($handle = @fopen($argv[0], 'r'))) {
            fwrite($descriptors[2], "head: cannot open file {$argv[0]} for reading\n");
            return 1;
        }
    }

    if (!$from_end) {
        for (; !feof($handle) && ($line = fgets($handle)) !== FALSE; --$n) {
            if ($n < 1) {
                fwrite($descriptors[1], $line);
            }
        }
    } else {
        $lines = array();
        for (; !feof($handle) && ($line = fgets($handle)) !== FALSE;) {
            $lines[] = $line;
            if (count($lines) > $n) {
                array_shift($lines);
            }
        }
        fwrite($descriptors[1], implode('', $lines));
    }

    return 0;
}

// FIXME: grouping
function test(array $argv, array $envp, array $descriptors)
{
    if ($argv[0] === '[') {
        if (end($argv) !== ']') {
            fwrite($descriptors[2], "[: missing ]\n");
            return 2;
        }

        array_pop($argv);
    }

    $program = array_shift($argv);

    $not = FALSE;
    if ($argv[0] === '!') {
        $not = TRUE;
        array_shift($argv);
    }

    $ok = FALSE;
    if (count($argv) === 2 && $argv[0][0] === '-') {
        if (!isset($argv[1])) {
            fwrite($descriptors[2], "$program: missing operand for {$argv[0]}\n");
            return 2;
        }

        $op = array_shift($argv);
        $operand = array_shift($argv);

        switch ($op) {
            case '-b': // block
            case '-c': // char
            case '-d': // directory
            case '-f': // regular
            case '-g': // set gid
            case '-h': // symlink
            case '-L': // symlink
            case '-p': // pipe
            case '-S': // socket
            case '-s': // size > 0
            case '-u': // set uid
                if (($stat = @\lstat($operand)) === FALSE) {
                    fwrite($descriptors[2], "$program: cannot stat file {$operand}\n");
                    $ok = FALSE;
                    break;
                }
                $stat = (object) $stat;

                $results = array(
                    '-b' => ($stat->mode & 0170000) === 0060000,
                    '-c' => ($stat->mode & 0170000) === 0020000,
                    '-d' => ($stat->mode & 0170000) === 0040000,
                    '-f' => ($stat->mode & 0170000) === 0100000,
                    '-g' => ($stat->mode & 0002000) === 0002000,
                    '-h' => ($stat->mode & 0170000) === 0120000,
                    '-L' => ($stat->mode & 0170000) === 0120000,
                    '-p' => ($stat->mode & 0170000) === 0010000,
                    '-S' => ($stat->mode & 0170000) === 0140000,
                    '-s' => $stat->size > 0,
                    '-u' => ($stat->mode & 0004000) === 0004000,
                );

                $ok = $results[$op];
            break;

            case '-r': // readable
                $ok = @\is_readable($operand);
            break;

            case '-w': // writeable
                $ok = @\is_writeable($operand);
            break;

            case '-x': // executable
                $ok = @\is_executable($operand);
            break;

            case '-z': // string zero length
                $ok = strlen($operand) === 0;
            break;

            case '-n': // strlen > 0
                $ok = strlen($operand) > 0;
            break;

            default:
                fwrite($descriptors[2], "$program: {$op} currently not supported\n");
                return 2;
        }

    } else if (count($argv) === 1) {
        $ok = strlen(array_shift($argv)) > 0;
    } else if (count($argv) === 3) {
        $left = array_shift($argv);
        $op = array_shift($argv);
        $right = array_shift($argv);

        switch ($op) {
            case '=':
                $ok = $left === $right;
            break;

            case '!=':
                $ok = $left !== $right;
            break;

            case '-eq':
            case '-ne':
            case '-gt':
            case '-ge':
            case '-lt':
            case '-le':
                if (((string) ($a = intval($left))) !== $left ||
                    ((string) ($b = intval($right))) !== $right)
                {
                    fwrite($descriptors[2], "$program: bad numbers\n");
                    return 2;
                }

                $result = array(
                    '-eq' => $a === $b,
                    '-ne' => $a !== $b,
                    '-gt' => $a >   $b,
                    '-ge' => $a >=  $b,
                    '-lt' => $a <   $b,
                    '-le' => $a <=  $b,
                );

                $ok = $result[$op];
            break;

            default:
                fwrite($descriptors[2], "$program: unknown binop $op\n");
                return 2;
        }
    }

    if (count($argv) > 0) {
        fwrite($descriptors[2], "$program: too many arguments\n");
        return 2;
    }

    return (int) !($ok !== $not);
}

// FIXME: options
function touch(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    if (!isset($argv[0])) {
        fwrite($descriptors[2], "touch: too few arguments\n");
        return 1;
    }

    $status = 0;
    foreach ($argv as $file) {
        if (!@\touch($file)) {
            fwrite($descriptors[2], "touch: cannot touch file {$file}\n");
            $status = 1;
        }
    }

    return $status;
}

function true_(array $argv, array $envp, array $descriptors)
{
    return 0;
}

function wc(array $argv, array $envp, array $descriptors)
{
    array_shift($argv);

    $bytes = FALSE;
    $lines = FALSE;
    $words = FALSE;

    while (isset($argv[0]) && $argv[0][0] === '-' && $argv[0] !== '-') {
        $option = substr(array_shift($argv), 1);
        foreach (str_split($option) as $flag) {
            switch ($flag) {
                case 'l':
                    $lines = TRUE;
                break;

                case 'w':
                    $words = TRUE;
                break;

                case 'c':
                case 'm':
                    $bytes = TRUE;
                break;

                default:
                    fwrite($descriptors[2], "wc: -{$flag} is currently not supported\n");
                    return 1;
            }
        }
    }

    if (empty($argv)) {
        $argv[] = '-';
    }

    $status = 0;

    foreach ($argv as $file) {
        if ($file === '-') {
            $handle = $descriptors[0];
        } else {
            if (!($handle = @fopen($file, 'r'))) {
                fwrite($descriptors[2], "wc: cannot open file {$file}\n");
                $status = 1;
                continue;
            }
        }

        $l = 0;
        $c = 0;
        $w = 0;

        while (!feof($handle) && ($line = fgets($handle)) !== FALSE) {
            ++$l;
            $c += strlen($line);
            $w += count(preg_split('~\s+~', $line, -1, PREG_SPLIT_NO_EMPTY));
        }

        $s = '';

        if (!$lines && !$words && !$bytes) {
            $s = sprintf('%d %d %d', $l, $w, $c);
        } else {
            $s = array();
            if ($lines) {
                $s[] = $l;
            }

            if ($words) {
                $s[] = $w;
            }

            if ($bytes) {
                $s[] = $c;
            }

            $s = implode(' ', $s);
        }

        if ($file !== '-' || count($argv) > 1) {
            $s .= ' ' . $file;
        }

        fwrite($descriptors[1], $s . "\n");
    }

    return $status;
}
