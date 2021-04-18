<?php

namespace Scriptle\Laragraph;

#[\Attribute(\Attribute::IS_REPEATABLE | \Attribute::TARGET_ALL)]
class Field {

    public function __construct(...$things)
    {
    }
}
