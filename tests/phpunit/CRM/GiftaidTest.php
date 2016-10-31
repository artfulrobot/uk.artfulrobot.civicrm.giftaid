<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the Gift Aid extension.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_GiftaidTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var test_contact_id
   */
  public $test_contact_id;

  public $test_contact_details = [
      'contact_type' => 'Individual',
      'first_name' => 'Wilma',
      'middle_name' => 'Test',
      'last_name' => 'Flintstone',
      'email' => 'wilma.flintstone@artfulrobot.uk',
  ];
  public $test_address = [
        'street_address' => '3 Cave St.',
        'city' => 'London',
        'postal_code' => 'N1 1ZZ',
        // 'country_id' => 1226,
        ];
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Creates a test contact, stores id in $this->test_contact_id;
   *
   * @return void
   */
  public function createTestContact()
  {
    $result = civicrm_api3('Contact', 'create', $this->test_contact_details);
    $this->assertGreaterThan(0, $result['id']);
    $this->test_contact_id = $result['id'];
    $params = $this->test_address + ['contact_id' => $this->test_contact_id, 'location_type_id' => 'Home'];
    $result = civicrm_api3('Address', 'create', $params);
    $this->assertGreaterThan(0, $result['id']);
  }
  /**
   * @dataProvider determineEligibilityDataProvider
   */
  public function testDetermineEligibility($params) {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    $date = '2016-01-01';
    $declarations = $contributions = [];
    $short = [];
    foreach ($params['fixture'] as $item) {
      if ($item['type'] == 'declaration') {

        // Apply defaults.
        $item += ['subject' => 'Eligible'];

        // Create declaration
        $result = civicrm_api3('Activity', 'create',[
          'target_id' => $this->test_contact_id,
          'source_contact_id' => $this->test_contact_id,
          'activity_type_id' => $ga->activity_type_declaration,
          'subject' => $item['subject'],
          'activity_date_time' => $date,
        ]);
        $this->assertEquals(0, $result['is_error']);
        $declarations[] = $result['id'];

        // Create short description.
        $short[] = $item['subject'];
      }
      elseif ($item['type'] == 'contribution') {

        // Apply defaults.
        $item += ['status' => 'unknown'];

        // Create contrib.
        $result = civicrm_api3('Contribution', 'create', array(
          'financial_type_id' => "Donation",
          'total_amount' => 10,
          'contact_id' => $this->test_contact_id,
          $ga->api_claim_status => $item['status'],
          'receive_date' => $date,
        ));
        $this->assertEquals(0, $result['is_error']);
        $contributions[] = $result['id'];

        // Create short description.
        $short[] = "contrib:$item[status]";
      }

      // Increment date, unless 'same_day_follows' set.
      if (!isset($item['same_day_follows'])) {
        $date = date('Y-m-d', strtotime("$date + 1 day"));
      }
    }
    $short = implode(", ", $short);

    $ga->determineEligibility($contributions);

    $result = civicrm_api3('Contribution', 'get', [
      'id' => $contributions,
      'return' => $ga->api_claim_status,
    ]);
    foreach ($params['expectations'] as $contribution_i => $expectation) {

      $got = $result['values'][$contributions[$contribution_i]][$ga->api_claim_status];

      $this->assertEquals($expectation, $got,
        "Failed on $short: expected contribution " . ($contribution_i+ 1) . " to be $expectation, but is $got"
      );
    }
  }
  public function determineEligibilityDataProvider() {
    return [
      // No declaration.
      [[
        'fixture' => [
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unknown' ],
      ]],

      // 1 declaration.
      // ... declaration after contrib
      [[
        'fixture' => [
          ['type' => 'contribution'],
          ['type' => 'declaration'],
        ],
        'expectations' => [ 'unknown' ],
      ]],
      // ... declaration before contribs
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unclaimed', 'unclaimed' ],
      ]],
      // ... check non 'unknown' status left alone.
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution', 'status' => 'unclaimed'],
          ['type' => 'contribution', 'status' => 'claimed'],
          ['type' => 'contribution', 'status' => 'ineligible'],
        ],
        'expectations' => [ 'unclaimed', 'claimed', 'ineligible' ],
      ]],
      // ... same but with -ve
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution', 'status' => 'unclaimed'],
          ['type' => 'contribution', 'status' => 'claimed'],
          ['type' => 'contribution', 'status' => 'ineligible'],
        ],
        'expectations' => [ 'unclaimed', 'claimed', 'ineligible' ],
      ]],
      // ... -ve declaration.
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'ineligible' ],
      ]],

      // 2 declarations
      // ... NY££
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'declaration'],
          ['type' => 'contribution'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unclaimed', 'unclaimed' ],
      ]],

      // ... YN£
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'ineligible' ],
      ]],

      // ... Y£Y
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution'],
          ['type' => 'declaration'],
        ],
        'expectations' => [ 'unclaimed' ],
      ]],

      // ... Y£N£
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution'],
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unclaimed', 'ineligible' ],
      ]],


      // ... N£Y£
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
          ['type' => 'declaration'],
          ['type' => 'contribution'],
        ],
        'expectations' => ['ineligible', 'unclaimed' ],
      ]],

      // Check same day.
      [[
        'fixture' => [
          ['type' => 'declaration', 'same_day_follows' => TRUE ],
          ['type' => 'contribution'],
        ],
        'expectations' => ['unclaimed' ],
      ]],


      // Y then £N on same day.
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution', 'same_day_follows' => TRUE ],
          ['type' => 'declaration', 'subject' => 'Ineligible'],
        ],
        'expectations' => ['ineligible' ],
      ]],

      // N then £Y on same day.
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution', 'same_day_follows' => TRUE ],
          ['type' => 'declaration'],
        ],
        'expectations' => ['unclaimed' ],
      ]],

      // Yes and no on same day. Stupid, we should leave it alone?
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible', 'same_day_follows' => TRUE ],
          ['type' => 'declaration', 'same_day_follows' => TRUE ],
          ['type' => 'contribution'],
        ],
        'expectations' => ['unknown'],
      ]],
      // Same, other way around.
      [[
        'fixture' => [
          ['type' => 'declaration', 'same_day_follows' => TRUE ],
          ['type' => 'declaration', 'subject' => 'Ineligible', 'same_day_follows' => TRUE ],
          ['type' => 'contribution'],
        ],
        'expectations' => ['unknown'],
      ]],
      // Two yeses on same day: should be considered eligible.
      [[
        'fixture' => [
          ['type' => 'declaration', 'same_day_follows' => TRUE ],
          ['type' => 'declaration', 'same_day_follows' => TRUE ],
          ['type' => 'contribution'],
        ],
        'expectations' => ['unclaimed'],
      ]],
      // Two Nos on same day: should act like just one.
      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible', 'same_day_follows' => TRUE ],
          ['type' => 'declaration', 'subject' => 'Ineligible', 'same_day_follows' => TRUE ],
          ['type' => 'contribution'],
        ],
        'expectations' => ['ineligible'],
      ]],


    ];
  }
  public function xxtestHMRCReportData() {
    $ga = CRM_Giftaid::singleton();

    // Create 2 contribs on diffrent dates.
    $this->createTestContact();
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-01-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-02-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    // Create third contribution with earlier date, just to check we are getting the latest date (not the latest ID'ed entry in table)
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2015-12-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    // Create 2nd contact.
    $params = [
      'contact_type' => 'Individual',
      'first_name' => 'Betty',
      'middle_name' => 'Test',
      'last_name' => 'Rubble',
      'email' => 'betty.rubble@artfulrobot.uk',
    ];
    $result = civicrm_api3('Contact', 'create', $params);
    $this->assertGreaterThan(0, $result['id']);
    $test_contact_id_2 = $result['id'];

    // Add contrib to contact 2.
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $test_contact_id_2,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-02-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];


    $hmrc = $ga->getHMRCReportData($contributions);
    $this->assertEquals(30, $hmrc[$this->test_contact_id]['amount']);
    $this->assertEquals('2016-02-01', $hmrc[$this->test_contact_id]['date']);
    $this->assertEquals($this->test_contact_details['first_name'], $hmrc[$this->test_contact_id]['first_name']);
    $this->assertEquals($this->test_contact_details['last_name'], $hmrc[$this->test_contact_id]['last_name']);
    $this->assertEquals($this->test_address['street_address'], $hmrc[$this->test_contact_id]['street_address']);
    // Check 2nd person.
    $this->assertEquals(10, $hmrc[$test_contact_id_2]['amount']);
    $this->assertEquals('2016-02-01', $hmrc[$test_contact_id_2]['date']);
    $this->assertEquals($params['first_name'], $hmrc[$test_contact_id_2]['first_name']);
    $this->assertEquals($params['last_name'], $hmrc[$test_contact_id_2]['last_name']);
  }
  public function testSummariseContributions() {
    $ga = CRM_Giftaid::singleton();

    // Create 2 contribs on diffrent dates.
    $this->createTestContact();
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-01-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-02-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    // Create third contribution with earlier date, just to check we are getting the latest date (not the latest ID'ed entry in table)
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2015-12-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    // Create 2nd contact.
    $params = [
      'contact_type' => 'Individual',
      'first_name' => 'Betty',
      'middle_name' => 'Test',
      'last_name' => 'Rubble',
      'email' => 'betty.rubble@artfulrobot.uk',
    ];
    $result = civicrm_api3('Contact', 'create', $params);
    $this->assertGreaterThan(0, $result['id']);
    $test_contact_id_2 = $result['id'];

    // Add minor contrib to contact 2.
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $test_contact_id_2,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-02-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    // Add major contrib to contact 2.
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 1000,
      'contact_id' => $test_contact_id_2,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-03-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contributions[] = $result['id'];

    $data = $ga->summariseContributions($contributions);
    // Check general unclaimed stuff.
    $this->assertEquals(5, $data['unclaimed']['count']);
    $this->assertEquals(1040, $data['unclaimed']['total']);
    $this->assertCount(2, $data['unclaimed']['contacts']);

    // Check unclaimed_ok - all data there, ready to claim.
    $this->assertEquals(30, $data['unclaimed_ok']['total']);
    $this->assertEquals(3, $data['unclaimed_ok']['count']);
    $this->assertCount(1, $data['unclaimed_ok']['contacts']);

    // Check unclaimed_aggregate - misssing data but amounts were <20 so could be aggregated.
    $this->assertEquals(10, $data ['unclaimed_aggregate']['total']);
    $this->assertEquals(1,  $data ['unclaimed_aggregate']['count']);
    $this->assertCount(1,   $data ['unclaimed_aggregate']['contacts']);

    // Check unclaimed_missing_data - missing data, big amounts.
    $this->assertEquals(1000, $data['unclaimed_missing_data']['total']);
    $this->assertEquals(1, $data['unclaimed_missing_data']['count']);
    $this->assertCount(1, $data['unclaimed_missing_data']['contacts']);
  }
  /**
   * Test the generateHMRCRows function.
   */
  public function testGenerateHMRCRows() {
    $ga = CRM_Giftaid::singleton();
    $contributions = [
        // Three contribs from contact 1.
        [ 'id' => 1, 'receive_date' => '2016-02-01 00:00:00', 'amount' => 1, 'contact_id' => 1 ],
        [ 'id' => 2, 'receive_date' => '2016-03-01 00:00:00', 'amount' => 1, 'contact_id' => 1 ],
        [ 'id' => 0, 'receive_date' => '2016-01-01 00:00:00', 'amount' => 1, 'contact_id' => 1 ],
        // Two contribs from contacts 2, 3 without details in Jan.
        [ 'id' => 3, 'receive_date' => '2016-01-02 00:00:00', 'amount' => 1, 'contact_id' => 2 ],
        [ 'id' => 4, 'receive_date' => '2016-01-01 00:00:00', 'amount' => 1, 'contact_id' => 3 ],
        // Another contrib from contact 2 in Feb.
        [ 'id' => 5, 'receive_date' => '2016-02-01 00:00:00', 'amount' => 1, 'contact_id' => 2 ],
        // A massive contrib from contact 2 in Mar - should not be included as it's too big.
        [ 'id' => 6, 'receive_date' => '2016-03-01 00:00:00', 'amount' => 100, 'contact_id' => 2 ],
        // Three contribs from contact 4 (just a 2nd contact, with details.).
        [ 'id' => 7, 'receive_date' => '2016-02-01 00:00:00', 'amount' => 1, 'contact_id' => 4 ],
        [ 'id' => 8, 'receive_date' => '2016-05-01 00:00:00', 'amount' => 1, 'contact_id' => 4 ],
        [ 'id' => 9, 'receive_date' => '2016-01-01 00:00:00', 'amount' => 1, 'contact_id' => 4 ],
      ];
    $contacts = [
        1 => [ 'id' => 1, 'complete' => TRUE, 'formal_title' => '', 'first_name' => 'Wilma', 'last_name' => 'Flintstone', 'street_address' => '3 Cave St', 'postal_code' => 'OX4 2HZ'],
        2 => [ 'id' => 2, 'complete' => FALSE ],
        3 => [ 'id' => 3, 'complete' => FALSE ],
        4 => [ 'id' => 4, 'complete' => TRUE, 'formal_title' => '', 'first_name' => 'Betty', 'last_name' => 'Rubble', 'street_address' => '3 Rock St', 'postal_code' => 'OX4 3HZ'],
      ];
    $lines = $ga->generateHMRCRows($contributions, $contacts, TRUE);
    // Check we have 4 lines.
    $this->assertCount(4, $lines);
    // Check contact 1 (who should be first.)
    $this->assertEquals(3, $lines[0]['amount']);
    $this->assertEquals('Wilma', $lines[0]['first_name']);
    $this->assertEquals('Flintstone', $lines[0]['last_name']);
    $this->assertEquals('3 Cave St', $lines[0]['street_address']);
    $this->assertEquals('OX4 2HZ', $lines[0]['postcode']);
    $this->assertEquals('01/03/2016', $lines[0]['date']);
    // Check contact 2 (should be 2nd).
    $this->assertEquals(3, $lines[1]['amount']);
    $this->assertEquals('Betty', $lines[1]['first_name']);
    $this->assertEquals('Rubble', $lines[1]['last_name']);
    $this->assertEquals('3 Rock St', $lines[1]['street_address']);
    $this->assertEquals('OX4 3HZ', $lines[1]['postcode']);
    $this->assertEquals('01/05/2016', $lines[1]['date']);
    // Check aggregate for Jan.
    $this->assertEquals(2, $lines[2]['amount']);
    $this->assertEquals('', $lines[2]['first_name']);
    $this->assertEquals('', $lines[2]['last_name']);
    $this->assertEquals('', $lines[2]['street_address']);
    $this->assertEquals('', $lines[2]['postcode']);
    $this->assertEquals('Small donations in January 2016', $lines[2]['aggregated_donations']);
    $this->assertEquals('02/01/2016', $lines[2]['date']);
    // Check aggregate for Feb
    $this->assertEquals(1, $lines[3]['amount']);
    $this->assertEquals('', $lines[3]['first_name']);
    $this->assertEquals('', $lines[3]['last_name']);
    $this->assertEquals('', $lines[3]['street_address']);
    $this->assertEquals('', $lines[3]['postcode']);
    $this->assertEquals('Small donations in February 2016', $lines[3]['aggregated_donations']);
    $this->assertEquals('01/02/2016', $lines[3]['date']);

    $lines = $ga->generateHMRCRows($contributions, $contacts, FALSE);
    // Check we have 2 lines.
    $this->assertCount(2, $lines);
    // Check contact 1 (who should be first.)
    $this->assertEquals('Wilma', $lines[0]['first_name']);
    // Check contact 2 (should be 2nd).
    $this->assertEquals('Betty', $lines[1]['first_name']);

  }
  /**
   * Tests that getContributions works as expected.
   */
  public function testGetContributions() {

    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();


    $contribution_ids = [];
    // Create contrib.
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-01-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contribution_ids[] = $result['id'];
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 12,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'claimed',
      'receive_date' => '2016-01-02',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contribution_ids[] = $result['id'];

    // Test it works without a filter
    $c = $ga->getContributions($contribution_ids);
    $this->assertCount(2, $c);
    $this->assertEquals(10, $c[$contribution_ids[0]]['amount']);
    $this->assertEquals($this->test_contact_id, $c[$contribution_ids[0]]['contact_id']);
    $this->assertEquals('2016-01-01 00:00:00', $c[$contribution_ids[0]]['receive_date']);
    $this->assertEquals('unclaimed', $c[$contribution_ids[0]]['ga_status']);
    // check the 2nd contact.
    $this->assertEquals(12, $c[$contribution_ids[1]]['amount']);

    // Test it works with a filter.
    $c = $ga->getContributions($contribution_ids, ['unclaimed']);
    $this->assertCount(1, $c);
    $this->assertEquals(10, $c[$contribution_ids[0]]['amount']);
    $this->assertEquals($this->test_contact_id, $c[$contribution_ids[0]]['contact_id']);
    $this->assertEquals('2016-01-01 00:00:00', $c[$contribution_ids[0]]['receive_date']);
    $this->assertEquals('unclaimed', $c[$contribution_ids[0]]['ga_status']);
  }
  /**
   * Tests that claimContributions works as expected.
   */
  public function testClaimContributions() {

    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    $contribution_ids = [];
    // Create contrib.
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'unclaimed',
      'receive_date' => '2016-01-01',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contribution_ids[] = $result['id'];
    $result = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 12,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => 'claimed',
      $ga->api_claimcode => 'old',
      'receive_date' => '2016-01-02',
    ));
    $this->assertEquals(0, $result['is_error']);
    $contribution_ids[] = $result['id'];

    $contributions = $ga->getContributions($contribution_ids);
    $claim_code = date('Y-m-d:H:i:s');
    $ga->claimContributions($contributions, $claim_code);

    // Now check we have what we expect.
    $result = civicrm_api3('Contribution', 'get', [
      'id' => $contribution_ids,
      'return' => [$ga->api_claim_status, $ga->api_claimcode],
    ]);
    $this->assertEquals('claimed', $result['values'][$contribution_ids[0]][$ga->api_claim_status]);
    $this->assertEquals($claim_code, $result['values'][$contribution_ids[0]][$ga->api_claimcode]);
    $this->assertEquals('claimed', $result['values'][$contribution_ids[1]][$ga->api_claim_status]);
    $this->assertEquals('old', $result['values'][$contribution_ids[1]][$ga->api_claimcode]);
  }
}
