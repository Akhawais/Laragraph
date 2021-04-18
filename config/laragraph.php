<?php

return [
    'prefix' => 'gql',
    'schemas' => [
        'v1' => [
            'schema' => [
                'query' => 'V1Query',
                'mutation' => 'V1Mutation',
            ],
            'middleware' => null,
        ],
        /* 'v2' => [
        *       'schema' => [
        *           'query' => 'V2Query',
        *           'mutation' => 'V2Mutation',
        *       ],
        *       'middleware' => 'throttle:60,1',
        *   ]
        */
    ]
];
