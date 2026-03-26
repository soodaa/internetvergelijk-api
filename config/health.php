<?php

return [
    'providers' => [
        'KPN' => [
            'postcode' => '2725DN',
            'number' => 27,
            'expects' => ['dsl'],
        ],
        'KPN-pairbonded' => [
            'postcode' => '2725DN',
            'number' => 27,
            'expects' => ['dsl'],
        ],
        'Ziggo' => [
            'postcode' => '2719GB',
            'number' => 23,
            'expects' => ['kabel'],
        ],
        'T-Mobile-GOP' => [
            'postcode' => '3201AA',
            'number' => 7,
            'expects' => ['glasvezel'],
        ],
        'Odido-OPF' => [
            'postcode' => '2725DN',
            'number' => 27,
            'expects' => ['glasvezel'],
        ],
        'Caiway' => [
            'postcode' => '2678WR',
            'number' => 71,
            'expects' => ['glasvezel'],
        ],
        'Jonaz' => [
            'postcode' => '3831DL',
            'number' => 3,
            'expects' => ['glasvezel'],
        ],
        'L2Fiber' => [
            'postcode' => '3061RA',
            'number' => 16,
            'expects' => ['glasvezel'],
        ],
        'GlaswebVenray' => [
            'postcode' => '5824AP',
            'number' => 15,
            'expects' => ['glasvezel'],
        ],
        'HSLnet' => [
            'postcode' => '5553VC',
            'number' => 15,
            'expects' => ['glasvezel'],
        ],
        'CAI Harderwijk' => [
            'postcode' => '3843CA',
            'number' => 43,
            'expects' => ['glasvezel'],
        ],
        'KTWaalre' => [
            'postcode' => '5582AD',
            'number' => 10,
            'expects' => ['glasvezel'],
        ],
        'SKV' => [
            'postcode' => '1051AC',
            'number' => 38,
            'expects' => ['glasvezel'],
        ],
        'EFiber' => [
            'postcode' => '3286LR',
            'number' => 18,
            'expects' => ['glasvezel'],
        ],
        'OpenDutchFiber' => [
            'postcode' => '2266KB',
            'number' => 114,
            'expects' => ['glasvezel'],
        ],
    ],
    'tokens' => [
        'kpn' => [
            'label' => 'KPN',
        ],
        'delta-fiber' => [
            'label' => 'Caiway/Delta Fiber',
        ],
    ],
];
