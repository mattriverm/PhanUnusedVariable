<?php

// Issue #12
function forloop007_1($start) {  // warns about unused $start
    for ($i = $start; $i < 10; $i++) {
        printf("i=%d\n", $i);
    }
}

function forloop007_2(string $start) {
    for ($i = 0; $i < 10; $i++) {
        printf("i=%s\n", $start);
    }
}

// Should warn about $i
function forloop007_3(int $start) {
    for ($i = 0; $start < 10; $start++) {
        printf("i=%s\n", $start);
    }
}


function foreachloop007_1() {
    $a = ['a' => 'b'];
    foreach ($a as $k => $v) {
        echo count($a);
    }
}

// Should not throw, should warn about $i and $start
function forloop007_4(int $start) {
    $i = 0;
    for (; ; ) {
        break;
    }
}