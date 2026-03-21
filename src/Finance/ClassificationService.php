<?php

namespace ASDLabs\Finance\Finance;

final class ClassificationService {
	public function apply( array $data ) {
		$context   = $this->normalize_context( $data );
		$fallback  = $this->fallback_for_type( $context['document_type'], $context['source_type'] );
		$rule      = null;
		$trace     = array(
			'mode'          => 'fallback',
			'document_type' => $context['document_type'],
			'source_type'   => $context['source_type'],
			'matched_rule' => null,
		);
		$result    = $fallback;

		if ( ! empty( $context['manual_override'] ) ) {
			$result = array(
				'financial_intent' => $this->prefer_value( $context['financial_intent'], $fallback['financial_intent'] ),
				'balance_nature'   => $this->prefer_value( $context['balance_nature'], $fallback['balance_nature'] ),
				'category_key'     => $this->prefer_value( $context['category_key'], $fallback['category_key'] ),
				'subcategory_key'  => $this->prefer_value( $context['subcategory_key'], $fallback['subcategory_key'] ),
			);
			$trace['mode'] = 'manual_override';
		} else {
			$rule = ( new RulesRepository() )->first_match( $context );

			if ( ! empty( $rule['id'] ) ) {
				$result = array(
					'financial_intent' => $this->prefer_value( $rule['actions']['financial_intent'] ?? '', $fallback['financial_intent'] ),
					'balance_nature'   => $this->prefer_value( $rule['actions']['balance_nature'] ?? '', $fallback['balance_nature'] ),
					'category_key'     => $this->prefer_value( $rule['actions']['category_key'] ?? '', $fallback['category_key'] ),
					'subcategory_key'  => $this->prefer_value( $rule['actions']['subcategory_key'] ?? '', $fallback['subcategory_key'] ),
				);
				$trace['mode'] = 'rule';
				$trace['matched_rule'] = array(
					'id'         => (int) $rule['id'],
					'rule_name'  => (string) $rule['rule_name'],
					'scope_type' => (string) $rule['scope_type'],
					'priority'   => (int) $rule['priority'],
					'actions'    => $rule['actions'],
				);
			}
		}

		return array_merge(
			$result,
			array(
				'classification_trace' => array_merge(
					$trace,
					array(
						'fallback'      => $fallback,
						'resolved'      => $result,
						'evaluated_at'  => current_time( 'mysql' ),
					)
				),
			)
		);
	}

	private function fallback_for_type( $document_type, $source_type ) {
		switch ( $document_type ) {
			case 'woo_sale':
				return array(
					'financial_intent' => 'income',
					'balance_nature'   => 'receivable',
					'category_key'     => 'sales',
					'subcategory_key'  => 'openpos' === $source_type ? 'pos_sale' : 'web_sale',
				);
			case 'external_expense':
				return array(
					'financial_intent' => 'expense',
					'balance_nature'   => 'payable',
					'category_key'     => 'operating_expense',
					'subcategory_key'  => 'external_expense',
				);
			case 'service_expense':
				return array(
					'financial_intent' => 'service',
					'balance_nature'   => 'payable',
					'category_key'     => 'services',
					'subcategory_key'  => 'contracted_service',
				);
			case 'salary_expense':
				return array(
					'financial_intent' => 'salary',
					'balance_nature'   => 'payable',
					'category_key'     => 'payroll',
					'subcategory_key'  => 'salary',
				);
			case 'loan_receivable':
				return array(
					'financial_intent' => 'loan',
					'balance_nature'   => 'receivable',
					'category_key'     => 'financing',
					'subcategory_key'  => 'loan_receivable',
				);
			case 'loan_payable':
				return array(
					'financial_intent' => 'loan',
					'balance_nature'   => 'payable',
					'category_key'     => 'financing',
					'subcategory_key'  => 'loan_payable',
				);
			case 'adjustment':
				return array(
					'financial_intent' => 'adjustment',
					'balance_nature'   => 'neutral',
					'category_key'     => 'adjustments',
					'subcategory_key'  => 'manual_adjustment',
				);
			case 'manual_document':
			default:
				return array(
					'financial_intent' => 'neutral',
					'balance_nature'   => 'neutral',
					'category_key'     => 'manual',
					'subcategory_key'  => 'general',
				);
		}
	}

	private function normalize_context( array $data ) {
		$document_type = sanitize_key( $data['document_type'] ?? 'manual_document' );
		$source_type   = sanitize_key( $data['source_type'] ?? 'manual' );
		$account_type  = sanitize_key( $data['account_type'] ?? '' );
		$contact_type  = sanitize_key( $data['contact_type'] ?? '' );

		if ( 'manual_document' === $document_type ) {
			if ( 'employee' === $contact_type ) {
				$document_type = 'salary_expense';
			} elseif ( 'loan' === $account_type ) {
				$document_type = 'loan_payable';
			}
		}

		return array(
			'document_type'      => $document_type,
			'source_type'        => $source_type,
			'account_type'       => $account_type,
			'account_id'         => ! empty( $data['account_id'] ) ? absint( $data['account_id'] ) : 0,
			'contact_type'       => $contact_type,
			'contact_id'         => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : 0,
			'wp_user_id'         => ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : 0,
			'financial_intent'   => sanitize_key( $data['financial_intent'] ?? '' ),
			'balance_nature'     => sanitize_key( $data['balance_nature'] ?? '' ),
			'category_key'       => sanitize_key( $data['category_key'] ?? '' ),
			'subcategory_key'    => sanitize_key( $data['subcategory_key'] ?? '' ),
			'operational_status' => sanitize_key( $data['operational_status'] ?? '' ),
			'external_reference' => sanitize_text_field( $data['external_reference'] ?? '' ),
			'title'              => sanitize_text_field( $data['title'] ?? '' ),
			'manual_override'    => ! empty( $data['manual_override'] ),
		);
	}

	private function prefer_value( $value, $fallback ) {
		$value = sanitize_key( (string) $value );

		if ( '' === $value || 'neutral' === $value ) {
			return $fallback;
		}

		return $value;
	}
}
