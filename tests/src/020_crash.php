<?php

/**
 * @suppress PhanTypeExpectedObjectPropAccess
 * @suppress PhanTypeMismatchDimFetch
 * Suppressing issues that aren't emitted by this plugin. See https://github.com/phan/phan/issues/1601
 */
function example() {
    $fields = ['key' => 'value'];
    $x = [];
    $o = new stdClass();
    foreach ($fields as $x['field'] => $o->propName) {
        var_export($x);
        var_export($o);
    }
    foreach ($fields as $o->propName2 => $x['field2']) {
        var_export($x);
        var_export($o);
    }
    foreach ($fields as $key => $x['field2']) {
        var_export($x);
    }
}
