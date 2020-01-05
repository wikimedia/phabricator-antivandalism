<?php

final class AntiVandalismFeedStory
  extends PhabricatorFeedStory {

  public function getPrimaryObjectPHID() {
    return $this->getValue('vandalPHID');
  }

  public function getRequiredHandlePHIDs() {
    $phids = array();

    $phids[] = $this->getValue('vandalPHID');
    $phids[] = $this->getValue('objectPHID');
    return $phids;
  }

  public function getRequiredObjectPHIDs() {
    $phids = array();
    $phids[] = $this->getValue('objectPHID');
    $phids[] = $this->getValue('vandalPHID');
    return $phids;
  }

  public function renderView() {
    $view = $this->newStoryView();
    $vandal_phid = $this->getValue('vandalPHID');
    $view->setAppIcon('fa-user-secret');

    $file = PhabricatorFile::loadBuiltin(PhabricatorUser::getOmnipotentUser(),
      'projects/v3/shield.png');

    $href = $this->getHandle($this->getValue('vandalPHID'))->getURI();
    $view->setHref($href);
    $view->setTitle($this->renderTitle());
    $view->setImage($file->getBestURI());

    return $view;
  }

  private function renderTitle() {
    $action = pht($this->getValue('action'));
    $title = pht(
      '%s triggered vandalism countermeasures (%s) by editing %s.',
      $this->linkTo($this->getValue('vandalPHID')),
      $action,
      $this->linkTo($this->getValue('objectPHID')));

    return $title;
  }

  public function renderText() {
    $old_target = $this->getRenderingTarget();
    $this->setRenderingTarget(PhabricatorApplicationTransaction::TARGET_TEXT);
    $title = $this->renderTitle();
    $this->setRenderingTarget($old_target);
    return $title;
  }

  public function renderAsTextForDoorkeeper(
    DoorkeeperFeedStoryPublisher $publisher) {
    return $this->renderText();
  }


}
