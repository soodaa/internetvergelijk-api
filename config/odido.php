<?php

return [
    /**
     * Maximum network capabilities per variant (in Mbps)
     * These caps are applied after parsing package speeds
     */
    'caps' => [
        'WBA' => null,   // Geen kunstmatige cap; volg providerwaarde
        'GOP' => null,
        'OPF' => null,
        'DFN' => null,
        'GPT' => null,
        'DSL' => 100,    // DSL maximum
    ],
];


