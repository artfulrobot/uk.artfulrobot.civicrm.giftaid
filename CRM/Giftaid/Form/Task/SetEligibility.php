<?php

require_once 'CRM/Contribute/Form/Task.php';


/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Giftaid_Form_Task_SetEligibility extends CRM_Contribute_Form_Task {
  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {
      //check for update permission
      if ( !CRM_Core_Permission::checkActionPermission( 'CiviContribute', CRM_Core_Action::UPDATE ) ) {
          CRM_Core_Error::fatal( ts( 'You do not have permission to access this page' ) );
      }
      parent::preProcess();
  }
  public function buildQuickForm() {

    /*
    // add form elements
    $this->add(
      'select', // field type
      'favorite_color', // field name
      'Favorite Color', // field label
      $this->getColorOptions(), // list of options
      TRUE // is required
    );
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));
    */

    $this->addDefaultButtons(ts('Update Contributions'), 'done');

    // first find next available claim code

    // Find table name
    $table_name = civicrm_api3('CustomGroup', 'getvalue', ['return' => "table_name", 'name' => "ar_giftaid_contribution"]);
    $column_name = civicrm_api3('CustomField', 'getvalue', ['return' => "column_name", 'name' => "ar_giftaid_contribution_claimcode"]);
    $sql = "SELECT MAX(COALESCE($column_name ,0))+1 FROM $table_name";
    $this->_next_claim_id = (int) CRM_Core_DAO::singleValueQuery( $sql );
    if (! $this->_next_claim_id) {
      CRM_Core_Error::fatal( ts( 'Internal Error. How embarrassing. (Info for maintainer: Could not determine next available claim code)' ) );
    }
    $this->assign('nextClaimId', $this->_next_claim_id);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();

    CRM_Giftaid::singleton()->determineEligibility($this->_contributionIds);
    /*
    // count changes
    $sql = "SELECT COUNT(*) FROM $table_name WHERE $col_claim_code = %1";
    $successes = (int) CRM_Core_DAO::singleValueQuery($sql, $params);

    if (count($this->_contributionIds) == $successes) {
      $status = ($successes == 1 ? "Contribution " : "All $successes contributions ") . "successfully updated.";
    }
    else {
        $status = "$successes contribution(s) updated (out of "
            . count($this->_contributionIds)
            . " selected). Nb. Contributions are only updated if their Gift Aid status was 'Eligible but unclaimed'";
    }
    CRM_Core_Session::setStatus($status);
     */

    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }
}
