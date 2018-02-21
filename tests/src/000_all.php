<?php
// this should fail on $one and $two
class testBeing {
    public function add(int $my_param):int {
        $one = "hello world";
        $two = $my_param;
        echo $my_param+1;
        return $my_param;
    }

    public function sub(int $other_param) {
        return $other_param;
    }
}

// This should pass
class testMethodWithAssignment {
    public function sha1_verify($password, $hash)
    {
        $shaHash = sha1($password);
        return ($shaHash === $hash);
    }
}

// This should fail on $three
class testMethodArgs {
    public function sha1_verify($password, $hash, $three)
    {
        $shaHash = sha1($password);
        return ($shaHash === $hash);
    }
}

// this should pass
class testAssignedInControlStructure {
    public function sha1_verify($password, $hash)
    {
        $t = new testMethodWithAssignment;
        if (true == 1) {
            $res = $t->sha1_verify($password, $hash);
        }

        return ($res === $hash);
    }
}

// this should fail on $four
class testUnusedInControlStructure {
    public function sha1_verify($password, $hash)
    {
        $four = null;
        $t = new testMethodWithAssignment;
        if (true == 1) {
            $four = $t->sha1_verify($password, $hash);
        }

        return ($password === $hash);
    }
}

// this should still fail on $five
class testTrackAssignInControlStructure {
    public function sha1_verify($password, $hash)
    {
        $five = null;
        $t = new testMethodWithAssignment;
        if (true == 1) {
            $five = $t->sha1_verify($password, $hash);
        }

        return ($password === $hash);
    }
}

// Ok
class testUsedAsReturnValue {
    public function test()
    {
        $a = 'b';
        return $a;
    }
}

// This is ok
class testUsedAsCondition {
    public function test()
    {
        $a = ['a', 'b', 'c'];
        $b = array_shift($a);

        if ($b == 'a') {
            return true;
        }

        return false;
    }
}

// This is ok
class testUsedInControlStructure {
    public function test()
    {
        $a = 'b';
        $b = 'a';
        if ($a == 'b') {
            $b = 'c';
            if ($b == 'c') {
                $c = 'd';
            }
        }

        return $c;
    }
}
// This is not ok at $six
class testAssignmentInCondAndNeverUsed {
    public function test()
    {
        $a = 'b';
        $six = 'a';
        if ($a == 'b') {
            $six = 'c';
            // Sure - but then it is never used
            if ($six = 'c') {
                $c = 'd';
            }
        }

        return $c;
    }
}

// This is ok
class testForeach {
    public function pub()
    {
        $puba = [['a'], ['b'], ['c']];

        $pub = array_shift($puba);

        foreach($pub as $l) {
            echo $l;
        }
    }
}

// This should fail on $seven
class testAssignInWhile {
    public function pub()
    {
        $puba = ['a', 'b', 'c'];

        $pub = array_shift($puba);

        while($pub == 'a') {
            $seven = 0;
        }
    }
}

// This should not be ok
class testAssignAndUseInSameStatement
{
    public function test()
    {
        $eight = ['a', 'b', 'c'];
        $eight = array_shift($eight);
    }
}

// These should be ok
class testSql
{
    public function prepare(string $sql, array $params)
    {
        return [$sql, $params];
    }
}
class testUseInMethodCall
{
    public function test()
    {
        $id = 1;
        $db = new testSql;

        $statement = $db->prepare(
            'SELECT * FROM users WHERE id = ?',
            [$id]
        );

        return $statement;
    }
}

// This should fail on $eight in elseif
class testForeachElseIf
{
    public function test()
    {
        $a = ['a', 'b', 'c'];
        $nine = ['a', 'b', 'c'];

        foreach ($a as $b) {
            if (count($nine) == 1) {
                $nine = array_shift($nine);
            } elseif (count($nine) > 1) {
                $nine = array_shift($nine);
            } else {
                continue;
            }
        }
    }
}

// Ok. Interfaces should be ignored
interface testShouldIgnore
{
    public function test($a);
}


class testMoreMethodCalls
{
    private function renderSection($a, $b, $c, $d)
    {
        return implode(",", [$a, $b, $c, $d]);
    }

    public function render($newConfig, $oldConfig)
    {
        $html = "";
        $changedSections = [];

        foreach ($changedSections as $ac => $sections) {
            $html .= $this->renderSection($ac, $sections, $newConfig, $oldConfig);
        }

        return $html;
    }
}

