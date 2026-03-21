<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Core\Contracts\Module as ModuleContract;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\SourceLinksRepository;

final class Module implements ModuleContract {
	private static $reverting_status = false;

	public static function run_without_status_guard( callable $callback ) {
		self::$reverting_status = true;

		try {
			return $callback();
		} finally {
			self::$reverting_status = false;
		}
	}

	public function register() {
		add_action( 'admin_post_asdl_fin_sync_orders', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_asdl_fin_manage_order', array( $this, 'handle_manage_order' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'woocommerce_new_order', array( $this, 'sync_order_from_hook' ), 20, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'sync_order_from_hook' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_on_status_change' ), 5, 4 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'guard_financial_status_consistency' ), 20, 4 );
		add_action( 'asdl_fin_payment_allocated', array( $this, 'maybe_complete_linked_order' ), 20, 1 );
	}

	public function handle_manual_sync() {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para sincronizar pedidos.', 'asd-labs-finanzas' ) );
		}

		check_admin_referer( 'asdl_fin_sync_orders' );

		$service = new OrderSyncService();
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;

		if ( $order_id > 0 ) {
			$result = $service->sync_order( $order_id, array( 'trigger' => 'manual_single' ) );

			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( 'error', $result->get_error_message() );
			}

			$this->redirect_with_notice( 'success', 'Pedido sincronizado correctamente.' );
		}

		$result = $service->sync_recent_orders(
			array(
				'limit'  => isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 25,
				'days'   => isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 30,
				'source' => isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'all',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$message = sprintf(
			'Sincronizacion completada. Procesados: %1$d | Nuevos: %2$d | Actualizados: %3$d | Sin cambios: %4$d | Errores: %5$d',
			(int) $result['processed'],
			(int) $result['created'],
			(int) $result['updated'],
			(int) $result['unchanged'],
			(int) $result['errors']
		);

		$this->redirect_with_notice( 'success', $message );
	}

	public function sync_order_from_hook( $order_id ) {
		if ( self::$reverting_status || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$service = new OrderSyncService();
		if ( ! $service->is_financially_managed_order( $order_id ) ) {
			return;
		}

		$service->sync_order( $order_id, array( 'trigger' => 'hook' ) );
	}

	public function sync_order_on_status_change( $order_id ) {
		if ( self::$reverting_status ) {
			return;
		}

		$service = new OrderSyncService();
		if ( ! $service->is_financially_managed_order( $order_id ) ) {
			return;
		}

		$service->sync_order( $order_id, array( 'trigger' => 'status_change' ) );
	}

	public function handle_manage_order() {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar pedidos.', 'asd-labs-finanzas' ) );
		}

		check_admin_referer( 'asdl_fin_manage_order' );

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$service  = new OrderSyncService();
		$result   = $service->sync_order( $order_id, array( 'trigger' => 'manual_manage' ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_return_page(
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$args = array(
			'page'                => 'asdl-fin-documents',
			'document_id'         => (int) $result['document_id'],
			'asdl_fin_notice'     => 'success',
			'asdl_fin_notice_text'=> rawurlencode( 'Pedido enlazado correctamente a Finanzas ASD.' ),
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function guard_financial_status_consistency( $order_id, $old_status, $new_status ) {
		if ( self::$reverting_status ) {
			return;
		}

		$context = ( new OrderSyncService() )->get_linked_document_context( $order_id );
		if ( empty( $context['document'] ) ) {
			return;
		}

		$document          = $context['document'];
		$allocations_count = (int) $context['allocations_count'];
		$balance           = (float) $document['balance'];
		$has_finance_lock  = $allocations_count > 0 || ! empty( $document['manual_override'] ) || ( (float) $document['paid_total'] > 0 && $balance > 0 );
		$new_status        = sanitize_key( $new_status );
		$old_status        = sanitize_key( $old_status );

		if ( 'completed' === $new_status && $balance > 0 && 'income' === (string) $document['financial_intent'] ) {
			$this->revert_order_status( $order_id, $old_status, 'No puedes completar este pedido porque el movimiento financiero aun tiene saldo pendiente.' );
			return;
		}

		if ( $has_finance_lock && in_array( $new_status, array( 'pending', 'on-hold', 'cancelled', 'failed', 'refunded' ), true ) ) {
			$this->revert_order_status( $order_id, $old_status, 'No puedes mover este pedido a un estado anterior o anularlo porque ya tiene gestion financiera aplicada.' );
		}
	}

	public function maybe_complete_linked_order( $result ) {
		if ( empty( $result['document_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$documents    = new DocumentsRepository();
		$source_links = new SourceLinksRepository();
		$document     = $documents->find( (int) $result['document_id'] );

		if ( empty( $document ) || 'income' !== (string) $document['financial_intent'] || (float) $document['balance'] > 0 ) {
			return;
		}

		foreach ( $source_links->find_for_document( (int) $document['id'] ) as $link ) {
			if ( ! in_array( $link['provider'], array( 'woocommerce', 'openpos' ), true ) ) {
				continue;
			}

			$order = wc_get_order( absint( $link['external_id'] ) );
			if ( ! $order ) {
				continue;
			}

			$current_status = sanitize_key( $order->get_status() );
			if ( in_array( $current_status, array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
				continue;
			}

			self::$reverting_status = true;
			$order->update_status( 'completed', 'Pedido completado automaticamente desde Finanzas ASD al quedar totalmente saldado.' );
			self::$reverting_status = false;

			( new OrderSyncService() )->sync_order( $order->get_id(), array( 'trigger' => 'payment_allocation' ) );
		}
	}

	public function render_notice() {
		$notice = get_transient( $this->notice_key() );
		if ( empty( $notice ) ) {
			return;
		}

		delete_transient( $this->notice_key() );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	private function revert_order_status( $order_id, $old_status, $message ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		self::$reverting_status = true;
		$order->update_status( $old_status, $message, true );
		self::$reverting_status = false;

		$this->push_notice( $message, 'error' );
	}

	private function push_notice( $message, $type ) {
		set_transient(
			$this->notice_key(),
			array(
				'message' => sanitize_text_field( $message ),
				'type'    => 'error' === $type ? 'error' : 'success',
			),
			MINUTE_IN_SECONDS
		);
	}

	private function redirect_with_notice( $type, $message ) {
		$this->push_notice( $message, $type );
		wp_safe_redirect( admin_url( 'admin.php?page=asdl-fin-integrations' ) );
		exit;
	}

	private function redirect_to_return_page( array $args ) {
		$page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-integrations';
		$url_args = array_merge(
			array(
				'page' => $page,
			),
			$args
		);

		if ( ! empty( $_POST['contact_id'] ) ) {
			$url_args['contact_id'] = absint( wp_unslash( $_POST['contact_id'] ) );
		}

		if ( ! empty( $_POST['range_from'] ) ) {
			$url_args['range_from'] = sanitize_text_field( wp_unslash( $_POST['range_from'] ) );
		}

		if ( ! empty( $_POST['range_to'] ) ) {
			$url_args['range_to'] = sanitize_text_field( wp_unslash( $_POST['range_to'] ) );
		}

		if ( ! empty( $_POST['order_limit'] ) ) {
			$url_args['order_limit'] = absint( wp_unslash( $_POST['order_limit'] ) );
		}

		wp_safe_redirect( add_query_arg( $url_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function notice_key() {
		return 'asdl_fin_wc_notice_' . get_current_user_id();
	}
}
