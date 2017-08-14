<?php

function testSuperglobal() {
    $_SESSION['foo'] = 'bar';
    $_ENV = ['envOverride' => 'bar'];
    $_ENVNOTSUPERGLOBAL = ['x' =>'bar'];  // should warn
}
