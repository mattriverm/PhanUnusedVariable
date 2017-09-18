<?php
function test9(array $arr) {
    $count = count($arr);
    while ($count-- > 10) {
        array_pop($arr);
    }
    return $arr;
}
