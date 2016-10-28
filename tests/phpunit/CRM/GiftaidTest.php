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
   * Test that an 'unknown' contribution made after an Eligible declaration is marked as 'unclaimed'.
   */
  public function testUnknownToUnclaimed() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Eligible',
      'activity_date_time' => '2016-01-01',
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unclaimed', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution is set to eligible if the last declaration was eligible, even if there was an ineligible one before.
   */
  public function testUnknownToUnclaimedIgnoringOlderDeclarations() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Ineligible',
      'activity_date_time' => '2016-01-01', // Before donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create ineligible declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Eligible',
      'activity_date_time' => '2016-01-02', // After donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-03',
    ));

    $this->assertEquals(0, $contribution['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unclaimed', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution with no declaration is not marked unclaimed.
   */
  public function testNoActionWithoutDeclaration() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unknown', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution made before the declaration is left alone.
   */
  public function testNoActionBeforeEligibleDeclaration() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-02',
    ));
    $this->assertEquals(0, $contribution['is_error']);

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Eligible',
      'activity_date_time' => '2016-01-03', // After donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);


    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unknown', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution made before the declaration is left alone.
   */
  public function testNoActionBeforeIneligibleDeclaration() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-02',
    ));
    $this->assertEquals(0, $contribution['is_error']);

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Ineligible',
      'activity_date_time' => '2016-01-03', // After donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);


    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unknown', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that only unknown things are affected.
   */
  public function testNoActionUnlessUnknown() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Eligible',
      'activity_date_time' => '2016-01-03', // After donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create donation.
    $contribution1 = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unclaimed",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution1['is_error']);

    $contribution2 = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "ineligible",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution2['is_error']);

    $contribution3 = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "claimed",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution3['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution1['id'], $contribution2['id'], $contribution3['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution1['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('unclaimed', $contribution[$ga->api_claim_status]);

    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution2['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('ineligible', $contribution[$ga->api_claim_status]);

    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution3['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('claimed', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution made after an Ineligible declaration is marked as 'ineligible'.
   */
  public function testUnknownToIneligible() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Ineligible',
      'activity_date_time' => '2016-01-01',
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-02', // After declaration.
    ));
    $this->assertEquals(0, $contribution['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('ineligible', $contribution[$ga->api_claim_status]);
  }
  /**
   * Test that an 'unknown' contribution is set to ineligible if the last declaration was Ineligible, even if there was an Eligible one before.
   */
  public function testUnknownToIneligibleIgnoringOlderDeclarations() {
    $this->createTestContact();
    $ga = CRM_Giftaid::singleton();

    // Create declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Eligible',
      'activity_date_time' => '2016-01-01', // Before donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create ineligible declaration.
    $declaration = civicrm_api3('Activity', 'create',[
      'target_id' => $this->test_contact_id,
      'source_contact_id' => $this->test_contact_id,
      'activity_type_id' => $ga->activity_type_declaration,
      'subject' => 'Ineligible',
      'activity_date_time' => '2016-01-02', // After donation.
    ]);
    $this->assertEquals(0, $declaration['is_error']);

    // Create donation.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'financial_type_id' => "Donation",
      'total_amount' => 10,
      'contact_id' => $this->test_contact_id,
      $ga->api_claim_status => "unknown",
      'receive_date' => '2016-01-03',
    ));

    $this->assertEquals(0, $contribution['is_error']);

    // Run the thing on this single contribution.
    $ga->determineEligibility([$contribution['id']]);

    // Check it worked.
    $contribution = civicrm_api3('Contribution', 'getsingle', [
      'id' => $contribution['id'],
      'return' => $ga->api_claim_status,
    ]);
    $this->assertEquals('ineligible', $contribution[$ga->api_claim_status]);
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
}
