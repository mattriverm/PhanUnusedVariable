<?php

function singleton21() {
    static $obj = null;
    if ($obj === null) {
        $obj = new stdClass();
    }
    return $obj;
}

function test21() {
    $obj = singleton21();
    $obj->prop[] = 'value';  // however, `$obj->prop =` counts as a usage of $obj
}
