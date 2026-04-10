<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Contracts\Module;

final class IntegrityMonitorModule implements Module {
	const LAST_SCAN_OPTION = 'asdl_fin_integrity_last_scan';

	public function register() {
		add_action( 'admin_post_asdl_fin_integrity_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_asdl_fin_integrity_case_state', array( $this, 'handle_case_state' ) );
		add_action( 'admin_post_asdl_fin_integrity_case_rescan', array( $this, 'handle_case_rescan' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'asdl-fin integrity scan', array( $this, 'cli_scan' ) );
			\WP_CLI::add_command( 'asdl-fin integrity list', array( $this, 'cli_list' ) );
		}
	}

	public function handle_scan() {
		check_admin_referer( 'asdl_fin_integrity_scan' );
		$this->assert_capability();

		$service = new IntegrityMonitorService();
		$result  = $service->scan( $this->extract_scan_args( $_POST ) );
		$this->persist_last_scan_snapshot( $result );

		$message = sprintf(
			'Escaneo completado. Casos detectados: %1$d. Nuevos: %2$d. Reabiertos: %3$d.',
			(int) ( $result['detected_count'] ?? 0 ),
			(int) ( $result['created_count'] ?? 0 ),
			(int) ( $result['reopened_count'] ?? 0 )
		);

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' Con observaciones en algunos detectores.';
		}

