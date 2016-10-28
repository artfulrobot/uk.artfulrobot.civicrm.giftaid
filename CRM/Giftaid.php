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
   * @param array $contribution_ids Array of integers. Only these contributions will be affected.
   * @return void
   */
  public function determineEligibility($contribution_ids) {

    // Get comma separated list of contribution ids, ensuring they're all
    // integers so there can't be any SQL injection.
    $list = implode(',', array_filter(array_map(function($_) { return (int) $_; }, $contribution_ids)));
    if (!$list) {
      throw new \InvalidArgumentException("No contribution Ids to process.");
    }

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
            WHERE ac2.contact_id = c.id AND a2.is_deleted = 0 AND a2.activity_date_time > a.activity_date_time AND a2.id != a.id
        )
      ";

    CRM_Core_DAO::executeQuery( $sql, [], true, null, true );
  }

}
