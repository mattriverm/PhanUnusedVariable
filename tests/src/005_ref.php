<?php
function foo(string &$outputArg) {
    $outputArg = str_replace("_", "-", $outputArg);
}
