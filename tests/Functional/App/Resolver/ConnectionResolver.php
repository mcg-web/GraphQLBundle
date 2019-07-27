<?php

declare(strict_types=1);

namespace Overblog\GraphQLBundle\Tests\Functional\App\Resolver;

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\PromiseAdapter;
use Overblog\GraphQLBundle\Executor\Promise\Adapter\ReactPromiseAdapter;
use Overblog\GraphQLBundle\Relay\Connection\ConnectionBuilder;
use Overblog\GraphQLBundle\Relay\Connection\Output\Edge;
use Overblog\GraphQLBundle\Resolver\ResolveArgStack;
use React\Promise\Promise;

class ConnectionResolver
{
    private $allUsers = [
        [
            'name' => 'Dan',
            'friends' => [1, 2, 3, 4],
        ],
        [
            'name' => 'Nick',
            'friends' => [0, 2, 3, 4],
        ],
        [
            'name' => 'Lee',
            'friends' => [0, 1, 3, 4],
        ],
        [
            'name' => 'Joe',
            'friends' => [0, 1, 2, 4],
        ],
        [
            'name' => 'Tim',
            'friends' => [0, 1, 2, 3],
        ],
    ];

    /**
     * @var PromiseAdapter
     */
    private $promiseAdapter;

    private $resolveArgStack;

    public function __construct(PromiseAdapter $promiseAdapter, ResolveArgStack $resolveArgStack)
    {
        $this->promiseAdapter = $promiseAdapter;
        $this->resolveArgStack = $resolveArgStack;
    }

    public function friendsResolver()
    {
        return $this->promiseAdapter->create(function (callable $resolve) {
            $resolveArg = $this->resolveArgStack->getCurrentResolveArg();

            return $resolve((new ConnectionBuilder())
                ->connectionFromArray($resolveArg->getValue()['friends'], $resolveArg->getArgs()));
        });
    }

    public function resolveNode(Edge $edge)
    {
        return $this->promiseAdapter->create(function (callable $resolve) use ($edge) {
            return $resolve(isset($this->allUsers[$edge->getNode()]) ? $this->allUsers[$edge->getNode()] : null);
        });
    }

    public function resolveConnection()
    {
        return $this->promiseAdapter->create(function (callable $resolve) {
            return $resolve(\count($this->allUsers) - 1);
        });
    }

    public function resolveQuery()
    {
        if ($this->promiseAdapter instanceof SyncPromiseAdapter) {
            return new Deferred(function () {
                return $this->allUsers[0];
            });
        } elseif ($this->promiseAdapter instanceof ReactPromiseAdapter) {
            return new Promise(function (callable $resolve) {
                return $resolve($this->allUsers[0]);
            });
        }

        return $this->allUsers[0];
    }

    public function resolvePromiseFullFilled($value)
    {
        return $this->promiseAdapter->createFulfilled($value);
    }
}
