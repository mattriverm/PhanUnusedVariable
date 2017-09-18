<?php

function example12($x) {
    $z = null;
    if ($x === 2) {
        $y = 3;  // Observed: No warning. expected: Both unused definitions warn.
        $z = 2;
    } elseif ($x === 2) {
        $y = 4;  // Observed and expected: warns
        $z = 4;
    }

    return $z;
}
