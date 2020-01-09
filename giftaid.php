<?php

require_once 'giftaid.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function giftaid_civicrm_config(&$config) {
  _giftaid_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function giftaid_civicrm_xmlMenu(&$files) {
  _giftaid_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function giftaid_civicrm_install() {
  _giftaid_civix_civicrm_install();

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

}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function giftaid_civicrm_uninstall() {
  _giftaid_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function giftaid_civicrm_enable() {
  _giftaid_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function giftaid_civicrm_disable() {
  _giftaid_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function giftaid_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _giftaid_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function giftaid_civicrm_managed(&$entities) {
  _giftaid_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function giftaid_civicrm_caseTypes(&$caseTypes) {
  _giftaid_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function giftaid_civicrm_angularModules(&$angularModules) {
_giftaid_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function giftaid_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _giftaid_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements https://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_searchTasks
 */
function giftaid_civicrm_searchTasks($object_name, &$tasks) {
  if ($object_name == 'contribution') {
    // Add our task.
    $tasks []= [
      'title'  => ts( 'Gift Aid administration' ),
      'class'  => 'CRM_Giftaid_Form_Task_Manage',
      'result' => FALSE,
    ];
    /*
    $tasks []= [
      'title'  => ts( 'Gift Aid: Update Unclaimed eligible to Claimed' ),
      'class'  => 'CRM_Giftaid_Form_Task_SetClaimed',
      'result' => FALSE,
    ];
    $tasks []= [
      'title'  => ts( 'Gift Aid: Determine eligibility from declarations' ),
      'class'  => 'CRM_Giftaid_Form_Task_SetEligibility',
      'result' => FALSE,
    ];
     */
  }
}

/**
 * @todo see if we can replace the activity subject with a drop down eligible/ineligible.
 */
function giftaid_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Activity_Form_Activity'
    && CRM_Core_Permission::check('edit all contacts')) {

    // look up activity type id and status_id
    /*
    $elem_status_id           = $form->getElement('status_id');
    $current_status_value     = $elem_status_id->getValue();
    $current_status_id        = $current_status_value[0];
     */
    $current_activity_type_id = $form->getVar('_activityTypeId');
    if (CRM_Giftaid::singleton()->activity_type_declaration != $current_activity_type_id) {
      // This is not one of our activities.
      return;
    }

    //CRM_Core_Resources::singleton()->addVars('de.systopia.xcm', $constants);

    CRM_Core_Region::instance('form-body')->add(array(
      'script' => file_get_contents(__DIR__ . '/activity-alter.js')
    ));
  }
}
/**
 *
 * https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_apiWrappers/
 *
 * @param Array &$wrappers
 * @param Array $apiRequest
 */
function giftaid_civicrm_apiWrappers(&$wrappers, $apiRequest) {
  if ($apiRequest['entity'] === 'Contribution' && $apiRequest['action'] === 'repeattransaction') {
    $wrappers[] = new CRM_Giftaid_ApiWrapperRepeatContribution();
  }
}
