<?php
/**
 * Starts a WiPay hosted checkout for the current cart.
 *
 * The order is created up front in an "Awaiting WiPay payment" state so the
 * merchant always has a record of a started attempt, and so the pinned total
 * can be verified when WiPay redirects the shopper back.
 *
 * @license GPL-2.0-or-later
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

class WipayWaypointSubmitModuleFrontController extends ModuleFrontController
{
	public $ssl = true;

	/** Guest checkout is allowed: WiPay only needs a valid contact email. */
	public $auth = false;

	public function initContent()
	{
		/* Nothing to render on the happy path; postProcess() always terminates. */
		parent::initContent();
	}

	public function postProcess()
	{
		$module  = $this->module;
		$cart    = $this->context->cart;
		$customer = $this->context->customer;

		if ( ! $module instanceof WipayWaypoint ) {
			$this->fail( $this->l( 'The WiPay module is not available.' ), '' );
			return;
		}

		if ( ! $cart instanceof Cart || ! (int) $cart->id ) {
			$this->fail( $this->l( 'Your cart is empty.' ), '' );
			return;
		}

		$availability = $module->checkAvailability( $cart );
		if ( true !== $availability ) {
			$module->logEvent( 'availability_rejected', array( 'id_cart' => (int) $cart->id ) );
			$this->fail( $availability, '' );
			return;
		}

		$environment = $module->getEnvironment();
		$country     = $module->getCountry();
		$endpoint    = $module->getEndpoint( $country, $environment );
		$api_key     = $module->getSecret( $environment );

		if ( ! $endpoint || ! $api_key || ! $module->getAccountNumber( $environment ) ) {
			$module->logEvent( 'not_configured', array( 'environment' => $environment, 'country' => $country ) );
			$this->fail( $this->l( 'WiPay is not configured correctly. Please contact the store.' ), '' );
			return;
		}

		/*
		 * Reuse an order that already exists for this cart when it is still
		 * awaiting WiPay, so a shopper can retry after an abandoned or
		 * declined attempt instead of being blocked by a consumed cart.
		 */
		$id_order = (int) Order::getIdByCartId( (int) $cart->id );
		$order    = $id_order ? new Order( $id_order ) : null;

		if ( $order instanceof Order && Validate::isLoadedObject( $order ) ) {
			if ( (int) $order->getCurrentState() === (int) Configuration::get( 'PS_OS_PAYMENT' ) ) {
				$this->redirectToConfirmation( $order );
				return;
			}
			if ( $order->module !== $module->name ) {
				$this->fail( $this->l( 'This cart has already been used for another payment method.' ), '' );
				return;
			}
		} else {
			$order = null;
		}

		$total_raw = (float) $cart->getOrderTotal( true, Cart::BOTH );
		$total     = number_format( $total_raw, 2, '.', '' );
		$currency  = Tools::strtoupper( Currency::getIsoCode( (int) $cart->id_currency ) );

		if ( ! $order ) {
			$id_order_state = (int) Configuration::get( WipayWaypoint::CONFIG_ORDER_STATE );
			if ( ! $id_order_state ) {
				$this->fail( $this->l( 'WiPay is not configured correctly. Please contact the store.' ), '' );
				return;
			}

			try {
				$module->validateOrder(
					(int) $cart->id,
					$id_order_state,
					$total_raw,
					$module->displayName,
					'',
					null,
					(int) $this->context->currency->id
				);
			} catch ( PrestaShopException $e ) {
				$module->logEvent( 'order_creation_failed', array( 'id_cart' => (int) $cart->id ) );
				$this->fail( $this->l( 'Your order could not be created. Please try again or contact the store.' ), '' );
				return;
			}

			$id_order = (int) Order::getIdByCartId( (int) $cart->id );
			$order    = $id_order ? new Order( $id_order ) : null;

			if ( ! $order instanceof Order || ! Validate::isLoadedObject( $order ) ) {
				$this->fail( $this->l( 'Your order could not be created. Please try again or contact the store.' ), '' );
				return;
			}
		}

		$response_url = $this->context->link->getModuleLink( $module->name, 'response', array() );

		$values = array(
			'order_id'     => (string) $order->id,
			'total'        => $total,
			'currency'     => $currency,
			'email'        => (string) $customer->email,
			'response_url' => $response_url,
			'fname'        => (string) $customer->firstname,
			'lname'        => (string) $customer->lastname,
			'phone'        => (string) $customer->phone_mobile,
		);

		/* Billing prefill comes from the cart's invoice address when one exists. */
		$id_address = (int) $cart->id_address_invoice;
		if ( $id_address ) {
			$address = new Address( $id_address );
			if ( Validate::isLoadedObject( $address ) ) {
				$values['fname']   = (string) $address->firstname;
				$values['lname']   = (string) $address->lastname;
				$values['addr1']   = (string) $address->address1;
				$values['addr2']   = (string) $address->address2;
				$values['city']    = (string) $address->city;
				$values['zipcode'] = (string) $address->postcode;
				$values['country'] = (string) Country::getIsoCode( (int) $address->id_country );

				$phone = (string) $address->phone_mobile ?: (string) $address->phone;
				if ( '' !== trim( $phone ) ) {
					$values['phone'] = $phone;
				}
			}
		}

		$payload = $module->buildRequestPayload( $values );

		$module->logEvent( 'payment_request_started', array( 'id_order' => (int) $order->id ) );

		$response = $module->sendPaymentRequest( $endpoint, $payload );

		if ( 200 !== (int) $response['code'] ) {
			$module->logEvent( 'payment_request_failed', array(
				'id_order' => (int) $order->id,
				'code'     => (int) $response['code'],
				'error'    => substr( (string) $response['error'], 0, 64 ),
			) );
			$this->fail( $this->l( 'WiPay could not start checkout. Please try again or contact the store.' ), $total );
			return;
		}

		$checkout = $module->readCheckoutResponse( $response['json'] );

		if ( ! $checkout['url'] || ! $checkout['transaction_id'] ) {
			$module->logEvent( 'invalid_checkout_response', array( 'id_order' => (int) $order->id ) );
			$this->fail( $this->l( 'WiPay returned an invalid checkout response. Please contact the store.' ), $total );
			return;
		}

		$module->recordAttempt(
			(int) $order->id,
			(int) $cart->id,
			$checkout['transaction_id'],
			(string) $order->id,
			$total,
			$currency,
			$environment
		);

		$module->logEvent( 'checkout_started', array( 'id_order' => (int) $order->id ) );

		Tools::redirect( $checkout['url'] );
		exit;
	}

	/**
	 * Send the shopper to a standalone error page. The cart and any created
	 * order are left untouched so the attempt can be retried.
	 *
	 * @param string $message
	 * @param string $total Pinned total when known, otherwise empty.
	 * @return void
	 */
	private function fail( $message, $total = '' )
	{
		$this->context->smarty->assign( array(
			'wipay_error'         => (string) $message,
			'wipay_error_title'   => $this->l( 'Payment could not be started' ),
			'wipay_error_details' => '' !== (string) $total
				? sprintf( $this->l( 'Order total: %s' ), (string) $total )
				: '',
			'wipay_back_url'      => $this->context->link->getPageLink( 'order', true ),
			'wipay_back_label'    => $this->l( 'Return to checkout' ),
		) );

		$this->setTemplate( 'module:wipaywaypoint/views/templates/front/error.tpl' );
	}

	private function redirectToConfirmation( Order $order )
	{
		Tools::redirect( $this->context->link->getPageLink( 'order-confirmation', true, null, array(
			'id_cart'   => (int) $order->id_cart,
			'id_module' => (int) $this->module->id,
			'id_order'  => (int) $order->id,
			'key'       => (string) $order->secure_key,
		) ) );
		exit;
	}
}
