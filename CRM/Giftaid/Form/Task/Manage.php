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
  public function preProcess() {
    //check for update permission
    if (!CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
      throw new CRM_Core_Error(ts('You do not have permission to access this page'));
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
      // type
        'advcheckbox',
      // field name
        'unclaimed_ok_include',
        'Include these contributions in a new claim.'
      );
    }
    // Do we need to offer checkbox to include contribs that could be aggregated?
    if ($summary['unclaimed_aggregate']['count']) {
      $this->add(
      // type
        'advcheckbox',
      // field name
        'unclaimed_aggregate_include',
        'Include these contributions in a new claim as an aggregate.'
      );
    }
    // Do we need to offer checkbox to include ok contribs?
    if ($summary['claimed']['count']) {
      $this->add(
      // type
        'advcheckbox',
      // field name
        'claimed_include',
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
      // field name
        'determineEligiblity',
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

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    // Get comma separated list of contribution ids, ensuring they're all
    // integers so there can't be any SQL injection.
    $list = implode(',', array_filter(array_map(function($_) {
      return (int) $_;
    }, $this->_contributionIds)));
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
      elseif ($values['_qf_Manage_submit'] == 'group_missing_data') {
        // Create a group of contacts who do not have data.
        $ga = CRM_Giftaid::singleton();
        $groupID = $ga->exportMissingDataContactsToGroup($this->_contributionIds);
        if (!$groupID) {
          CRM_Core_Session::setStatus("Nothing to do", 'Gift Aid', 'success');
        }
        else {
          // Redirect to group contacts list.
          $url = CRM_Utils_System::url('civicrm/group/search', [
            'reset'  => 1,
            'force'  => 1,
            'gid'    => $groupID,
            'action' => 'view',
          ]);
          CRM_Utils_System::redirect($url);
        }
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
          $ga_status_allowed[] = 'unclaimed';
        }
        if (!empty($values['unclaimed_aggregate_include'])) {
          $ga_status_allowed[] = 'unclaimed';
          $include_aggregates = TRUE;
        }
        $ga_status_allowed = array_unique($ga_status_allowed);
        if (count($ga_status_allowed) != 1) {
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
        throw new CRM_Core_Error('Invalid form submission. This should not be possible. Code ARGA1');
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

}
