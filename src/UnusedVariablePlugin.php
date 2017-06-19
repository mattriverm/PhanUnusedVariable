<?php declare(strict_types=1);

use Phan\AST\AnalysisVisitor;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\Method;
use Phan\Plugin;
use Phan\Plugin\PluginImplementation;
use ast\Node;
use Phan\Debug;

/**
 * Unused variables
 */
class UnusedVariablePlugin extends PluginImplementation {

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * The context in which the node exits. This is
     * the context inside the given node rather than
     * the context outside of the given node
     *
     * @param Node $node
     * The php-ast Node being analyzed.
     *
     * @param Node $node
     * The parent node of the given node (if one exists).
     *
     * @return void
     */
    public function analyzeNode(
        CodeBase $code_base,
        Context $context,
        Node $node,
        Node $parent_node = null
    ) {
        (new UnusedVariableVisitor($code_base, $context, $this))(
            $node
        );
    }
}

/**
 * When __invoke on this class is called with a node, a method
 * will be dispatched based on the `kind` of the given node.
 *
 * Visitors such as this are useful for defining lots of different
 * checks on a node based on its kind.
 */
class UnusedVariableVisitor extends AnalysisVisitor {

    /** @var Plugin */
    private $plugin;

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


    public function __construct(
        CodeBase $code_base,
        Context $context,
        Plugin $plugin
    ) {
        // After constructing on parent, `$code_base` and
        // `$context` will be available as protected properties
        // `$this->code_base` and `$this->context`.
        parent::__construct($code_base, $context);

        // We take the plugin so that we can call
        // `$this->plugin->emitIssue(...)` on it to emit issues
        // to the user.
        $this->plugin = $plugin;
    }

    /**
     * Default visitor that does nothing
     *
     * @param Node $node
     * A node to analyze
     *
     * @return void
     */
    public function visit(Node $node)
    {
    }

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
        if (!isset($node->children['name'])) {
            return;
        }
        $name = $node->children['name'];

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

            if ($node->children['var']->kind === \ast\AST_LIST) {
                foreach ($node->children['var']->children as $ast_var) {
                    $instructionCount++;
                    $this->assignSingle(
                        $assignments,
                        $node,
                        $instructionCount,
                        $ast_var->children['name']
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

    private function parseStmts(
        array &$assignments,
        Node $node,
        int &$instructionCount,
        bool $loopFlag = false
    ) {
        foreach ($node->children as $stmtKey => $statement) {
            if (!$statement instanceof Node) {
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

            // Loops are a bit trickier
            $loops = [
                \ast\AST_WHILE,
                \ast\AST_FOREACH,
                \ast\AST_FOR,
                \ast\AST_DO_WHILE
            ];

            // Reset the instruction count and then run the loop again 
            if (in_array($statement->kind, $loops)) {
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
     * 
     */
    private function addMethodParameters(&$assignments, $node)
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
                if ("EXEC_EVAL" === Debug::astFlagDescription($p->flags ?? 0)) {
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
     * @param Node $node
     * A node to analyze
     *
     * @return void
     */
    public function visitMethod(ast\Node\Decl $node)
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
                        $this->plugin->emitIssue(
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
                            $this->plugin->emitIssue(
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
                            $this->plugin->emitIssue(
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
