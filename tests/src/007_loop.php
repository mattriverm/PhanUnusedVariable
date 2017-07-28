<?php
function foo3() {
    $sleepTime = 50000;
    while (rand() % 5 > 0) {
        echo "Other code\n";
        usleep($sleepTime);
        if (rand() % 2 > 0) {
           $sleepTime = $sleepTime * 2;
        }
    }
}
