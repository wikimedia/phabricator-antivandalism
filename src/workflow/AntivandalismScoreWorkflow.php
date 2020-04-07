<?php

class AntivandalismScoreWorkflow extends AntivandalismWorkflow {

  protected function didConstruct()
  {
    $this
      ->setName('score')
      ->setExamples('**score** [options]')
      ->setSynopsis(
        pht('compute score for given transactions.')
      )
      ->setArguments(
        array(
          array(
            'name' => 'user',
            'param' => 'username',
            'help' => pht(
              'The username for whom transactions will be scored.'
            ),
          ),
          array(
            'name' => 'user-phid',
            'param' => 'PHID',
            'help' => pht(
              'The username for whom transactions will be rolled back.'
            ),
          )
        )
      );
  }

  public function execute(PhutilArgumentParser $args) {
    $console = PhutilConsole::getConsole();
    $viewer = PhabricatorUser::getOmnipotentUser();
    $targetUser = $this->getTargetUser($args);
    $console->writeErr($targetUser->getPHID());
  }


  protected function getTargetUser($args) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $query = new PhabricatorPeopleQuery();
    $query->setViewer($viewer);
    if ($args->getArg('user')) {
      $query->withUsernames(array($args->getArg('user')));
    } else if ($args->getArg('user-phid')) {
      $query->withPHIDs(array($args->getArg('user-phid')));
    } else {
      throw new PhutilArgumentUsageException(
        pht('You must provide either --user or --user-phid')
      );
    }

    $targetUser = $query->executeOne();

    if (!$targetUser) {
      throw new PhutilArgumentUsageException(
        pht('The specified username / userPHID was not found')
      );
    }
    return $targetUser;
  }

}
