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
      $config_max_score = PhabricatorEnv::getEnvConfig(
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
        ->withIsSystemAgent(false)
        ->withIsAdmin(false)
        ->executeOne();
      if (!$actor) {
        return;
      }

      if (!$this->isFriendlyUser($actor, $object)) {
        $config_edit_period_hours = PhabricatorEnv::getEnvConfig(
          'antivandalism.edit-period-hours');

        $score = $this->scoreTransactions($actor, $object, $config_edit_period_hours);

        if ($score > $config_max_score) {
          return $this->quarantineUser($actor, $object, $score, $config_max_score);
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

    /**
     * look up a project by name
     * @param string $projectName
     * @return PhabricatorProject|null
     */
    public static function getProjectByName($projectName, $viewer=null, $needMembers=false) {
      if ($viewer === null) {
        $viewer = PhabricatorUser::getOmnipotentUser();
      }
      if (!is_array($projectName)) {
        $projectName = array($projectName);
      }
      $query = new PhabricatorProjectQuery();
      $query->setViewer($viewer)
                ->withNames($projectName)
                ->needMembers($needMembers);
      if (count($projectName) == 1) {
        return $query->executeOne();
      } else {
        return $query->execute();
      }
    }

    private function isFriendlyUser(PhabricatorUser $user,
      ManiphestTask $task) {
      if (!$user->isLoggedIn()) {
        return false;
      }
      $user_phid = $user->getPHID();

      $trusted_project_names = ["Trusted-Contributors", "WMF-NDA", "acl*sre-team", "acl*security"];
      $projects = self::getProjectByName($trusted_project_names, $user, true);
      if (count($projects) !== 4) {
        phlog('WMF-AVA: Some project tags required by Antivandalism extension do not exist.');
      }

      foreach ($projects as $proj) {
        try {
          if ($proj instanceof PhabricatorProject &&
              $proj->isUserMember($user_phid)) {
            return true;
          }
        } catch(PhabricatorDataNotAttachedException $e) {
          continue;
        }
      }
      return false;
    }

    private function scoreTransactions(PhabricatorUser $user,
      ManiphestTask $task, $config_edit_period_hours) {

      $now = time();
      $seconds_per_hour = 60 * 60;
      $ts_start = $now - ($seconds_per_hour * $config_edit_period_hours);

      $table = id(new ManiphestTransaction())->getTableName();
      $userPHID = $user->getPHID();

      $userCreated = $user->getDateCreated();
      $userAccountAge = time() - $userCreated;
      $userIsNew = $userAccountAge < (60*60*24*7); // 7 days

      // these transaction types include textual `old` and `new` values which
      // are scored based on how much the text is changed.
      $config_text_edit_scores = PhabricatorEnv::getEnvConfig(
        'antivandalism.text-edit-scores');

      $config_short_text_penalty = PhabricatorEnv::getEnvConfig('antivandalism.short-text-penalty');
      $config_short_text_length = PhabricatorEnv::getEnvConfig('antivandalism.short-text-length');

      // scores given to various transaction types
      $config_transaction_scores = PhabricatorEnv::getEnvConfig(
      'antivandalism.transaction-scores');

      // Get latest Maniphest transaction ID
      $latest_transaction_id = queryfx_one(
        $task->establishConnection('r'),
        'SELECT
          MAX(id) AS latestTransactionId
          FROM    %T',
          $table);
      $latest_ts_id = (int)$latest_transaction_id['latestTransactionId'];

      // $id_limit resembles approx. last 3-4 weeks per DB growth in 02/2025,
      // still way above the other limit parameter $ts_start based on hours.
      $id_limit = $latest_ts_id - 100000;
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
        WHERE authorPHID = %s AND id > %d AND dateModified > %d
        ORDER BY dateModified ASC',
        $table, $userPHID, $id_limit, $ts_start);

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

        $old_value_blank_or_unchanged = ($oldValue == null || $oldValue == ''
          || $oldValue == '[]' || $oldValue == $newValue);

        if (!isset($scores[$obj])) {
          $scores[$obj] = array();
        }

        // default score for any transaction not defined in either $config_text_edit_scores
        // or $config_transaction_scores:
        $editScore = 0.5;

        // Edit-score a change in a defined text field (e.g. title, desc):
        if (isset($config_text_edit_scores[$type])) {
          $scoreConfig = $config_text_edit_scores[$type];
          $oldLen = strlen($oldValue);
          $newLen = strlen($newValue);
          if ($old_value_blank_or_unchanged) {
            // edit added text where there was none before, not likely to be vandalism
            $editScore = 0;
          } else if ($oldLen > 0 && $newLen == 0) {
            // edit removed all text, this is more likely to be vandalism
            // apply double the shortTextPenalty in this case
            $editScore = $scoreConfig + (2*$config_short_text_penalty);
          } else {

            // Calculate a score based on how much the text changed
            // this naively uses only the length of the text for comparison.

            $diff = max($oldLen, $newLen) - min($oldLen, $newLen);
            $editScale = $diff / $oldLen;
            $editScore = 0.6 + ($scoreConfig * $editScale);
            $editScore = max($editScore, 0.5 * $scoreConfig);
            $editScore = min($editScore, 3 * $scoreConfig);
            if ($newLen <= $config_short_text_length && $oldLen > $config_short_text_length) {
              $editScore += $config_short_text_penalty;
            }
          }
        // Edit-score a change in a defined non-text field:
        } else if (isset($config_transaction_scores[$type])) {
          $editScore = $config_transaction_scores[$type];
          if ($old_value_blank_or_unchanged) {
            $editScore = $editScore / 2;
          }
        // Edit-score a change in a non-defined field:
        } else if ($old_value_blank_or_unchanged) {
          $editScore = 0;
        } else {
          $editScore = 0.5;
        }

        // Don't consider $age = 0 because it inflates the score.
        if ($age > 0 && $editScore > 0) {
          // This penalizes very rapid edits with a logarithmic decay over time.
          // logfactor is y=$multiplier * (x/x ^ $power) where x is the age of the transaction
          // in seconds. This means that the scores decay rapidly at first,
          // then more gradually after a few seconds.
          $config_age_factor_multiplier = PhabricatorEnv::getEnvConfig(
            'antivandalism.age-factor-multiplier');
          $config_age_factor_decay = (float) PhabricatorEnv::getEnvConfig(
            'antivandalism.age-factor-decay');
          $logfactor = $config_age_factor_multiplier * ($age / pow($age, $config_age_factor_decay));
          // limit the multiplier range:  0.1 < $logfactor < 5
          $logfactor = max($logfactor, 0.1);
          $logfactor = min($logfactor, 5);
          $scores[$obj][] = $logfactor * $editScore;
        }
      }

      // Number of user touched objects within last 2mio Maniphest transactions
      $id_limit = $latest_ts_id - 2000000;
      $longterm_count = queryfx_one(
        $task->establishConnection('r'),
        'SELECT
          COUNT(DISTINCT objectPHID) AS objectCount
          FROM    %T
          WHERE   authorPHID = %s
          AND id > %d',
          $table, $userPHID, $id_limit);

      // To get recentEditRatio, Multiply the score by the ratio of recently
      // edited objects divided by the longterm number of objects touched
      // by this user.
      // This lowers the score for users with edit history that occured prior
      // to the current period defined by `antivandalism.edit-period-hours`
      // So new users get scored higher than users who have a long history.

      $uniqueObjects = array_keys($scores);
      $objectCount = count($uniqueObjects);
      // Limit the multiplier to a range of 0.5 to 1.0
      $totalObjectCount = max($longterm_count['objectCount'], $objectCount);
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
      //phlog('WMF-AVA: recent ratio:'.$recentEditRatio);
      $totalScore = $totalScore * $recentEditRatio;
      //phlog("WMF-AVA: antivandalism score: $totalScore");

      // it's weekend
      if (date('N') >= 6) {
        $totalScore = 1.2 * $totalScore;
      }

      // new account
      if ($userIsNew) {
        $totalScore = 1.2 * $totalScore;
      }

      return $totalScore;
    }

    private function quarantineUser(
      PhabricatorUser $user, $object, $score, $config_max_score) {

      // Log the user out of all their sessions
      $sessions = id(new PhabricatorAuthSessionQuery())
        ->setViewer($user)
        ->withIdentityPHIDs(array($user->getPHID()))
        ->execute();
      foreach ($sessions as $session) {
        $session->delete();
      }

      $config_disable_vandals = PhabricatorEnv::getEnvConfig(
        'antivandalism.disable-vandals');

      $disable_threshold = $config_max_score * 1.2;

      if ($config_disable_vandals && $score < $disable_threshold) {
        phlog('WMF-AVA: User '.$user->getUsername()
          ." logged out as they exceeded max score: $score > $config_max_score");
        // only disable the account if score exceeds max by 1.2x
        $config_disable_vandals = false;
      }

      $story_data = array(
        'vandalPHID' => $user->getPHID(),
        'objectPHID' => $object->getPHID(),
      );

      if ($config_disable_vandals) {
        // disable the user
        $user->setIsDisabled(true);
        $user->saveWithoutIndex();
        phlog('WMF-AVA: User '.$user->getUsername()
          ." disabled as they 1.2x exceeded max score: $score > $disable_threshold");
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
