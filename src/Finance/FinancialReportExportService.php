<?php

namespace ASDLabs\Finance\Finance;

final class FinancialReportExportService {
	public function send_csv( array $payload, $filename = 'reporte-maestro-financiero' ) {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'reporte-maestro-financiero';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename . '.csv' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		fputcsv(
			$output,
			array(
				'section',
				'subsection',
				'row_type',
				'label',
				'entity_ref',
				'date',
				'amount',
				'currency',
				'meta_json',
			)
		);

		foreach ( $this->flatten_payload( $payload ) as $row ) {
			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	private function flatten_payload( array $payload ) {
		$rows     = array();
		$currency = sanitize_text_field( (string) ( $payload['meta']['currency'] ?? 'USD' ) );

		foreach ( (array) ( $payload['executive'] ?? array() ) as $key => $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}

			$rows[] = $this->row( 'executive', '', 'metric', $this->labelize( $key ), '', '', $value, $currency );
		}

		foreach ( (array) ( $payload['sales']['totals'] ?? array() ) as $key => $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}

			$rows[] = $this->row( 'sales', 'totals', 'metric', $this->labelize( $key ), '', '', $value, $currency );
		}

		foreach ( (array) ( $payload['sales']['products'] ?? array() ) as $item ) {
			$rows[] = $this->row(
				'sales',
				'products',
				'item',
				sanitize_text_field( (string) ( $item['nombre'] ?? 'Producto' ) ),
				sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
				'',
				(float) ( $item['total'] ?? 0 ),
				$currency,
				array( 'cantidad' => (float) ( $item['cantidad'] ?? 0 ) )
			);
		}

		foreach ( (array) ( $payload['sales']['categories'] ?? array() ) as $item ) {
			$rows[] = $this->row(
				'sales',
				'categories',
				'item',
				sanitize_text_field( (string) ( $item['nombre'] ?? 'Categoria' ) ),
				'',
				'',
				(float) ( $item['total'] ?? 0 ),
				$currency,
				array( 'cantidad' => (float) ( $item['cantidad'] ?? 0 ) )
			);
		}

		foreach ( (array) ( $payload['sales']['top_days'] ?? array() ) as $subsection => $items ) {
			foreach ( (array) $items as $item ) {
				$rows[] = $this->row(
					'sales',
					(string) $subsection,
					'item',
					'Dia',
					'',
					sanitize_text_field( (string) ( $item['fecha'] ?? '' ) ),
					(float) ( $item['total'] ?? 0 ),
					$currency
				);
			}
		}

		foreach ( array( 'receivables', 'payables' ) as $section ) {
			foreach ( (array) ( $payload[ $section ]['summary'] ?? array() ) as $key => $value ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}

				$rows[] = $this->row( $section, 'summary', 'metric', $this->labelize( $key ), '', '', $value, $currency );
			}

			foreach ( (array) ( $payload[ $section ]['items'] ?? array() ) as $item ) {
				$rows[] = $this->row(
					$section,
					'groups',
					'item',
					sanitize_text_field( (string) ( $item['display_name'] ?? 'Grupo' ) ),
					sanitize_text_field( (string) ( $item['group_key'] ?? $item['id'] ?? '' ) ),
					sanitize_text_field( (string) ( $item['oldest_date'] ?? '' ) ),
					(float) ( $item['pending_total'] ?? 0 ),
					$currency,
					array(
						'count' => (int) ( $item['count'] ?? 0 ),
						'providers' => array_values( (array) ( $item['providers'] ?? array() ) ),
					)
				);
			}
		}

		foreach ( array( 'external', 'services', 'payroll', 'total' ) as $bucket ) {
			foreach ( (array) ( $payload['expenses'][ $bucket ] ?? array() ) as $key => $value ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}

				$rows[] = $this->row( 'expenses', $bucket, 'metric', $this->labelize( $key ), '', '', $value, $currency );
			}
		}

		foreach ( (array) ( $payload['expenses']['items'] ?? array() ) as $item ) {
			$rows[] = $this->row(
				'expenses',
				sanitize_key( (string) ( $item['document_type'] ?? 'expense' ) ),
				'item',
				sanitize_text_field( (string) ( $item['title'] ?? 'Movimiento' ) ),
				sanitize_text_field( (string) ( $item['document_number'] ?? $item['id'] ?? '' ) ),
				sanitize_text_field( (string) ( $item['issue_date'] ?? '' ) ),
				(float) ( $item['total'] ?? 0 ),
				sanitize_text_field( (string) ( $item['currency'] ?? $currency ) ),
				array(
					'balance' => (float) ( $item['balance'] ?? 0 ),
					'payment_status' => sanitize_key( (string) ( $item['payment_status'] ?? '' ) ),
					'financial_status' => sanitize_key( (string) ( $item['financial_status'] ?? '' ) ),
				)
			);
		}

		foreach ( (array) ( $payload['comparison']['rows'] ?? array() ) as $row ) {
			$rows[] = $this->row(
				'comparison',
				'rows',
				'metric',
				sanitize_text_field( (string) ( $row['label'] ?? 'Comparativo' ) ),
				'',
				'',
				(float) ( $row['current'] ?? 0 ),
				$currency,
				array(
					'previous'      => (float) ( $row['previous'] ?? 0 ),
					'delta'         => (float) ( $row['delta'] ?? 0 ),
					'delta_percent' => (float) ( $row['delta_percent'] ?? 0 ),
				)
			);
		}

		return $rows;
	}

	private function row( $section, $subsection, $row_type, $label, $entity_ref, $date, $amount, $currency, array $meta = array() ) {
		return array(
			sanitize_key( (string) $section ),
			sanitize_key( (string) $subsection ),
			sanitize_key( (string) $row_type ),
			sanitize_text_field( (string) $label ),
			sanitize_text_field( (string) $entity_ref ),
			sanitize_text_field( (string) $date ),
			round( (float) $amount, 2 ),
			sanitize_text_field( (string) $currency ),
			! empty( $meta ) ? wp_json_encode( $meta ) : '',
		);
	}

	private function labelize( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		return ucwords( str_replace( '_', ' ', $value ) );
	}
}
