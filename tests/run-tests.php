<?php
/**
 * Standalone test harness for the WiPay Waypoint module.
 *
 * PrestaShop itself is not installed here, so the core class is loaded against
 * small stand-ins for the framework APIs it touches. That is enough to prove
 * the parts that actually decide whether money is accepted: the request
 * payload rules, the response hash, the checkout-URL validation, the endpoint
 * map and the availability guards.
 *
 * Run:  php tests/run-tests.php
 * Exit code 0 means every assertion passed.
 */

define( '_PS_VERSION_', '8.1.0' );

/* ------------------------------------------------------------------ */
/* Framework stand-ins                                                 */
/* ------------------------------------------------------------------ */

class PaymentModule
{
	public $version = '1.0.0';
	public $name    = 'wipaywaypoint';
	public $context;
	public $active = true;

	/* Declared so the module constructor does not create dynamic properties. */
	public $id;
	public $tab;
	public $author;
	public $need_instance;
	public $bootstrap;
	public $ps_versions_compliancy;
	public $displayName;
	public $description;
	public $confirmUninstall;
	public $table;
	public $identifier;

	public function __construct()
	{
	}

	public function l( $string, $domain = null )
	{
		return (string) $string;
	}

	public function registerHook( $hook )
	{
		return true;
	}
}

class Configuration
{
	public static $values = array();

	public static function get( $key )
	{
		return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : false;
	}

	public static function set( $key, $value )
	{
		self::$values[ $key ] = $value;
	}
}

class Tools
{
	public static function strtoupper( $value )
	{
		return strtoupper( (string) $value );
	}

	public static function strtolower( $value )
	{
		return strtolower( (string) $value );
	}
}

