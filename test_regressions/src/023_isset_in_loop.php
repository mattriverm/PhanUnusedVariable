<?php

function test23() {
    $checked = [];
    foreach ([2,3,2,5] as $number) {
        if (!isset($checked[$number])) {
            echo "n=$number\n";
            $checked[$number] = true;  // false positive PhanPluginUnusedVariable Variable is never used: $checked
        }
    }
}

function test23b(int $x) {
    $checked = [2 => true];  // correctly emits no warnings
    if (!isset($checked[$x])) {
        return true;
    }
    return false;
}
