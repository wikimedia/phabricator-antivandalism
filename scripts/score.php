#!/usr/bin/env php
<?php

$root = dirname(dirname(__FILE__));
require_once $root . '/scripts/init/init-script.php';
init_script();
$args = new PhutilArgumentParser($argv);
$args->setTagline(pht('Antivandalism scoring tool..'));
$args->setSynopsis(<<<EOSYNOPSIS
**score.php** __workflow__ [__options__]
    Test the antivandalism engine.

EOSYNOPSIS
);
$args->parseStandardArguments();

$workflows = id(new PhutilClassMapQuery())
  ->setAncestorClass('AntivandalismWorkflow')
  ->execute();
$workflows[] = new PhutilHelpArgumentWorkflow();
$args->parseWorkflows($workflows);
