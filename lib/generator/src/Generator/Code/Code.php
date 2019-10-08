<?php declare(strict_types=1);

/*
 * This file is part of the OverblogGraphQLPhpGenerator package.
 *
 * (c) Overblog <http://github.com/overblog/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Overblog\GraphQLGenerator\Generator\Code;

class Code implements CodeInterface
{
    /** @var string[] */
    private $lines;

    public function __construct($lines = null)
    {
        $this->setLines($lines);
    }

    public function getLines(): array
    {
        return $this->lines;
    }

    public function setLines($lines): self
    {
        $this->lines = $this->cleanLines($lines);

        return $this;
    }

    public function prepend($lines, ?int $position = null): self
    {
        $newLines = $this->cleanLines($lines);

        if (null === $position) {
            $this->lines = \array_merge($newLines, $this->lines);
        } else {
            \array_splice($this->lines, $position, 0, $newLines);
        }

        return $this;
    }

    public function append($lines, ?int $position = null): self
    {
        $newLines = $this->cleanLines($lines);
        if (null === $position) {
            $this->lines = \array_merge($this->lines, $newLines);
        } else {
            \array_splice($this->lines, $position + 1, 0, $newLines);
        }

        return $this;
    }

    public function replace($lines, int $position): self
    {
        $newLines = $this->cleanLines($lines);
        \array_splice($this->lines, $position, 1, $newLines);

        return $this;
    }

    private function cleanLines($lines): array
    {
        if (\is_string($lines)) {
            $lines = \explode("\n", $lines);
        } elseif (\is_iterable($lines)) {
            $lines = \is_array($lines) ? $lines : \iterator_to_array($lines);
        } elseif (null === $lines) {
            $lines = [];
        } else {
            throw new \InvalidArgumentException('Lines should be an array, a string, an iterable or null.');
        }

        return \array_values($lines);
    }

    public function generate(): string
    {
        return \join("\n", $this->lines);
    }
}
