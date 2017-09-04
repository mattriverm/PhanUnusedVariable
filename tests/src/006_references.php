<?php
// Ok
class testSimpleReferenceInParam
{
    public function test(&$a)
    {
        $a = 'b';
    }
}

// Fail on $fourteen
class testSimpleReferencesInParam
{
    public function test(&$a, &$fifteen)
    {
        $a = 'b';
    }
}

// Ok
class testSimpleReferencesAssignmentInParam
{
    public function test(&$a, &$b)
    {
        $a = $b;
    }
}

// $a and $b is never used. You have modified $a, but never use it.
// However meaningless, it is correct that they both are unused.
class testSimpleReference
{
    public function test()
    {
        $seventeen = 'a';
        $sixteen = &$seventeen;
        $sixteen = 'b';
    }
}

// This is ok. We are using both a and b
class testSimpleReferenceIsUsed
{
    public function test()
    {
        $a = 'a';
        $b = &$a;
        $b = 'b';
        if  ($a == 'b') {
            return true;
        }
    }
}
class testSimpleParamReferenceIsUsed
{
    public function test(&$a)
    {
        $b = &$a;
        $b = 'b';
        if  ($a == 'b') {
            return true;
        }
    }
}

// $a is never used
class testSimpleReferenceIsNotUsed
{
    public function test()
    {
        $nineteen = 'a';
        $eighteen = &$nineteen;
        $eighteen = 'b';
        if  ($eighteen == 'b') {
            return true;
        }
    }
}

// This is ok
class testSimpleReferenceUseOriginal
{
    public function test()
    {
        $a = 'a';
        $b = &$a;
        $b = 'b';
        return explode("/", $a);
    }
}

class testReferences
{
    public function setConfig($config)
    {
        return $config;
    }

    public static function new(array $config)
    {
        $ret = new static;

        // Loop sections and set false to everything
        foreach ($config as $secName => &$section) {
            foreach ($section['fields'] as &$confField) {
                $confField = array_merge($confField, ['edit' => false]);
            }
        }
        $ret->setConfig($config);

        return $ret;
    }
}

function foo(string &$outputArg) {
    $outputArg = str_replace("_", "-", $outputArg);
}

// Issue #15
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

// @todo
// class testIncremenetButNeverReturnedOrUsed {
//     public function pub()
//     {
//         $puba = ['a', 'b', 'c'];

//         $pub = array_shift($puba);

//         while($pub == 'a') {
//             $c = 0;
//         }

//         $c++;
//     }
// }

