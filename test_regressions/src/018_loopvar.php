<?php

function testComplexExpr(array $values) {
    $isFirst = false;
    $return = [];
    foreach ($values as $v) {
        $x = $v + ($isFirst ? 1 : 0);  // isn't recorded as use of $isFirst
        $return[] = intdiv($isFirst ? 3 : 0, 2);  // isn't recorded as use
        $return[] = $v ? $isFirst : false;
        $return[] = $x;
        $isFirst = false;
    }
    return true;
}
