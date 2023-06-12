<?php

return [
    /*
     * This configuration is to determine whether you want to have the package run checks for whether a request already
     * exists before creating one. If set to false, duplicate requests (with similar payload/subjects) would be allowed.
     */
    'ensure_requests_are_unique' => true,
    /*
     * The time, in minutes, at which point a pending request is marked as expired.
     * If left as null, pending requests would be allowed to stay as long as possible till an action is performed on them.
     */
    'request_expiration_in_minutes' => null,

    /*
     * This configuration is for the purpose of limiting the actions of making/checking requests to certain models.
     * If it is left empty, any model will be able to initiate/approve/decline a request.
     */
    'whitelisted_models' => [
        'maker' => [], //e.g [User::class]
        'checker' => [], //e.g [Admin::class]
    ],

    // The model attached to the table for storing requests.
    'request_model' => \Prismaticoder\MakerChecker\Models\MakerCheckerRequest::class,

    // The table that will be created by the published migration andthat will be attached to the request model.
    'table_name' => 'maker_checker_requests',
];
