<?php declare(strict_types=1);
/**
 * UnusedVariableVisitor is based on https://github.com/mattriverm/PhanUnusedVariable
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * When __invoke on this class is called with a node, a method
 * will be dispatched based on the `kind` of the given node.
 *
 * Visitors such as this are useful for defining lots of different
 * checks on a node based on its kind.
 */

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

/**
 * This file checks for unused variables in
 * the global scope or function bodies.
 *
 * As a side effect, it adds 'isRef' to \ast\Node->children in argument lists
 * of function calls, method calls (instance/static), and calls to `new MyClass($x)`
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
final class UnusedVariablePlugin extends PluginV2
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

    // AST node types that would both can be references, and would affect analysis.
    const POSSIBLE_REFERENCE_TYPE_SET = [
        \ast\AST_VAR => true,
        \ast\AST_DIM => true,
        // \ast\AST_PROP => true,
        // \ast\AST_STATIC_PROP => true,
    ];

    /**
     * @param Node $node
     * A node to analyze to record whether individual arguments are references.
     *
     * @return void
     *
     * @override
     */
    public function visitCall(Node $node)
    {
        $args = $node->children['args']->children;
        if (count($args) === 0) {
            return;
        }
        $unknown_argument_set = self::extractArgumentsToAnalyze($args);
        if (count($unknown_argument_set) === 0) {
            return;
        }
        $expression = $node->children['expr'];
        try {
            $function_list_generator = (new ContextNode(
                $this->code_base,
                $this->context,
                $expression
            ))->getFunctionFromNode();

            foreach ($function_list_generator as $function) {
                assert($function instanceof FunctionInterface);
                // Check the call for parameter and argument types
                $this->analyzeCallToMethodForReferences(
                    $function,
                    $unknown_argument_set
                );
            }
        } catch (CodeBaseException $e) {
            // ignore it.
        }
    }

    /**
     * @return void
     *
     * @override
     */
    public function visitNew(Node $node)
    {
        $args = $node->children['args']->children;
        if (count($args) === 0) {
            return;
        }
        $unknown_argument_set = self::extractArgumentsToAnalyze($args);
        if (count($unknown_argument_set) === 0) {
            return;
        }
        try {
            $context_node = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ));

            $method = $context_node->getMethod(
                '__construct',
                false,
                false
            );
            $this->analyzeCallToMethodForReferences(
                $method,
                $unknown_argument_set
            );
        } catch (\Exception $exception) {
        }
    }

    /**
     * @param Node $node
     * A node to analyze to record whether individual arguments are references.
     *
     * @return void
     *
     * @override
     */
    public function visitStaticCall(Node $node)
    {
        // Get the name of the method being called
        $method_name = $node->children['method'];

        // Give up on things like Class::$var
        if (!\is_string($method_name)) {
            return;
        }
        $args = $node->children['args']->children;
        if (count($args) === 0) {
            return;
        }
        $unknown_argument_set = self::extractArgumentsToAnalyze($args);
        if (count($unknown_argument_set) === 0) {
            return;
        }
        // Get the name of the static class being referenced
        $static_class = '';
        if ($node->children['class']->kind == \ast\AST_NAME) {
            $static_class = $node->children['class']->children['name'];
        }

        try {
            $method = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethod($method_name, true, true);
        } catch (Exception $e) {
            return;  // ignore.
        }

        if ($method === null) {
            return;
        }

        $this->analyzeCallToMethodForReferences($method, $unknown_argument_set);
    }

    /**
     * @param Node $node
     * A node to analyze to record whether individual arguments are references.
     *
     * @return void
     *
     * @override
     */
    public function visitMethodCall(Node $node)
    {
        $args = $node->children['args']->children;
        if (count($args) === 0) {
            return;
        }
        $unknown_argument_set = self::extractArgumentsToAnalyze($args);
        if (count($unknown_argument_set) === 0) {
            return;
        }
        $method_name = $node->children['method'];

        if (!\is_string($method_name)) {
            return;
        }

        try {
            $method = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethod($method_name, false);
        } catch (Exception $exception) {
            return;
        }

        // Check the call for parameter and argument types
        $this->analyzeCallToMethodForReferences(
            $method,
            $unknown_argument_set
        );
    }

    /**
     * @param Node[] $arg_list
     * @return Node[] subset of those, preserving array indices.
     */
    private function extractArgumentsToAnalyze(array $arg_list) : array
    {
        $unknown_argument_set = [];
        foreach ($arg_list as $i => $arg) {
            if (!($arg instanceof Node)) {
                continue;
            }
            if (!array_key_exists($arg->kind, self::POSSIBLE_REFERENCE_TYPE_SET)) {
                continue;
            }
            if (isset($arg->children['isRef'])) {
                continue;
            }
            $unknown_argument_set[$i] = $arg;
            $arg->children['isRef'] = false;  // set it to true if any possible implementations are true.
        }
        return $unknown_argument_set;
    }

    /**
     * @param Node[] $unknown_argument_set a subset of the parameters of the call.
     * @return void
     */
    private function analyzeCallToMethodForReferences(
        FunctionInterface $method,
        array $unknown_argument_set
    ) {
        foreach ($unknown_argument_set as $i => $argument) {
            assert($argument instanceof Node);
            $parameter = $method->getParameterForCaller($i);
            if (!$parameter) {
                continue;
            }
            if ($parameter->isPassByReference()) {
                $argument->children['isRef'] = true;
            }
        }
    }

    /**
     * This is called after all of the arguments from calls made by this function
     * have been found to be references or non-references.
     * @return void
     * @override
     */
    public function visitMethod(Decl $node) {
		return (new UnusedVariableVisitor($this->code_base, $this->context))->visitMethod($node);
    }

    /**
     * This is called after all of the arguments from calls made by this function
     * have been found to be references or non-references.
     * @return void
     * @override
     */
    public function visitFuncDecl(Decl $node) {
		return (new UnusedVariableVisitor($this->code_base, $this->context))->visitFuncDecl($node);
    }
}

