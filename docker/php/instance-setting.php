<?php

/**
 * Print one of this instance's settings, the way the application resolves it.
 *
 *   php instance-setting.php DB_HOST [fallback]
 *
 * Its own file rather than a string inside the entrypoint, because it is the
 * piece with a rule in it, and a rule nobody can run is a rule nobody has
 * checked — see tests/Shell/instance-setting.test.sh.
 *
 * The rule is Laravel's: the environment first, then `.env`. Its dotenv is
 * immutable, so a variable already in the process environment is never replaced
 * by the file, and anything reading these has to agree with that or it will
 * connect somewhere the application does not.
 *
 * It exists at all because the production stack stopped delivering `DB_*` as
 * environment variables. They are seeded into `.env` instead, so that the setup
 * wizard can change them afterwards — which is the whole point of offering a
 * database of your own. An entrypoint still reading only `getenv()` would wait
 * two minutes for 127.0.0.1 on every boot and then carry on regardless, which
 * is a failure nobody would look at twice.
 */
declare(strict_types=1);

$key = $argv[1] ?? '';
$fallback = $argv[2] ?? '';

if ($key === '') {
    fwrite(STDERR, "usage: instance-setting.php KEY [fallback]\n");
    exit(2);
}

$fromEnvironment = getenv($key);

if ($fromEnvironment !== false && $fromEnvironment !== '') {
    echo $fromEnvironment;
    exit(0);
}

$path = rtrim((string) (getenv('TICKTZ_APP_ROOT') ?: '/var/www/html'), '/').'/.env';

foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = ltrim($line);

    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$name, $value] = explode('=', $line, 2);

    if (trim($name) !== $key) {
        continue;
    }

    // Quoted when it has to be: the installer quotes a value containing a `#`,
    // which would otherwise be a comment from that character on, or a space,
    // which would otherwise truncate it.
    $value = trim($value);

    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
        $quote = $value[0];
        $value = substr($value, 1, -1);

        // A double-quoted value carries escapes as well as delimiters: the
        // installer writes `\\`, `\"` and `\$` for a backslash, a quote and a
        // dollar. Stripping the quotes and stopping there hands back a
        // password with extra backslashes in it — which fails to connect sixty
        // times and then says the database is unreachable, while the framework
        // reads the same line and connects. Single quotes are literal, as they
        // are in dotenv.
        if ($quote === '"') {
            $value = preg_replace('/\\\\([\\\\"$])/', '$1', $value) ?? $value;
        }
    }

    // Present but empty is not a value, for the same reason an empty
    // environment variable is not one: `DB_HOST=` is what a copied example
    // leaves behind, and treating it as an answer means connecting to nothing.
    if ($value === '') {
        break;
    }

    echo $value;
    exit(0);
}

echo $fallback;
