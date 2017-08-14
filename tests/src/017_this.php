<?php

class X16 implements ArrayAccess {
    // stub implementation
    public function offsetGet($key) {return null;}
    public function offsetSet($key, $value) {}
    public function offsetExists($key) { return false;}
    public function offsetUnset($key) {}

    public function test() {
        $this['x'] = 'y';  // should not warn
    }
}
$x = new X16();
$x->test();
