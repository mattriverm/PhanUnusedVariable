<?php declare(strict_types=1);

use Phan\AST\AnalysisVisitor;
use Phan\AST\ContextNode;
use Phan\CodeBase;
use Phan\Analysis\BlockExitStatusChecker;
use Phan\Exception\CodeBaseException;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\UnionType;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeNodeCapability;
use Phan\PluginV2\PluginAwareAnalysisVisitor;
use ast\Node;
use ast\Node\Decl;

// By default, don't warn about parameters beginning with "$unused"
// or about the variable $_
const WHITELISTED_UNUSED_PARAM_NAME = '/^(_$|unused)/i';

/**
 * This file checks for unused variables in
 * the global scope or function bodies.
 *
 * This depends on PluginV2, which was added in Phan 0.9.3/0.8.5.
 * It also requires a version of Phan using AST version 40.
 *
 * It hooks into one event:
 *
 * - getAnalyzeNodeVisitorClassName
 *   This method returns a class that is called on every AST node from every
 *   file being analyzed
 *
 * A plugin file must
 *
 * - Contain a class that inherits from \Phan\Plugin
 *
 * - End by returning an instance of that class.
 *
 * It is assumed without being checked that plugins aren't
 * mangling state within the passed code base or context.
 *
 * Note: When adding new plugins,
 * add them to the corresponding section of README.md
 */
class UnusedVariablePlugin extends PluginV2
    implements AnalyzeNodeCapability {

    /**
     * @return string - The name of the visitor that will be called (formerly analyzeNode)
     * @override
     */
    public static function getAnalyzeNodeVisitorClassName() : string
    {
        return UnusedVariableReferenceAnnotatorVisitor::class;
    }
}

/**
 * When __invoke on this class is called with a node, a method
 * will be dispatched based on the `kind` of the given node.
 *
 * Visitors such as this are useful for defining lots of different
 * checks on a node based on its kind.
 */
final class UnusedVariableReferenceAnnotatorVisitor extends PluginAwareAnalysisVisitor {
    // A plugin's visitors should NOT implement visit(), unless they need to.

    // Note: In the future, it's planned to have another pass during the main analysis of this function
    // so that this plugin add information about references
    // to improve the unused variable checks.
    //
    // See \Phan\Language\Element\Parameter->getReferenceType() - it can return REFERENCE_WRITE_ONLY
    // (E.g. preg_match('/a/', 'a value', $matches) is not a *usage* of $matches, it is a definition)
    //
    // In some edge cases, this plugin should depend on the Context to check if $myClassInstance->myMethod($var) is a usage of $var or a possible definition of $var

    /**
     * This will be called after all of the arguments from calls made by this function
     * have been found to be references or non-references.
     * @return void
     * @override
     */
    public function visitMethod(Decl $node) {
         return (new UnusedVariableVisitor($this->code_base, $this->context))->visitMethod($node);
    }

    /**
     * This will be called after all of the arguments from calls made by this function
     * have been found to be references or non-references.
     * @return void
     * @override
     */
    public function visitFuncDecl(Decl $node) {
         return (new UnusedVariableVisitor($this->code_base, $this->context))->visitFuncDecl($node);
    }

    /**
     * This will be called after all of the arguments from calls made by this function
     * have been found to be references or non-references.
     * @return void
     * @override
     */
    public function visitClosure(Decl $node) {
         return (new UnusedVariableVisitor($this->code_base, $this->context))->visitClosure($node);
    }
}

class UnusedVariableVisitor extends PluginAwareAnalysisVisitor {

    /** @var array */
    protected $references = [];

    /** @var array */
    protected $reverse_references = [];

    /** @var array */
    protected $statics = [];

    /**
     * Expressions might be recursive
     */
    private function parseExpr(array &$assignments, $statement, int &$instructionCount)
    {
        if (!($statement instanceof Node)) {
            return;
        }
        if (isset($statement->children['expr']) && $statement->children['expr'] instanceof Node) {
            $instructionCount++;
            $this->tryVarUse($assignments, $statement->children['expr'], $instructionCount);

            foreach ($statement->children['expr']->children as $exChild) {
                $instructionCount++;

                if ($exChild instanceof Node) {
                    $this->tryVarUse($assignments, $exChild, $instructionCount);
                    $this->recurseToFindVarUse($assignments, $exChild, $instructionCount);
                }
            }
        }
    }

