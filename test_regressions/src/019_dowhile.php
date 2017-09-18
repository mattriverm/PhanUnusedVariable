<?php

function testWhile() {
    do {
        $x = false;
        if (rand() % 2 > 0) {
            $x = true;
        }
        echo ".";
    } while ($x);
}
