<?php

namespace ASDLabs\Finance\Finance;

final class InstallmentsRepository extends BaseRepository {
	protected $table_key = 'installments';

	public function find( $installment_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$installment_id = absint( $installment_id );
		if ( $installment_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$installment_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_row( $row );
	}

	public function for_plan( $plan_id, $open_only = false, $limit = 200 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$plan_id = absint( $plan_id );
		$limit   = max( 1, min( 500, (int) $limit ) );

		if ( $plan_id <= 0 ) {
			return array();
		}

		$where_sql = 'WHERE plan_id = %d';
		if ( $open_only ) {
			$where_sql .= " AND balance > 0 AND payment_status IN ('pending', 'partial', 'overdue')";
		}

		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				{$where_sql}
				ORDER BY CASE WHEN due_date IS NULL OR due_date = '' THEN 1 ELSE 0 END ASC, due_date ASC, sequence_no ASC, id ASC
				LIMIT %d",
				$plan_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'hydrate_row' ), $rows );
	}

	public function open_for_plan( $plan_id, $limit = 200 ) {
		return $this->for_plan( $plan_id, true, $limit );
	}

	public function applications_for_payment( $payment_id, $limit = 100 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$payment_id = absint( $payment_id );
		$limit      = max( 1, min( 500, (int) $limit ) );

		if ( $payment_id <= 0 ) {
			return array();
		}

		$like = sprintf( '%%"payment_id":%d%%', $payment_id );
		$rows = $this->db()->get_results(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE meta_json LIKE %s
				ORDER BY updated_at DESC, id DESC
				LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$plans        = new InstallmentPlansRepository();
		$applications = array();

		foreach ( $rows as $row ) {
			$installment      = $this->hydrate_row( $row );
			$entries          = is_array( $installment['meta']['applications'] ?? null ) ? $installment['meta']['applications'] : array();
			$plan             = $plans->find( (int) $installment['plan_id'] );
			$plan_title       = ! empty( $plan['title'] ) ? $plan['title'] : sprintf( 'Compromiso #%d', (int) $installment['plan_id'] );

			foreach ( $entries as $entry ) {
				if ( (int) ( $entry['payment_id'] ?? 0 ) !== $payment_id ) {
					continue;
				}

				$applications[] = array(
					'plan_id'            => (int) $installment['plan_id'],
					'plan_title'         => $plan_title,
					'installment_id'     => (int) $installment['id'],
					'installment_title'  => $installment['title'] ?? '',
					'sequence_no'        => (int) ( $installment['sequence_no'] ?? 0 ),
					'applied_amount'     => (float) ( $entry['applied_amount'] ?? 0 ),
					'applied_at'         => sanitize_text_field( (string) ( $entry['applied_at'] ?? '' ) ),
					'origin'             => sanitize_key( (string) ( $entry['origin'] ?? '' ) ),
					'reference'          => sanitize_text_field( (string) ( $entry['reference'] ?? '' ) ),
					'notes'              => sanitize_textarea_field( (string) ( $entry['notes'] ?? '' ) ),
					'remaining_balance'  => (float) ( $installment['balance'] ?? 0 ),
				);
			}
		}

		return $applications;
	}

	public function summary_for_plan( $plan_id ) {
		$empty = array(
			'installment_count' => 0,
			'open_count'        => 0,
			'paid_total'        => 0,
			'balance_total'     => 0,
		);

		if ( ! $this->has_table() ) {
			return $empty;
		}

		$plan_id = absint( $plan_id );
		if ( $plan_id <= 0 ) {
			return $empty;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT
					COUNT(*) AS installment_count,
					COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS open_count,
					COALESCE(SUM(paid_amount), 0) AS paid_total,
					COALESCE(SUM(balance), 0) AS balance_total
				FROM {$this->table()}
				WHERE plan_id = %d",
				$plan_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $empty;
		}

		return array(
			'installment_count' => isset( $row['installment_count'] ) ? (int) $row['installment_count'] : 0,
			'open_count'        => isset( $row['open_count'] ) ? (int) $row['open_count'] : 0,
			'paid_total'        => isset( $row['paid_total'] ) ? (float) $row['paid_total'] : 0,
			'balance_total'     => isset( $row['balance_total'] ) ? (float) $row['balance_total'] : 0,
		);
	}

	public function apply_amount( $installment_id, $amount, array $context = array() ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_installments_missing', 'La tabla de cuotas aun no esta disponible.' );
		}

		$installment = $this->find( $installment_id );
		if ( empty( $installment['id'] ) ) {
			return $this->error( 'asdl_fin_installment_missing', 'No se encontro la cuota seleccionada.' );
		}

		$amount = max( 0, (float) $amount );
		if ( $amount <= 0 ) {
			return $this->error( 'asdl_fin_installment_amount', 'Debes indicar un monto valido para aplicar sobre la cuota.' );
		}

		if ( (float) $installment['balance'] <= 0 ) {
			return $this->error( 'asdl_fin_installment_settled', 'La cuota seleccionada ya no tiene saldo pendiente.' );
		}

		$applied_amount = min( $amount, (float) $installment['balance'] );
		$paid_amount    = round( (float) $installment['paid_amount'] + $applied_amount, 6 );
		$balance        = round( max( 0, (float) $installment['amount'] - $paid_amount ), 6 );
		$status         = $balance <= 0 ? 'paid' : 'partial';
		$meta           = is_array( $installment['meta'] ?? null ) ? $installment['meta'] : array();
		$applications   = is_array( $meta['applications'] ?? null ) ? $meta['applications'] : array();
		$entry          = array(
			'applied_amount' => $applied_amount,
			'payment_id'     => ! empty( $context['payment_id'] ) ? (int) $context['payment_id'] : 0,
			'document_id'    => ! empty( $context['document_id'] ) ? (int) $context['document_id'] : 0,
			'origin'         => sanitize_key( (string) ( $context['origin'] ?? 'manual' ) ),
			'applied_at'     => sanitize_text_field( (string) ( $context['applied_at'] ?? $this->now() ) ),
			'reference'      => sanitize_text_field( (string) ( $context['reference'] ?? '' ) ),
			'notes'          => sanitize_textarea_field( (string) ( $context['notes'] ?? '' ) ),
		);

		$applications[] = $entry;
		$meta['applications']     = array_slice( $applications, -20 );
		$meta['last_application'] = $entry;

		$result = $this->db()->update(
			$this->table(),
			array(
				'paid_amount'    => $paid_amount,
				'balance'        => $balance,
				'payment_status' => $status,
				'paid_at'        => $balance <= 0 ? $entry['applied_at'] : null,
				'meta_json'      => wp_json_encode( $meta ),
				'updated_at'     => $this->now(),
			),
			array( 'id' => (int) $installment['id'] ),
			array( '%f', '%f', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_installment_update', 'No se pudo actualizar la cuota aplicada.' );
		}

		$installment['paid_amount']   = $paid_amount;
		$installment['balance']       = $balance;
		$installment['payment_status']= $status;
		$installment['paid_at']       = $balance <= 0 ? $entry['applied_at'] : null;
		$installment['meta']          = $meta;
		$installment['applied_amount']= $applied_amount;

		return $installment;
	}

	public function reverse_payment_applications( $payment_id, array $context = array() ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_installments_missing', 'La tabla de cuotas aun no esta disponible.' );
		}

		$payment_id = absint( $payment_id );
		if ( $payment_id <= 0 ) {
			return array(
				'reversed_total' => 0,
				'plan_ids'       => array(),
				'installments'   => array(),
			);
		}

		$rows = $this->applications_for_payment( $payment_id, 500 );
		if ( empty( $rows ) ) {
			return array(
				'reversed_total' => 0,
				'plan_ids'       => array(),
				'installments'   => array(),
			);
		}

		$reversed_total       = 0.0;
		$affected_plan_ids    = array();
		$affected_installment_ids = array();

		foreach ( $rows as $application ) {
			$installment_id = (int) ( $application['installment_id'] ?? 0 );
			$installment    = $this->find( $installment_id );

			if ( empty( $installment['id'] ) ) {
				continue;
			}

			$meta         = is_array( $installment['meta'] ?? null ) ? $installment['meta'] : array();
			$applications = is_array( $meta['applications'] ?? null ) ? $meta['applications'] : array();
			$next_entries = array();
			$reversed_for_installment = 0.0;

			foreach ( $applications as $entry ) {
				if ( (int) ( $entry['payment_id'] ?? 0 ) !== $payment_id ) {
					$next_entries[] = $entry;
					continue;
				}

				$reversed_for_installment += max( 0, (float) ( $entry['applied_amount'] ?? 0 ) );
			}

			if ( $reversed_for_installment <= 0 ) {
				continue;
			}

			$paid_amount = round( max( 0, (float) $installment['paid_amount'] - $reversed_for_installment ), 6 );
			$balance     = round( max( 0, (float) $installment['amount'] - $paid_amount ), 6 );
			$status      = $this->resolve_payment_status( $paid_amount, $balance, (string) ( $installment['due_date'] ?? '' ) );

			$meta['applications'] = array_slice( $next_entries, -20 );
			if ( empty( $next_entries ) ) {
				unset( $meta['last_application'] );
			} else {
				$meta['last_application'] = end( $next_entries );
			}
			$meta['last_reversal'] = array(
				'payment_id'   => $payment_id,
				'reversed_at'  => sanitize_text_field( (string) ( $context['reversed_at'] ?? $this->now() ) ),
				'reversed_by'  => (int) ( $context['reversed_by'] ?? get_current_user_id() ),
				'reason'       => sanitize_textarea_field( (string) ( $context['reason'] ?? '' ) ),
			);

			$result = $this->db()->update(
				$this->table(),
				array(
					'paid_amount'    => $paid_amount,
					'balance'        => $balance,
					'payment_status' => $status,
					'paid_at'        => $balance <= 0 ? ( $installment['paid_at'] ?? null ) : null,
					'meta_json'      => wp_json_encode( $meta ),
					'updated_at'     => $this->now(),
				),
				array( 'id' => $installment_id ),
				array( '%f', '%f', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				return $this->error( 'asdl_fin_installment_reverse', 'No se pudo revertir la aplicacion sobre una cuota.' );
			}

			$reversed_total            += $reversed_for_installment;
			$affected_plan_ids[]       = (int) $installment['plan_id'];
			$affected_installment_ids[] = $installment_id;
		}

		if ( empty( $affected_plan_ids ) ) {
			return array(
				'reversed_total' => 0,
				'plan_ids'       => array(),
				'installments'   => array(),
			);
		}

		$plans = new InstallmentPlansRepository();
		foreach ( array_values( array_unique( $affected_plan_ids ) ) as $plan_id ) {
			$plan         = $plans->find( $plan_id );
			$plan_summary = $this->summary_for_plan( $plan_id );
			$plan_balance = (float) ( $plan_summary['balance_total'] ?? 0 );
				$plan_status  = $plan_balance <= 0 ? 'closed' : ( in_array( $plan['status'] ?? '', array( 'paused', 'inactive', 'cancelled' ), true ) ? $plan['status'] : 'active' );

			$plans->set_balance_status(
				$plan_id,
				$plan_balance,
				$plan_status,
				array(
					'last_reversal_payment_id' => $payment_id,
					'last_reversal_at'         => sanitize_text_field( (string) ( $context['reversed_at'] ?? $this->now() ) ),
				)
			);
		}

		return array(
			'reversed_total' => round( $reversed_total, 6 ),
			'plan_ids'       => array_values( array_unique( array_map( 'intval', $affected_plan_ids ) ) ),
			'installments'   => array_values( array_unique( array_map( 'intval', $affected_installment_ids ) ) ),
		);
	}

	private function hydrate_row( array $row ) {
		$row['id']          = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['plan_id']     = isset( $row['plan_id'] ) ? (int) $row['plan_id'] : 0;
		$row['sequence_no'] = isset( $row['sequence_no'] ) ? (int) $row['sequence_no'] : 0;
		$row['amount']      = isset( $row['amount'] ) ? (float) $row['amount'] : 0;
		$row['paid_amount'] = isset( $row['paid_amount'] ) ? (float) $row['paid_amount'] : 0;
		$row['balance']     = isset( $row['balance'] ) ? (float) $row['balance'] : 0;
		$row['meta']        = $this->decode_meta_json( $row['meta_json'] ?? '' );

		return $row;
	}

	private function resolve_payment_status( $paid_amount, $balance, $due_date ) {
		if ( $balance <= 0 ) {
			return 'paid';
		}

		if ( $paid_amount > 0 ) {
			return 'partial';
		}

		if ( ! empty( $due_date ) && $due_date < gmdate( 'Y-m-d' ) ) {
			return 'overdue';
		}

		return 'pending';
	}

	private function decode_meta_json( $meta_json ) {
		$decoded = json_decode( (string) $meta_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
