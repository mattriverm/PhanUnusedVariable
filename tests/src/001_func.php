<?php
// Note: Functions, Methods, and Closures use the exact same code to check for unused parameters.
// So, it's redundant to duplicate the tests. Just add a few tests to act as a sanity check.

// this should fail on $one and $two
function add(int $my_param):int {
    $one = "hello world";
    $two = $my_param;
    $my_param+1;
    return $my_param;
}

function sub(int $other_param) {
    return $other_param;
}

// This should pass
function sha1_verify($password, $hash)
{
    $shaHash = sha1($password);
    return ($shaHash === $hash);
}

// This should fail on $three
function sha1_verifyB($password, $hash, $three)
{
    $shaHash = sha1($password);
    return ($shaHash === $hash);
}
