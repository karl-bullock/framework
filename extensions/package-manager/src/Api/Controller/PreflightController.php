<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\ExtensionManager\Api\Controller;

use Flarum\Bus\Dispatcher;
use Flarum\ExtensionManager\Command\Preflight;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reports whether a major update would work, without changing anything.
 *
 * Dispatched straight to the bus rather than through the job dispatcher: this
 * writes nothing, so there is nothing to serialise into a task, and the answer
 * is only useful while the administrator is looking at it.
 */
class PreflightController implements RequestHandlerInterface
{
    public function __construct(
        protected Dispatcher $bus
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        return new JsonResponse([
            'data' => $this->bus->dispatch(new Preflight($actor)),
        ]);
    }
}
