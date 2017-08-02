<?php

class X {
    private $_y = 3;

    private function foo13(array $x) {
        $y = $this->_y;
        $c = 42;

        return array_map(function($elem) use($y, $c) {
            if ($elem == 0) { return 0; }
            return [[$elem, $y]];
        }, $x);
    }
}