class Validate
{
	public static function isEmail( $email )
	{
		return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

class Currency
{
	public static $iso = 'TTD';

	public static function getIsoCode( $id_currency )
	{
		return self::$iso;
	}
}

class Cart
{
	const BOTH = 'both';

	public $id          = 42;
	public $id_currency = 1;
	public $total       = 0.0;

	public function getOrderTotal( $with_tax = true, $type = null )
	{
		return $this->total;
	}
}

class Customer
{
	public $id    = 7;
	public $email = 'buyer@example.com';
}

require dirname( __DIR__ ) . '/wipaywaypoint/wipaywaypoint.php';

/* ------------------------------------------------------------------ */
/* Tiny assertion helpers                                              */
/* ------------------------------------------------------------------ */

$GLOBALS['assertions'] = 0;
$GLOBALS['failures']   = 0;
$GLOBALS['suite']      = '';

function suite( $name )
{
	$GLOBALS['suite'] = $name;
	echo "\n== {$name} ==\n";
}

function ok( $condition, $label )
{
	$GLOBALS['assertions']++;
	if ( $condition ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$GLOBALS['failures']++;
	echo "  FAIL  {$label}\n";
}

function same( $expected, $actual, $label )
{
	$GLOBALS['assertions']++;
	if ( $expected === $actual ) {
		echo "  PASS  {$label}\n";
		return;
	}
	$GLOBALS['failures']++;
	echo "  FAIL  {$label}\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * @return WipayWaypoint
 */
function make_module()
{
	$module            = new WipayWaypoint();
	$module->context   = new stdClass();
	$module->context->customer = new Customer();

	return $module;
}

function make_cart( $total, $iso = 'TTD' )
{
	$cart           = new Cart();
	$cart->total    = $total;
	Currency::$iso  = $iso;

	return $cart;
}

/* ------------------------------------------------------------------ */
/* Endpoint map                                                        */
/* ------------------------------------------------------------------ */

suite( 'Endpoint map' );
$module = make_module();

same( 'https://ttsb.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'TT', 'sandbox' ), 'TT sandbox host' );
same( 'https://tt.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'TT', 'live' ), 'TT live host' );
same( 'https://jmsb.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'JM', 'sandbox' ), 'JM sandbox host' );
same( 'https://bbsb.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'BB', 'sandbox' ), 'BB sandbox host' );
same( 'https://gysb.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'GY', 'sandbox' ), 'GY sandbox host' );
same( 'https://gdsb.wipayfinancial.com/plugins/payments/request', $module->getEndpoint( 'GD', 'sandbox' ), 'GD sandbox host' );
same( '', $module->getEndpoint( 'XX', 'sandbox' ), 'unknown country returns empty' );
same( '', $module->getEndpoint( 'TT', 'staging' ), 'unknown environment returns empty' );
same( '', $module->getEndpoint( '', '' ), 'empty inputs return empty' );

/* ------------------------------------------------------------------ */
/* Response hash                                                       */
/* ------------------------------------------------------------------ */

suite( 'Response hash' );
$module = make_module();

$transaction_id = 'SB-12-1-order42-20260310120000';
$expected = md5( $transaction_id . '10.00' . '123' );

same( $expected, $module->computeHash( $transaction_id, '10.00', '123' ), 'hash is md5(txn + original_total + api_key)' );
same( $expected, $module->computeHash( $transaction_id, 10.00, '123' ), 'total is formatted even when given as a float' );
same( md5( $transaction_id . '10.50' . '123' ), $module->computeHash( $transaction_id, '10.5', '123' ), 'a one-decimal total is padded to two decimals' );
same( md5( $transaction_id . '5.00' . '123' ), $module->computeHash( $transaction_id, 5, '123' ), 'an integer total is rendered as N.00' );

ok( $module->verifyHash( $transaction_id, '10.00', '123', $expected ), 'matching hash verifies' );
ok( $module->verifyHash( $transaction_id, '10.00', '123', strtoupper( $expected ) ), 'uppercase hash verifies (normalised)' );
ok( ! $module->verifyHash( $transaction_id, '10.01', '123', $expected ), 'a one-cent amount change breaks the hash' );
ok( ! $module->verifyHash( $transaction_id, '10.00', 'wrong-key', $expected ), 'a wrong API key breaks the hash' );
ok( ! $module->verifyHash( 'OTHER-TXN', '10.00', '123', $expected ), 'a different transaction id breaks the hash' );
ok( ! $module->verifyHash( $transaction_id, '10.00', '123', 'not-a-hash' ), 'a non-hex value is rejected' );
ok( ! $module->verifyHash( $transaction_id, '10.00', '123', 'abcdef' ), 'a short value is rejected' );
ok( ! $module->verifyHash( $transaction_id, '10.00', '123', '' ), 'an empty hash is rejected' );
ok( ! $module->verifyHash( $transaction_id, '10.00', '', $expected ), 'an empty API key is rejected' );
ok( ! $module->verifyHash( '', '10.00', '123', $expected ), 'an empty transaction id is rejected' );
/* Timing-safe comparison must not accept a near-miss of the right length. */
ok( ! $module->verifyHash( $transaction_id, '10.00', '123', str_repeat( 'a', 32 ) ), 'a 32-char wrong hash is rejected' );

/* ------------------------------------------------------------------ */
/* Checkout URL validation                                             */
/* ------------------------------------------------------------------ */

suite( 'Checkout URL validation' );
$module = make_module();

$good = $module->readCheckoutResponse( array(
	'url'            => 'https://tt.wipayfinancial.com/checkout/payment?token=abc',
	'transaction_id' => 'SB-1-1-x-20260310',
) );
same( 'https://tt.wipayfinancial.com/checkout/payment?token=abc', $good['url'], 'valid https URL is accepted' );
same( 'SB-1-1-x-20260310', $good['transaction_id'], 'transaction id is returned' );

$bad_scheme = $module->readCheckoutResponse( array(
	'url'            => 'http://tt.wipayfinancial.com/checkout/payment',
	'transaction_id' => 'SB-1-1-x-20260310',
) );
same( '', $bad_scheme['url'], 'a plain http URL is rejected' );

$bad_host = $module->readCheckoutResponse( array(
	'url'            => 'https://evil.example.com/checkout/payment',
	'transaction_id' => 'SB-1-1-x-20260310',
) );
same( '', $bad_host['url'], 'a non-wipay host is rejected' );

$bad_lookalike = $module->readCheckoutResponse( array(
	'url'            => 'https://wipayfinancial.com.evil.example.com/checkout',
	'transaction_id' => 'SB-1-1-x-20260310',
) );
same( '', $bad_lookalike['url'], 'a host that merely contains the wipay domain is rejected' );

$subdomain = $module->readCheckoutResponse( array(
	'url'            => 'https://checkout.wipayfinancial.com/checkout/payment',
	'transaction_id' => 'SB-1-1-x-20260310',
) );
same( 'https://checkout.wipayfinancial.com/checkout/payment', $subdomain['url'], 'a wipayfinancial.com subdomain is accepted' );

$no_txn = $module->readCheckoutResponse( array(
	'url' => 'https://tt.wipayfinancial.com/checkout/payment',
) );
same( '', $no_txn['url'], 'a response without a transaction id is rejected' );

$no_url = $module->readCheckoutResponse( array( 'transaction_id' => 'SB-1-1-x-20260310' ) );
same( '', $no_url['url'], 'a response without a url is rejected' );

/* ------------------------------------------------------------------ */
/* Request payload                                                     */
/* ------------------------------------------------------------------ */

suite( 'Request payload' );
$module = make_module();

$payload = $module->buildRequestPayload( array(
	'order_id'     => '42',
	'total'        => '10.00',
	'currency'     => 'ttd',
	'email'        => 'buyer@example.com',
	'response_url' => 'https://shop.example.com/module/wipaywaypoint/response',
	'fname'        => 'Matthew',
	'lname'        => 'Chan',
	'addr1'        => '12 Main Street',
	'addr2'        => 'Unit 4',
	'city'         => 'Port of Spain',
	'zipcode'      => '12345',
	'country'      => 'TT',
	'phone'        => '+18681234567',
) );

same( 1234567890, $payload['account_number'], 'sandbox account number is used in sandbox' );
same( 'sandbox', $payload['environment'], 'environment defaults to sandbox' );
same( 'TT', $payload['country_code'], 'platform country code is sent' );
same( 'TTD', $payload['currency'], 'currency is uppercased' );
same( 'credit_card_co', $payload['method'], 'the current documented method is used' );
same( 'WaypointPSC', $payload['origin'], 'origin identifies this integration' );
same( '10.00', $payload['total'], 'total is sent with two decimals' );
same( '42', $payload['order_id'], 'order id is sent as a string' );
same( 'https://shop.example.com/module/wipaywaypoint/response', $payload['response_url'], 'response url is passed through' );
same( 'buyer@example.com', $payload['email'], 'email is sent' );
same( 'Matthew', $payload['fname'], 'first name is sent' );
same( 'Chan', $payload['lname'], 'last name is sent when a first name is present' );
same( '12 Main Street', $payload['addr1'], 'address line 1 is sent' );
same( 'Unit 4', $payload['addr2'], 'address line 2 is sent when address line 1 is present' );
same( 'Port of Spain', $payload['city'], 'city is sent' );
same( '12345', $payload['zipcode'], 'postcode is sent' );
same( 'TT', $payload['country'], 'billing country is sent' );
same( '+18681234567', $payload['phone'], 'an E.164 phone number is sent' );
ok( isset( $payload['version'] ), 'application version is sent' );

/* Dependent-field rules. */

$payload = $module->buildRequestPayload( array(
	'order_id'     => '42',
	'total'        => '10.00',
	'currency'     => 'TTD',
	'email'        => 'buyer@example.com',
	'response_url' => 'https://shop.example.com/module/wipaywaypoint/response',
	'lname'        => 'Chan',
	'addr1'        => '12 Main Street',
) );
ok( ! isset( $payload['lname'] ), 'lname is omitted when fname is absent' );
ok( isset( $payload['addr1'] ), 'addr1 is still sent on its own' );

$payload = $module->buildRequestPayload( array(
	'order_id'     => '42',
	'total'        => '10.00',
	'currency'     => 'TTD',
	'email'        => 'buyer@example.com',
	'response_url' => 'https://shop.example.com/module/wipaywaypoint/response',
	'fname'        => 'Matthew',
	'addr2'        => 'Unit 4',
) );
ok( ! isset( $payload['addr2'] ), 'addr2 is omitted when addr1 is absent' );
ok( isset( $payload['fname'] ), 'fname is still sent on its own' );

/* Length caps. */

$payload = $module->buildRequestPayload( array(
	'order_id'     => '42',
	'total'        => '10.00',
	'currency'     => 'TTD',
	'email'        => str_repeat( 'a', 40 ) . '@example.com',
	'response_url' => 'https://shop.example.com/module/wipaywaypoint/response',
	'fname'        => str_repeat( 'F', 80 ),
	'lname'        => str_repeat( 'L', 80 ),
	'addr1'        => str_repeat( 'A', 120 ),
	'city'         => str_repeat( 'C', 90 ),
	'zipcode'      => str_repeat( 'Z', 40 ),
	'country'      => 'TTT',
) );
same( 30, strlen( $payload['fname'] ), 'fname is capped at 30 characters' );
same( 30, strlen( $payload['lname'] ), 'lname is capped at 30 characters' );
same( 50, strlen( $payload['addr1'] ), 'addr1 is capped at 50 characters' );
same( 30, strlen( $payload['city'] ), 'city is capped at 30 characters' );
same( 10, strlen( $payload['zipcode'] ), 'zipcode is capped at 10 characters' );
same( 2, strlen( $payload['country'] ), 'country is capped at 2 characters' );
same( 50, strlen( $payload['email'] ), 'email is capped at 50 characters' );

/* Phone normalisation. */

$base = array(
	'order_id'     => '42',
	'total'        => '10.00',
	'currency'     => 'TTD',
	'email'        => 'buyer@example.com',
	'response_url' => 'https://shop.example.com/module/wipaywaypoint/response',
);

$payload = $module->buildRequestPayload( array_merge( $base, array( 'phone' => '18681234567' ) ) );
ok( ! isset( $payload['phone'] ), 'a national-format number is dropped rather than sent invalid' );

$payload = $module->buildRequestPayload( array_merge( $base, array( 'phone' => '+1868 123-4567' ) ) );
same( '+18681234567', $payload['phone'] , 'separators are stripped from an E.164 number' );

$payload = $module->buildRequestPayload( array_merge( $base, array( 'phone' => 'not a phone' ) ) );
ok( ! isset( $payload['phone'] ), 'a non-numeric value is dropped' );

$payload = $module->buildRequestPayload( array_merge( $base, array( 'phone' => '' ) ) );
ok( ! isset( $payload['phone'] ), 'an empty phone is omitted entirely' );

/* Minimal payload must not gain empty optional keys. */

$payload = $module->buildRequestPayload( $base );
foreach ( array( 'fname', 'lname', 'addr1', 'addr2', 'city', 'zipcode', 'country', 'phone' ) as $optional ) {
	ok( ! isset( $payload[ $optional ] ), "{$optional} is omitted when empty" );
}

/* ------------------------------------------------------------------ */
/* Availability guards                                                 */
/* ------------------------------------------------------------------ */

suite( 'Availability guards' );
$module = make_module();

Configuration::set( WipayWaypoint::CONFIG_ENVIRONMENT, 'sandbox' );
Configuration::set( WipayWaypoint::CONFIG_COUNTRY, 'TT' );

$cart = make_cart( 10.00, 'TTD' );
same( true, $module->checkAvailability( $cart ), 'a sandbox TT cart above the minimum is allowed' );

$cart = make_cart( 4.99, 'TTD' );
$reason = $module->checkAvailability( $cart );
ok( true !== $reason, 'a sub-minimum TTD cart is blocked' );
ok( false !== strpos( $reason, '5.00' ), 'the block message names the TTD 5.00 minimum' );

$cart = make_cart( 4.99, 'USD' );
same( true, $module->checkAvailability( $cart ), 'the TTD minimum does not apply to other currencies' );

Configuration::set( WipayWaypoint::CONFIG_ENVIRONMENT, 'live' );
Configuration::set( WipayWaypoint::CONFIG_ACCOUNT, '99887766' );

$cart = make_cart( 10.00, 'TTD' );
$reason = $module->checkAvailability( $cart );
ok( true !== $reason, 'live mode without a server-side key is blocked' );
ok( false !== strpos( $reason, 'Live checkout is not configured yet' ), 'the block message explains live is unconfigured' );

/* The secret guard fires first, so define it to reach the account-number guard. */
define( 'WIPAYWAYPOINT_API_KEY', 'live-secret-key' );
Configuration::set( WipayWaypoint::CONFIG_ACCOUNT, '' );
$module2 = make_module();
$reason   = $module2->checkAvailability( make_cart( 10.00, 'TTD' ) );
ok( true !== $reason, 'live mode with an empty account number is blocked' );
ok( false !== strpos( $reason, 'Live checkout is not configured yet' ), 'the account guard reports live as unconfigured' );

Configuration::set( WipayWaypoint::CONFIG_ACCOUNT, '99887766' );
$module3 = make_module();
same( true, $module3->checkAvailability( make_cart( 10.00, 'TTD' ) ), 'live mode works once key and account are both present' );
same( 'live-secret-key', $module3->getSecret( 'live' ), 'the live key is read from the PHP constant' );
same( '99887766', $module3->getAccountNumber( 'live' ), 'the live account number comes from configuration' );
same( '1234567890', $module3->getAccountNumber( 'sandbox' ), 'sandbox never uses the merchant account number' );
same( '123', $module3->getSecret( 'sandbox' ), 'sandbox uses the published test key' );

/* Invalid email. */
$module4 = make_module();
$module4->context->customer->email = 'not-an-email';
$reason = $module4->checkAvailability( make_cart( 10.00, 'TTD' ) );
ok( true !== $reason, 'an invalid customer email blocks checkout' );

/* Unknown platform. */
Configuration::set( WipayWaypoint::CONFIG_COUNTRY, 'ZZ' );
$module5 = make_module();
$reason = $module5->checkAvailability( make_cart( 10.00, 'TTD' ) );
ok( true !== $reason, 'an unknown platform blocks checkout' );
Configuration::set( WipayWaypoint::CONFIG_COUNTRY, 'TT' );

/* ------------------------------------------------------------------ */
/* Request transport shape                                             */
/* ------------------------------------------------------------------ */

suite( 'Request transport' );
$module = make_module();

$result = $module->sendPaymentRequest( 'https://127.0.0.1:1/does-not-exist', array( 'order_id' => '1' ) );
same( 0, (int) $result['code'], 'an unreachable endpoint reports code 0' );
ok( is_array( $result['json'] ), 'an unreachable endpoint returns an empty json body' );

/* ------------------------------------------------------------------ */
/* Report                                                              */
/* ------------------------------------------------------------------ */

echo "\n----------------------------------------\n";
echo "Assertions: {$GLOBALS['assertions']}\n";
echo "Failures:   {$GLOBALS['failures']}\n";

if ( $GLOBALS['failures'] > 0 ) {
	echo "RESULT: FAILED\n";
	exit( 1 );
}

echo "RESULT: PASSED\n";
exit( 0 );
