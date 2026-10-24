<?php

namespace App\Control;

/**
 * Règle de contrôle. Une règle lit l'OM et ne modifie rien : elle relève
 * des situations, que le moteur transforme en anomalies proposées.
 */
abstract class Rule
{
    abstract public function definition(): RuleDefinition;

    /**
     * @return iterable<Finding>
     */
    abstract public function check(ControlContext $context): iterable;
}