    private function recurseToFindVarUse(array &$assignments, Node $statement, int &$instructionCount)
    {
        foreach ($statement->children as $subStmt) {
            if ($subStmt instanceof Node) {
                $this->tryVarUse($assignments, $subStmt, $instructionCount);
                $this->recurseToFindVarUse($assignments, $subStmt, $instructionCount);
            }
        }
    }

    private function parseCond(array &$assignments, Node $node, int &$instructionCount)
    {
        if (!isset($node->children['cond']) ||  !($node->children['cond'] instanceof Node)) {
            return;
        }

        $instructionCount++;
        foreach ($node->children['cond'] as $cond) {
            if (is_array($cond)) {
                // TODO: Use node kind instead.
                foreach ($cond as $key => $condNode) {
                    if ($key === 'expr') {
                        $this->parseExpr($assignments, $condNode, $instructionCount);
                    } elseif ($key == 'left' || $key == 'right') {
                        $this->tryVarUse($assignments, $condNode, $instructionCount);
                        $this->parseExpr($assignments, $condNode, $instructionCount);
                    } elseif ($key == 'args') {
                        $this->parseStmts($assignments, $condNode, $instructionCount);
                    } else {
                        $this->tryVarUse($assignments, $condNode, $instructionCount);
                    }
                }
            }
        }
    }

    /**
     * Recording the use of a variable means we just remove it from
     * our list of assignments
     *
     * @param array $assignments
     * @param Node|mixed $node
     * @param int $instructionCount
     * @return void
     */
    private function tryVarUse(
        array &$assignments,
        $node,
        int $instructionCount
    ) {
        if (!$node instanceof Node) {
            return;
        }

        if (\ast\AST_VAR !== $node->kind) {
            return;
        }

        $name = $node->children['name'];
        // e.g. $$x, ${42}
        if (!is_string($name) || !$name) {
            return;
        }

        if (!isset($assignments[$name])) {
            return;
        }

        if ($instructionCount > $assignments[$name]['key']) {
            // Dont unset references, only mark them as used
            if (isset($this->references[$name])) {
                $assignments[$name]['used'] = true;
            } else {
                unset($assignments[$name]);
            }

            // Okay, is it a reference to something?
            if (isset($this->reverse_references[$name])) {
                unset(
                    $assignments[$this->reverse_references[$name]]
                );
            }
        }
    }

    /**
     * Clear an assignment without checking instructioncount
     *
     * @param array $assignments
     * @param Node|mixed $node
     * @return void
     */
    private function tryVarUseUnchecked(
        array &$assignments,
        $node
    ) {
        if (!$node instanceof Node) {
            return;
        }
        $name = $node->children['name'] ?? null;
        if (!is_string($name)) {
            return;
        }

        unset($assignments[$name]);
    }

    private function assignSingle(array &$assignments, Node $node, int $instructionCount, string $name)
    {
        $ref = false;
        $used = false;
        $param = false;

        // If this was declared static before, and we are not tracking it (has
        // been used already), don't record a new assignment
        if (in_array($name, $this->statics)) {
            return;
        }

        // Keep a record of static variables
        if (\ast\AST_STATIC === $node->kind) {
            $this->statics[] = $name;
        }


        if (isset($assignments[$name])) {
            $ref = $assignments[$name]['reference'];
            $used = true;
            $param = $assignments[$name]['param'];
        }

        // Are we tracking this as a reference?
        if (isset($this->references[$name])) {
            $ref = true;
        }

        $assignments[$name] = [
            'line' => $node->lineno,
            'key' => $instructionCount,
            'param' => $param,
            'reference' => $ref,
            'used' => $used
        ];
    }

