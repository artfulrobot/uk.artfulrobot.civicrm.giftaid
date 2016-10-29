<?php
/**
 * I've tried to put most of the doing code in here so it can be tested.
 */
class CRM_Giftaid {
  /**
   * @var $singleton
   */
  protected static $singleton;

  /**
   * @var int
   */
  public $activity_target_type;


  /**
   * @var int
   */
  public $activity_type_declaration;

  /**
   * @var string column name for the claim status field.
   */
  public $col_claim_status;

  /**
   * @var string e.g. 'custom_13' to use in the API.
   */
  public $api_claim_status;

  /**
   * @var string table name for the contribution eligibility group.
   */
  public $table_eligibility;

  /**
   * Singleton pattern.
   */
  public static function singleton() {
    if (!isset(static::$singleton)) {
      static::$singleton = new static();
    }
    return static::$singleton;
  }

  /**
   * Constructor. Discovers some useful ids/
   */
  public function __construct() {
    $this->activity_target_type = (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_contacts", 'name' => "Activity Targets"]);

    $this->table_eligibility = civicrm_api3('CustomGroup', 'getvalue',
      ['return' => "table_name", 'name' => "ar_giftaid_contribution"]);

    $this->col_claim_status = civicrm_api3('CustomField', 'getvalue',
      ['return' => "column_name", 'name' => "ar_giftaid_contribution_status"]);
    $this->api_claim_status = preg_replace('/^.*(_\d+)$/', 'custom$1', $this->col_claim_status);

    $this->activity_type_declaration= (int) civicrm_api3('OptionValue', 'getvalue',
      ['return' => "value", 'option_group_id' => "activity_type", 'name' => "ar_giftaid_declaration"]);
  }

  /**
   * Change 'unknown' eligibility to 'unclaimed' or 'ineligible' based on declaration activities.
   *
   * This gets fairly complicated, see tests for expectations.
   *
   * Note this is a tool for the Gift Aid Guru. It is not a get rich quick scheme.
   * There are lots of things you can do wrong, e.g. by selecing the wrong set of contributions
   * (such as non-donations).
   *
   * @param array $contribution_ids Array of integers. Only these contributions will be affected.
   * @return void
   */
  public function determineEligibility($contribution_ids) {

    $list = $this->integerArrayToString($contribution_ids);

    // Find unique contacts, and the last declaration from them.
    // These unknown eligibility contributions are eligible, unclaimed.
    $sql = "UPDATE $this->table_eligibility el
        INNER JOIN civicrm_contribution co ON el.entity_id = co.id
        INNER JOIN civicrm_contact c ON co.contact_id = c.id AND c.is_deleted = 0 AND c.is_deceased = 0

        INNER JOIN civicrm_activity_contact ac ON c.id = ac.contact_id AND ac.record_type_id = $this->activity_target_type
        INNER JOIN civicrm_activity a ON ac.activity_id = a.id AND a.activity_type_id = $this->activity_type_declaration AND a.subject IN('Eligible','Ineligible') AND a.is_deleted = 0

      SET el.$this->col_claim_status = IF(a.subject = 'Eligible',  'unclaimed', 'ineligible')

      WHERE co.id IN ($list) AND el.$this->col_claim_status = 'unknown'
        AND co.receive_date >= a.activity_date_time
        AND NOT EXISTS (
            SELECT ac2.id FROM civicrm_activity_contact ac2
              INNER JOIN civicrm_activity a2 ON a2.id = ac2.activity_id AND ac2.record_type_id = $this->activity_target_type
            WHERE ac2.contact_id = c.id
              AND a2.is_deleted = 0
              AND a2.activity_date_time >= a.activity_date_time
              AND a2.activity_date_time <= co.receive_date
              AND a2.subject != a.subject
              AND a2.id != a.id
        )
      ";

    CRM_Core_DAO::executeQuery( $sql, [], true, null, true );
  }

  /**
   * Check we have an array of integers, return them comma separated string.
   *
   * @param array $contribution_ids Array of integers.
   * @return string
   */
  public function integerArrayToString($contribution_ids) {

    // Get comma separated list of contribution ids, ensuring they're all
    // integers so there can't be any SQL injection.
    $list = implode(',', array_filter(array_map(function($_) { return (int) $_; }, $contribution_ids)));
    if (!$list) {
      throw new \InvalidArgumentException("No contribution Ids to process.");
    }
    return $list;
  }

  /**
   * Export for HMRC Report
   *
   * Generate a table of contributions that you could copy-n-paste into an HMRC spreadsheet.
   *
   * See the not-for-dummies warning on determineEligibility().
   *
   * Get all contributions.
   * Aggregate by donor, using date of latest payment.
   * https://www.gov.uk/guidance/schedule-spreadsheet-to-claim-back-tax-on-gift-aid-donations
   *
   * Output fields
   * - title
   * - first name
   * - last name
   * - first line of address (street_address) or full address if overseas
   * - postcode or X if overseas
   * - aggregated donations (not in use)
   * - sponsored event (not in use)
   * - donation date or latest donation from regular donor.
   * - amount
   *
   * @todo ought to handle those living overseas. This is fairly simple, but it
   * means getting the address formatted correctly, which is a configurable
   * thing, so to do it properly it means using that config. PRs welcome.
   *
   * @param array $contribution_ids Array of integers. Only these contributions will be included.
   * @return array
   */
  public function getHMRCReportData($contribution_ids) {

    if (!$contribution_ids) {
      return [];
    }

    $contributions = civicrm_api3('Contribution', 'get', [
      'sequential' => 1,
      'id' => $contribution_ids,
      'return' => ['total_amount', 'receive_date', 'contact_id'],
      'options' => ['limit' => 100000],
    ]);
    if ($contributions['count'] == 100000) {
      throw new \Exception("Sorry, this sytem can only cope with 100,000 contributions at once.");
    }

    // get unique contacts.
    $contacts = [];
    foreach ($contributions['values'] as $contribution) {
      $contacts[$contribution['contact_id']] = 1;
    }
    $contacts = civicrm_api3('Contact', 'get', [
      'id' => array_keys($contacts),
      'return' => ['formal_title', 'first_name', 'last_name', 'street_address', 'postal_code'],
      'options' => ['limit' => 100000],
    ]);

    $output = [];
    $required = array_fill_keys(['title', 'first_name', 'last_name', 'postal_code', 'street_address'], '');
    foreach ($contributions['values'] as $contribution) {
      $contact_id = $contribution['contact_id'];
      $contact = $contacts['values'][$contact_id];
      if (!isset($output[$contact_id])) {
        // New person.
        // Ensure we have the keys we need.
        $output[$contact_id] = array_intersect_key($contact, $required) + $required;

        // Initialise amount and date.
        $output[$contact_id]['amount'] = 0;
        $output[$contact_id]['date'] = '0000-00-00';
      }

      // Add in the amount.
      $output[$contact_id]['amount'] += $contribution['total_amount'];
      // Set date unless this is an earlier contribution to one already included.
      $_ = substr($contribution['receive_date'], 0, 10);
      if ($_ > $output[$contact_id]['date']) {
        $output[$contact_id]['date'] = $_;
      }
    }

    // Sort by name because it's useful for humans.
    uasort($output, function($a, $b) {
      $_ = strcasecmp($a['last_name'], $b['last_name']);
      if ($_ == 0) {
        $_ = strcasecmp($a['first_name'], $b['first_name']);
      }
      return $_;
    });

    return $output;
  }

}
