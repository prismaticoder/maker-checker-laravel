<?php

namespace Prismaticode\MakerChecker\Contracts;

interface ExecuteRequestInterface
{
    //should have empty constructors
    public function execute(MakerCheckerRequestInterface $request);

    public function uniqueBy(): array;

    public function beforeApproval(MakerCheckerRequestInterface $request);

    public function afterApproval(MakerCheckerRequestInterface $request);

    public function beforeRejection(MakerCheckerRequestInterface $request);

    public function afterRejection(MakerCheckerRequestInterface $request);

    public function onFailure(MakerCheckerRequestInterface $request);
}