    private function assign(
        array &$assignments,
        Node $node,
        int &$instructionCount,
        bool $loopFlag = false
    ): bool {
        if (\ast\AST_ASSIGN === $node->kind || \ast\AST_ASSIGN_OP === $node->kind || \ast\AST_STATIC === $node->kind) {
            $this->parseExpr($assignments, $node, $instructionCount);

            $var_node = $node->children['var'];
            if ($var_node->kind === \ast\AST_ARRAY) {
                foreach ($var_node->children as $elem_node) {
                    if ($elem_node === null) {
                        continue;  // e.g. "list(, $x) = expr"
                    }
                    assert($elem_node->kind === \ast\AST_ARRAY_ELEM);
                    $var_node = $elem_node->children['value'];
                    if ($var_node->kind !== \ast\AST_VAR) {
                        continue;
                    }
                    $var_name = $var_node->children['name'];
                    if (!is_string($var_name) || !$var_name) {
                        // e.g. list(${0}) = $v, list($$var) = $v
                        continue;
                    }
                    $instructionCount++;
                    $this->assignSingle(
                        $assignments,
                        $node,
                        $instructionCount,
                        $var_node->children['name']
                    );
                }
                return true;
            }

            // We dont want to track assignments second time through a loop
            if (isset($node->children['var']->children['name']) && !$loopFlag) {
                $instructionCount++;
                $this->assignSingle(
                    $assignments,
                    $node,
                    $instructionCount,
                    $node->children['var']->children['name']
                );

                return true;
            }
        }

        // If this is a reference variable
        if (\ast\AST_ASSIGN_REF === $node->kind) {
            $this->parseExpr($this->references, $node, $instructionCount);
            if (isset($node->children['var']->children['name']) && !$loopFlag) {
                $name = $node->children['var']->children['name'];
                if (!is_string($name) || !$name) {
                    return false;
                }
                $instructionCount++;
                // If this is a ref, mark it as used
                $ref = false;
                $used = false;
                $param = false;

                if (array_key_exists($name, $this->references)) {
                    $ref = $this->references[$name]['reference'];
                    $used = true;
                    $param = $this->references[$name]['param'];
                }

                $this->references[$name] = [
                    'line' => $node->lineno,
                    'key' => $instructionCount,
                    'param' => $param,
                    'reference' => $ref,
                    'used' => $used
                ];

                if (\ast\AST_VAR === $node->children['expr']->kind) {
                    $this->reverse_references[$node->children['expr']->children['name']] = $name;
                }

                return true;
            }
        }

        return false;
    }

    const LOOPS_SET = [
        \ast\AST_WHILE => true,
        \ast\AST_FOREACH => true,
        \ast\AST_FOR => true,
        \ast\AST_DO_WHILE => true
    ];

    private function parseStmts(
        array &$assignments,
        Node $node,
        int &$instructionCount,
        bool $loopFlag = false
    ) {
        foreach ($node->children as $statement) {
            if (!($statement instanceof Node)) {
                continue;
            }

            $instructionCount++;

            if ($this->assign($assignments, $statement, $instructionCount, $loopFlag)) {
                continue;
            }

            if (\ast\AST_STMT_LIST === $statement->kind) {
                $this->parseStmts($assignments, $statement, $instructionCount);
                return;
            }

            // Reset the instruction count and then run the loop again
            if (array_key_exists($statement->kind, self::LOOPS_SET)) {
                // Parse the value and keep track of it
                if (\ast\AST_FOREACH === $statement->kind) {
                    if (\ast\AST_REF === $statement->children['value']->kind ?? 0) {
                        $this->references[$statement->children['value']->children['var']->children['name']] = [
                            'line' => $statement->lineno,
                            'key' => $instructionCount,
                            'param' => false,
                            'reference' => true,
                            'used' => false
                        ];
                    }
                }

                $this->parseCond($assignments, $statement, $instructionCount);
                $this->parseExpr($assignments, $statement, $instructionCount);

                $this->parseStmts($assignments, $statement->children['stmts'], $instructionCount);
                $shadowAssignments = $assignments;
                // Now we know if there are dangling assignments
                $shadowCount = 0;
                $this->parseStmts($shadowAssignments, $statement->children['stmts'], $shadowCount, true);
                $assignments = $shadowAssignments;
                // Run trough loop conditions one more time in case we are
                // assigning in the loop scope and using that as a condition for
                // looping (issue #4)
                $this->parseCond($assignments, $statement, $instructionCount);
                continue;
            }

            foreach ($statement->children as $name => $subStmt) {
                if ($subStmt instanceof Node) {
                    if (\ast\AST_STMT_LIST === $subStmt->kind) {
                        $this->parseStmts($assignments, $subStmt, $instructionCount);
                    }

                    if (isset($subStmt->children['name'])) {
                        $this->tryVarUse($assignments, $subStmt, $instructionCount);
                    } else {
                        $instructionCount++;

                        // Else control structure or other scope?
                        $this->parseCond($assignments, $subStmt, $instructionCount);
                        $this->parseStmts($assignments, $subStmt, $instructionCount);

                        foreach ($subStmt->children as $argKey => $argParam) {
                            $this->tryVarUseUnchecked($assignments, $argParam);
                        }
                    }
                }
            }
        }
    }

