<?php
// Note: Functions, Methods, and Closures use the exact same code to check for unused parameters.
// So, it's redundant to duplicate the tests. Just add a few tests to act as a sanity check.

// this should fail on $one and $two
(function (int $my_param):int {
    $one = "hello world";
    $two = $my_param;
    $my_param+1;
    return $my_param;
})(3);

(function (int $other_param) {
    return $other_param;
})(2);

// This should pass
(function($password, $hash) {
    $shaHash = sha1($password);
    return ($shaHash === $hash);
})('hunter2', 'badhash');

// This should fail on $three
$c = function($password, $hash, $three) {
    $shaHash = sha1($password);
    return ($shaHash === $hash);
};
$c('x', 'badhash', 'threeVal');
