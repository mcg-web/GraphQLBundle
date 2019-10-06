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

class Closure implements CodeInterface
{
    private $args = [];

    /** @var Code */
    private $code;

    private $uses = [];

    /**
     * Closure constructor.
     * @param array $args
     * @param Code|string|null $code
     * @param array $uses
     */
    public function __construct(array $args = [], $code = null, array $uses = [])
    {
        $this->setArgs($args);
        $this->setCode($code);
        $this->setUses($uses);
    }

    public function setArgs(array $args): self
    {
        $this->args = \array_values($args);

        return $this;
    }

    /**
     * @param Code|string|string[]|null $code $code
     * @return Closure
     */
    public function setCode($code): self
    {
        if (!$code instanceof Code) {
            $code = new Code($code);
        }
        $this->code = $code;
        return $this;
    }

    public function getCode(): Code
    {
        return $this->code;
    }

    public function setUses(array $uses): self
    {
        $this->uses = \array_values($uses);

        return $this;
    }

    public function getUses(): array
    {
        return $this->uses;
    }

    public function generate(): string
    {
        $code = <<<FUNC
function (<args>)<uses> {
<code>
}
FUNC;

        return \strtr($code, [
            '<args>' => \join(', ', $this->args),
            '<uses>' => \join(', ', $this->uses),
            '<code>' => $this->code->generate(),
        ]);
    }
}
