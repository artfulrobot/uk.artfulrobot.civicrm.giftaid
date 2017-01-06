<?php

require_once 'CRM/Contribute/Form/Task.php';


/**
 * Form controller class
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 */
class CRM_Giftaid_Form_Task_Manage extends CRM_Contribute_Form_Task {
  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess()
  {
    //check for update permission
    if ( !CRM_Core_Permission::checkActionPermission( 'CiviContribute', CRM_Core_Action::UPDATE ) ) {
      CRM_Core_Error::fatal( ts( 'You do not have permission to access this page' ) );
    }
    parent::preProcess();

  }
  public function buildQuickForm() {

    // Summarise the contributions.
    $ga = CRM_Giftaid::singleton();

    $summary = $ga->summariseContributions($this->_contributionIds);

    // Tidy up the dates.
    if ($summary['earliest']) {
      $summary['earliest'] = date('j M Y', strtotime($summary['earliest']));
    }
    if ($summary['latest']) {
      $summary['latest'] = date('j M Y', strtotime($summary['latest']));
    }

    // Tidy amounts.
    foreach (['unclaimed_ok', 'unclaimed_missing_data', 'unclaimed_aggregate', 'unknown', 'no_data', 'claimed', 'ineligible'] as $_) {
      $summary[$_]['total'] = number_format($summary[$_]['total'], 2, ".", ",");
    }

    $this->assign('gaSummary', $summary);

    // Do we need to offer checkbox to include ok contribs?
    if ($summary['unclaimed_ok']['count']) {
      $this->add(
        'advcheckbox', // type
        'unclaimed_ok_include', // field name
        'Include these contributions in a new claim.'
      );
    }
    // Do we need to offer checkbox to include contribs that could be aggregated?
    if ($summary['unclaimed_aggregate']['count']) {
      $this->add(
        'advcheckbox', // type
        'unclaimed_aggregate_include', // field name
        'Include these contributions in a new claim as an aggregate.'
      );
    }
    // Do we need to offer checkbox to include ok contribs?
    if ($summary['claimed']['count']) {
      $this->add(
        'advcheckbox', // type
        'claimed_include', // field name
        'Re-generate claim data'
      );
    }
    // Do we need to offer the check unknown?
    if (FALSE && $summary['unknown']['count']) {

      // I could not get this to work. Any submit button I added did not cause
      // the form to be submitted.  The only way to get a button to submit to
      // the form is to set it's name to _qf_Manage_submit but understandably,
      // QuickForm does not like you having two buttons with the same name. So
      // I added this as raw HTML in the tpl file instead.
      $this->add(
        'submit',
        'determineEligiblity', // field name
        'Determine Eligibility',
        [
          'name' => '_qf_Manage_submit',
        ]
      );
    }
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => ts('Submit a'),
        'isDefault' => FALSE,
      ),
    ));
    if (FALSE) {
    $this->assign('nextClaimId', $this->_next_claim_id);


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

    }
    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    // Get comma separated list of contribution ids, ensuring they're all
    // integers so there can't be any SQL injection.
    $list = implode(',', array_filter(array_map(function($_) { return (int) $_; }, $this->_contributionIds)));
    if (!$list) {
      CRM_Core_Session::setStatus(ts("Sorry, an error sprung up from out of
        nowhere and confounded me. I was expecting to be able to update some
        contributions, but apparently there's none to update. This can happen
        if there's been a bit of over-keen pressing back on the browser. Sorry
        for the hassle. Suggest you try starting the process again."));
    }
    else {
      $values = $this->exportValues();
      if (!isset($values['_qf_Manage_submit'])) {
        // Um, nothing to do, I think.
      }
      elseif ($values['_qf_Manage_submit'] == 'determine_eligibility_unknown') {
        // Determine eligibility on the contributions.
        CRM_Giftaid::singleton()->determineEligibility($this->_contributionIds);
        CRM_Core_Session::setStatus("Contributions updated.", 'Gift Aid', 'success');
      }
      elseif ($values['_qf_Manage_submit'] == 'regenerate_claimed') {
        // Like Create a claim but it won't update anything.

        $ga_status_allowed = ['claimed'];
        $include_aggregates = TRUE;
        CRM_Giftaid::singleton()->makeClaim($this->_contributionIds, $ga_status_allowed, $include_aggregates);
      }
      elseif ($values['_qf_Manage_submit'] == 'create_claim') {
        // Create a claim. Nb. if this is called on non-unclaimed stuff it won't update anything.

        $ga_status_allowed = ['unclaimed'];
        $include_aggregates = FALSE;
        if (!empty($values['unclaimed_ok_include'])) {
          $ga_status_allowed []= 'unclaimed';
        }
        if (!empty($values['unclaimed_aggregate_include'])) {
          $ga_status_allowed []= 'unclaimed';
          $include_aggregates = TRUE;
        }
        $ga_status_allowed = array_unique($ga_status_allowed);
        if (count($ga_status_allowed)!=1) {
          // @todo move this to validation.
          CRM_Core_Session::setStatus("No contributions selected. Use the check-boxes.", 'Gift Aid');
        }
        else {
          // Hopefully this will show on the next screen they visit.
          // Haven't figured out how to send browser CSV and redirect them...
          CRM_Core_Session::setStatus("Contributions updated.", 'Gift Aid', 'success');
          CRM_Giftaid::singleton()->makeClaim($this->_contributionIds, $ga_status_allowed, $include_aggregates);
        }
      }
      else {
        CRM_Core_Error::fatal( ts( 'Huh?' ) );
      }
    }

    parent::postProcess();
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contribute/search'));
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
  public function determineEligiblity() {
    $x=1;
  }
}
