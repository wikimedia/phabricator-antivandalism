<?php
  final class AntiVandalismAction
    extends HeraldAction {

    const ACTIONCONST = 'wmf.antivandalism';

    const DO_QUARANTINE = 'do.wmf.ava.quarantine';
    const DO_NOTHING = 'do.wmf.ava.nothing';

    public function getHeraldActionName() {
      return pht('Scan for vandalism');
    }

    public function getActionGroupKey() {
      return HeraldUtilityActionGroup::ACTIONGROUPKEY;
    }

    public function supportsObject($object) {
      return ($object instanceof ManiphestTask);
    }

    public function supportsRuleType($rule_type) {
      return ($rule_type == HeraldRuleTypeConfig::RULE_TYPE_GLOBAL);
    }

    public function applyEffect($object, HeraldEffect $effect) {
      $max_score = PhabricatorEnv::getEnvConfig(
        'antivandalism.max-score');
      // This is super janky but we don't currently get a reliable acting user.
      $last_actor_row = queryfx_one(
        $object->establishConnection('r'),
        'SELECT authorPHID FROM %T WHERE objectPHID = %s ORDER BY id DESC
          LIMIT 1',
        id(new ManiphestTransaction())->getTableName(),
        $object->getPHID());
      if (!$last_actor_row) {
        return;
      }

      $actor = id(new PhabricatorPeopleQuery())
        ->setViewer(PhabricatorUser::getOmnipotentUser())
        ->withPHIDs(array($last_actor_row['authorPHID']))
        ->executeOne();
      if (!$actor) {
        return;
      }

      if (!$this->isFriendlyUser($actor, $object)) {
        $hours = PhabricatorEnv::getEnvConfig(
          'antivandalism.edit-period-hours');

        $score = $this->scoreTransactions($actor, $object, $hours);

        if ($score > $max_score) {
          phlog('User '.$actor->getPHID()
            ." exceeded max score: $score > $max_score");
          return $this->quarantineUser($actor, $object, $score, $max_score);
        }
      }
      $this->logEffect(self::DO_NOTHING);
    }

    public function getHeraldActionStandardType() {
      return self::STANDARD_NONE;
    }

    public function renderActionDescription($value) {
      return pht('Disable vandal accounts.');
    }

    protected function getActionEffectMap() {
      return array(
        self::DO_QUARANTINE => array(
          'icon' => 'fa-stop',
          'color' => 'indigo',
          'name' => pht('Vandalism detected'),
        ),
        self::DO_NOTHING => array(
          'icon' => 'fa-cross',
          'color' => 'grey',
          'name' => pht('No action'),
        )
      );
    }

    protected function renderActionEffectDescription($type, $data) {
      switch ($type) {
        case self::DO_QUARANTINE:
          return pht('Quarantine the vandal account.');
        case self::DO_NOTHING:
          return pht('No vandalism detected.');
      }
    }

    private function isFriendlyUser(PhabricatorUser $user,
      ManiphestTask $task) {
      return WMFSecurityPolicy::userCanLockTask($user, $task);
    }

    private function scoreTransactions(PhabricatorUser $user,
      ManiphestTask $task, $hours) {

      $now = time();
      $seconds_per_hour = 60 * 60;
      $ts_start = $now - ($seconds_per_hour * $hours);

      $table = id(new ManiphestTransaction())->getTableName();
      $userPHID = $user->getPHID();

      // these transaction types include textual `old` and `new` values which
      // are scored based on how much the text is changed.
      $textEdits = PhabricatorEnv::getEnvConfig(
        'antivandalism.text-edit-scores');

      // scores given to various transaction types
      $trnsValues = PhabricatorEnv::getEnvConfig(
      'antivandalism.transaction-scores');

      $counts = queryfx_one(
        $task->establishConnection('r'),
        'SELECT
          COUNT(DISTINCT objectPHID) AS objectCount
          FROM    %T
          WHERE   authorPHID = %s',
          $table, $userPHID);

      $transactions = queryfx_all(
        $task->establishConnection('r'),
        'SELECT
          `commentPHID`,
          `objectPHID`,
          `dateCreated`,
          `transactionType`,
          `oldValue`,
          `newValue`
        FROM %T
        WHERE authorPHID = %s AND dateModified > %d
        ORDER BY dateModified ASC',
        $table, $userPHID, $ts_start);

      if (!$transactions) {
        $transactions = array();
      }
      $scores = array();

      foreach($transactions as $trns) {
        $obj = $trns['objectPHID'];
        $type = $trns['transactionType'];
        $trnsDate = $trns['dateCreated'];
        $oldValue = $trns['oldValue'];
        $newValue = $trns['newValue'];
        $age = ($now - $trnsDate);

        $wasBlank = ($oldValue == null || $oldValue == '' || $oldValue == '[]'
                    || $oldValue == $newValue);

        if (!isset($scores[$obj])) {
          $scores[$obj] = array();
        }

        // default score for any transaction not defined in either $textEdits
        // or $trnsValues:
        $editScore = 0.5;

        if (isset($textEdits[$type])) {
          $scoreConfig = $textEdits[$type];
          $oldLen = strlen($oldValue);
          $newLen = strlen($newValue);
          if ($wasBlank) {
            // edit added text where there was none before, not likely to be vandalism
            $editScore = 0;
          } else if ($oldLen > 0 && $newLen == 0) {
            // edit removed all text, this is more likely to be vandalism
            $editScore = 4 * $scoreConfig;
          } else {
            // Calculate a score based on how much the text changed
            // this naively uses only the length of the text for comparison.

            $diff = max($oldLen, $newLen) - min($oldLen, $newLen);
            $editScale = $diff / $oldLen;
            $editScore = 0.6 + ($scoreConfig * $editScale);
            $editScore = max($editScore, 0.5 * $scoreConfig);
            $editScore = min($editScore, 3 * $scoreConfig);
          }
        } else if (isset($trnsValues[$type])) {
          $editScore = $trnsValues[$type];
          if ($wasBlank) {
            $editScore = $editScore / 2;
          }
        } else if ($wasBlank) {
          $editScore = 0;
        } else {
          $editScore = 0.5;
        }

        if ($age > 0 && $editScore > 0) {
          // This penalizes very rapid edits with a logrithmic decay over time.
          // logfactor is y=$multiplier * (x/x ^ $power) where x is the age of the transaction
          // in seconds. This means that the scores decay rapidly at first,
          // then more gradually after a few seconds.
          $age_multiplier = PhabricatorEnv::getEnvConfig(
            'antivandalism.age-factor-multiplier');
          $age_decay = PhabricatorEnv::getEnvConfig(
            'antivandalism.age-factor-decay');
          $logfactor = $age_multiplier * ($age / pow($age, $age_decay));
          // limit the multiplier range:  0.2 < $logfactor < 2
          $logfactor = max($logfactor, 0.2);
          $logfactor = min($logfactor, 2);
          $scores[$obj][] = $logfactor * $editScore;
        }
      }


      // To get recentEditRatio, Multiply the score by the ratio of recently
      // edited objects divided by the total number of objects ever touched
      // by this user.
      // This lowers the score for users with edit history that occured prior
      // to the current period defined by `antivandalism.edit-period-hours`
      // So new users get scored higher than users who have a long history.

      $uniqueObjects = array_keys($scores);
      $objectCount = count($uniqueObjects);
      // Limit the multiplier to a range of 0.5 to 1.0
      $totalObjectCount = max($counts['objectCount'], $objectCount);
      $recentEditRatio = max($objectCount / $totalObjectCount, 0.5);

      $objScore = array();
      $totalScore = 0;
      foreach($scores as $obj=>$objScores) {
        if (count($objScores) > 0) {
          $objTotal = 0;
          foreach($objScores as $score) {
            $objTotal += $score;
          }
        } else {
          $objTotal = 1;
        }
        $totalScore += $objTotal;
      }
      //phlog('recent ratio:'.$recentEditRatio);
      $totalScore = $totalScore * $recentEditRatio;
      //phlog("antivandalism score: $totalScore");
      if (date('N') >= 6) {
        $totalScore = 1.2 * $totalScore;
      }
      return $totalScore;
    }

    private function quarantineUser(
      PhabricatorUser $user, $object, $score, $max_score) {

      if ($user->getIsAdmin()) {
        phlog('skip quarantine, user is an admin.');
        return;
      }
      // Log the user out of all their sessions
      $sessions = id(new PhabricatorAuthSessionQuery())
        ->setViewer($user)
        ->withIdentityPHIDs(array($user->getPHID()))
        ->execute();
      foreach ($sessions as $session) {
        $session->delete();
      }

      $should_disable_vandals = PhabricatorEnv::getEnvConfig(
        'antivandalism.disable-vandals');

      $disable_threshold = $max_score * 1.5;

      if ($should_disable_vandals && $score < $disable_threshold) {
        // only disable the account if score exceeds max by 1.5x
        $should_disable_vandals = false;
      }

      $story_data = array(
        'vandalPHID' => $user->getPHID(),
        'objectPHID' => $object->getPHID(),
      );

      if ($should_disable_vandals) {
        // disable the user
        $user->setIsDisabled(true);
        $user->saveWithoutIndex();
        $story_data['action'] = 'Account Disabled';
      } else {
        $story_data['action'] = 'Sessions Deleted';
      }

      $herald_phid = id(new PhabricatorHeraldApplication())->getPHID();
      $publisher = new PhabricatorFeedStoryPublisher();
      $publisher->setStoryType('AntiVandalismFeedStory')
                ->setStoryData($story_data)
                ->setStoryTime(time())
                ->setStoryAuthorPHID($herald_phid)
                ->setPrimaryObjectPHID($object->getPHID())
                ->setRelatedPHIDs(array($object->getPHID()))
                ->setSubscribedPHIDs(array($user->getPHID()))
                ->setNotifyAuthor(true);
      $data = $publisher->publish();

      $this->logEffect(self::DO_QUARANTINE);
      //throw new Exception(pht('Vandalism detected: %s', $story_data['action']));
    }

  }
