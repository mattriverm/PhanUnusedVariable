<?php
function foo8() {
    $data = 3.2;
    $cb = function(&$value) {
        if (is_float($value)) {
            $value = (string)$value;
        }
    };
    $cb($data);
}

class C8 { public static function bar($x) { return $x * 3; } }

function test8() {
    $data = [3];
    array_walk($data, function(&$value) {
        $value = C8::bar($value);
    });
}
