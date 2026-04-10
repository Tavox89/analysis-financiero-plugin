<?php

namespace ASDLabs\Finance\Integrations\Woo;

use ASDLabs\Finance\Core\Contracts\Module as ModuleContract;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\HistoricalIndexRebuildService;
use ASDLabs\Finance\Finance\InstallmentPlansRepository;
use ASDLabs\Finance\Finance\MoneyStateService;
use ASDLabs\Finance\Finance\ProductMarginCheckService;
use ASDLabs\Finance\Finance\RuntimeRefreshService;
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
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_related_finance_profile_action' ), 20, 1 );
		add_action( 'save_post_product', array( $this, 'bump_product_catalog_version' ), 20, 3 );
		add_action( 'save_post_product_variation', array( $this, 'bump_product_catalog_version' ), 20, 3 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'track_product_inventory_activity' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'track_product_inventory_activity' ), 20, 1 );
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
			$this->refresh_unmanaged_order_runtime( $order_id );
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
		$mode     = isset( $_POST['manage_mode'] ) ? sanitize_key( wp_unslash( $_POST['manage_mode'] ) ) : 'manage';
		$service  = new OrderSyncService();
		$result   = $service->sync_order(
			$order_id,
			array(
				'trigger' => 'resync' === $mode ? 'manual_single' : 'manual_manage',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_return_page(
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		if ( 'resync' === $mode ) {
			if ( ! empty( $result['document_id'] ) ) {
				$this->maybe_complete_linked_order(
					array(
						'document_id' => (int) $result['document_id'],
					)
				);
			}

			$fresh_order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( $fresh_order && method_exists( $service, 'describe_order' ) ) {
				$fresh_snapshot = $service->describe_order( $fresh_order );
				if ( ! empty( $fresh_snapshot['has_order_sync_mismatch'] ) ) {
					$this->redirect_to_return_page(
						array(
							'asdl_fin_notice'      => 'error',
							'asdl_fin_notice_text' => rawurlencode( (string) ( $fresh_snapshot['order_sync_mismatch_message'] ?? 'No se pudo corregir la sincronizacion del pedido.' ) ),
						)
					);
				}
			}

			$this->redirect_to_return_page(
				array(
					'asdl_fin_notice'      => 'success',
					'asdl_fin_notice_text' => rawurlencode( 'Pedido resincronizado correctamente.' ),
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
		$currency          = (string) ( $document['currency'] ?? '' );
		$balance           = MoneyStateService::normalize_balance( (float) ( $document['balance'] ?? 0 ), $currency );
		$has_active_plan   = ! empty( $document['id'] ) && ( new InstallmentPlansRepository() )->has_active_for_document( (int) $document['id'] );
		$has_finance_lock  = $allocations_count > 0 || $has_active_plan || ! empty( $document['manual_override'] ) || ( (float) $document['paid_total'] > 0 && $balance > 0 );
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

		if ( empty( $document ) ) {
			return;
		}

		$currency = (string) ( $document['currency'] ?? '' );
		$balance  = MoneyStateService::normalize_balance( (float) ( $document['balance'] ?? 0 ), $currency );

		$financial_status = sanitize_key( (string) ( $document['financial_status'] ?? '' ) );

		if ( 'void' === $financial_status || ! MoneyStateService::balance_is_zero( $balance, $currency ) ) {
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

	public function render_related_finance_profile_action( $order ) {
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$contact = $this->resolve_related_contact_for_order( $order );
		if ( empty( $contact['id'] ) ) {
			return;
		}

		$contact_id   = (int) $contact['id'];
		$display_name = sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) );
		$profile_url  = add_query_arg(
			array(
				'page'       => 'asdl-fin-contacts',
				'contact_id' => $contact_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="form-field form-field-wide asdl-fin-order-related-profile">
			<label><?php echo esc_html( 'Perfil relacionado en Finanzas ASD' ); ?></label>
			<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
				<a class="button button-secondary" href="<?php echo esc_url( $profile_url ); ?>">
					<?php echo esc_html( 'Ir al perfil relacionado' ); ?>
				</a>
				<?php if ( '' !== $display_name ) : ?>
					<span style="color:#50575e;"><?php echo esc_html( $display_name . ' #' . $contact_id ); ?></span>
				<?php else : ?>
					<span style="color:#50575e;"><?php echo esc_html( 'Perfil #' . $contact_id ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
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

		$result = ( new OrderSyncService() )->sync_order(
			$order_id,
			array(
				'trigger' => 'status_guard_revert',
			)
		);

		if ( is_wp_error( $result ) ) {
			$message .= ' La sincronizacion financiera quedo pendiente: ' . $result->get_error_message();
		}

		$this->push_notice( $message, 'error' );
	}

	private function resolve_related_contact_for_order( $order ) {
		$contacts = new ContactsRepository();
		$service  = new OrderSyncService();
		$context  = $service->get_linked_document_context( (int) $order->get_id() );

		if ( ! empty( $context['document']['contact_id'] ) ) {
			$contact = $contacts->find( (int) $context['document']['contact_id'] );
			if ( ! empty( $contact['id'] ) ) {
				return $contact;
			}
		}

		$wp_user_id = absint( $order->get_customer_id() );
		if ( $wp_user_id > 0 ) {
			$contact = $contacts->find_by_wp_user_id( $wp_user_id );
			if ( ! empty( $contact['id'] ) ) {
				return $contact;
			}
		}

		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( '' !== $email ) {
			$contact = $contacts->find_by_email( $email );
			if ( ! empty( $contact['id'] ) ) {
				return $contact;
			}
		}

		return null;
	}

	private function invalidate_order_runtime_for_profile( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		OrderSyncService::invalidate_cached_views();
		RuntimeRefreshService::invalidate(
			array(
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
			)
		);

		$contact = $this->resolve_related_contact_for_order( $order );
		if ( ! empty( $contact['id'] ) ) {
			RuntimeRefreshService::invalidate(
				array( RuntimeRefreshService::SCOPE_CONTACT ),
				array( 'contact_id' => (int) $contact['id'] )
			);
		}
	}

	private function refresh_unmanaged_order_runtime( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id <= 0 ) {
			return;
		}

		// Los pedidos no gestionados del ejercicio anterior viven en el indice
		// historico; cuando cambian de estado en Woo/OpenPOS hay que refrescar
		// esa fila puntual antes de invalidar los snapshots operativos.
		( new HistoricalIndexRebuildService() )->refresh_order_index( $order_id );
		$this->invalidate_order_runtime_for_profile( $order_id );
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

	public function bump_product_catalog_version( $post_id, $post = null, $update = false ) {
		unset( $post, $update );

		$post_id = absint( $post_id );
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) ) {
			return;
		}

		ProductMarginCheckService::track_positive_inventory_timestamp( $post_id );
		ProductMarginCheckService::bump_catalog_version();
	}

	public function track_product_inventory_activity( $product ) {
		ProductMarginCheckService::track_positive_inventory_timestamp( $product );
		ProductMarginCheckService::bump_catalog_version();
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