    /**
     * @return void
     */
    private function addMethodParameters(&$assignments, Node $node)
    {
        foreach ($node->children['params']->children ?? [] as $p) {
            if ($p->kind !== \ast\AST_PARAM) {
                continue;
            }
            $name = $p->children['name'];
            if (!is_string($name) || !$name) {
                continue;
            }

            // Reference?
            if (\ast\flags\EXEC_EVAL === $p->flags) {
                $assignments[$name] = $this->references[$name] = [
                    'line' => $node->lineno,
                    'key' => 0,
                    'param' => true,
                    'reference' => true,
                    'used' => false
                ];
            } else {
                $assignments[$name] = [
                    'line' => $node->lineno,
                    'key' => 0,
                    'param' => true,
                    'reference' => false,
                    'used' => false
                ];
            }
        }
    }

    /**
     * @param Decl $node
     * A node to analyze
     *
     * @return void
     */
    public function visitMethod(Decl $node)
    {
        // ast kinds
        // https://github.com/nikic/php-ast/blob/master/ast_data.c
        $this->analyzeMethod($node);
    }

    /**
     * @param Decl $node
     * A node to analyze
     *
     * @return void
     */
    public function visitClosure(Decl $node)
    {
        $this->analyzeMethod($node);
    }

    /**
     * @param Decl $node
     * A node to analyze
     *
     * @return void
     */
    public function visitFuncDecl(Decl $node)
    {
        $this->analyzeMethod($node);
    }

    /**
     * @param Decl $node
     * A node to analyze (AST_FUNC_DECL, AST_METHOD, or AST_CLOSURE)
     *
     * @return void
     */
    public function analyzeMethod(Decl $node)
    {
        //\Phan\Debug::printNode($node);

        // Collect all assignments
        $assignments = [];

        $stmts_list = $node->children['stmts'] ?? null;
        if ($stmts_list === null) {
            // abstract method or method of interface, nothing to do.
            return;
        }
        assert($stmts_list instanceof Node);

        // Add all the method params to check if they are used
        $this->addMethodParameters($assignments, $node);

        // Instruction count
        $instructionCount = 0;

        $this->parseStmts($assignments, $stmts_list, $instructionCount);

        if (count($assignments) > 0) {
            foreach ($assignments as $param => $data) {
                if ($data['param'] === true) {
                    $shouldWarn = false;
                    if ($data['reference']) {
                        if ($data['used'] == false) {
                            $shouldWarn = true;
                        }
                    } else {
                        $shouldWarn = true;
                    }

                    if ($shouldWarn) {
                        if ($this->shouldWarnAboutParameter($param, $node)) {
                            $this->emitPluginIssue(
                                $this->code_base,
                                clone($this->context)->withLineNumberStart($data['line']),
                                'PhanPluginUnusedMethodArgument',
                                'Parameter is never used: ${PARAMETER}',
                                [$param]
                            );
                        }
                    }
                } else {
                    // If there is a reverse pointer to this var,
                    // we need to check if that is unused
                    if (array_key_exists($param, $this->reverse_references)) {
                        $pointer = $this->reverse_references[$param];
                        if (isset($assignments[$param])) {
                            $this->emitPluginIssue(
                                $this->code_base,
                                clone($this->context)->withLineNumberStart($data['line']),
                                'PhanPluginUnnecessaryReference',
                                '${VARIABLE} is a reference to ${PARAMETER}, but ${PARAMETER} is never used',
                                [$pointer, $param, $param]
                            );
                        }
                    } else {
                        $shouldWarn = false;
                        if ($data['reference']) {
                            if ($data['used'] == false) {
                                $shouldWarn = true;
                            }
                        } else {
                            $shouldWarn = true;
                        }

                        if ($shouldWarn) {
                            $this->emitPluginIssue(
                                $this->code_base,
                                clone($this->context)->withLineNumberStart($data['line']),
                                'PhanPluginUnusedVariable',
                                'Variable is never used: ${VARIABLE}',
                                [$param]
                            );
                        }
                    }
                }
            }
        }
    }

    private function shouldWarnAboutParameter(string $param, Decl $decl) : bool
    {
        // Don't warn about $_ or $unusedVariable or $unused_variable
        if (preg_match(WHITELISTED_UNUSED_PARAM_NAME, $param) > 0) {
            return false;
        }
        $docComment = $decl->docComment ?? '';
        if (!$docComment) {
            return true;
        }
        // If there is a line of the form "* @param [T] $myUnusedVar [description] @phan-unused-param [rest of description]" anywhere in the doc comment,
        // then don't warn about the parameter being unused.
        if (strpos($docComment, '@phan-unused-param') === false) {
            return true;
        }
        $regex = '/@param[^$]*\$' . preg_quote($param, '/') . '\b.*@phan-unused-param\b/';
        if (preg_match($regex, $docComment) > 0) {
            return false;
        }
        return true;
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new UnusedVariablePlugin;
