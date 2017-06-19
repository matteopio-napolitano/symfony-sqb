<?php

namespace Command;

use PHPSQLParser\PHPSQLParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class QueryBuilderEncoder
 *
 * @package Command
 */
class QueryBuilderEncoder extends Command
{
    /**
     * Command configuration
     */
    protected function configure()
    {
        try {
            $this->setName('encode')
                ->setDescription('Convert a Native SQL query to a Query Builder php code')
                ->addOption('query', null, InputOption::VALUE_REQUIRED, 'The Native SQL query', null);
        } catch (\Exception $exception) {
            echo PHP_EOL . $exception->getMessage() . PHP_EOL;
        }
    }

    /**
     * Main execution
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $query = $input->getOption('query');
        if (empty($query)) {
            echo 'No Native SQL query provided' . PHP_EOL;
            return;
        }

        $query = str_replace('\'', '"', $query);

        $parser = new PHPSQLParser();
        $parsed = $parser->parse($query);

        /** @var string $qbComposed -- initialize */
        $qbComposed = $this->getQueryBuilderBase();

        /** add select part */
        foreach ($parsed['SELECT'] as $key => $select) {
            $qbComposed .= $this->getSelect($select, $key);
        }

        /** add from part, if needed */
        if (!empty($parsed['FROM'])) {
            $baseAlias = !empty($parsed['FROM'][0]['alias']['name']) ? $parsed['FROM'][0]['alias']['name'] : '';

            foreach ($parsed['FROM'] as $key => $from) {
                $qbComposed .= $this->getFrom($from, $key, $baseAlias);
            }
        }

        /** add where part, if needed */
        if (!empty($parsed['WHERE'])) {
            $qbComposed .= $this->getWhere($parsed['WHERE']);
        }

        /** add group by part, if needed */
        if (!empty($parsed['GROUP'])) {
            $qbComposed .= $this->getGroupBy($parsed['GROUP']);
        }

        /** add order part, if needed */
        if (!empty($parsed['ORDER'])) {
            foreach ($parsed['ORDER'] as $key => $order) {
                $qbComposed .= $this->getOrderBy($order, $key);
            }
        }

        echo PHP_EOL . PHP_EOL . '==> Result:' . PHP_EOL . $qbComposed . PHP_EOL . PHP_EOL;
    }

    /**
     * Method that returns base query builder initialization
     *
     * @return string
     */
    private function getQueryBuilderBase()
    {
        return '$this->connection->createQueryBuilder()' . PHP_EOL;
    }

    /**
     * Method that returns select part
     *
     * @param array $args
     * @param int $iteration
     *
     * @return string
     */
    private function getSelect(array $args, $iteration = 0)
    {
        return
            ($iteration > 0 ? '->addSelect(\'' : '->select(\'') .
            $this->generateSelectPartFromSubTree($args) .
            (!empty($args['alias']['base_expr']) ? ' ' . $args['alias']['base_expr'] : '') . '\')' .
            PHP_EOL;
    }

    /**
     * Method that helps in generating select partials
     *
     * @param array $arg
     *
     * @return string
     */
    private function generateSelectPartFromSubTree(array $arg)
    {
        if (empty($arg['sub_tree'])) {
            return trim($arg['base_expr']);
        }

        $lbase = '';
        foreach ($arg['sub_tree'] as $a) {
            if ($arg['expr_type'] !== 'expression') {
                $lbase .= $this->generateSelectPartFromSubTree($a) . ($arg['expr_type'] === 'function' ? '$$$' : ' ');
            }
        }

        $lbase = str_replace('$$$', ',', $lbase);
        $lbase = rtrim($lbase, ',');

        if ($arg['expr_type'] === 'function') {
            $lbase = '(' . $lbase . ')';
            return $arg['base_expr'] . $lbase;
        }

        return $arg['base_expr'] . ' ' . $lbase;
    }

    /**
     * Method that returns from part
     *
     * @param array $args
     * @param int $iteration
     * @param string $baseAlias
     *
     * @return string
     */
    private function getFrom(array $args, $iteration = 0, $baseAlias = '')
    {
        if ($iteration === 0) {
            return '->from(\'' . $args['table'] . '\'' . (!empty($baseAlias) ? ', \'' . $baseAlias . '\'' : '') . ')' . PHP_EOL;
        }

        $return = '';
        if ($args['join_type'] === 'JOIN') {
            $return .= '->innerJoin(';
        }

        if ($args['join_type'] === 'LEFT') {
            $return .= '->leftJoin(';
        }

        if ($args['join_type'] === 'RIGHT') {
            $return .= '->rightJoin(';
        }

        $splittedOriginalJoinConditions = strpos($args['base_expr'], ' on ') !== false ? explode(' on ', $args['base_expr']) : explode(' ON ', $args['base_expr']);

        $return .= '\'' . $baseAlias . '\', \'' . $args['table'] . '\', \'' . $args['alias']['name'] . '\', \'' . $splittedOriginalJoinConditions[1] . '\')';

        return $return . PHP_EOL;
    }

    /**
     * Method that returns where part
     *
     * @param array $args
     *
     * @return string
     */
    private function getWhere(array $args)
    {
        $return = '';

        for ($i = 0, $iMax = count($args), $increment = 4; $i < $iMax; $i += $increment) {
            $leftSideCond = $args[$i]['base_expr'];
            $conditionOperator = $args[$i + 1]['base_expr'];
            $rightSideCond = $args[$i + 2]['base_expr'];

            $whereOperand = ($i + 3) < $iMax ? $args[$i + 3]['base_expr'] : '';

            if ($args[$i]['expr_type'] === 'bracket_expression') {
                $increment = 2;

                $conditionOperator = '';
                $rightSideCond = '';
            } else {
                $increment = 4;
            }

            $return .= $this->generateWherePartFromConditions($leftSideCond, $conditionOperator, $rightSideCond, $whereOperand, $i) . PHP_EOL;
        }

        return $return;
    }

    /**
     * Method that helps in generating where condition partials
     *
     * @param $lSideCondition
     * @param string $conditionOperator
     * @param string $rSideCondition
     * @param string $whereOperand
     * @param int $iteration
     *
     * @return string
     */
    private function generateWherePartFromConditions($lSideCondition, $conditionOperator = '', $rSideCondition = '', $whereOperand = '', $iteration = 0)
    {
        $return = '->andWhere';

        if ($iteration === 0) {
            $return = '->where';
        }

        if ($whereOperand == 'or') {
            $return = '->orWhere';
        }

        $return .= '(\'' . trim($lSideCondition) .
            (!empty($conditionOperator) ? ' ' . trim($conditionOperator) : '') .
            (!empty($rSideCondition) ? ' ' . trim($rSideCondition) : '') .
            '\')';

        return $return;
    }

    /**
     * Method that returns group by part
     *
     * @param array $args
     *
     * @return string
     */
    private function getGroupBy(array $args)
    {
        $return = '->groupBy(\'';

        foreach ($args as $gbCond) {
            $return .= $gbCond['base_expr'] . ', ';
        }

        return rtrim($return, ', ') . '\')' . PHP_EOL;
    }

    /** Method that returns order by part
     *
     * @param array $args
     * @param int $iteration
     *
     * @return string
     */
    private function getOrderBy(array $args, $iteration = 0)
    {
        if ($iteration === 0) {
            return '->orderBy(\'' . $args['base_expr'] . '\', \'' . $args['direction'] . '\')' . PHP_EOL;
        }

        return '->addOrderBy(\'' . $args['base_expr'] . '\', \'' . $args['direction'] . '\')' . PHP_EOL;
    }
}