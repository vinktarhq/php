<?php

declare(strict_types=1);

/*
 * The SDK on a locked-down host, run in its own process by RestrictedHostTest with the ini settings
 * under test (`open_basedir`, `disable_functions`), which cannot be set or undone at runtime.
 *
 *     php -d <setting> restricted.php <mode> <output file>
 *
 * The application's error handler throws for every level, the way a strict framework's does.
 */

require __DIR__.'/../../vendor/autoload.php';

use Vinktar\Client;
use Vinktar\Tests\Support\FileTransport;

$args = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], \is_string(...))) : [];
$mode = $args[1] ?? '';
$out = $args[2] ?? '';

set_error_handler(static function (int $type, string $message): bool {
    if ((error_reporting() & $type) === 0) {
        return false;
    }
    echo "the application's handler saw: {$message}\n";
    throw new ErrorException($message, 0, $type);
});

$client = new Client([
    'writeKey' => 'vnk_sk_restricted',
    'transport' => new FileTransport($out),
    'autoFlush' => false,
    // Frames under /etc are "the application's", so their source is looked for.
    'projectRoot' => '/etc',
    'logger' => static function (string $level, string $message): void {
        echo "{$level}: {$message}\n";
    },
]);

$error = new RuntimeException('raised on a restricted host');
if ($mode === 'open-basedir') {
    // A frame whose file is outside open_basedir: reading it for context lines is what PHP refuses.
    (new ReflectionProperty(Exception::class, 'file'))->setValue($error, '/etc/hosts');
}
$client->captureException($error);
$client->track('still_tracked');
echo $client->flush() ? "flushed\n" : "not flushed\n";
