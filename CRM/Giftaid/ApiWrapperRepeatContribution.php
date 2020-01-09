<?php
/**
 *
 * API_Wrapper to handle Contribution.repeattransaction calls.
 * @see https://github.com/artfulrobot/uk.artfulrobot.civicrm.giftaid/issues/1
 */
class CRM_Giftaid_ApiWrapperRepeatContribution implements API_Wrapper {

  /**
   * We do not need to change anything here.
   */
  public function fromApiInput($apiRequest) {
    return $apiRequest;
  }

  /**
   * Reset the ga claim data for the new transaction.
   */
  public function toApiOutput($apiRequest, $result) {
    if (isset($result['id'])) {
      $ga = CRM_Giftaid::singleton();
      civicrm_api3('Contribution', 'create', [
        'id'                  => $result['id'],
        $ga->api_claimcode    => '',
        $ga->api_claim_status => 'unknown',
      ]);
    }
    return $result;
  }
}
