<?php
/**
 *
 * API_Wrapper to handle Contribution.create calls.
 */
class CRM_Giftaid_ApiWrapperContributionCreate implements API_Wrapper {

  /**
   * We do not need to change anything here.
   */
  public function fromApiInput($apiRequest) {

    $ga = CRM_Giftaid::singleton();
    if ($apiRequest instanceof Civi\Api4\Generic\AbstractAction) {

      $params = $apiRequest->getParams();
      if (empty($params['id'])) {
        // If neither of our custom fields are present, set one of them, which
        // should trigger creation of the defaults for the other records.
        $found = FALSE;
        foreach ($params as $key => $val) {
          if (strpos($key, 'ar_giftaid_contribution.') === 0) {
            // we don't need to interfere.
            return $apiRequest;
          }
        }
        $apiRequest->addValue('ar_giftaid_contribution.ar_giftaid_contribution_claimcode', '');
      }
    }
    else {
      // APIv3
      // If we do not have an id, we're doing a create, as opposed to an update.
      if (empty($apiRequest['params']['id'])) {
        // If neither of our custom fields are present, set one of them, which
        // should trigger creation of the defaults for the other records.
        if (!isset($apiRequest['params'][$ga->api_claimcode]) && !isset($apiRequest['params'][$ga->api_claim_status])) {
          $apiRequest['params'][$ga->api_claimcode] = '';
        }
      }
    }

    return $apiRequest;
  }

  /**
   */
  public function toApiOutput($apiRequest, $result) {
    return $result;
  }
}
