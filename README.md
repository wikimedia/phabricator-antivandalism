# Wikimedia's Anti-Vandalism extension for Phabricator.

This extension implements a herald custom action which reacts to rapid (e.g. automated) user
activity by either logging out the offender or optionally disabling the account.

Project page: https://phabricator.wikimedia.org/tag/phabricator_antivandalism_extension/

This code is released under the Apache License Version 2.0

## Herald Rule Configuration

For the extension to have any affect, you first need to create a herald rule
that triggers on every maniphest task edit. This rule should have the following
configuration:

* Rule Type:  `Global`
* Applies To `Maniphest Tasks`
* When all of these conditions are met:
  * `Always`
* Take these actions every time this rule matches:
  * `Disable vandal accounts.`

## Extension configuration
You should also visit the extension's configuration section in the phabricator config interface and tune the parameters which affect the behavior and allow you to tune the scoring algorithm. The tuning parameters are described briefly in the following sections.

### General Parameters
* `antivandalism.max-score`
  * The edit score above which the filter will take action against a vandal. If you set this too low then innocent activity will be detected as vandalism. If it's set too high, then actual vandalism will be ignored by the filter.
* `antivandalism.edit-period-hours`
  * When examining a user's recent activity, how many hours of activity are taken into consideration.
* `antivandalism.disable-vandals`
  * If set to true then the extension will disable the account of offenders. Otherwise the offenders will simply have their session invalidated.  Leave this set to false until you are confident in your tuning parameters, otherwise you could end up disabling the accounts of innocent users.

### Scores
* `antivandalism.transaction-scores`
  * Here you can adjust the scores given to each type of transaction. Set each score between 0 and 1, where 1 is the activity most associated with vandals and 0 is for activity most likely to be legitimate.
* `antivandalism.text-edit-scores`
  * This allows you to adjust the relative scores given to text edits to the title and description. Use this if you want to weigh one type of edit more than the other.

### Decay Factors
The age multiplier and decay are used to scale the value of each recent edit within the time window under consideration. The window is controlled by `antivandalism.edit-period-hours` and the decay factors are calculated as follows:

```php
$decay_factor = $age_multiplier * ($age / pow($age, $age_decay))
```

`$decay_factor` is then constrained to the range of `0.2` < `$decay_factor` < `2.0` and that is multiplied with the configured score value for the specific transaction type (see `antivandalism.transaction-scores` and `antivandalism.text-edit-scores`) to obtain the final score.

* `antivandalism.age-factor-multiplier`
  * The age of any given edit is taken into account when scoring the user's activity. This multiplier is applied to the age factor after applying the decay to the age. Larger value here makes the filter more sensitive.
* `antivandalism.age-factor-decay`
  * The rate at which edit scroes decay with time. Larger decay means older edits decay quicker so a larger value here results in a less sensitive filter and edits will have to occur faster to trigger the filter. This should probably be set somewhere between 1 and 2.