class UnusedVariableVisitor extends PluginAwareAnalysisVisitor {

    /** @var array */
    protected $assignments = [];

    /**
     * Instruction count
     * @var int
     */
    protected $instruction_count = 0;

    /** @var array */
    protected $references = [];

    /** @var array */
    protected $reverse_references = [];


    /**
     * Expressions might be recursive
     */
    private function parseExpr(&$assignments, $statement, &$instructionCount)
    {
        if (isset($statement->children['expr']) && $statement->children['expr'] instanceof Node) {
            $instructionCount++;
            $this->tryVarUse($assignments, $statement->children['expr'], $instructionCount);

            foreach ($statement->children['expr']->children as $exChild) {
                $instructionCount++;

                $this->tryVarUse($assignments, $exChild, $instructionCount);
                $this->recurseToFindVarUse($assignments, $exChild, $instructionCount);
            }
        }
    }

    private function recurseToFindVarUse(&$assignments, $statement, &$instructionCount)
    {
        if ($statement instanceof Node) {
            foreach ($statement->children as $key => $subStmt) {
                if ($subStmt instanceof Node) {
                    $this->tryVarUse($assignments, $subStmt, $instructionCount);
                    $this->recurseToFindVarUse($assignments, $subStmt, $instructionCount);
                }
            }
        }
    }

    private function parseCond(&$assignments, $node, &$instructionCount)
    {
        if (!isset($node->children['cond']) ||  !($node->children['cond'] instanceof Node)) {
            return;
        }

        $instructionCount++;
        foreach ($node->children['cond'] as $cond) {
            if (is_array($cond)) {
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

        if (!isset($assignments[$name])) {
            return;
        }

        if ($instructionCount > $assignments[$name]['key']) {
            unset($assignments[$name]);

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

        if (array_key_exists($name, $assignments)) {
            unset($assignments[$name]);
        }
    }

    private function assignSingle(&$assignments, $node, $instructionCount, $name)
    {
        $ref = false;
        $used = false;
        $param = false;

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
        if (\ast\AST_ASSIGN === $node->kind || \ast\AST_ASSIGN_OP === $node->kind) {
            $this->parseExpr($assignments, $node, $instructionCount);

            $var_node = $node->children['var'];
            if ($var_node->kind === \ast\AST_ARRAY) {
                foreach ($var_node->children as $elem_node) {
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
                            $this->tryVarUseUnchecked($assignments, $argParam, $instructionCount);
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
        foreach ($node->children['params'] ?? [] as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            foreach ($parameter as $p) {
                if (!isset($p->children['name'])) {
                    return;
                }

                // Reference?
                if (\ast\flags\EXEC_EVAL === $p->flags) {
                    $assignments[$p->children['name']] = [
                        'line' => $node->lineno,
                        'key' => 0,
                        'param' => true,
                        'reference' => true,
                        'used' => false
                    ];
                } else {
                    $assignments[$p->children['name']] = [
                        'line' => $node->lineno,
                        'key' => 0,
                        'param' => true,
                        'reference' => false,
                        'used' => false
                    ];
                }
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

        // Ignore interfaces because variables are never used
        if (\ast\flags\CLASS_INTERFACE === $this->context->getClassInScope($this->code_base)->getFlags()) {
            return;
        }

        //Debug::printNode($node);

        // Collect all assignments
        $assignments = [];

        // Add all the method params to check if they are used
        $this->addMethodParameters($assignments, $node);

        // Instruction count
        $instructionCount = 0;

        if (isset($node->children['stmts']) && ($node->children['stmts'] instanceof Node)) {
            $this->parseStmts($assignments, $node->children['stmts'], $instructionCount);
        }

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
                        $this->emitPluginIssue(
                            $this->code_base,
                            $this->context,
                            'PhanPluginUnusedMethodArgument',
                            "Parameter is never used: $".$param."."
                        );
                    }
                } else {
                    // If there is a reverse pointer to this var,
                    // we need to check if that is unused
                    if (array_key_exists($param, $this->reverse_references)) {
                        $pointer = $this->reverse_references[$param];
                        if (isset($assignments[$param])) {
                            $this->emitPluginIssue(
                                $this->code_base,
                                $this->context,
                                'PhanPluginUnnecessaryReference',
                                "$".$pointer." (assigned on line ".$data['line'].") is a reference to $".$param.", but $".$param." is never used."
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
                                $this->context,
                                'PhanPluginUnusedVariable',
                                "Variable is never used: $".$param." assigned on line ".$data['line']."."
                            );
                        }
                    }
                }
            }
        }
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new UnusedVariablePlugin;
