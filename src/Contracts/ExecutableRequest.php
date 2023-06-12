<?php

namespace Prismaticoder\MakerChecker\Contracts;

abstract class ExecutableRequest
{
    abstract public function execute(MakerCheckerRequestInterface $request);

    /**
     * Set the fields in the payload that mark the request as unique.
     *
     * @return array
     */
    public function uniqueBy(): array
    {
        return [];
    }

    /**
     * Define an action to be performed before a request is approved.
     *
     * @param MakerCheckerRequestInterface $request
     *
     * @return void
     */
    public function beforeApproval(MakerCheckerRequestInterface $request): void
    {

    }

    /**
     * Define an action to be performed after a request is approved.
     *
     * @param MakerCheckerRequestInterface $request
     *
     * @return void
     */
    public function afterApproval(MakerCheckerRequestInterface $request): void
    {

    }

    /**
     * Define an action to be performed before a request is rejected.
     *
     * @param MakerCheckerRequestInterface $request
     *
     * @return void
     */
    public function beforeRejection(MakerCheckerRequestInterface $request): void
    {

    }

    /**
     * Define an action to be performed after a request is rejected.
     *
     * @param MakerCheckerRequestInterface $request
     *
     * @return void
     */
    public function afterRejection(MakerCheckerRequestInterface $request): void
    {

    }

    /**
     * Define an action to be performed when a request fails to be processed.
     *
     * @param MakerCheckerRequestInterface $request
     *
     * @return void
     */
    public function onFailure(MakerCheckerRequestInterface $request): void
    {

    }
}
