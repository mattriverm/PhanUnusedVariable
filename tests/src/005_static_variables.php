<?php
// This is ok ($initialized is used in condition)
class testStaticVarInMethod
{
    public function run()
    {
        static $initialized = false;
        if (!$initialized) {
            self::init();
            $initialized = true;
        }
    }

    public static function init()
    {
    }
}

// Not ok
class testStaticUnused
{
    public function run()
    {
        static $initialized = false;
        $initialized = true;
    }
}

class testStaticUsedIn
{
    public function run()
    {
        static $initialized = false;
        if ($initialized) return;
        $initialized = true;
        $this->innerMethod();
    }

    private function innerMethod()
    {
    }
}
