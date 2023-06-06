<?php

return [
    /*
     * The time, in minutes, at which point a pending request is marked as expired.
     * If left as null, pending requests would be allowed to stay as long as possible till an action is performed on them.
     */
    'request_expiration_in_minutes' => null,

    /*
     * The queue connection for dispatching actions to be performed when a request is approved or rejected.
     * If not set, the default queue connection is used.
     */
    'queue_connection' => null,

    /*
     * This configuration is for the purpose of limiting the actions of making/checking requests to certain models.
     * If it is left empty, any model will be able to initiate/approve/decline a request.
     */
    'whitelisted_models' => [
        'maker' => [], //e.g [User::class]
        'checker' => [], //e.g [Admin::class]
    ],

    // The model attached to the table for storing requests.
    'request_model' => null,

    // The table that will be created by the published migration andthat will be attached to the request model.
    'table_name' => 'maker_checker_requests',

    /*
     * The database connection to be used by the migration when creating the table.
     * If not set, the default database connection of your Laravel application will be used.
     */
    'database_connection' => null,
];
