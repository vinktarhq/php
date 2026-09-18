<?php

declare(strict_types=1);

/*
 * One case of spec/fixtures/hostile.json in a process of its own, run by HostileTest for the cases
 * whose failure is a fatal error, or that install the process-wide handlers.
 *
 *     php hostile.php <case name>
 *
 * Prints what went wrong as a JSON list and exits 0 only when that list is empty.
 */

require __DIR__.'/../../vendor/autoload.php';

use Vinktar\Tests\Spec\SpecFile;
use Vinktar\Tests\Support\HostileRunner;

$args = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], \is_string(...))) : [];
$case = SpecFile::cases('fixtures/hostile.json')[$args[1] ?? ''][0] ?? null;
if ($case === null) {
    fwrite(\STDERR, "no such case\n");
    exit(2);
}

$failures = (new HostileRunner())->run($case);
echo json_encode($failures, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
exit($failures === [] ? 0 : 1);
