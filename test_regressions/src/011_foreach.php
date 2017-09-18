<?php

function test11(array $group) {
    $minKey = null;
    $minIndex = 0;
    foreach ($group as $i => $value) {
        if (!is_null($minKey)) {
            if (strcmp($value, $minKey) >= 0) {
                break;
            }
        }
        $minIndex = $i;
        $minKey = $value;
    }
    return $minIndex;
}
