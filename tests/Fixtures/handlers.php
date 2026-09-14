<?php

declare(strict_types=1);

/*
 * A script with the SDK's handlers installed, run in its own process by HandlersTest.
 *
 *     php handlers.php <mode> <output file> [<second output file>]
 */

require __DIR__.'/../../vendor/autoload.php';

use Vinktar\Client;
use Vinktar\Tests\Support\FileTransport;

$args = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], \is_string(...))) : [];
$mode = $args[1] ?? '';
$out = $args[2] ?? '';
$second = $args[3] ?? null;
// With VINKTAR_NO_SDK set, the same script runs with no client at all: the baseline the handlers must not change.
$withSdk = getenv('VINKTAR_NO_SDK') === false;

if ($mode === 'previous') {
    set_exception_handler(static function (Throwable $error): void {
        echo "previous handler saw: {$error->getMessage()}\n";
    });
}

$options = static fn (string $path): array => [
    'writeKey' => 'vnk_sk_handlers',
    'transport' => new FileTransport($path),
    'captureErrors' => true,
    'logger' => static function (): void {},
    'projectRoot' => dirname(__DIR__, 2),
];
$client = $withSdk ? new Client($options($out)) : null;
$other = $withSdk && $second !== null ? new Client($options($second)) : null;
$client?->setUser(['id' => 'handler-user']);

function fail_deep(): never
{
    throw new RuntimeException('uncaught in a function');
}

switch ($mode) {
    case 'exception':
    case 'previous':
    case 'two-clients':
        fail_deep();

        // no break
    case 'warning':
        trigger_error('a warning the application raised', E_USER_WARNING);
        @trigger_error('a silenced warning', E_USER_WARNING);
        trigger_error('a notice', E_USER_NOTICE);
        echo "continued\n";
        $client?->captureMessage('after the warnings');
        break;

    case 'fatal':
        ini_set('memory_limit', '16M');
        $hog = [];
        for ($i = 0; $i < 1024; ++$i) {
            $hog[] = str_repeat('x', 1024 * 1024);
        }
}
