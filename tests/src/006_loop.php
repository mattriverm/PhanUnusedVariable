<?php
function foo2($a, $b) {
    $limit = $a < $b ? $a : $b;
    for ($i = 0; $i < $limit; $i++) {
        echo $i;
    }
    return $i;
}
function foo4($a, $b) {
    $limit = $a < $b ? $a : $b;
    $i = 0;
    while (true && $i < $limit) {
        echo $i;
        $i++;
    }
    return $i;
}
