<?php

namespace ASDLabs\Finance\Finance;

use WP_Error;

final class ReceiptService {
	public function build( $receipt_type, $entity_id ) {
		$receipt_type = sanitize_key( (string) $receipt_type );
		$entity_id    = absint( $entity_id );

		if ( $entity_id <= 0 ) {
			return new WP_Error( 'asdl_fin_receipt_missing', 'Debes indicar un comprobante valido.' );
		}

		switch ( $receipt_type ) {
			case 'payment':
				return $this->build_payment_receipt( $entity_id );
			case 'salary_advance':
				return $this->build_salary_advance_receipt( $entity_id );
			case 'payroll_period':
				return $this->build_payroll_receipt( $entity_id );
			default:
				return new WP_Error( 'asdl_fin_receipt_type', 'Ese tipo de comprobante todavia no esta disponible.' );
		}
	}

	private function build_payment_receipt( $payment_id ) {
		$payments     = new PaymentsRepository();
		$contacts     = new ContactsRepository();
		$accounts     = new AccountsRepository();
		$allocations  = new PaymentAllocationsRepository();
		$installments = new InstallmentsRepository();
		$methods      = new PaymentMethodsService();
		$payment      = $payments->find( $payment_id );

		if ( empty( $payment['id'] ) ) {
			return new WP_Error( 'asdl_fin_receipt_payment_missing', 'No se encontro el pago solicitado.' );
		}

		$contact                = ! empty( $payment['contact_id'] ) ? $contacts->find( (int) $payment['contact_id'] ) : null;
		$account                = ! empty( $payment['account_id'] ) ? $accounts->find( (int) $payment['account_id'] ) : null;
		$allocation_rows        = $allocations->for_payment( (int) $payment['id'], 100 );
		$installment_rows       = $installments->applications_for_payment( (int) $payment['id'], 100 );
		$meta                   = json_decode( (string) ( $payment['meta_json'] ?? '' ), true );
		$meta                   = is_array( $meta ) ? $meta : array();
		$lines                  = array();

		foreach ( $allocation_rows as $allocation ) {
			$lines[] = array(
				'label'   => ! empty( $allocation['document_title'] ) ? $allocation['document_title'] : ( $allocation['document_number'] ?: 'Movimiento enlazado' ),
				'detail'  => ! empty( $allocation['document_number'] ) ? $allocation['document_number'] : 'Sin numero',
				'amount'  => (float) ( $allocation['amount'] ?? 0 ),
				'context' => 'Asignacion a movimiento',
			);
		}

		foreach ( $installment_rows as $application ) {
			$detail = sprintf( '%s | saldo restante %s', $application['plan_title'], number_format_i18n( (float) $application['remaining_balance'], 2 ) );
			$context = 'Aplicado a compromiso';

			if ( ! empty( $application['is_recovery_plan'] ) ) {
				$backing_bits = array();
				if ( ! empty( $application['backing_document_ids'] ) ) {
					$backing_bits[] = sprintf( 'docs %s', implode( ', ', array_map( 'intval', (array) $application['backing_document_ids'] ) ) );
				}
				if ( ! empty( $application['backing_order_ids'] ) ) {
					$backing_bits[] = sprintf( 'pedidos %s', implode( ', ', array_map( 'intval', (array) $application['backing_order_ids'] ) ) );
				}
				if ( empty( $backing_bits ) && ! empty( $application['backing_document_id'] ) ) {
					$backing_bits[] = sprintf( 'doc %d', (int) $application['backing_document_id'] );
				}

				$detail = sprintf(
					'%s | respaldo %s | saldo restante %s',
					$application['plan_title'],
					! empty( $backing_bits ) ? implode( ' / ', $backing_bits ) : 'sin detalle',
					number_format_i18n( (float) $application['remaining_balance'], 2 )
				);
				$context = 'Recuperacion programada';
			}

			$lines[] = array(
				'label'   => ! empty( $application['installment_title'] ) ? $application['installment_title'] : $application['plan_title'],
				'detail'  => $detail,
				'amount'  => (float) ( $application['applied_amount'] ?? 0 ),
				'context' => $context,
			);
		}

		$title             = 'Comprobante de ' . strtolower( $this->payment_type_label( $payment['payment_type'] ?? '' ) );
		$subtitle          = 'Soporte imprimible del movimiento de caja ya registrado en Finanzas ASD.';
		$is_salary_advance = 'salary_advance' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) );
		$is_dual_child     = 'dual_price_discount' === sanitize_key( (string) ( $payment['method_key'] ?? '' ) ) || ! empty( $meta['dual_discount_parent_payment_id'] );
		$is_dual_parent    = ! empty( $meta['dual_discount_mode'] ) && (float) ( $meta['dual_discount_total'] ?? 0 ) > 0;
		$parent_payment    = $is_dual_child && ! empty( $meta['dual_discount_parent_payment_id'] ) ? $payments->find( (int) $meta['dual_discount_parent_payment_id'] ) : null;
		$meta_rows         = array(
			array(
				'label' => 'Tipo',
				'value' => $this->payment_type_label( $payment['payment_type'] ?? '' ),
			),
			array(
				'label' => 'Metodo',
				'value' => $methods->label( $payment['method_key'] ?? '' ),
			),
			array(
				'label' => 'Cuenta',
				'value' => $account['name'] ?? 'Sin definir',
			),
		);

		if ( $is_dual_parent ) {
			$title     = 'Comprobante de abono con precio dual';
			$subtitle  = 'Soporte imprimible del abono aplicado en divisa con descuento automatico sobre pedidos Woo/OpenPOS.';
			$meta_rows[] = array(
				'label' => 'Gestion',
				'value' => 'Abono con precio dual',
			);
			$meta_rows[] = array(
				'label' => 'Descuento aplicado',
				'value' => $this->money_text( (float) ( $meta['dual_discount_total'] ?? 0 ), $payment['currency'] ?? 'USD' ),
			);
			$meta_rows[] = array(
				'label' => 'Porcentaje',
				'value' => number_format_i18n( (float) ( $meta['dual_discount_percent'] ?? 0 ), 2 ) . '%',
			);
			$meta_rows[] = array(
				'label' => 'Pedidos afectados',
				'value' => (string) count( array_unique( array_filter( array_map( 'absint', (array) ( $meta['dual_discount_order_ids'] ?? array() ) ) ) ) ),
			);
		} elseif ( $is_dual_child ) {
			$title     = 'Comprobante de ajuste por precio dual';
			$subtitle  = 'Ajuste tecnico generado automaticamente para registrar el descuento concedido al abono principal.';
			$meta_rows[] = array(
				'label' => 'Gestion',
				'value' => 'Ajuste dual',
			);
			$meta_rows[] = array(
				'label' => 'Abono principal',
				'value' => ! empty( $parent_payment['payment_number'] ) ? $parent_payment['payment_number'] : ( ! empty( $meta['dual_discount_parent_payment_id'] ) ? '#' . (int) $meta['dual_discount_parent_payment_id'] : 'Sin relacion detectada' ),
			);
			$meta_rows[] = array(
				'label' => 'Descuento aplicado',
				'value' => $this->money_text( (float) ( $meta['dual_discount_total'] ?? 0 ), $payment['currency'] ?? 'USD' ),
			);
			$meta_rows[] = array(
				'label' => 'Porcentaje',
				'value' => number_format_i18n( (float) ( $meta['dual_discount_percent'] ?? 0 ), 2 ) . '%',
			);
		}

		return array(
			'type'            => 'payment',
			'title'           => ucfirst( $title ),
			'subtitle'        => $subtitle,
			'number'          => $payment['payment_number'] ?? '',
			'date'            => $payment['payment_date'] ?? '',
			'status_label'    => $this->payment_status_label( $payment['status'] ?? '' ),
			'status_tone'     => $this->status_tone( $payment['status'] ?? '' ),
			'party_name'      => $contact['display_name'] ?? 'Sin perfil asociado',
			'party_meta'      => $contact['email'] ?? '',
			'account_name'    => $account['name'] ?? 'Sin cuenta definida',
			'method_label'    => $methods->label( $payment['method_key'] ?? '' ),
			'reference'       => $payment['reference'] ?? '',
			'notes'           => $payment['notes'] ?? '',
			'currency'        => $payment['currency'] ?? 'USD',
			'total_amount'    => (float) ( $payment['total'] ?? 0 ),
			'secondary_total' => $is_salary_advance || $is_dual_child ? 0 : (float) ( $payment['available_amount'] ?? 0 ),
			'secondary_label' => $is_salary_advance ? 'Se recupera por sueldo' : ( $is_dual_child ? 'Ajuste tecnico' : 'Saldo disponible' ),
			'lines'           => $lines,
			'meta_rows'       => $meta_rows,
		);
	}

	private function build_salary_advance_receipt( $advance_id ) {
		$advances = new EmployeeAdvancesRepository();
		$contacts = new ContactsRepository();
		$accounts = new AccountsRepository();
		$payments = new PaymentsRepository();
		$methods  = new PaymentMethodsService();
		$advance  = $advances->find( $advance_id );

		if ( empty( $advance['id'] ) ) {
			return new WP_Error( 'asdl_fin_receipt_advance_missing', 'No se encontro el adelanto solicitado.' );
		}

		$contact = ! empty( $advance['contact_id'] ) ? $contacts->find( (int) $advance['contact_id'] ) : null;
		$account = ! empty( $advance['source_account_id'] ) ? $accounts->find( (int) $advance['source_account_id'] ) : null;
		$payment = ! empty( $advance['payment_id'] ) ? $payments->find( (int) $advance['payment_id'] ) : null;

		return array(
			'type'            => 'salary_advance',
			'title'           => 'Comprobante de adelanto de sueldo',
			'subtitle'        => 'Soporte imprimible del adelanto registrado al perfil del empleado.',
			'number'          => ! empty( $payment['payment_number'] ) ? $payment['payment_number'] : sprintf( 'ADV-%d', (int) $advance['id'] ),
			'date'            => $advance['issued_at'] ?? '',
			'status_label'    => $this->advance_status_label( $advance['status'] ?? '' ),
			'status_tone'     => $this->status_tone( $advance['status'] ?? '' ),
			'party_name'      => $contact['display_name'] ?? 'Empleado sin perfil',
			'party_meta'      => $contact['email'] ?? '',
			'account_name'    => $account['name'] ?? 'Sin cuenta definida',
			'method_label'    => $methods->label( $payment['method_key'] ?? 'salary_advance' ),
			'reference'       => $advance['reference'] ?? '',
			'notes'           => $advance['notes'] ?? '',
			'currency'        => $advance['currency'] ?? 'USD',
			'total_amount'    => (float) ( $advance['total_amount'] ?? 0 ),
			'secondary_total' => (float) ( $advance['balance'] ?? 0 ),
			'secondary_label' => 'Saldo pendiente por compensar',
			'lines'           => array(
				array(
					'label'   => 'Monto entregado',
					'detail'  => 'Adelanto registrado en el perfil laboral',
					'amount'  => (float) ( $advance['total_amount'] ?? 0 ),
					'context' => 'Salida de caja',
				),
			),
			'meta_rows'       => array(
				array(
					'label' => 'Modo de recuperacion',
					'value' => $this->advance_recovery_label( $advance['recovery_mode'] ?? '' ),
				),
				array(
					'label' => 'Cuenta',
					'value' => $account['name'] ?? 'Sin definir',
				),
				array(
					'label' => 'Recuperado',
					'value' => $this->money_text( (float) ( $advance['recovered_amount'] ?? 0 ), $advance['currency'] ?? 'USD' ),
				),
				array(
					'label' => 'Previsto',
					'value' => ! empty( $advance['expected_recovery_date'] ) ? $advance['expected_recovery_date'] : 'Sin fecha prevista',
				),
			),
		);
	}

	private function build_payroll_receipt( $payroll_id ) {
		$periods  = new PayrollPeriodsRepository();
		$contacts = new ContactsRepository();
		$accounts = new AccountsRepository();
		$payments = new PaymentsRepository();
		$methods  = new PaymentMethodsService();
		$period   = $periods->find( $payroll_id );

		if ( empty( $period['id'] ) ) {
			return new WP_Error( 'asdl_fin_receipt_payroll_missing', 'No se encontro el periodo de nomina solicitado.' );
		}

		$contact              = ! empty( $period['contact_id'] ) ? $contacts->find( (int) $period['contact_id'] ) : null;
		$account              = ! empty( $period['payment_account_id'] ) ? $accounts->find( (int) $period['payment_account_id'] ) : null;
		$payment              = ! empty( $period['payment_id'] ) ? $payments->find( (int) $period['payment_id'] ) : null;
		$meta                 = is_array( $period['meta'] ?? null ) ? $period['meta'] : array();
		$advance_breakdown    = is_array( $meta['applied_advance_breakdown'] ?? null ) ? $meta['applied_advance_breakdown'] : array();
		$commitment_breakdown = is_array( $meta['applied_commitment_breakdown'] ?? null ) ? $meta['applied_commitment_breakdown'] : array();
		$commitment_payout_breakdown = is_array( $meta['applied_commitment_payout_breakdown'] ?? null ) ? $meta['applied_commitment_payout_breakdown'] : array();
		$lines                = array();

		$lines[] = array(
			'label'   => 'Sueldo bruto del periodo',
			'detail'  => sprintf( '%s al %s', $period['period_start'] ?? '', $period['period_end'] ?? '' ),
			'amount'  => (float) ( $period['gross_amount'] ?? 0 ),
			'context' => 'Monto base',
		);

		foreach ( $advance_breakdown as $advance_item ) {
			$lines[] = array(
				'label'   => sprintf( 'Compensacion de adelanto #%d', (int) ( $advance_item['advance_id'] ?? 0 ) ),
				'detail'  => 'Descuento aplicado desde adelanto de sueldo',
				'amount'  => 0 - max( 0, (float) ( $advance_item['amount'] ?? 0 ) ),
				'context' => 'Descuento',
			);
		}

		foreach ( $commitment_breakdown as $commitment_item ) {
			$lines[] = array(
				'label'   => ! empty( $commitment_item['title'] ) ? $commitment_item['title'] : sprintf( 'Compromiso #%d', (int) ( $commitment_item['plan_id'] ?? 0 ) ),
				'detail'  => 'Descuento por compromiso o deuda acordada',
				'amount'  => 0 - max( 0, (float) ( $commitment_item['applied_total'] ?? 0 ) ),
				'context' => 'Descuento',
			);
		}

		foreach ( $commitment_payout_breakdown as $commitment_item ) {
			$lines[] = array(
				'label'   => ! empty( $commitment_item['title'] ) ? $commitment_item['title'] : sprintf( 'Compromiso #%d', (int) ( $commitment_item['plan_id'] ?? 0 ) ),
				'detail'  => 'Pago programado a favor del empleado',
				'amount'  => max( 0, (float) ( $commitment_item['applied_total'] ?? 0 ) ),
				'context' => 'Pago adicional',
			);
		}

		$lines[] = array(
			'label'   => 'Pago neto entregado',
			'detail'  => ! empty( $payment['payment_number'] ) ? $payment['payment_number'] : 'Sin pago de caja registrado',
			'amount'  => (float) ( $period['net_amount'] ?? 0 ),
			'context' => 'Pago final',
		);

		return array(
			'type'            => 'payroll_period',
			'title'           => 'Comprobante de nomina',
			'subtitle'        => 'Soporte imprimible del pago procesado al empleado, con descuentos y neto entregado.',
			'number'          => ! empty( $payment['payment_number'] ) ? $payment['payment_number'] : sprintf( 'NOM-%d', (int) $period['id'] ),
			'date'            => ! empty( $period['paid_at'] ) ? $period['paid_at'] : ( $period['scheduled_payment_date'] ?? '' ),
			'status_label'    => $this->payroll_status_label( $period['status'] ?? '' ),
			'status_tone'     => $this->status_tone( $period['status'] ?? '' ),
			'party_name'      => $contact['display_name'] ?? 'Empleado sin perfil',
			'party_meta'      => $contact['email'] ?? '',
			'account_name'    => $account['name'] ?? 'Sin cuenta definida',
			'method_label'    => $methods->label( $payment['method_key'] ?? ( $period['payment_method_key'] ?? '' ) ),
			'reference'       => $payment['reference'] ?? '',
			'notes'           => $period['notes'] ?? '',
			'currency'        => $period['currency'] ?? 'USD',
			'total_amount'    => (float) ( $period['net_amount'] ?? 0 ),
			'secondary_total' => (float) ( $period['gross_amount'] ?? 0 ),
			'secondary_label' => 'Sueldo bruto del periodo',
			'lines'           => $lines,
			'meta_rows'       => array(
				array(
					'label' => 'Frecuencia',
					'value' => $this->frequency_label( $period['frequency_key'] ?? '' ),
				),
				array(
					'label' => 'Adelantos descontados',
					'value' => $this->money_text( (float) ( $period['advance_deduction_amount'] ?? 0 ), $period['currency'] ?? 'USD' ),
				),
				array(
					'label' => 'Compromisos descontados',
					'value' => $this->money_text( (float) ( $period['commitment_deduction_amount'] ?? 0 ), $period['currency'] ?? 'USD' ),
				),
				array(
					'label' => 'Compromisos pagados',
					'value' => $this->money_text( (float) ( $period['commitment_payout_amount'] ?? 0 ), $period['currency'] ?? 'USD' ),
				),
				array(
					'label' => 'Cuenta',
					'value' => $account['name'] ?? 'Sin definir',
				),
			),
		);
	}

	private function payment_type_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'disbursement':
				return 'Pago';
			case 'adjustment':
				return 'Ajuste';
			case 'collection':
			default:
				return 'Cobro';
		}
	}

	private function payment_status_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'draft':
				return 'Borrador';
			case 'void':
				return 'Anulado';
			case 'posted':
			default:
				return 'Registrado';
		}
	}

	private function advance_status_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'partial':
				return 'Parcialmente descontado';
			case 'settled':
				return 'Compensado';
			case 'cancelled':
				return 'Anulado';
			case 'active':
			default:
				return 'Activo';
		}
	}

	private function advance_recovery_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'manual':
				return 'Gestion manual';
			case 'next_payroll':
			default:
				return 'Proximo pago';
		}
	}

	private function payroll_status_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'paid':
				return 'Pagado';
			case 'cancelled':
				return 'Anulado';
			case 'planned':
			default:
				return 'Pendiente';
		}
	}

	private function frequency_label( $value ) {
		switch ( sanitize_key( (string) $value ) ) {
			case 'weekly':
				return 'Semanal';
			case 'biweekly':
				return 'Quincenal';
			case 'quarterly':
				return 'Trimestral';
			case 'monthly':
			default:
				return 'Mensual';
		}
	}

	private function money_text( $amount, $currency ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );

		return sprintf(
			'%s %s',
			number_format_i18n( (float) $amount, 2 ),
			'' !== $currency ? $currency : 'USD'
		);
	}

	private function status_tone( $value ) {
		$value = sanitize_key( (string) $value );

		if ( in_array( $value, array( 'paid', 'posted', 'active', 'settled' ), true ) ) {
			return 'success';
		}

		if ( in_array( $value, array( 'partial', 'pending', 'planned' ), true ) ) {
			return 'warning';
		}

		if ( in_array( $value, array( 'void', 'cancelled' ), true ) ) {
			return 'danger';
		}

		return 'neutral';
	}
}
