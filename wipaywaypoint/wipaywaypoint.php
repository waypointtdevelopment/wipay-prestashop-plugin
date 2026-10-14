<?php
/**
 * WiPay Waypoint - hosted checkout payment module for PrestaShop.
 *
 * Independent project maintained by Waypoint. It is not affiliated with,
 * sponsored by, or endorsed by WiPay. Card details are entered on WiPay's
 * hosted checkout page; this module never collects card numbers or CVV values.
 *
 * @license GPL-2.0-or-later
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

class WipayWaypoint extends PaymentModule
{
	/* Published sandbox test values - WiPay Payments API > Testing. */
	const SANDBOX_ACCOUNT = '1234567890';
	const SANDBOX_KEY     = '123';

	/* Application identifier sent to WiPay: 1-32 chars, no leading/trailing - or _ */
	const ORIGIN = 'WaypointPSC';

	const CONFIG_ENVIRONMENT = 'WIPAYWAYPOINT_ENVIRONMENT';
	const CONFIG_COUNTRY     = 'WIPAYWAYPOINT_COUNTRY';
	const CONFIG_FEE         = 'WIPAYWAYPOINT_FEE';
	const CONFIG_ACCOUNT     = 'WIPAYWAYPOINT_LIVE_ACCOUNT';
	const CONFIG_DEBUG       = 'WIPAYWAYPOINT_DEBUG';
	const CONFIG_ORDER_STATE = 'WIPAYWAYPOINT_ORDER_STATE';

	const TABLE = 'wipaywaypoint_attempt';

	/** WiPay processing platforms. The key is the WiPay country_code, not the shopper's billing country. */
	private static $platforms = array(
		'TT' => 'Trinidad and Tobago',
		'JM' => 'Jamaica',
		'BB' => 'Barbados',
		'GY' => 'Guyana',
		'GD' => 'Grenada',
	);

	/**
	 * Territory => host map.
	 *
	 * The TT pair is published by WiPay's Platforms and Environments page. The
	 * remaining territories follow the documented host layout and were carried
	 * over from the sandbox-tested WordPress gateway; re-confirm them against
	 * https://docs.wipayfinancial.com/platforms-and-environments before release.
	 */
	private static $hosts = array(
		'TT' => array( 'live' => 'tt.wipayfinancial.com', 'sandbox' => 'ttsb.wipayfinancial.com' ),
		'JM' => array( 'live' => 'jm.wipayfinancial.com', 'sandbox' => 'jmsb.wipayfinancial.com' ),
		'BB' => array( 'live' => 'bb.wipayfinancial.com', 'sandbox' => 'bbsb.wipayfinancial.com' ),
		'GY' => array( 'live' => 'gy.wipayfinancial.com', 'sandbox' => 'gysb.wipayfinancial.com' ),
		'GD' => array( 'live' => 'gd.wipayfinancial.com', 'sandbox' => 'gdsb.wipayfinancial.com' ),
	);

	private static $fee_structures = array(
		'merchant_absorb' => 'Merchant absorbs',
		'customer_pay'    => 'Customer pays',
		'split'           => 'Split',
	);

	public function __construct()
	{
		$this->name                 = 'wipaywaypoint';
		$this->tab                  = 'payments_gateways';
		$this->version              = '1.0.0';
		$this->author               = 'Waypoint';
		$this->need_instance        = 0;
		$this->bootstrap            = true;
		$this->ps_versions_compliancy = array( 'min' => '8.0.0', 'max' => _PS_VERSION_ );

		parent::__construct();

		$this->displayName   = $this->l( 'WiPay Waypoint' );
		$this->description   = $this->l( 'Redirect customers to WiPay hosted card checkout. Independent module, not endorsed by WiPay.' );
		$this->confirmUninstall = $this->l( 'Uninstall the WiPay Waypoint module?' );
	}

	/* ------------------------------------------------------------------ */
	/* Install / uninstall                                                 */
	/* ------------------------------------------------------------------ */

	public function install()
	{
		return parent::install()
			&& $this->registerHook( 'paymentOptions' )
			&& $this->installOrderState()
			&& $this->installAttemptTable();
	}

	public function uninstall()
	{
		/*
		 * The attempt table and the custom order state are deliberately kept:
		 * historical orders reference the order state, and the attempt rows are
		 * the audit trail used to reconcile a payment. Only configuration goes.
		 */
		Configuration::deleteByName( self::CONFIG_ENVIRONMENT );
		Configuration::deleteByName( self::CONFIG_COUNTRY );
		Configuration::deleteByName( self::CONFIG_FEE );
		Configuration::deleteByName( self::CONFIG_ACCOUNT );
		Configuration::deleteByName( self::CONFIG_DEBUG );

		return parent::uninstall();
	}

	private function installOrderState()
	{
		if ( (int) Configuration::get( self::CONFIG_ORDER_STATE ) ) {
			return true;
		}

		$state = new OrderState();
		foreach ( Language::getLanguages( false ) as $language ) {
			$state->name[ (int) $language['id_lang'] ] = 'Awaiting WiPay payment';
		}
		$state->send_email   = false;
		$state->module_name  = $this->name;
		$state->color        = '#1F6FEB';
		$state->logable      = false;
		$state->invoice      = false;
		$state->delivery     = false;
		$state->pdf_invoice  = false;
		$state->pdf_delivery = false;
		$state->deleted      = false;

		if ( ! $state->add() ) {
			return false;
		}

		Configuration::updateValue( self::CONFIG_ORDER_STATE, (int) $state->id );

		return true;
	}

	private function installAttemptTable()
	{
		$table   = _DB_PREFIX_ . self::TABLE;
		$engine  = _MYSQL_ENGINE_;
		$charset = $this->getDatabaseCharset();

		$sql = 'CREATE TABLE IF NOT EXISTS `' . pSQL( $table ) . '` (
			`id_attempt` int(11) NOT NULL AUTO_INCREMENT,
			`id_order` int(11) NOT NULL DEFAULT 0,
			`id_cart` int(11) NOT NULL DEFAULT 0,
			`transaction_id` varchar(64) NOT NULL DEFAULT \'\',
			`order_id_sent` varchar(32) NOT NULL DEFAULT \'\',
			`total` varchar(16) NOT NULL DEFAULT \'\',
			`currency` varchar(8) NOT NULL DEFAULT \'\',
			`environment` varchar(16) NOT NULL DEFAULT \'\',
			`status` varchar(16) NOT NULL DEFAULT \'started\',
			`hash_rejected` tinyint(1) NOT NULL DEFAULT 0,
			`date_add` datetime NOT NULL,
			PRIMARY KEY (`id_attempt`),
			KEY `idx_transaction` (`transaction_id`),
			KEY `idx_order` (`id_order`)
		) ENGINE=' . $engine . ' DEFAULT CHARSET=' . $charset;

		try {
			return (bool) Db::getInstance()->execute( $sql );
		} catch ( PrestaShopException $e ) {
			return false;
		}
	}

	private function getDatabaseCharset()
	{
		$row = Db::getInstance()->getRow( 'SHOW VARIABLES LIKE "character_set_database"' );
		return ( ! empty( $row['Value'] ) ) ? pSQL( $row['Value'] ) : 'utf8mb4';
	}

	/* ------------------------------------------------------------------ */
	/* Admin configuration                                                 */
	/* ------------------------------------------------------------------ */

	public function isConfigurable()
	{
		return true;
	}

	public function getContent()
	{
		$output = '';

		if ( Tools::getValue( 'submitWipayWaypoint' ) ) {
			$output .= $this->saveConfiguration();
		}

		return $output . $this->renderForm();
	}

	private function saveConfiguration()
	{
		$environment = Tools::getValue( self::CONFIG_ENVIRONMENT );
		$environment = in_array( $environment, array( 'sandbox', 'live' ), true ) ? $environment : 'sandbox';

		$country = strtoupper( (string) Tools::getValue( self::CONFIG_COUNTRY ) );
		$country = isset( self::$platforms[ $country ] ) ? $country : 'TT';

		$fee = Tools::getValue( self::CONFIG_FEE );
		$fee = isset( self::$fee_structures[ $fee ] ) ? $fee : 'merchant_absorb';

		$account = preg_replace( '/\D+/', '', (string) Tools::getValue( self::CONFIG_ACCOUNT ) );
		$debug   = (int) Tools::getValue( self::CONFIG_DEBUG );

		Configuration::updateValue( self::CONFIG_ENVIRONMENT, $environment );
		Configuration::updateValue( self::CONFIG_COUNTRY, $country );
		Configuration::updateValue( self::CONFIG_FEE, $fee );
		Configuration::updateValue( self::CONFIG_ACCOUNT, $account );
		Configuration::updateValue( self::CONFIG_DEBUG, $debug );

		$this->logEvent( 'configuration_saved', array( 'environment' => $environment, 'country' => $country ) );

		return $this->displayConfirmation( $this->l( 'Settings updated.' ) );
	}

	private function renderForm()
	{
		$platforms = array();
		foreach ( self::$platforms as $code => $label ) {
			$platforms[] = array( 'id' => $code, 'name' => $label );
		}

		$environments = array(
			array( 'id' => 'sandbox', 'name' => $this->l( 'Sandbox' ) ),
			array( 'id' => 'live', 'name' => $this->l( 'Live' ) ),
		);

		$fees = array();
		foreach ( self::$fee_structures as $code => $label ) {
			$fees[] = array( 'id' => $code, 'name' => $this->l( $label ) );
		}

		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l( 'WiPay hosted checkout settings' ),
					'icon'  => 'icon-cogs',
				),
				'input' => array(
					array(
						'type'    => 'select',
						'label'   => $this->l( 'Environment' ),
						'name'    => self::CONFIG_ENVIRONMENT,
						'options' => array( 'query' => $environments, 'id' => 'id', 'name' => 'name' ),
						'class'   => 'fixed-width-lg',
						'desc'    => $this->l( 'Keep this release in Sandbox until your own live review is complete.' ),
					),
					array(
						'type'    => 'select',
						'label'   => $this->l( 'WiPay platform' ),
						'name'    => self::CONFIG_COUNTRY,
						'options' => array( 'query' => $platforms, 'id' => 'id', 'name' => 'name' ),
						'class'   => 'fixed-width-lg',
						'desc'    => $this->l( 'The platform that processes your merchant account. This is not the shopper billing country.' ),
					),
					array(
						'type'    => 'select',
						'label'   => $this->l( 'Fee structure' ),
						'name'    => self::CONFIG_FEE,
						'options' => array( 'query' => $fees, 'id' => 'id', 'name' => 'name' ),
						'class'   => 'fixed-width-lg',
					),
					array(
						'type'    => 'text',
						'label'   => $this->l( 'Live WiPay account number' ),
						'name'    => self::CONFIG_ACCOUNT,
						'class'   => 'fixed-width-lg',
						'desc'    => $this->l( 'Used only in Live mode. Sandbox uses WiPay\'s published test account.' ),
					),
					array(
						'type'    => 'switch',
						'label'   => $this->l( 'Diagnostics' ),
						'name'    => self::CONFIG_DEBUG,
						'values'  => array(
							array( 'id' => 'on', 'value' => 1, 'label' => $this->l( 'Enabled' ) ),
							array( 'id' => 'off', 'value' => 0, 'label' => $this->l( 'Disabled' ) ),
						),
						'desc'    => $this->l( 'Write redacted diagnostic events. Request bodies, customer details and API keys are never logged.' ),
					),
				),
				'submit' => array(
					'title' => $this->l( 'Save' ),
					'class' => 'btn btn-default pull-right',
				),
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar             = false;
		$helper->table                    = $this->table;
		$helper->module                   = $this;
		$helper->identifier               = $this->identifier;
		$helper->default_form_language    = (int) $this->context->language->id;
		$helper->allow_employee_form_lang = (int) Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0 );
		$helper->submit_action            = 'submitWipayWaypoint';
		$helper->currentIndex             = $this->context->link->getAdminLink( 'AdminModules', false )
			. '&configure=' . $this->name
			. '&tab_module=' . $this->tab
			. '&module_name=' . $this->name;
		$helper->token                   = Tools::getAdminTokenLite( 'AdminModules' );
		$helper->languages               = $this->context->controller->getLanguages();
		$helper->default_language         = (int) Configuration::get( 'PS_LANG_DEFAULT' );
		$helper->tpl_vars                = array(
			'fields_value' => $this->getConfigValues(),
			'id_language'  => $this->context->language->id,
		);

		return $helper->generateForm( array( $fields_form ) );
	}

	private function getConfigValues()
	{
		return array(
			self::CONFIG_ENVIRONMENT => Configuration::get( self::CONFIG_ENVIRONMENT ) ?: 'sandbox',
			self::CONFIG_COUNTRY     => Configuration::get( self::CONFIG_COUNTRY ) ?: 'TT',
			self::CONFIG_FEE         => Configuration::get( self::CONFIG_FEE ) ?: 'merchant_absorb',
			self::CONFIG_ACCOUNT     => (string) Configuration::get( self::CONFIG_ACCOUNT ),
			self::CONFIG_DEBUG       => (int) Configuration::get( self::CONFIG_DEBUG ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                            */
	/* ------------------------------------------------------------------ */

	public function hookPaymentOptions( $params )
	{
		if ( ! $this->active ) {
			return array();
		}

		$cart = isset( $params['cart'] ) ? $params['cart'] : null;
		if ( ! $cart instanceof Cart || ! $cart->id ) {
			return array();
		}

		$availability = $this->checkAvailability( $cart );
		if ( true !== $availability ) {
			return array();
		}

		$option = new PaymentOption();
		$option->setCallToActionText( $this->l( 'Credit card (WiPay)' ) );
		$option->setAdditionalInformation(
			'<p>' . $this->l( 'You will complete payment on WiPay\'s secure hosted checkout. Card details are never entered on this site.' ) . '</p>'
		);
		$option->setAction(
			$this->context->link->getModuleLink( $this->name, 'submit', array() )
		);

		return array( $option );
	}

	/**
	 * Returns true when the cart may be sent to WiPay, otherwise a translated
	 * reason that can be shown to the shopper.
	 *
	 * @param Cart $cart
	 * @return true|string
	 */
	public function checkAvailability( Cart $cart )
	{
		$country     = $this->getCountry();
		$environment = $this->getEnvironment();
		$currency    = Tools::strtoupper( $cart->id_currency ? Currency::getIsoCode( (int) $cart->id_currency ) : '' );
		$total       = $cart->getOrderTotal( true, Cart::BOTH );

		if ( ! $this->getEndpoint( $country, $environment ) ) {
			return $this->l( 'WiPay is not configured for this platform. Please contact the store.' );
		}

		if ( 'sandbox' !== $environment && ! $this->getSecret( $environment ) ) {
			return $this->l( 'Live checkout is not configured yet. Please contact the store.' );
		}

		if ( 'sandbox' !== $environment && ! $this->getAccountNumber( $environment ) ) {
			return $this->l( 'Live checkout is not configured yet. Please contact the store.' );
		}

		$customer = $this->context->customer;
		if ( ! $customer instanceof Customer || ! $customer->id || ! Validate::isEmail( $customer->email ) ) {
			return $this->l( 'A valid account email is required to pay with WiPay.' );
		}

		/* Carried over from the sandbox-tested WordPress gateway: TTD 5.00 floor for the TT platform. */
		if ( 'TT' === $country && 'TTD' === $currency && (float) $total < 5.00 ) {
			return $this->l( 'WiPay requires a minimum payment of TTD 5.00.' );
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* WiPay contract                                                      */
	/* ------------------------------------------------------------------ */

	public function getEnvironment()
	{
		$environment = (string) Configuration::get( self::CONFIG_ENVIRONMENT );
		return in_array( $environment, array( 'sandbox', 'live' ), true ) ? $environment : 'sandbox';
	}

	public function getCountry()
	{
		$country = (string) Configuration::get( self::CONFIG_COUNTRY );

		/* Never configured: fall back to the documented default platform. */
		if ( '' === $country ) {
			return 'TT';
		}

		$country = Tools::strtoupper( $country );

		/*
		 * Configured but unrecognised. Returning a wrong-but-valid platform
		 * would silently send payments to another territory's host, so report
		 * the store as unconfigured instead.
		 */
		return isset( self::$platforms[ $country ] ) ? $country : '';
	}

	public function getFeeStructure()
	{
		$fee = (string) Configuration::get( self::CONFIG_FEE );
		return isset( self::$fee_structures[ $fee ] ) ? $fee : 'merchant_absorb';
	}

	public function getEndpoint( $country, $environment )
	{
		$country     = Tools::strtoupper( (string) $country );
		$environment = (string) $environment;

		if ( ! isset( self::$hosts[ $country ][ $environment ] ) ) {
			return '';
		}

		return 'https://' . self::$hosts[ $country ][ $environment ] . '/plugins/payments/request';
	}

	public function getAccountNumber( $environment )
	{
		if ( 'sandbox' === $environment ) {
			return self::SANDBOX_ACCOUNT;
		}
		return preg_replace( '/\D+/', '', (string) Configuration::get( self::CONFIG_ACCOUNT ) );
	}

	/**
	 * The response-verification key. Live keys are never stored in module
	 * settings: they are read from a PHP constant defined server-side, which
	 * mirrors the wp-config.php approach used by the WordPress gateway.
	 */
	public function getSecret( $environment )
	{
		if ( 'sandbox' === $environment ) {
			return self::SANDBOX_KEY;
		}
		return defined( 'WIPAYWAYPOINT_API_KEY' ) ? (string) WIPAYWAYPOINT_API_KEY : '';
	}

	/**
	 * hash = md5(transaction_id + original_total + api_key)
	 *
	 * The original total is the amount sent in the request, formatted to
	 * exactly two decimals. The customer-facing total returned on redirect may
	 * include fees and must never be used here.
	 *
	 * @param string $transaction_id
	 * @param string $pinned_total
	 * @param string $api_key
	 * @return string Lowercase 32 character hex digest.
	 */
	public function computeHash( $transaction_id, $pinned_total, $api_key )
	{
		return md5( (string) $transaction_id . number_format( (float) $pinned_total, 2, '.', '' ) . (string) $api_key );
	}

	/**
	 * @param string $transaction_id
	 * @param string $pinned_total
	 * @param string $api_key
	 * @param string $provided_hash
	 * @return bool
	 */
	public function verifyHash( $transaction_id, $pinned_total, $api_key, $provided_hash )
	{
		$provided = Tools::strtolower( trim( (string) $provided_hash ) );

		if ( '' === $provided || ! preg_match( '/^[a-f0-9]{32}$/', $provided ) ) {
			return false;
		}

		if ( '' === (string) $api_key || '' === (string) $transaction_id ) {
			return false;
		}

		return hash_equals( $this->computeHash( $transaction_id, $pinned_total, $api_key ), $provided );
	}

	/**
	 * Build the documented payment request body.
	 *
	 * @param array $values order_id, total, currency, email, response_url and
	 *                       optional customer prefill keys.
	 * @return array
	 */
	public function buildRequestPayload( array $values )
	{
		$environment = $this->getEnvironment();

		$payload = array(
			'account_number' => (int) $this->getAccountNumber( $environment ),
			'country_code'   => $this->getCountry(),
			'currency'       => Tools::strtoupper( (string) $values['currency'] ),
			'environment'    => $environment,
			'fee_structure'  => $this->getFeeStructure(),
			'method'         => 'credit_card_co',
			'order_id'       => (string) $values['order_id'],
			'origin'         => self::ORIGIN,
			'response_url'   => (string) $values['response_url'],
			'total'          => number_format( (float) $values['total'], 2, '.', '' ),
			'email'          => $this->truncate( (string) $values['email'], 50 ),
			'version'        => $this->version,
		);

		/*
		 * Dependent fields: lname requires fname, addr2 requires addr1. WiPay
		 * rejects a dependent value sent on its own, so both are omitted
		 * unless their parent is present.
		 */
		$first_name = isset( $values['fname'] ) ? trim( (string) $values['fname'] ) : '';
		$address_1  = isset( $values['addr1'] ) ? trim( (string) $values['addr1'] ) : '';

		$optional = array(
			'fname'   => array( $first_name, 30 ),
			'addr1'   => array( $address_1, 50 ),
			'city'    => array( isset( $values['city'] ) ? trim( (string) $values['city'] ) : '', 30 ),
			'zipcode' => array( isset( $values['zipcode'] ) ? trim( (string) $values['zipcode'] ) : '', 10 ),
			'country' => array( isset( $values['country'] ) ? trim( (string) $values['country'] ) : '', 2 ),
		);

		$optional['lname'] = array( isset( $values['lname'] ) ? trim( (string) $values['lname'] ) : '', 30 );
		$optional['addr2'] = array( isset( $values['addr2'] ) ? trim( (string) $values['addr2'] ) : '', 50 );

		foreach ( $optional as $field => $value ) {
			if ( ( 'lname' === $field && '' === $first_name ) || ( 'addr2' === $field && '' === $address_1 ) ) {
				continue;
			}
			if ( '' !== $value[0] ) {
				$payload[ $field ] = $this->truncate( $value[0], (int) $value[1] );
			}
		}

		if ( isset( $values['phone'] ) ) {
			$phone = preg_replace( '/[^+0-9]/', '', (string) $values['phone'] );
			if ( preg_match( '/^\+[1-9][0-9]{1,14}$/', $phone ) ) {
				$payload['phone'] = $phone;
			}
		}

		return $payload;
	}

	/**
	 * Multibyte-safe truncation that still works when mbstring is unavailable.
	 *
	 * @param string $text
	 * @param int    $length
	 * @return string
	 */
	private function truncate( $text, $length )
	{
		$text = (string) $text;
		$length = (int) $length;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length );
		}

		/* Byte-safe fallback for hosts without the mbstring extension. */
		return substr( $text, 0, $length );
	}

	/**
	 * POST the payload to WiPay and return the parsed response.
	 *
	 * @param string $endpoint
	 * @param array  $payload
	 * @return array{code:int, error:string, body:string, json:array}
	 */
	public function sendPaymentRequest( $endpoint, array $payload )
	{
		$json = json_encode( $payload );

		if ( false === $json ) {
			return array( 'code' => 0, 'error' => 'encode_failed', 'body' => '', 'json' => array() );
		}

		if ( function_exists( 'curl_init' ) ) {
			return $this->postJsonWithCurl( $endpoint, $json );
		}

		return $this->postJsonWithStream( $endpoint, $json );
	}

	private function postJsonWithCurl( $endpoint, $json )
	{
		$ch = curl_init( $endpoint );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $json,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json', 'Content-Type: application/json; charset=utf-8' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 25,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		) );

		$body = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = curl_error( $ch );
		curl_close( $ch );

		if ( false === $body ) {
			return array( 'code' => 0, 'error' => $error, 'body' => '', 'json' => array() );
		}

		$decoded = json_decode( $body, true );

		return array(
			'code'  => $code,
			'error' => '',
			'body'  => (string) $body,
			'json'  => is_array( $decoded ) ? $decoded : array(),
		);
	}

	private function postJsonWithStream( $endpoint, $json )
	{
		$context = stream_context_create( array(
			'http' => array(
				'method'          => 'POST',
				'header'          => "Accept: application/json\r\nContent-Type: application/json; charset=utf-8\r\n",
				'content'         => $json,
				'timeout'         => 25,
				'ignore_errors'   => true,
				'follow_location' => 0,
			),
			'ssl'   => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
			),
		) );

		$body = @file_get_contents( $endpoint, false, $context );

		if ( false === $body ) {
			return array( 'code' => 0, 'error' => 'transport_error', 'body' => '', 'json' => array() );
		}

		$code = 0;
		if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
			foreach ( $http_response_header as $header ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $matches ) ) {
					$code = (int) $matches[1];
				}
			}
		}

		$decoded = json_decode( $body, true );

		return array(
			'code'  => $code,
			'error' => '',
			'body'  => (string) $body,
			'json'  => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Validate the checkout URL WiPay returned before sending a shopper there.
	 *
	 * @param array $response
	 * @return array{url:string, transaction_id:string} Empty values when invalid.
	 */
	public function readCheckoutResponse( array $response )
	{
		$url  = isset( $response['url'] ) ? (string) $response['url'] : '';
		$txn  = isset( $response['transaction_id'] ) ? (string) $response['transaction_id'] : '';
		$host = $url ? parse_url( $url, PHP_URL_HOST ) : '';
		$scheme = $url ? parse_url( $url, PHP_URL_SCHEME ) : '';

		$valid = $url
			&& 'https' === Tools::strtolower( (string) $scheme )
			&& '' !== $txn
			&& '' !== (string) $host
			&& preg_match( '/(^|\.)wipayfinancial\.com$/i', (string) $host );

		return array(
			'url'            => $valid ? $url : '',
			'transaction_id' => $valid ? $txn : '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Attempt ledger                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int    $id_order
	 * @param int    $id_cart
	 * @param string $transaction_id
	 * @param string $order_id_sent
	 * @param string $total
	 * @param string $currency
	 * @param string $environment
	 * @return int Attempt id.
	 */
	public function recordAttempt( $id_order, $id_cart, $transaction_id, $order_id_sent, $total, $currency, $environment )
	{
		Db::getInstance()->insert( self::TABLE, array(
			'id_order'       => (int) $id_order,
			'id_cart'        => (int) $id_cart,
			'transaction_id' => pSQL( $transaction_id, 64 ),
			'order_id_sent'  => pSQL( $order_id_sent, 32 ),
			'total'          => pSQL( $total, 16 ),
			'currency'       => pSQL( $currency, 8 ),
			'environment'    => pSQL( $environment, 16 ),
			'status'         => 'started',
			'date_add'       => date( 'Y-m-d H:i:s' ),
		) );

		return (int) Db::getInstance()->Insert_ID();
	}

	/**
	 * @param string $transaction_id
	 * @return array|null
	 */
	public function findAttempt( $transaction_id )
	{
		if ( '' === (string) $transaction_id ) {
			return null;
		}

		$row = Db::getInstance()->getRow(
			'SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '`
			 WHERE `transaction_id` = "' . pSQL( $transaction_id, 64 ) . '"
			 ORDER BY `id_attempt` DESC'
		);

		return $row ? $row : null;
	}

	/**
	 * @param int    $id_attempt
	 * @param string $status
	 * @param bool   $hash_rejected
	 * @return void
	 */
	public function updateAttemptStatus( $id_attempt, $status, $hash_rejected = false )
	{
		Db::getInstance()->update( self::TABLE, array(
			'status'        => pSQL( $status, 16 ),
			'hash_rejected' => $hash_rejected ? 1 : 0,
		), '`id_attempt` = ' . (int) $id_attempt );
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostics                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Redacted diagnostics. Never pass request bodies, customer details,
	 * API keys or full callback URLs to this method.
	 *
	 * @param string $event
	 * @param array  $context
	 * @return void
	 */
	public function logEvent( $event, array $context = array() )
	{
		if ( 1 !== (int) Configuration::get( self::CONFIG_DEBUG ) ) {
			return;
		}

		$message = '[wipaywaypoint] ' . (string) $event;

		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$message .= ' ' . $key . '=' . (string) $value;
			}
		}

		$id_employee = ( isset( $this->context->employee ) && $this->context->employee && $this->context->employee->id )
			? (int) $this->context->employee->id
			: 0;

		if ( class_exists( 'PrestaShopLogger' ) ) {
			PrestaShopLogger::addLog( $message, 1, null, 'WiPay Waypoint', $id_employee );
		}
	}
}
