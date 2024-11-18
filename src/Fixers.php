<?php
declare(strict_types=1);

namespace JJCustom;

use Generator;
use IteratorAggregate;
use PhpCsFixer\Finder;
use PhpCsFixer\Fixer\FixerInterface;
use ReflectionClass;
use Traversable;

/**
 * @implements \IteratorAggregate<FixerInterface>
 */
final class Fixers implements IteratorAggregate
{
    /**
     * {@inheritdoc}
     */
    public function getIterator(): Generator
    {
        $finder = new Finder();
        $finder
            ->in(__DIR__)
            ->name('*.php');

        $files = array_map(
            static fn ($file) => $file->getPathname(),
            iterator_to_array($finder)
        );

        sort($files);

        foreach ($files as $file) {
            require_once $file;

            $class = __NAMESPACE__ . str_replace('/', '\\', mb_substr($file, mb_strlen(__DIR__), -4));

            if (!class_exists($class)) {
                continue;
            }

            $rfl = new ReflectionClass($class);

            if (!$rfl->implementsInterface(FixerInterface::class) || $rfl->isAbstract()) {
                continue;
            }

            yield new $class();
        }
    }
}
