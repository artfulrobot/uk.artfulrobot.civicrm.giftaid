# Simple Gift Aid Extension for CiviCRM

[Please see
documentation](https://artfulrobot.github.io/uk.artfulrobot.civicrm.giftaid/)


## Changes

### 1.7.1 (CARE NEEDED WITH UPGRADING!)

The gift aid integrity check now works with per-minute accuracy, not per-second as before.

There is an upgrade migration included, so as long as you run upgrades in the proper way, you should be fine. The upgrade step will create a backup table containing data related to any changed records.

If you do not run the upgrader after having updated the code, then edits to contributions could result in contributions that have been claimed already losing this information.


