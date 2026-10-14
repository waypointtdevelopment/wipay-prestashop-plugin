<?php
/**
 * Handles the redirect WiPay sends the shopper back to.
 *
 * Nothing here trusts the query string. The transaction must exist in this
 * module's attempt ledger, the order it was created for must still be awaiting
 * WiPay, the pinned total must match the order, and the documented hash
 * (md5(transaction_id + original_total + api_key)) must verify server-side
 * before the order is marked paid.
 *
 * @license GPL-2.0-or-later
 */

if ( ! defined( '_PS_VERSION_' ) ) {
	exit;
}

class WipayWaypointResponseModuleFrontController extends ModuleFrontController
{
	public $ssl = true;

	/** The redirect may arrive without a session when cookies were lost. */
	public $auth = false;

	public function guestAllowed()
	{
		return true;
	}

	public function initContent()
	{
		/* postProcess() always terminates with a redirect or an error page. */
		parent::initContent();
	}

	public function postProcess()
	{
		$module = $this->module;

		if ( ! $module instanceof WipayWaypoint ) {
			$this->fail( $this->l( 'The WiPay module is not available.' ) );
			return;
		}

		$transaction_id = (string) Tools::getValue( 'transaction_id' );
		$status         = Tools::strtolower( (string) Tools::getValue( 'status' ) );
		$hash           = (string) Tools::getValue( 'hash' );
		$order_id_sent  = (string) Tools::getValue( 'order_id' );

		$attempt = $module->findAttempt( $transaction_id );

		if ( ! $attempt ) {
			/*
			 * No attempt was ever started for this transaction id, so there is
			 * nothing to reconcile. Leave the store untouched.
			 */
			$module->logEvent( 'callback_unknown_transaction' );
			$this->fail( $this->l( 'This payment could not be matched to an order. If you completed a payment, contact the store with your transaction reference.' ) );
			return;
		}

		$order = new Order( (int) $attempt['id_order'] );

		if ( ! Validate::isLoadedObject( $order ) || $order->module !== $module->name ) {
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'rejected' );
			$module->logEvent( 'callback_order_missing', array( 'id_attempt' => (int) $attempt['id_attempt'] ) );
			$this->fail( $this->l( 'This payment could not be matched to an order. Contact the store with your transaction reference.' ) );
			return;
		}

		/* Already completed: repeated callbacks must never complete it twice. */
		if ( (int) $order->getCurrentState() === (int) Configuration::get( 'PS_OS_PAYMENT' ) ) {
			$this->redirectToConfirmation( $order );
			return;
		}

		$awaiting_state = (int) Configuration::get( WipayWaypoint::CONFIG_ORDER_STATE );

		if ( 'success' !== $status ) {
			/*
			 * The order stays in the awaiting state so the shopper can retry
			 * and the merchant can see the incomplete attempt.
			 */
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'failed' );
			$module->logEvent( 'payment_not_successful', array(
				'id_attempt' => (int) $attempt['id_attempt'],
				'status'     => $status,
			) );

			$this->fail( $this->l( 'WiPay did not complete this payment. Your order has not been paid - you can try again from checkout.' ) );
			return;
		}

		/* The order WiPay was told about must be the order we are updating. */
		if ( '' !== $order_id_sent && (string) $order->id !== $order_id_sent ) {
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'rejected' );
			$module->logEvent( 'callback_order_id_mismatch', array( 'id_attempt' => (int) $attempt['id_attempt'] ) );
			$this->fail( $this->l( 'This payment could not be matched to an order. Contact the store with your transaction reference.' ) );
			return;
		}

		/* The amount must still be the amount we pinned when checkout started. */
		$order_total = number_format( (float) $order->total_paid, 2, '.', '' );
		$pinned      = (string) $attempt['total'];

		if ( '' === $pinned || $order_total !== $pinned ) {
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'rejected' );
			$module->logEvent( 'callback_total_mismatch', array(
				'id_attempt' => (int) $attempt['id_attempt'],
				'pinned'     => $pinned,
			) );
			$this->fail( $this->l( 'This payment could not be verified against your order. Contact the store with your transaction reference.' ) );
			return;
		}

		$api_key = $module->getSecret( (string) $attempt['environment'] );

		if ( ! $module->verifyHash( $transaction_id, $pinned, $api_key, $hash ) ) {
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'rejected', true );
			$module->logEvent( 'callback_hash_rejected', array( 'id_attempt' => (int) $attempt['id_attempt'] ) );
			$this->fail( $this->l( 'WiPay returned a response that failed verification. Your order has not been paid.' ) );
			return;
		}

		/* The order must still be in the state this module created it in. */
		if ( (int) $order->getCurrentState() !== $awaiting_state ) {
			$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'rejected' );
			$module->logEvent( 'callback_unexpected_state', array(
				'id_attempt' => (int) $attempt['id_attempt'],
				'state'      => (int) $order->getCurrentState(),
			) );
			$this->fail( $this->l( 'This order is no longer awaiting payment. Contact the store with your transaction reference.' ) );
			return;
		}

		$this->completeOrder( $order, $transaction_id, $pinned );
		$module->updateAttemptStatus( (int) $attempt['id_attempt'], 'paid' );
		$module->logEvent( 'payment_verified', array( 'id_order' => (int) $order->id ) );

		$this->redirectToConfirmation( $order );
	}

	/**
	 * Mark the order paid. Every guard has already passed.
	 *
	 * @param Order  $order
	 * @param string $transaction_id
	 * @param string $pinned
	 * @return void
	 */
	private function completeOrder( Order $order, $transaction_id, $pinned )
	{
		$payment_state = (int) Configuration::get( 'PS_OS_PAYMENT' );

		$order->updateState( $payment_state, true );

		$order->payment = $this->module->displayName;
		$order->update();

		if ( method_exists( $order, 'addOrderPayment' ) ) {
			$order->addOrderPayment( (float) $pinned, $this->module->displayName, $transaction_id );
		}
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private function fail( $message )
	{
		$this->context->smarty->assign( array(
			'wipay_error'         => (string) $message,
			'wipay_error_title'   => $this->l( 'Payment result' ),
			'wipay_error_details' => '',
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
