<?php

final class PhabricatorAntiVandalismConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Anti-Vandalism');
  }

  public function getDescription() {
    return pht('Options for tuning the antivandalism filter.');
  }

  public function getIcon() {
    return 'fa-hand-stop-o';
  }

  public function getGroup() {
    return 'apps';
  }

  public function getOptions() {

    return array(
      $this->newOption('antivandalism.max-score', 'int', 75)
        ->setSummary(
          pht('The number of tasks a new user can edit before we react.')),

      $this->newOption('antivandalism.edit-period-hours', 'int', 2)
        ->setSummary(
          pht('The time period examined when scoring edits made by a user.')),

      $this->newOption('antivandalism.disable-vandals', 'bool', false)
        ->setSummary(
          pht('Disable the accounts of vandals when these limits are exceeded')
        ),

      $this->newOption(
        'antivandalism.transaction-scores',
        'wild',
        array(
          'priority' => 0.4,
          'core:subscribers' => 0.4,
          'reassign' => 0.4,
          'core:edge' => 0.4,
          'status' => 0.4,
          'core:comment' => 0.1,
          'core:columns' => 0.4,
          'token:give' => 0.2,
          'core:create' => 0.4,
          'core:customfield' => 0.4,
          'core:subtype' => 0.0,
          'core:edit-policy' => 0.1,
          'core:view-policy' => 0.1,
        )
      )
      ->setSummary(pht('Adjust the base scores for each transaction type'))
      ->setDescription(pht(
        'For each action taken by a user, phabricator records one or more ' .
        'transactions. The type of transaction reflects what action was ' .
        'taken. When Antivandalism is responding to user activity, each ' .
        'transaction is assigned a base score and the scores are then ' .
        'added together and multiplied by a factor based on the frequency ' .
        'of activity the user has generated. Faster editing produces a ' .
        'larger multiplier. The final score is compared to the value in ' .
        '**antivandalism.max-score**.  If the score is too high, then' .
        'the account is either logged out of all sessions or disabled. ' .
        'To customize scores, provide a json-formatted map of transaction ' .
        'type keys with floating-point values. For most purposes, values ' .
        'should be between 0.0 and 1.0 for all transaction types.')),

      $this->newOption(
        'antivandalism.text-edit-scores',
        'wild',
        array(
          'description' => 1.0, 'title' => 0.5
        ))
        ->setSummary(
          'Default scores applied to edits that change a text field.')
        ->setDescription(
          'These are the default scores applied to edits on text fields such' .
          'as task title or description. The base score is multiplied by a ' .
          'factor which is determined by how much the text was changed. ' .
          'What this means is that edits which only add text are scored ' .
          'lower than edits which remove or alter existing text. The base ' .
          'score for each field should represent its relative importance.'
        ),

      $this->newOption(
        'antivandalism.age-factor-multiplier',
        'int',
        1)
        ->setSummary(
          'The multiplier applied to the age component of the score.')
        ->setDescription('Larger values inflate the overall score. '.
          'This should be a value between 2 and 10.'),

      $this->newOption(
        'antivandalism.age-factor-decay',
        'wild',
        '1.2')
        ->setSummary(
          'The rate of decay applied to the age component of the score.')
        ->setDescription(
          'Larger values result in a faster decay which means that older ' .
          'edits score lower.  Each edit is scored, then the score is multiplied '.
          'by the age multiplier. The multiplier is calculated as follows: '.
          'age_factor = multiplier * (age / age^decay). '.
          'Age is how long ago the edit occurred, in seconds.'),

        $this->newOption('antivandalism.short-text-penalty', 'int', 5)
          ->setSummary(
            'This constant is added to the score when an edit results in very '.
            'short title or description.'),

        $this->newOption('antivandalism.short-text-length', 'int', 10)
            ->setSummary(
              'Minimum length below which a penalty is applied. '.
            'See also: antivandalism.short-text-penalty'),
      );
  }

}
