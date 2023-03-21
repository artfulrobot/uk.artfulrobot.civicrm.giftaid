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
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function giftaid_civicrm_install() {
  _giftaid_civix_civicrm_install();
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
  if ($apiRequest['entity'] === 'Contribution' && $apiRequest['action'] === 'create') {
    $wrappers[] = new CRM_Giftaid_ApiWrapperContributionCreate();
  }
}

}

function giftaid_civicrm_custom( $op, $groupID, $entityID, &$params ) {
  if ($op === 'create' || $op === 'edit') {
    if ($params[0]['entity_table'] ?? NULL === 'civicrm_contributions') {
      // Looks likely.
      // echo "$op $groupID $entityID " . json_encode($params, JSON_PRETTY_PRINT) . "\n";
      CRM_Giftaid::singleton()->checkContribution($entityID);
    }
  }
}

/**
 * Implementation of hook_civicrm_check
 *
 * Add a check to the status page/System.check results if $snafu is TRUE.
 */
function giftaid_civicrm_check(&$messages, $statusNames, $includeDisabled) {

  // Early return if $statusNames doesn't call for our check
  if ($statusNames && !in_array('giftaidIntegrityCheck', $statusNames)) {
    return;
  }

  // If performing your check is resource-intensive, consider bypassing if disabled
  if (!$includeDisabled) {
    $disabled = \Civi\Api4\StatusPreference::get()
      ->setCheckPermissions(FALSE)
      ->addWhere('is_active', '=', FALSE)
      ->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('name', '=', 'giftaidIntegrityCheck')
      ->execute()->count();
    if ($disabled) {
      return;
    }
  }

  $ga = CRM_Giftaid::singleton();
  $baduns = $ga->getContributionsThatLackIntegrity();
  if ($baduns) {
    $contacts = [];
    foreach ($baduns as $contact) {
      $contacts[$contact['contact_id']] = TRUE;
    }

    Civi::log()->warning("giftaid: " . count($baduns) . " (" . count($contacts) . " contacts) are failing integrity checks. First record:\n" . json_encode($baduns[0], JSON_PRETTY_PRINT));

    $messages[] = new CRM_Utils_Check_Message(
      'giftaidIntegrityCheck',
      ts('Gift aid data integrity check problems'),
      ts('There are %1 contributions (%2 distinct contacts) that are failing integrity checks. This should not happen, and needs investigation.', [
        1 => count($baduns),
        2 => count($contacts)
      ]),
      \Psr\Log\LogLevel::ERROR,
      'fa-flag'
    );
  }
}