		$this->redirect_to_integrity_page(
			array(
				'asdl_fin_notice'      => empty( $result['errors'] ) ? 'success' : 'error',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function handle_case_state() {
		$case_id = absint( wp_unslash( $_POST['case_id'] ?? 0 ) );
		check_admin_referer( 'asdl_fin_integrity_case_state_' . $case_id );
		$this->assert_capability();

		$status = sanitize_key( (string) wp_unslash( $_POST['target_status'] ?? '' ) );
		$note   = sanitize_textarea_field( (string) wp_unslash( $_POST['status_note'] ?? '' ) );
		$cases  = new IntegrityCasesRepository();
		$events = new EventsRepository();
		$case   = $cases->find( $case_id );

		if ( empty( $case['id'] ) ) {
			$this->redirect_to_integrity_page(
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'No se encontro el caso de integridad indicado.' ),
				)
			);
		}

		if ( ! $cases->update_status( $case_id, $status, get_current_user_id(), $note ) ) {
			$this->redirect_to_integrity_page(
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'No se pudo actualizar el estado del caso.' ),
				)
			);
		}

		$events->log(
			'integrity_case',
			$case_id,
			'status_changed',
			sprintf( 'Estado del caso cambiado a %s.', sanitize_text_field( IntegrityMonitorService::status_label( $status ) ) ),
			array(
				'previous_status' => sanitize_key( (string) ( $case['status'] ?? '' ) ),
				'next_status'     => $status,
				'note'            => $note,
			)
		);

		$this->redirect_to_integrity_page(
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Estado del caso actualizado correctamente.' ),
			)
		);
	}

	public function handle_case_rescan() {
		$case_id = absint( wp_unslash( $_POST['case_id'] ?? 0 ) );
		check_admin_referer( 'asdl_fin_integrity_case_rescan_' . $case_id );
		$this->assert_capability();

		$service = new IntegrityMonitorService();
		$result  = $service->rescan_case( $case_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_integrity_page(
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect_to_integrity_page(
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( sanitize_text_field( (string) ( $result['message'] ?? 'Reescaneo completado.' ) ) ),
			)
		);
	}

	public function cli_scan( $args, $assoc_args ) {
		$service = new IntegrityMonitorService();
		$result  = $service->scan( $this->extract_scan_args( $assoc_args ) );
		$this->persist_last_scan_snapshot( $result );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( sanitize_text_field( (string) ( $error['message'] ?? 'Error de escaneo.' ) ) );
			}
		}

		\WP_CLI::success(
			sprintf(
				'Escaneo completado. Casos detectados: %1$d. Nuevos: %2$d. Reabiertos: %3$d.',
				(int) ( $result['detected_count'] ?? 0 ),
				(int) ( $result['created_count'] ?? 0 ),
				(int) ( $result['reopened_count'] ?? 0 )
			)
		);
	}

	public function cli_list( $args, $assoc_args ) {
		$cases  = new IntegrityCasesRepository();
		$result = $cases->list_for_admin(
			array(
				'page'             => 1,
				'limit'            => max( 1, min( 500, (int) ( $assoc_args['limit'] ?? 50 ) ) ),
				'search'           => sanitize_text_field( (string) ( $assoc_args['search'] ?? '' ) ),
				'contact_search'   => sanitize_text_field( (string) ( $assoc_args['contact'] ?? '' ) ),
				'case_type'        => sanitize_key( (string) ( $assoc_args['type'] ?? '' ) ),
				'severity'         => sanitize_key( (string) ( $assoc_args['severity'] ?? '' ) ),
				'status'           => sanitize_key( (string) ( $assoc_args['status'] ?? '' ) ),
				'contact_id'       => absint( $assoc_args['contact-id'] ?? 0 ),
				'external_order_id'=> absint( $assoc_args['order'] ?? 0 ),
				'batch_id'         => absint( $assoc_args['batch'] ?? 0 ),
				'range_from'       => sanitize_text_field( (string) ( $assoc_args['range-from'] ?? '' ) ),
				'range_to'         => sanitize_text_field( (string) ( $assoc_args['range-to'] ?? '' ) ),
			)
		);

		$items = array_map(
			static function ( array $case ) {
				return array(
					'id'            => (int) ( $case['id'] ?? 0 ),
					'type'          => IntegrityMonitorService::case_type_label( $case['case_type'] ?? '' ),
					'severity'      => IntegrityMonitorService::severity_label( $case['severity'] ?? '' ),
					'status'        => IntegrityMonitorService::status_label( $case['status'] ?? '' ),
					'contact'       => sanitize_text_field( (string) ( $case['contact_label'] ?? '' ) ),
					'order'         => sanitize_text_field( (string) ( $case['order_number'] ?? '' ) ),
					'batch'         => ! empty( $case['batch_id'] ) ? (int) $case['batch_id'] : '',
					'amount'        => round( (float) ( $case['amount'] ?? 0 ), 2 ),
					'currency'      => sanitize_text_field( (string) ( $case['currency'] ?? '' ) ),
					'detected_at'   => sanitize_text_field( (string) ( $case['last_detected_at'] ?? '' ) ),
				);
			},
			(array) ( $result['items'] ?? array() )
		);

		if ( empty( $items ) ) {
			\WP_CLI::success( 'No se encontraron casos con esos filtros.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			'table',
			$items,
			array( 'id', 'type', 'severity', 'status', 'contact', 'order', 'batch', 'amount', 'currency', 'detected_at' )
		);
	}

	private function extract_scan_args( array $source ) {
		return array(
			'case_type'         => sanitize_key( (string) wp_unslash( $source['case_type'] ?? '' ) ),
			'contact_id'        => absint( wp_unslash( $source['contact_id'] ?? 0 ) ),
			'external_order_id' => absint( wp_unslash( $source['order_id'] ?? $source['external_order_id'] ?? 0 ) ),
			'batch_id'          => absint( wp_unslash( $source['batch_id'] ?? 0 ) ),
			'range_from'        => sanitize_text_field( (string) wp_unslash( $source['range_from'] ?? '' ) ),
			'range_to'          => sanitize_text_field( (string) wp_unslash( $source['range_to'] ?? '' ) ),
		);
	}

	private function persist_last_scan_snapshot( array $result ) {
		update_option(
			self::LAST_SCAN_OPTION,
			array(
				'ran_at'         => current_time( 'mysql' ),
				'detected_count' => (int) ( $result['detected_count'] ?? 0 ),
				'created_count'  => (int) ( $result['created_count'] ?? 0 ),
				'updated_count'  => (int) ( $result['updated_count'] ?? 0 ),
				'reopened_count' => (int) ( $result['reopened_count'] ?? 0 ),
				'scanned_types'  => array_values( array_map( 'sanitize_key', (array) ( $result['scanned_types'] ?? array() ) ) ),
				'filters'        => (array) ( $result['filters'] ?? array() ),
			),
			false
		);
	}

	private function redirect_to_integrity_page( array $args = array() ) {
		$url_args = array_merge(
			array(
				'page' => 'asdl-fin-integrity',
			),
			$this->preserved_query_args(),
			$args
		);

		wp_safe_redirect( add_query_arg( $url_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function preserved_query_args() {
		$preserved = array();
		$keys      = array(
			'fiscal_year',
			'case_id',
			'integrity_search',
			'integrity_contact_search',
			'integrity_case_type',
			'integrity_severity',
			'integrity_status',
			'integrity_contact_id',
			'integrity_order_id',
			'integrity_batch_id',
			'integrity_range_from',
			'integrity_range_to',
			'integrity_page_num',
		);

		foreach ( $keys as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $key ] );
			if ( '' === (string) $value ) {
				continue;
			}

			if ( in_array( $key, array( 'fiscal_year', 'case_id', 'integrity_contact_id', 'integrity_order_id', 'integrity_batch_id', 'integrity_page_num' ), true ) ) {
				$preserved[ $key ] = absint( $value );
				continue;
			}

			$preserved[ $key ] = sanitize_text_field( (string) $value );
		}

		return $preserved;
	}

	private function assert_capability() {
		$capability = class_exists( '\WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';

		if ( current_user_can( $capability ) ) {
			return;
		}

		wp_die( esc_html__( 'No tienes permisos suficientes para gestionar la integridad financiera.', 'asd-labs-finanzas' ) );
	}
}
