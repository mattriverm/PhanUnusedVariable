<?php
function assignInLoopAndUseAsCond()
{
    $arr = ['prop' => 1];
    while ($arr['prop'] > 0) {
        $arr = ['prop' => rand() % 2];  // should not warn.
    }
}