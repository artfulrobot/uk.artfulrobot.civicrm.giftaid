There has been an issue that has twice raised its head that happens when Civi
copies old custom data when creating a new contribution (in a recur), which then
includes the claim status and claim code of the *old* contribution.

This leads to things showing as claimed when they're not.

To solve this problem:

1. An 'integrity' column is added to the custom gift aid fieldset which contains
   the contribution ID, amount, date_received. So if the entity_id for a row does
   not match the start of the integrity column, something is up. The amount, date
   are softer assertions really.

2. A `hook_civicrm_custom` fires on writing a contribution's custom data. If
   the integrity check fails, the status is set to 'unknown'; the claim code removed.
   This should prevent problems with repeattransaction calls.

