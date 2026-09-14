<?php

declare(strict_types=1);

namespace Vinktar;

/**
 * The package, as it names itself on the wire (`$lib`, `$lib_version`, client reports).
 *
 * Composer takes versions from tags, so this constant is the only place the version is written
 * down. The release workflow refuses a tag that disagrees with it.
 */
final class Version
{
    public const VERSION = '0.1.0-beta.1';

    public const LIB = 'vinktar-php';
}
