<?php

namespace ASDLabs\Finance\Finance;

final class MasterReportBatchService extends BaseRepository {
	const JOB_TTL = 21600;
	const JOB_PREFIX = 'asdl_fin_master_report_job_';

	public function start( array $args = array() ) {
		$report_service = new FinancialMasterReportService();
		$context        = $report_service->resolve_context( $args );
		$job            = array(
			'job_id'         => wp_generate_uuid4(),
			'status'         => 'running',
			'context'        => $context,
			'current_stage'  => 'preflight',
			'parts'          => array(),
			'payload'        => array(),
			'versions'       => array(),
			'message'        => 'Preparando el runner del reporte maestro.',
			'progress_percent' => 0,
			'created_at'     => $this->now(),
			'updated_at'     => $this->now(),
			'margin_job_id'  => '',
			'error_message'  => '',
		);

		$this->save_job( $job );

		return $this->decorate_job( $job );
	}

	public function continue_job( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			return $this->error( 'asdl_fin_master_report_job_missing', 'No se encontro el runner del reporte solicitado.' );
		}

		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'completed', 'error' ), true ) ) {
			return $this->decorate_job( $job );
		}

		$context = (array) ( $job['context'] ?? array() );
		$service = new FinancialMasterReportService();
		$margin_service = new ProductMarginCheckService();

		try {
			switch ( (string) ( $job['current_stage'] ?? 'preflight' ) ) {
				case 'preflight':
					$scope = $margin_service->report_scope_from_filters( (array) ( $context['sales_filters'] ?? array() ) );
					$preflight = $margin_service->get_report_view_result_for_scope( $scope );

					if ( ! empty( $preflight['cache_valid'] ) ) {
						$job['parts']['preflight'] = $preflight;
						if ( 'daily' === (string) ( $preflight['report_view_source'] ?? '' ) ) {
							$job['message'] = 'Se reutilizo la revision del dia ya lista para este mismo alcance.';
						} elseif ( ! empty( $preflight['certified_today'] ) ) {
							$job['message'] = 'Se reutilizo la vista activa de Productos y precios, que tambien ya estaba validada hoy.';
						} else {
							$job['message'] = 'Se reutilizo la vista activa de Productos y precios para este mismo alcance, sin volver a correr toda la revision.';
						}
						$job['current_stage'] = 'sales';
						break;
					}

					if ( empty( $job['margin_job_id'] ) ) {
						$margin_job = $margin_service->start_job(
							array(
								'scope_kind'             => 'report',
								'exclude_categories_raw' => (string) ( $scope['exclude_categories_raw'] ?? '' ),
								'require_today'          => true,
							)
						);
						$job['margin_job_id'] = (string) ( $margin_job['job_id'] ?? '' );
					}

					$margin_job = $margin_service->continue_job( $job['margin_job_id'] );
					if ( is_wp_error( $margin_job ) ) {
						throw new \RuntimeException( $margin_job->get_error_message() );
					}

					$job['message'] = sanitize_text_field( (string) ( $margin_job['message'] ?? 'Verificando productos.' ) );
					if ( 'completed' === (string) ( $margin_job['status'] ?? '' ) ) {
						$job['parts']['preflight'] = (array) ( $margin_job['result'] ?? $margin_service->get_result( $job['margin_job_id'] ) );
						$job['current_stage'] = 'sales';
					}
					break;

				case 'sales':
					$job['parts']['sales'] = $service->build_sales_payload( $context );
					$job['message'] = 'Bloque comercial calculado.';
					$job['current_stage'] = 'receivables';
					break;

				case 'receivables':
					$job['parts']['receivables'] = ( new PendingCollectionsService() )->get_snapshot(
						array(
							'limit'      => 60,
							'range_from' => $context['range_from'],
							'range_to'   => $context['range_to'],
						)
					);
					$job['message'] = 'Cuentas por cobrar calculadas.';
					$job['current_stage'] = 'payables';
					break;

				case 'payables':
					$job['parts']['payables'] = ( new PendingPayablesService() )->get_snapshot(
						array(
							'limit'      => 60,
							'range_from' => $context['range_from'],
							'range_to'   => $context['range_to'],
						)
					);
					$job['message'] = 'Cuentas por pagar calculadas.';
					$job['current_stage'] = 'overview';
					break;

				case 'overview':
					$overview = ( new OverviewService() )->get_dashboard_snapshot(
						array(
							'range_from'     => $context['range_from'],
							'range_to'       => $context['range_to'],
							'include_recent' => false,
						)
					);
					$job['parts']['overview'] = array(
						'open_documents' => (int) ( $overview['open_document_count'] ?? 0 ),
						'payment_count'  => (int) ( $overview['payment_count'] ?? 0 ),
						'document_count' => (int) ( $overview['document_count'] ?? 0 ),
					);
					$job['message'] = 'Resumen global del core listo.';
					$job['current_stage'] = 'expenses';
					break;

				case 'expenses':
					$job['parts']['expenses'] = $service->build_expenses_payload( $context );
					$job['message'] = 'Gastos del periodo calculados.';
					$job['current_stage'] = 'compose_base';
					break;

				case 'compose_base':
					$job['payload'] = $service->compose_payload(
						$context,
						(array) ( $job['parts']['sales'] ?? array() ),
						(array) ( $job['parts']['receivables'] ?? array() ),
						(array) ( $job['parts']['payables'] ?? array() ),
						(array) ( $job['parts']['expenses'] ?? array() ),
						(array) ( $job['parts']['overview'] ?? array() ),
						(array) ( $job['parts']['preflight'] ?? array() )
					);
					$job['message'] = 'Resumen ejecutivo ensamblado.';
					$job['current_stage'] = 'comparison';
					break;

				case 'comparison':
					$job['payload']['comparison'] = $service->build_comparison_payload( $context, (array) ( $job['payload'] ?? array() ) );
					$job['message'] = 'Comparativo del periodo anterior listo.';
					$job['current_stage'] = 'finalize';
					break;

				case 'finalize':
					$payload = (array) ( $job['payload'] ?? array() );
					$versions = array();
					$mode = sanitize_key( (string) ( $payload['meta']['mode'] ?? $context['mode'] ?? '' ) );
					$month_key = sanitize_text_field( (string) ( $payload['meta']['month_key'] ?? $context['month_key'] ?? '' ) );

					if ( 'monthly_close' === $mode && '' !== $month_key ) {
						$versions = ( new MonthlyCloseSnapshotService() )->list_versions( $month_key );
					}

					$job['versions'] = $versions;
					$job['status'] = 'completed';
					$job['message'] = 'Reporte maestro generado correctamente.';
					$service->cache_payload_for_context( $context, $payload );
					break;
			}
		} catch ( \Throwable $throwable ) {
			$job['status'] = 'error';
			$job['error_message'] = $throwable->getMessage();
			$job['message'] = 'El runner del reporte se detuvo con error.';
		}

		$job['updated_at'] = $this->now();
		$this->save_job( $job );

		return $this->decorate_job( $job );
	}

	public function status( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			return $this->error( 'asdl_fin_master_report_job_missing', 'No se encontro el estado del reporte solicitado.' );
		}

		return $this->decorate_job( $job );
	}

	public function result( $job_id ) {
		$job = $this->get_job( $job_id );

		if ( empty( $job['job_id'] ) ) {
			return $this->error( 'asdl_fin_master_report_job_missing', 'No se encontro el resultado del reporte solicitado.' );
		}

		if ( 'completed' !== (string) ( $job['status'] ?? '' ) ) {
			return $this->error( 'asdl_fin_master_report_job_running', 'El reporte todavia no ha terminado.' );
		}

		return array(
			'job'      => $this->decorate_job( $job ),
			'payload'  => (array) ( $job['payload'] ?? array() ),
			'versions' => array_values( (array) ( $job['versions'] ?? array() ) ),
		);
	}

	private function save_job( array $job ) {
		$job_id = sanitize_text_field( (string) ( $job['job_id'] ?? '' ) );
		if ( '' === $job_id ) {
			return false;
		}

		return set_transient( self::JOB_PREFIX . md5( $job_id ), $job, self::JOB_TTL );
	}

	private function get_job( $job_id ) {
		$job_id = sanitize_text_field( (string) $job_id );
		if ( '' === $job_id ) {
			return array();
		}

		$job = get_transient( self::JOB_PREFIX . md5( $job_id ) );

		return is_array( $job ) ? $job : array();
	}

	private function decorate_job( array $job ) {
		$stages = $this->stage_map();
		$current_stage = sanitize_key( (string) ( $job['current_stage'] ?? 'preflight' ) );
		$stage_index = array_search( $current_stage, array_keys( $stages ), true );
		$stage_index = false === $stage_index ? 0 : (int) $stage_index;
		$percent = 0;
		$total_stages = count( $stages );

		if ( 'completed' === (string) ( $job['status'] ?? '' ) ) {
			$percent = 100;
		} elseif ( 'error' === (string) ( $job['status'] ?? '' ) ) {
			$percent = min( 99, max( 5, (int) floor( ( $stage_index / max( 1, $total_stages ) ) * 100 ) ) );
		} else {
			$base = ( $stage_index / max( 1, $total_stages ) ) * 100;
			$current_fraction = 0;
			$subprogress = array();

			if ( 'preflight' === $current_stage && ! empty( $job['margin_job_id'] ) ) {
				$margin_job = ( new ProductMarginCheckService() )->get_job( $job['margin_job_id'] );
				$total = max( 1, (int) ( $margin_job['total_products'] ?? 0 ) );
				$processed = min( $total, (int) ( $margin_job['processed_products'] ?? 0 ) );
				$current_fraction = $processed / $total;
				$subprogress = array(
					'processed'   => $processed,
					'total'       => $total,
					'last_batch'  => (int) ( $margin_job['last_batch'] ?? 0 ),
					'issue_count' => (int) ( $margin_job['issue_count'] ?? 0 ),
					'message'     => sanitize_text_field( (string) ( $margin_job['message'] ?? '' ) ),
				);
			}

			$percent = (int) floor( $base + ( $current_fraction * ( 100 / max( 1, $total_stages ) ) ) );
			if ( ! empty( $subprogress ) ) {
				$job['subprogress'] = $subprogress;
			}
		}

		$job['progress_percent'] = $percent;
		$job['stage_label'] = sanitize_text_field( (string) ( $stages[ $current_stage ] ?? 'Procesando' ) );

		return $job;
	}

	private function stage_map() {
		return array(
			'preflight'    => 'Verificacion de productos',
			'sales'        => 'Ventas y margen comercial',
			'receivables'  => 'Cuentas por cobrar',
			'payables'     => 'Cuentas por pagar',
			'overview'     => 'Resumen global',
			'expenses'     => 'Gastos del periodo',
			'compose_base' => 'Resumen ejecutivo',
			'comparison'   => 'Comparativo',
			'finalize'     => 'Cierre del payload',
		);
	}
}
