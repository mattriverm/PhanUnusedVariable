<?php

function test(&$array) {
    assert(is_array($array));
    $array = [];
}
