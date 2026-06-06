<?php

namespace ASDLabs\Finance\Finance;

final class CommitmentExposureService {
	const OPEN_PLAN_STATUSES = array( 'active', 'partial', 'paused' );

	public static function enrich_plan( array $plan, array $context = array() ) {
		$plan        = self::normalize_plan( $plan );
		$balance     = max( 0, (float) ( $plan['balance'] ?? 0 ) );
		$direction   = sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) );
		$is_open     = in_array( sanitize_key( (string) ( $plan['status'] ?? '' ) ), self::OPEN_PLAN_STATUSES, true );
		$is_recovery = false;
		$backing_open_balance = 0.0;
		$planned_recovery_balance = 0.0;
		$additional_exposure_balance = 0.0;
		$presentation_bucket = 'inactive';

		if ( $is_open && $balance > 0 && 'receivable' === $direction ) {
			$classification        = self::resolve_exposure_metadata( $plan );
			$plan['exposure_kind'] = $classification['exposure_kind'];
			$plan['backing_source_type'] = $classification['backing_source_type'];
			$plan['backing_document_id'] = $classification['backing_document_id'];
			$plan['backing_debt_scope']  = $classification['backing_debt_scope'];
			$is_recovery           = 'recovery_existing_debt' === $classification['exposure_kind'];
			$backing_open_balance  = $is_recovery ? self::resolve_backing_open_balance( $plan, $context ) : 0.0;
			$planned_recovery_balance   = $is_recovery ? min( $balance, $backing_open_balance ) : 0.0;
			$additional_exposure_balance = $is_recovery ? max( 0, $balance - $planned_recovery_balance ) : $balance;
			$presentation_bucket        = self::resolve_presentation_bucket(
				$is_recovery,
				$planned_recovery_balance,
				$additional_exposure_balance
			);
		} else {
			$classification = self::resolve_exposure_metadata( $plan );
			$plan['exposure_kind'] = $classification['exposure_kind'];
			$plan['backing_source_type'] = $classification['backing_source_type'];
			$plan['backing_document_id'] = $classification['backing_document_id'];
			$plan['backing_debt_scope']  = $classification['backing_debt_scope'];
		}

		$plan['is_open_receivable']           = $is_open && 'receivable' === $direction && $balance > 0;
		$plan['is_recovery_plan']             = $is_recovery;
		$plan['backing_open_balance']         = round( $backing_open_balance, 6 );
		$plan['planned_recovery_balance']     = round( $planned_recovery_balance, 6 );
		$plan['additional_exposure_balance']  = round( $additional_exposure_balance, 6 );
		$plan['presentation_bucket']          = $presentation_bucket;
		$plan['counts_as_real_exposure']      = $additional_exposure_balance > 0.00001;
		$plan['counts_as_planned_recovery']   = $planned_recovery_balance > 0.00001;

		return $plan;
	}

	public static function summarize_receivable_plans( array $plans, array $context = array() ) {
		$summary = array(
			'balance_total'              => 0.0,
			'open_count'                 => 0,
			'planned_recovery_total'     => 0.0,
			'planned_recovery_count'     => 0,
			'additional_exposure_total'  => 0.0,
			'additional_exposure_count'  => 0,
			'standalone_total'           => 0.0,
			'standalone_count'           => 0,
			'recovery_excess_total'      => 0.0,
			'recovery_excess_count'      => 0,
			'orphaned_recovery_total'    => 0.0,
			'orphaned_recovery_count'    => 0,
			'enriched_items'             => array(),
		);

		foreach ( $plans as $plan ) {
			$enriched = self::enrich_plan( $plan, $context );
			$summary['enriched_items'][] = $enriched;

			if ( empty( $enriched['is_open_receivable'] ) ) {
				continue;
			}

			$balance      = max( 0, (float) ( $enriched['balance'] ?? 0 ) );
			$planned      = max( 0, (float) ( $enriched['planned_recovery_balance'] ?? 0 ) );
			$additional   = max( 0, (float) ( $enriched['additional_exposure_balance'] ?? 0 ) );
			$bucket       = sanitize_key( (string) ( $enriched['presentation_bucket'] ?? '' ) );
			$is_recovery  = ! empty( $enriched['is_recovery_plan'] );

			$summary['open_count']++;
			$summary['balance_total'] += $balance;

			if ( $planned > 0.00001 ) {
				$summary['planned_recovery_total'] += $planned;
				$summary['planned_recovery_count']++;
			}

			if ( $additional > 0.00001 ) {
				$summary['additional_exposure_total'] += $additional;
				$summary['additional_exposure_count']++;
			}

			if ( ! $is_recovery ) {
				$summary['standalone_total'] += $additional;
				if ( $additional > 0.00001 ) {
					$summary['standalone_count']++;
				}
				continue;
			}

			if ( 'recovery_with_excess' === $bucket ) {
				$summary['recovery_excess_total'] += $additional;
				$summary['recovery_excess_count']++;
			} elseif ( 'recovery_without_backing' === $bucket ) {
				$summary['orphaned_recovery_total'] += $additional;
				$summary['orphaned_recovery_count']++;
			}
		}

		foreach ( $summary as $key => $value ) {
			if ( is_float( $value ) ) {
				$summary[ $key ] = round( $value, 6 );
			}
		}

		return $summary;
	}

	public static function creation_metadata( array $data ) {
		$direction = self::normalize_direction(
			$data['settlement_direction'] ?? '',
			$data['commitment_origin'] ?? ''
		);
		$document_id = absint( $data['document_id'] ?? 0 );
		$origin      = sanitize_key( (string) ( $data['commitment_origin'] ?? '' ) );

		if ( 'receivable' !== $direction ) {
			return array(
				'exposure_kind'      => 'standalone_obligation',
				'backing_source_type'=> 'none',
				'backing_document_id'=> 0,
				'backing_debt_scope' => 'standalone',
			);
		}

		if ( $document_id > 0 ) {
			return array(
				'exposure_kind'      => 'recovery_existing_debt',
				'backing_source_type'=> 'document',
				'backing_document_id'=> $document_id,
				'backing_debt_scope' => 'single_document',
			);
		}

		if ( 'store_debt' === $origin ) {
			return array(
				'exposure_kind'      => 'recovery_existing_debt',
				'backing_source_type'=> 'orders',
				'backing_document_id'=> 0,
				'backing_debt_scope' => 'contact_open_orders',
			);
		}

		return array(
			'exposure_kind'      => 'standalone_obligation',
			'backing_source_type'=> 'none',
			'backing_document_id'=> 0,
			'backing_debt_scope' => 'standalone',
		);
	}

	private static function normalize_plan( array $plan ) {
		$meta = $plan['meta'] ?? null;
		if ( ! is_array( $meta ) ) {
			$decoded = json_decode( (string) ( $plan['meta_json'] ?? '' ), true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}

		$plan['meta']                 = $meta;
		$plan['commitment_origin']    = sanitize_key(
			(string) (
				$plan['commitment_origin']
				?? $meta['commitment_origin']
				?? ( 'loan' === sanitize_key( (string) ( $plan['plan_type'] ?? '' ) ) ? 'loan' : 'manual_charge' )
			)
		);
		$plan['settlement_direction'] = self::normalize_direction(
			$plan['settlement_direction'] ?? $meta['settlement_direction'] ?? '',
			$plan['commitment_origin']
		);
		$plan['document_id']          = absint( $plan['document_id'] ?? 0 );

		return $plan;
	}

	private static function resolve_exposure_metadata( array $plan ) {
		$meta                = is_array( $plan['meta'] ?? null ) ? $plan['meta'] : array();
		$explicit_kind       = sanitize_key( (string) ( $meta['exposure_kind'] ?? '' ) );
		$explicit_source     = sanitize_key( (string) ( $meta['backing_source_type'] ?? '' ) );
		$explicit_document   = absint( $meta['backing_document_id'] ?? 0 );
		$explicit_scope      = sanitize_key( (string) ( $meta['backing_debt_scope'] ?? '' ) );
		$document_id         = $explicit_document > 0 ? $explicit_document : absint( $plan['document_id'] ?? 0 );
		$origin              = sanitize_key( (string) ( $plan['commitment_origin'] ?? '' ) );
		$direction           = sanitize_key( (string) ( $plan['settlement_direction'] ?? 'receivable' ) );

		if ( 'receivable' !== $direction ) {
			return array(
				'exposure_kind'      => 'standalone_obligation',
				'backing_source_type'=> 'none',
				'backing_document_id'=> 0,
				'backing_debt_scope' => 'standalone',
			);
		}

		if ( in_array( $explicit_kind, array( 'recovery_existing_debt', 'standalone_obligation' ), true ) ) {
			return array(
				'exposure_kind'      => $explicit_kind,
				'backing_source_type'=> 'recovery_existing_debt' === $explicit_kind ? ( $explicit_source ?: ( $document_id > 0 ? 'document' : 'orders' ) ) : 'none',
				'backing_document_id'=> 'recovery_existing_debt' === $explicit_kind ? $document_id : 0,
				'backing_debt_scope' => $explicit_scope ?: ( $document_id > 0 ? 'single_document' : ( 'orders' === $explicit_source ? 'contact_open_orders' : 'standalone' ) ),
			);
		}

		if ( $document_id > 0 ) {
			return array(
				'exposure_kind'      => 'recovery_existing_debt',
				'backing_source_type'=> 'document',
				'backing_document_id'=> $document_id,
				'backing_debt_scope' => $explicit_scope ?: 'single_document',
			);
		}

		if ( 'store_debt' === $origin ) {
			return array(
				'exposure_kind'      => 'recovery_existing_debt',
				'backing_source_type'=> 'orders',
				'backing_document_id'=> 0,
				'backing_debt_scope' => $explicit_scope ?: 'contact_open_orders',
			);
		}

		return array(
			'exposure_kind'      => 'standalone_obligation',
			'backing_source_type'=> 'none',
			'backing_document_id'=> 0,
			'backing_debt_scope' => $explicit_scope ?: 'standalone',
		);
	}

	private static function resolve_backing_open_balance( array $plan, array $context ) {
		$source_type      = sanitize_key( (string) ( $plan['backing_source_type'] ?? '' ) );
		$backing_document = absint( $plan['backing_document_id'] ?? 0 );
		$contact_id       = absint( $plan['contact_id'] ?? 0 );

		if ( 'document' === $source_type && $backing_document > 0 ) {
			return max( 0, (float) ( $context['document_backing_totals'][ $backing_document ] ?? 0 ) );
		}

		if ( 'orders' === $source_type && $contact_id > 0 ) {
			return max( 0, (float) ( $context['order_backing_totals'][ $contact_id ] ?? 0 ) );
		}

		if ( 'documents' === $source_type && $contact_id > 0 ) {
			return max( 0, (float) ( $context['document_backing_totals_by_contact'][ $contact_id ] ?? 0 ) );
		}

		return 0.0;
	}

	private static function resolve_presentation_bucket( $is_recovery, $planned_balance, $additional_balance ) {
		if ( ! $is_recovery ) {
			return 'standalone';
		}

		if ( $planned_balance > 0.00001 && $additional_balance > 0.00001 ) {
			return 'recovery_with_excess';
		}

		if ( $planned_balance > 0.00001 ) {
			return 'planned_recovery';
		}

		if ( $additional_balance > 0.00001 ) {
			return 'recovery_without_backing';
		}

		return 'planned_recovery';
	}

	private static function normalize_direction( $direction, $origin = '' ) {
		$direction = sanitize_key( (string) $direction );
		if ( in_array( $direction, array( 'receivable', 'payable' ), true ) ) {
			return $direction;
		}

		return 'company_debt' === sanitize_key( (string) $origin ) ? 'payable' : 'receivable';
	}
}
