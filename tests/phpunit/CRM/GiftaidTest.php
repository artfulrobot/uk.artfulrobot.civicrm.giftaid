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
        'country_id' => 1226,
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
  }
  /**
   * @dataProvider provider
   */
  public function testRunner($params) {
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

      // Increment date.
      $date = date('Y-m-d', strtotime("$date + 1 day"));
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
  public function provider() {
    return [
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unclaimed' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'declaration'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unclaimed' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'unknown' ],
      ]],


      // weird one.
      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution'],
          ['type' => 'declaration'],
        ],
        'expectations' => [ 'unknown' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'contribution'],
          ['type' => 'declaration'],
        ],
        'expectations' => [ 'unknown' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'contribution', 'status' => 'unclaimed'],
          ['type' => 'contribution', 'status' => 'claimed'],
          ['type' => 'contribution', 'status' => 'ineligible'],
        ],
        'expectations' => [ 'unclaimed', 'claimed', 'ineligible' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'ineligible' ],
      ]],

      [[
        'fixture' => [
          ['type' => 'declaration'],
          ['type' => 'declaration', 'subject' => 'Ineligible'],
          ['type' => 'contribution'],
        ],
        'expectations' => [ 'ineligible' ],
      ]],

    ];
  }
}
