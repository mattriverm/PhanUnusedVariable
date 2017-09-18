<?php

function example14($start) {  // warns about unused $start
    for ($i = $start; $i < 10; $i++) {
        printf("i=%d\n", $i);
    }
}