// This should only fail on $ten (was false pos)
class testCountingInForeach
{
    public function testForeach()
    {
        $a = ['a', 'b', 'c'];
        $start = 0;
        $end = 3;
        foreach ($a as $b) {
            $ten = 'hello world';
            if ($start == 2) {
                echo $b;
            }
            if ($end == 0) {
                echo $a[0];
            }
            $end = $end-1;
            $start = $start+1;
        }
    }
}

// Fail on $eleven
class testCountingInWhile
{
    public function testForeach()
    {
        $a = ['a', 'b', 'c'];
        $start = 0;
        $end = 3;
        while ($b = array_shift($a)) {
            $eleven = 'hello world';
            if ($start == 2) {
                echo $b;
            }
            if ($end == 0) {
                echo $a[0];
            }
            $end = $end-1;
            $start = $start+1;
        }
    }
}

// Fail on $twelwe
class testCountingInFor
{
    public function testForeach()
    {
        $a = ['a', 'b', 'c'];
        $start = 0;
        $end = 3;
        for ($b = 0; $b < 10; $b++) {
            $twelwe = 'hello world';
            if ($start == 2) {
                echo $b;
            }
            if ($end == 0) {
                echo $a[0];
            }
            $end = $end-1;
            $start = $start+1;
        }
    }
}
// Issue #6
function testUsageInForCondition($a, $b) {
    $limit = $a < $b ? $a : $b;
    for ($i = 0; $i < $limit; $i++) {
        echo $i;
    }
    return $i;
}


// Fail on $thirteen
class testCountingInDo
{
    public function testForeach()
    {
        $a = ['a', 'b', 'c'];
        $start = 0;
        $end = 3;
        $c = array_shift($a);
        do {
            $thirteen = 'hello world';
            if ($start == 2) {
                echo $c;
            }
            if ($end == 0) {
                echo $a[0];
            }
            $end = $end-1;
            $start = $start+1;
        } while ($b = array_shift($a));
    }
}
// This said statement is never used but should be ok
class testAssignmentInWhileCondition
{
    /** @param object $fetcher */
    public function all($fetcher): array
    {
        $collection = [];

        while ($row = $fetcher->fetch()) {
            $collection[] = $row;
        }

        return $collection;
    }
}

// This should be ok
class testMoreAssignmentInCondition
{
    public function test()
    {
        if ($handle = opendir('/etc/shadow')) {
            while (false !== ($file = readdir($handle))) {
                if ($file !== '.' && $file != '..') {
                    echo 'file';
                }
            }
        }
    }
}

// Issue #8
function testLoopWithNestedExpression($a, $b) {
    $limit = $a < $b ? $a : $b;
    $i = 0;
    while (true && $i < $limit) {  // emits an issue. However, just "while ($i < $limit)" would not warn
        echo $i;
        $i++;
    }
    return $i;
}

// This should be ok
class testUseInCondition
{
    public function isSatisfiedBy($user)
    {
        if (in_array('A', $user->getFlags())) {
            return true;
        }

        return false;
    }
}

// This should be ok
class testUsedInReturnMethodCall
{
    protected $tableObject;

    private function fetchById($id)
    {
        $select = $this->tableObject->select()->where('id = ?', $id);
        return $this->tableObject->fetchRow($select);
    }
}

// This said itemid is never used but should be ok
class testMoreUsesInReturn
{
    private function fetchById($itemId)
    {
        return [$itemId];
    }

    private function map(array $a, array $b)
    {
        return array_merge($a, $b);
    }

    public function get($itemId)
    {
        return $this->map([], $this->fetchById($itemId));
    }
}

class testListAssignOk
{
    private function test()
    {
        list($a, $b) = ['a', 'b'];
        return [$a, $b];
    }
}
class testListAssign
{
    private function test()
    {
        list($a, $fourteen) = ['a', 'b'];
        return $a;
    }
}

// Issue #7
function testBranchInLoop() {
    $sleepTime = 50000;
    while (rand() % 5 > 0) {
        echo "Other code\n";
        usleep($sleepTime);
        if (rand() % 2 > 0) {  // the branch seems to effect detection
           $sleepTime = $sleepTime * 2;  // erroneously warns
        }
    }
}

// Arrays
// Issue #14

function testAssignToArray() {
    $validate = [];
    $validate['a'] = 'b';
}

function testAssignToArrayInLoop()
{
    $b = ['c' => 'c'];
    $a = ['b' => 'c'];
    $validate = [];
    foreach ($a as $v) {
        $validate[$a[$b[$v]]] = 1;
    }
}
function testAssignNestedDim() {
    $a = [];
    $b = 'a';
    $c = 1;
    $a['AP'][$b] = (string) $c;
}