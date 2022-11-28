<?php

/**
 * Collection of upgrade steps.
 */
class CRM_Giftaid_Upgrader extends CRM_Giftaid_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
  public function install() {
    $this->executeSqlFile('sql/myinstall.sql');
  }

  /**
   * Set all contribs to 'unknown' eligibility.
   *
   * Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  public function postInstall() {
    $this->ensureFieldsEtc();
    CRM_Giftaid::singleton()->createDefaultsWhereMissing();
  }

  public function ensureFieldsEtc() {

    /**
   * Helper function for creating data structures.
   *
   * @param string $entity - name of the API entity.
   * @param Array $params_min parameters to use for search.
   * @param Array $params_extra these plus $params_min are used if a create call
   *              is needed.
   */
    $api_get_or_create = function ($entity, $params_min, $params_extra) {
      $params_min += ['sequential' => 1];
      $result = civicrm_api3($entity, 'get', $params_min);
      if (!$result['count']) {
        // Couldn't find it, create it now.
        $result = civicrm_api3($entity, 'create', $params_extra + $params_min);
      }
      return $result['values'][0];
    };

    // We need a Gift Aid declaration activity type
    $activity_type = $api_get_or_create('OptionValue', [
      'option_group_id' => "activity_type",
      'name' => "ar_giftaid_declaration",
    ],
      [ 'label' => 'Gift Aid Declaration']);

    // Ensure we have the custom field group we need for contributions.
    $contribution_custom_group = $api_get_or_create('CustomGroup', [
      'name' => "ar_giftaid_contribution",
      'extends' => "Contribution",
    ],
      ['title' => 'Gift Aid Details']);

    // Add our 'Eligibility' field.
    // ...This is a drop-down select field, first we need to check the option
    //    group exists, and its values.
    $status_opts_group = $api_get_or_create('OptionGroup',
      ['name' => 'ar_giftaid_contribution_eligibility_opts'],
      ['title' => 'Eligibility', 'is_active' => 1]);
    $weight = 0;
    foreach ([
      "unknown"    => "Unknown",
      "ineligible" => "NOT eligible for Gift Aid",
      "unclaimed"  => "Eligible but not yet claimed",
      "claimed"    => "Eligible and has been claimed",
    ] as $name => $label) {
      $api_get_or_create('OptionValue',
        [ 'option_group_id' => "ar_giftaid_contribution_eligibility_opts", 'name' => $name, ],
        [ 'label' => $label, 'value' => $name, 'weight' => $weight++ ]);
    }

    // ... Now we can add the Eligibility field to the custom group for contributions.
    $eligibility = $api_get_or_create('CustomField', [
      'name' => "ar_giftaid_contribution_status",
      'custom_group_id' => $contribution_custom_group['id'],
      'data_type' => "String",
      'html_type' => "Select",
      'is_required' => "1",
      'is_searchable' => "1",
      'default_value' => "unknown",
      'text_length' => "30",
      'option_group_id' => $status_opts_group['id'],
    ],
      ['label' => 'Eligibility']);

    // ... Now add a field to group the claims.
    $eligibility = $api_get_or_create('CustomField', [
      'name' => "ar_giftaid_contribution_claimcode",
      'custom_group_id' => $contribution_custom_group['id'],
    ],
      [
        'label' => 'Claim Code',
        'data_type' => "String",
        'html_type' => "Text",
        'is_required' => "0",
        'is_searchable' => "1",
        'default_value' => "",
        'text_length' => "30",
      ]);

    // ... Now add a field to check the integrity, see #1, #2
    // This is <contributionID>-<receive-date>-<amount>
    $integrity = $api_get_or_create('CustomField', [
      'name' => "ar_giftaid_contribution_integrity",
      'custom_group_id' => $contribution_custom_group['id'],
    ],
      [
        'label' => 'Integrity check',
        'data_type' => "String",
        'html_type' => "Text",
        'is_required' => "0",
        'is_searchable' => "1",
        'default_value' => "",
        'text_length' => "40",
      ]);
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * This is now included in the postInstall hook.
   *
   * @return TRUE on success
   * @throws Exception
   *
   */
  public function upgrade_4700() {
    $this->ctx->log->info('Applying update 4700');
    CRM_Giftaid::singleton()->createDefaultsWhereMissing();
    return TRUE;
  }

  /**
   * This is now included in the postInstall hook.
   * It's included in v1.5 because there were cases that 1.4 might have left contribs without default values.
   *
   * @return TRUE on success
   * @throws Exception
   *
   */
  public function upgrade_5000() {
    $this->ctx->log->info('Applying update 5000');
    CRM_Giftaid::singleton()->createDefaultsWhereMissing();
    return TRUE;
  }

  public function upgrade_5001() {
    $this->ctx->log->info('Applying update 5001');
    $this->ensureFieldsEtc();
    $ga = CRM_Giftaid::singleton();
    $ga->fixFakeClaims();
    $ga->addIntegrityCheckToExistingClaims();
    return TRUE;
  }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
