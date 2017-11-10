<?php

class C18 {
    public function __destruct() {
        echo "Free\n";
    }
}

function main18() {
    $value = new stdClass();  // should warn
    $raiiValue = new C18();  // The name is special, not the class
}
main18();
