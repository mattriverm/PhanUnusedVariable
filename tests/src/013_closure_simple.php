<?php

return function() {
    $c = 42;

    return function($elem) use($c) {
        return $elem;
    };
};
