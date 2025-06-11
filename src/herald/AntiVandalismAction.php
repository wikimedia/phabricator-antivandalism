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
        ->withIsDisabled(false)
        ->executeOne();
      if (!$actor) {
        return;
      }

      if (!$this->isFriendlyUser($actor, $object)) {
        $config_edit_period_hours = PhabricatorEnv::getEnvConfig(
          'antivandalism.edit-period-hours');

        $score = $this->scoreTransactions($actor, $object, $config_edit_period_hours);

        // TODO: remove WMF temporary debugging (202502 changes)
        if ($score > ($config_max_score - 12) && $score < $config_max_score) {
          phlog('WMF-AVA DEBUG: User '.$actor->getUsername()
            ." close to logout score of $config_max_score: $score");
        }

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

      $trusted_project_names = ["Trusted-Contributors", "WMF-NDA", "acl*sre-team", "acl*security", "acl*Batch-Editors", "acl*phabricator"];
      $projects = self::getProjectByName($trusted_project_names, $user, true);
      if (count($projects) !== 6) {
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
      $userAccountAge = $now - $userCreated;
      $userIsNew = $userAccountAge < (60*60*24*7); // 7 days
      $userIsBrandNew = $userAccountAge < (60*60*12); // 12 hours

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
      // Get recent transactions by user, sort by newest first
      $transactions = queryfx_all(
        $task->establishConnection('r'),
        'SELECT
          `commentPHID`,
          `objectPHID`,
          `dateCreated`,
          `transactionType`,
          `oldValue`,
          `newValue`,
          `metadata`
        FROM %T
        WHERE authorPHID = %s AND id > %d AND dateModified > %d
        ORDER BY dateModified DESC',
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
        $transaction_score = 0.5;

        // Score a change in a defined text field (e.g. title, desc):
        if (isset($config_text_edit_scores[$type])) {
          $scoreConfig = $config_text_edit_scores[$type];
          $oldLen = strlen($oldValue);
          $newLen = strlen($newValue);
          if ($old_value_blank_or_unchanged) {
            // edit added text where there was none before, not likely to be vandalism
            $transaction_score = 0;
          } else if ($oldLen > 0 && $newLen == 0) {
            // edit removed all text, this is more likely to be vandalism
            // apply double the shortTextPenalty in this case
            $transaction_score = $scoreConfig + (2*$config_short_text_penalty);
          } else {

            // Calculate a score based on how much the text changed
            // this naively uses only the length of the text for comparison.

            $diff = max($oldLen, $newLen) - min($oldLen, $newLen);
            $editScale = $diff / $oldLen;
            $transaction_score = 0.6 + ($scoreConfig * $editScale);
            $transaction_score = max($transaction_score, 0.5 * $scoreConfig);
            $transaction_score = min($transaction_score, 3 * $scoreConfig);
            if ($newLen <= $config_short_text_length && $oldLen > $config_short_text_length) {
              $transaction_score += $config_short_text_penalty;
            }
          }
          // Penalize on _creating_ tasks with short titles - T396471
          if ($type === 'title' && $oldValue === '""' && strlen($newValue) < 10) {
            $transaction_score = $transaction_score + 20;
          }
        // Score a change in a defined non-text field:
        } else if (isset($config_transaction_scores[$type])) {
          $transaction_score = $config_transaction_scores[$type];
          if ($type === "core:customfield") {
            $metadata_json = $trns['metadata'];
            $metadata = json_decode($metadata_json, true);
            // Penalize hard on nonsensical large story point values
            if ($metadata['customfield:key'] === "std:maniphest:points.final"
                && $newValue !== "null" && $newValue > 99) {
              $transaction_score = $transaction_score + 15;
            }
            // Penalize on setting Due Date to default last midnight
            if ($metadata['customfield:key'] === "std:maniphest:deadline.due"
                && $newValue <= $now && $newValue >= $now - 86400
                && $newValue % 86400 == 0) {
              $transaction_score = $transaction_score + 8;
            }
          }
          // Penalize harder on removing _all_ subscribers
          else if ($type == "core:subscribers" &&
              $oldValue !== '[]' && $newValue === '[]') {
            $transaction_score = $transaction_score + 4;
          }
          else if ($type == "core:edge") {
            // Transaction removed _all_ existing edges of some type
            if ($oldValue !== '[]' && $newValue === '[]') {
              // Penalize harder on removing _all_ project tags by number of tags
              if (strpos($oldValue, 'PHID-PROJ-') !== false) {
                // TODO: Use str_contains() instead of strpos() in PHP8.0
                $removed_projs = substr_count($oldValue, "PHID-PROJ-");
                if ($removed_projs > 1) {
                  $transaction_score = $transaction_score + $removed_projs;
                }
              }
              // Penalize harder on removing _all_ parent/child tasks by number of tasks
              if (strpos($oldValue, 'PHID-TASK-') !== false) {
                // TODO: Use str_contains() instead of strpos() in PHP8.0
                $removed_tasks = substr_count($oldValue, "PHID-TASK-");
                if ($removed_tasks > 1) {
                  $transaction_score = $transaction_score + $removed_tasks;
                }
              }
            }
            // linking a mock is very uncommon, hip kids are on Figma - T396609
            else if (strpos($newValue, 'PHID-MOCK-') !== false) {
              $transaction_score = $transaction_score + 12;
            }
          }
        // Score a change in a non-defined field:
        } else {
          $transaction_score = 0.5;
        }

        // Don't consider $age = 0 because it inflates the score.
        if ($age > 0 && $transaction_score > 0) {
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
          $scores[$obj][] = $logfactor * $transaction_score;
        }
      }

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

      // Number of different epochs within the max 150 feed stories of the
      // user within the last six months
      $epoch_one_week_ago = $now - (60 * 60 * 24 * 7);
      $epoch_half_year_ago = $now - (60 * 60 * 24 * 183);
      $query = id(new PhabricatorFeedQuery())
               ->setViewer(PhabricatorUser::getOmnipotentUser())
               ->withFilterPHIDs(array($userPHID))
               ->withEpochInRange($epoch_half_year_ago, $epoch_one_week_ago)
               ->setLimit(150)
               ->setReturnPartialResultsOnOverheat(true);
      $stories = $query->execute();
      $story_epochs = [];
      foreach ($stories as $story) {
        $story_epochs[] = $story->getEpoch();
      }
      $unique_feed_epochs = sizeof(array_unique($story_epochs));
      // To get $recent_ratio, Multiply the score by the ratio of recently
      // edited objects by the user divided by the user's recent feed stories.
      // This lowers the score for users with edit history that occured prior
      // to the current period defined by `antivandalism.edit-period-hours`
      // So new users get scored higher than users who have some history.
      $period_hours_unique_objects = array_keys($scores);
      $period_hours_object_count = count($period_hours_unique_objects);
      // Limit the multiplier to a range of 0.5 to 1.0
      // Next line is old code - TODO: first should always be larger or equal?
      // TODO: $unique_feed_epochs and $period_hours_object_count have overlap
      // (dup values) if antivandalism.edit-period-hours was set to >= 168
      $max_object_count = max(($unique_feed_epochs + $period_hours_object_count), $unique_feed_epochs);
      // 0 should be impossible but let's play safe and don't divide by zero
      if ($max_object_count > 0) {
        $recent_ratio = max($period_hours_object_count / $max_object_count, 0.5);
      } else {
        $recent_ratio = 1;
      }
      $totalScore = $totalScore * $recent_ratio;

      // it's weekend
      // if (date('N') >= 6) {
      //   $totalScore = 1.2 * $totalScore;
      // }

      // new account
      if ($userIsBrandNew) {
        $totalScore = 2.5 * $totalScore;
      }
      else if ($userIsNew) {
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

      $disable_threshold = $config_max_score * 1.4;

      if ($config_disable_vandals && $score < $disable_threshold) {
        phlog('WMF-AVA: User '.$user->getUsername()
          ." logged out as they exceeded max score: $score > $config_max_score");
        // only disable the account if score exceeds max by 1.4x
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
          ." disabled as they 1.4x exceeded max score: $score > $disable_threshold");
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
