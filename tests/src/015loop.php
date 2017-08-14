<?php

function testAssignDimRef(array $values) {
    foreach ($values as &$val) {
        $val['x'] = $val['x'] * 2;  // warns unexpectedly about PhanPluginUnusedVariable
    }
    var_export($values);
}
function testAssignRegularRef(array $values) {
    foreach ($values as &$val) {
        $val = $val * 2;  // correctly does not warn
    }
    var_export($values);
}
function testAssignDimRef2(array $values) {
    foreach ($values as &$val) {
        $val['x'] = 3;  // warns unexpectedly about PhanPluginUnusedVariable
    }
    var_export($values);
}
/*
function testAssignDimRef3(array &$paramRefs) {
    $values = &$paramRefs;
    $values['x'] = 3;
}
 */
