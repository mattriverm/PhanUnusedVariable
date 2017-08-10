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
