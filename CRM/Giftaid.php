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
   * Array of valid statuses.
   */
  public static $eligibility_statuses = [ 'unknown', 'unclaimed', 'claimed', 'ineligible' ];
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

  // Main tasks.
  /**
   * Summarise and check the contributions.
   *
   * The output looks like this:
   *     [
   *        'unclaimed' => [
   *          'unique_contact' => [1, 2, 3 ... contact ids],
   *          'count' => 12, // Count of contributions.
   *          'total' => 123.35,
   *        ],
   *        'unclaimed_ok'           => ... same as above ...
   *        'unclaimed_aggregate'    => ... same as above ...
   *        'unclaimed_missing_data' => ... same as above ...
   *        'unknown'                => ... same as above ...
   *        'claimed'                => ... same as above ...
   *        'ineligible'             => ... same as above ...
   *        'no_data'                => ... same as above ... (contributions created before extension installed)
   *        'earliest' => Y-m-d date of earliest contribution
   *        'latest'   => Y-m-d date of latest contribution
   *     ]
   *
   * @param array $contribution_ids Array of integers. Only these contributions will be affected.
   * @return void
   */
  public function summariseContributions($contribution_ids) {

    $contributions = array_fill_keys([
        'unclaimed', 'unclaimed_ok', 'unclaimed_missing_data', 'unclaimed_aggregate',
        'claimed', 'ineligible', 'unknown', 'no_data',
      ],
      [
        'contacts' => [],
        'count' => 0,
        'total' => 0,
      ]
    );
    $contributions['latest'] = $contributions['earliest'] = NULL;

    // Fetch the statuses for each.
    foreach ($this->getContributions($contribution_ids) as $contribution) { //{{{
      $ga_status = $contribution['ga_status'];
      $contact_id = $contribution['contact_id'];

      if ('unclaimed' == $ga_status) {

        if (!isset($contributions[$ga_status]['contacts'][$contact_id])) {
          // We've not seen this contact before.
          $contributions[$ga_status]['contacts'][$contact_id] = [
            'minor' => ['count' => 0, 'total' => 0],
            'major' => ['count' => 0, 'total' => 0],
          ];
        }
        $majorminor = ($contribution['amount'] > 20) ? 'major' : 'minor';
          $contributions[$ga_status]['contacts'][$contact_id][$majorminor]['count']++;
          $contributions[$ga_status]['contacts'][$contact_id][$majorminor]['total'] += $contribution['amount'];
      }
      else {
        $contributions[$ga_status]['contacts'][$contact_id] = TRUE;
      }

      // Create grand totals for all types.
      $contributions[$ga_status]['count']++;
      $contributions[$ga_status]['total'] += $contribution['amount'];

      // Keep track of min/max range.
      $date = substr($contribution['receive_date'], 0, 10); // 2016-01-01 == 10 characters.
      if (!isset($contributions['earliest']) || $contributions['earliest']<$date) {
        $contributions['earliest'] = $date;
      }
      if (!isset($contributions['latest']) || $contributions['latest']<$date) {
        $contributions['latest'] = $date;
      }
    } // }}}

    if ($contributions['unclaimed']['count']>0) {
      $contact_data = $this->getContactData(array_keys($contributions['unclaimed']['contacts']));

      foreach ($contributions['unclaimed']['contacts'] as $contact_id => $contact_contribs) {

        // Do we have details for this person?
        if ($contact_data[$contact_id]['complete']) {
          // Fine include major and minor.
          $contributions['unclaimed_ok']['contacts'][$contact_id] = TRUE;
          $contributions['unclaimed_ok']['count'] += $contact_contribs['major']['count'] + $contact_contribs['minor']['count'];
          $contributions['unclaimed_ok']['total'] += $contact_contribs['major']['total'] + $contact_contribs['minor']['total'];
        }
        else {
          // Some data is missing.
          if ($contact_contribs['major']['count']>0) {
            $contributions['unclaimed_missing_data']['contacts'][$contact_id] = TRUE;
            $contributions['unclaimed_missing_data']['count'] += $contact_contribs['major']['count'];
            $contributions['unclaimed_missing_data']['total'] += $contact_contribs['major']['total'];
          }
          if ($contact_contribs['minor']['count']>0) {
            $contributions['unclaimed_aggregate']['contacts'][$contact_id] = TRUE;
            $contributions['unclaimed_aggregate']['count'] += $contact_contribs['minor']['count'];
            $contributions['unclaimed_aggregate']['total'] += $contact_contribs['minor']['total'];
          }
        }
      }
    }
    return $contributions;
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
        INNER JOIN civicrm_activity a ON ac.activity_id = a.id
          AND a.activity_type_id = $this->activity_type_declaration
          AND a.subject IN('Eligible','Ineligible') AND a.is_deleted = 0

      SET el.$this->col_claim_status = IF(a.subject = 'Eligible',  'unclaimed', 'ineligible')

      WHERE co.id IN ($list) AND el.$this->col_claim_status = 'unknown'
        AND co.receive_date >= a.activity_date_time
        AND NOT EXISTS (
            SELECT ac2.id FROM civicrm_activity_contact ac2
              INNER JOIN civicrm_activity a2 ON a2.id = ac2.activity_id
                AND ac2.record_type_id = $this->activity_target_type
                AND a2.activity_type_id = $this->activity_type_declaration
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
   * Create claim code and provide CSV HMRC Report
   *
   * Generate a table of contributions that you could copy-n-paste into an HMRC spreadsheet.
   *
   * See the not-for-dummies warning on determineEligibility().
   *
   * Get all contributions.
   * Aggregate by donor, using date of latest payment.
   * https://www.gov.uk/guidance/schedule-spreadsheet-to-claim-back-tax-on-gift-aid-donations
   *
   * @todo ought to handle those living overseas. This is fairly simple, but it
   * means getting the address formatted correctly, which is a configurable
   * thing, so to do it properly it means using that config. PRs welcome.
   *
   * @param array $contribution_ids Array of integers. Only these contributions will be included.
   * @param array $ga_status_allowed Array of eligibility statuses to filter the contributions by.
   * @param bool $include_aggregates Whether aggregates are allowed.
   * @return array
   */
  public function makeClaim($contribution_ids, $ga_status_allowed, $include_aggregates) {

    if (!$contribution_ids) {
      CRM_Core_Session::setStatus("No contributions selected.");
      return;
    }

    // Collect data.
    $contributions = $this->getContributions($contribution_ids, $ga_status_allowed);
    $contact_ids = array_unique(array_map(function ($_) { return $_['contact_id']; }, $contributions));
    $contact_data = $this->getContactData($contact_ids);

    // Split into items and aggregates.
    $lines = $this->generateHMRCRows($contributions, $contact_data, $include_aggregates);

    // OK, we're ready to output the data. Now's a good time to update the claim.
    // Use timestamp as a claim code.
    $claim_code = date('Y-m-d:H:i:s');
    $this->claimContributions($contributions, $claim_code);

    // Output CSV.
    // Make a safer looking filename.
    $filename = str_replace(':', '-', $claim_code) . '.csv';
    header('Content-Description: File Transfer');
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment;\n    filename=$filename");
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Apparently this help Excep understand that it's UTF-8.
    fputs($out, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));

    foreach ($lines as $_) {
      fputcsv($out, $_);
    }
    fclose($out);
    exit; // @todo nicer.
  }

  // Internals.
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
   * Get Contact data
   * @param array of contact ids.
   * @return array of contact data including 'complete' field which is a
   * boolean saying that there's enough data to do a claim for this person.
   */
  public function getContactData($contact_ids) {

    $contacts = civicrm_api3('Contact', 'get', [
      'id'      => $contact_ids,
      'return'  => ['formal_title', 'first_name', 'last_name', 'street_address', 'postal_code'],
      'options' => ['limit' => 100000],
    ]);

    foreach ($contacts['values'] as &$contact) {
      $contact['complete'] = !empty($contact['first_name'])
        && !empty($contact['last_name'])
        && !empty($contact['street_address'])
        && !empty($contact['postal_code']);
    }

    return $contacts['values'];
  }

  /**
   * Fetch contributions including gift aid eligibility status.
   *
   * @param array $contribution_ids Array of integers. Only these contributions will be affected.
   * @return array of arrays with keys:
   * - contact_id
   * - id
   * - ga_status
   * - receive_date
   * - amount
   * @param Array $contribution_ids
   * @param NULL|Array statuses allowed.
   */
  public function getContributions($contribution_ids, $ga_status_allowed=NULL) {

    $ga = CRM_Giftaid::singleton();
    $contributions_raw = civicrm_api3('Contribution', 'get', [
      'return' => [$ga->api_claim_status, "receive_date", "contact_id", 'total_amount'],
      'id' => $contribution_ids,
      'options' => ['limit' => 100000],
    ]);

    $contributions = [];

    foreach ($contributions_raw['values'] as $id=>$_) {
      $row = [
        'ga_status' => $_[$ga->api_claim_status] ? $_[$ga->api_claim_status] : 'no_data',
        'id' => $id,
        'receive_date' => $_['receive_date'],
        'contact_id' => $_['contact_id'],
        'amount' => $_['total_amount'],
      ];
      if ($ga_status_allowed && !in_array($row['ga_status'], $ga_status_allowed)) {
        continue;
      }
      $contributions[$id] = $row;
    }

    return $contributions;
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
  public function xxgetHMRCReportData($contribution_ids, $status=NULL) {

    if (!$contribution_ids) {
      return [];
    }
    $params = [
      'sequential' => 1,
      'id' => $contribution_ids,
      'return' => ['total_amount', 'receive_date', 'contact_id'],
      'options' => ['limit' => 100000],
    ];
    if ($status !== NULL) {
      // @todo strip this out.
      if (!in_array($status, CRM_Giftaid::$eligibility_statuses)) {
        throw new \InvalidArgumentException("Invalid gift aid eligibility status '$status'");
      }
    }
    $contributions = civicrm_api3('Contribution', 'get', $params);
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
    $required = array_fill_keys(['id', 'title', 'first_name', 'last_name', 'postal_code', 'street_address'], '');
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

  /**
   * Update unclaimed to claimed.
   * @param string string claim_code to use.
   */
  public function claimContributions($contributions, $claim_code) {

    // Filter for just 'unclaimed' ones.
    $to_update = [];
    foreach ($contributions as $contribution) {
      if ($contribution['ga_status'] == 'unclaimed') {
        $to_update []= (int) $contribution['id'];
      }
    }
    if (!$to_update) {
      return;
    }

    // Update. Nb. the SQL also checks to only update unclaimed codes, just in case.
    $sql = "UPDATE $this->table_eligibility
      SET $this->col_claim_status = 'claimed',
          $this->col_claim_code = '$claim_code'
      WHERE id IN (" . implode(',', $to_update) . ")
        AND $this->col_claim_status = 'unclaimed';";
    CRM_Core_DAO::executeQuery( $sql, [], true, null, true );

  }

  /**
   * Create rows for HMRC's Gift Aid spreadsheet.
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
   */
  public function generateHMRCRows($contributions, $contact_data, $include_aggregates) {
    $lines = [];
    $aggregates = [];

    foreach ($contributions as $contribution) {
      $date = substr($contribution['receive_date'], 0, 10); // Y-m-d
      $contact = empty($contact_data[$contribution['contact_id']]) ? [] : $contact_data[$contribution['contact_id']];
      if (empty($contact['complete'])) {
        // Can only include this as an aggregate as we don't have name/address.
        if (!$include_aggregates) {
          // Aggregates disallowed by user.
          continue;
        }
        if ($contribution['amount']>20) {
          // Only payments <=20 can be included in aggregates.
          continue;
        }
        // OK, this needs aggregating.
        // Do aggregates by month to limit chance of going over £1,000 in a month.
        $index = 'zz' . substr($contribution['receive_date'], 0, 7);
        if (!isset($lines[$index])) {
          $lines[$index] = [
            'title' => '',
            'first_name' => '',
            'last_name' => '',
            'street_address' => '',
            'postcode' => '',
            'aggregated_donations' => "Small donations in " . date('F Y', strtotime($date)),
            'sponsored' => '',
            'date' => $date,
            'amount' => 0,
          ];
        }
      }
      else {
        // We have data for this contact. Aggregate by contact Id.
        $index = str_pad($contact['last_name'], 20) . "$contact[first_name]$contact[id]";
        if (!isset($lines[$index])) {
          $lines[$index] = [
            'title' => $contact['formal_title'],
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'street_address' => $contact['street_address'],
            'postcode' => $contact['postal_code'],
            'aggregated_donations' => '',
            'sponsored' => '',
            'date' => $date,
            'amount' => 0,
          ];
        }
      }

      // Add in the amount.
      $lines[$index]['amount'] += $contribution['amount'];

      // Ensure we use the last date.
      if ($date > $lines[$index]['date']) {
        $lines[$index]['date'] = $date;
      }
    }

    // Change date format.
    array_walk($lines, function (&$_) {
      $_['date'] = date('d/m/Y', strtotime($_['date']));
    });
    // Sort by key.
    ksort($lines);
    return array_values($lines);
  }
  // Unused code. remove it.
  /**
   * Group array by gift aid status.
   * @param array of arrays that contain a 'ga_status' field.
   * @return array
   */
  public function groupByStatus($contributions) {

    $output = [];

    foreach ($contributions as $contribution) {
      if (!isset($output[$contribution['ga_status']])) {
        // Initialise group.
        $output[$contribution['ga_status']] = [];
      }
      $output[$contribution['ga_status']][$contribution['contribution_id']] = $contribution;
    }

    return $output;
  }

}
