<?php

namespace ASDLabs\Finance\Finance;

final class RuntimeRefreshService {
	const SCOPE_CONTACT             = 'contact';
	const SCOPE_DASHBOARD_SUMMARY   = 'dashboard_summary';
	const SCOPE_DASHBOARD_RECEIVABLES = 'dashboard_receivables';
	const SCOPE_DASHBOARD_PAYABLES  = 'dashboard_payables';
	const SCOPE_PAYROLL             = 'payroll';
	const SCOPE_HISTORICAL_DATA     = 'historical_data';

	public static function invalidate( array $scopes, array $context = array() ) {
		$contact_id = absint( $context['contact_id'] ?? 0 );
		$scopes     = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $scopes )
				)
			)
		);

		if ( empty( $scopes ) ) {
			return;
		}

		$historical = null;

		foreach ( $scopes as $scope ) {
			switch ( $scope ) {
				case self::SCOPE_CONTACT:
					if ( $contact_id > 0 ) {
						ContactOverviewService::bump_contact_snapshot_cache_version( $contact_id );
					}
					break;

				case self::SCOPE_DASHBOARD_SUMMARY:
					OverviewService::bump_cache_version();
					break;

				case self::SCOPE_DASHBOARD_RECEIVABLES:
				case self::SCOPE_HISTORICAL_DATA:
					if ( ! $historical ) {
						$historical = new HistoricalIndexRebuildService();
					}
					$historical->bump_data_version();
					break;

				case self::SCOPE_DASHBOARD_PAYABLES:
					PendingPayablesService::bump_cache_version();
					break;

				case self::SCOPE_PAYROLL:
					PayrollQueueService::bump_cache_version();
					break;
			}
		}
	}

	public static function build_runtime_refresh( array $args = array() ) {
		return array(
			'page_keys'       => self::normalize_tokens( $args['page_keys'] ?? array() ),
			'groups'          => self::normalize_tokens( $args['groups'] ?? array() ),
			'sections'        => self::normalize_tokens( $args['sections'] ?? array() ),
			'contact_id'      => absint( $args['contact_id'] ?? 0 ),
			'fallback_reload' => ! empty( $args['fallback_reload'] ),
		);
	}

	public static function merge_runtime_refreshes( ...$plans ) {
		$merged = array(
			'page_keys'       => array(),
			'groups'          => array(),
			'sections'        => array(),
			'contact_id'      => 0,
			'fallback_reload' => false,
		);

		foreach ( $plans as $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}

			$normalized = self::build_runtime_refresh( $plan );
			$merged['page_keys']       = array_values( array_unique( array_merge( $merged['page_keys'], $normalized['page_keys'] ) ) );
			$merged['groups']          = array_values( array_unique( array_merge( $merged['groups'], $normalized['groups'] ) ) );
			$merged['sections']        = array_values( array_unique( array_merge( $merged['sections'], $normalized['sections'] ) ) );
			$merged['contact_id']      = $merged['contact_id'] > 0 ? $merged['contact_id'] : $normalized['contact_id'];
			$merged['fallback_reload'] = $merged['fallback_reload'] || ! empty( $normalized['fallback_reload'] );
		}

		return $merged;
	}

	public static function build_profile_refresh( $contact_id, array $sections = array(), array $args = array() ) {
		if ( empty( $sections ) ) {
			$sections = array(
				'profile-financial-cards',
				'profile-orders-summary',
				'profile-orders-table',
				'profile-account-state',
				'profile-payments',
				'profile-history',
			);
		}

		return self::build_runtime_refresh(
			array_merge(
				$args,
				array(
					'page_keys'  => array( 'contacts' ),
					'groups'     => array( 'contacts-detail' ),
					'sections'   => $sections,
					'contact_id' => absint( $contact_id ),
				)
			)
		);
	}

	public static function build_dashboard_refresh( array $groups = array(), array $args = array() ) {
		if ( empty( $groups ) ) {
			$groups = array( 'dashboard-summary' );
		}

		return self::build_runtime_refresh(
			array_merge(
				$args,
				array(
					'page_keys' => array( 'dashboard' ),
					'groups'    => $groups,
				)
			)
		);
	}

	public static function build_payroll_refresh( $contact_id = 0, array $args = array() ) {
		$refresh = self::merge_runtime_refreshes(
			self::build_runtime_refresh(
				array_merge(
					$args,
					array(
						'page_keys'  => array( 'payroll' ),
						'groups'     => array( 'payroll' ),
						'contact_id' => absint( $contact_id ),
					)
				)
			),
			self::build_dashboard_refresh(
				array( 'dashboard-summary' ),
				$args
			)
		);

		if ( $contact_id > 0 ) {
			$refresh = self::merge_runtime_refreshes(
				$refresh,
				self::build_profile_refresh(
					$contact_id,
					array(
						'profile-financial-cards',
						'profile-account-state',
						'profile-payroll',
					)
				)
			);
		}

		return $refresh;
	}

	public static function build_services_refresh( $contact_id = 0, array $args = array() ) {
		$refresh = self::merge_runtime_refreshes(
			self::build_runtime_refresh(
				array_merge(
					$args,
					array(
						'page_keys'  => array( 'services' ),
						'groups'     => array( 'services' ),
						'contact_id' => absint( $contact_id ),
					)
				)
			),
			self::build_dashboard_refresh(
				array( 'dashboard-summary', 'dashboard-payables' ),
				$args
			)
		);

		if ( $contact_id > 0 ) {
			$refresh = self::merge_runtime_refreshes(
				$refresh,
				self::build_profile_refresh(
					$contact_id,
					array(
						'profile-financial-cards',
						'profile-account-state',
						'profile-payments',
						'profile-history',
					)
				)
			);
		}

		return $refresh;
	}

	private static function normalize_tokens( $values ) {
		if ( ! is_array( $values ) ) {
			$values = array( $values );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_key( (string) $value );
						},
						$values
					)
				)
			)
		);
	}
}
