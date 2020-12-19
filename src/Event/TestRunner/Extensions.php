<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Event\TestRunner;

use function array_merge;
use function asort;
use function extension_loaded;
use function get_loaded_extensions;
use ArrayIterator;
use IteratorAggregate;

final class Extensions implements IteratorAggregate
{
    public function loaded(string $name): bool
    {
        return extension_loaded($name);
    }

    public function getIterator()
    {
        $all = array_merge(
            get_loaded_extensions(true),
            get_loaded_extensions(false)
        );

        asort($all);

        return new ArrayIterator($all);
    }
}