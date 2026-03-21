<?php

namespace ASDLabs\Finance\Finance;

final class FiscalYearService {
	const OPTION_KEY = 'asdl_fin_fiscal_year_settings';

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$start_month = max( 1, min( 12, absint( $saved['start_month'] ?? 1 ) ) );
		$max_day     = cal_days_in_month( CAL_GREGORIAN, $start_month, (int) gmdate( 'Y' ) );
		$start_day   = max( 1, min( $max_day, absint( $saved['start_day'] ?? 1 ) ) );
		$active_year = absint( $saved['active_start_year'] ?? 0 );

		if ( $active_year <= 0 ) {
			$active_year = $this->resolve_start_year_from_date( gmdate( 'Y-m-d' ), $start_month, $start_day );
		}

		return array(
			'start_month'       => $start_month,
			'start_day'         => $start_day,
			'active_start_year' => $active_year,
		);
	}

	public function save( array $data ) {
		$current     = $this->get_settings();
		$start_month = max( 1, min( 12, absint( $data['start_month'] ?? $current['start_month'] ) ) );
		$year_for_day = max( 2000, absint( $data['active_start_year'] ?? $current['active_start_year'] ) );
		$max_day     = cal_days_in_month( CAL_GREGORIAN, $start_month, $year_for_day );
		$start_day   = max( 1, min( $max_day, absint( $data['start_day'] ?? $current['start_day'] ) ) );
		$active_year = max( 2000, absint( $data['active_start_year'] ?? $current['active_start_year'] ) );

		$payload = array(
			'start_month'       => $start_month,
			'start_day'         => $start_day,
			'active_start_year' => $active_year,
		);

		update_option( self::OPTION_KEY, $payload, false );

		return $payload;
	}

	public function get_context( $selected_start_year = 0 ) {
		$settings           = $this->get_settings();
		$active_start_year  = (int) $settings['active_start_year'];
		$selected_start_year = absint( $selected_start_year ) > 0 ? absint( $selected_start_year ) : $active_start_year;

		$start_date = sprintf(
			'%04d-%02d-%02d',
			$selected_start_year,
			(int) $settings['start_month'],
			(int) $settings['start_day']
		);
		$end_date = gmdate( 'Y-m-d', strtotime( '+1 year -1 day', strtotime( $start_date ) ) );

		return array(
			'start_year'   => $selected_start_year,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'label'        => $this->label_for_year( $selected_start_year ),
			'is_active'    => $selected_start_year === $active_start_year,
			'settings'     => $settings,
			'active_label' => $this->label_for_year( $active_start_year ),
		);
	}

	public function available_years( $lookback = 5, $lookahead = 1 ) {
		$settings    = $this->get_settings();
		$active_year = (int) $settings['active_start_year'];
		$years       = array();

		for ( $year = $active_year + max( 0, (int) $lookahead ); $year >= $active_year - max( 0, (int) $lookback ); --$year ) {
			$years[ $year ] = $this->label_for_year( $year );
		}

		return $years;
	}

	public function label_for_year( $start_year ) {
		$start_year = absint( $start_year );

		return sprintf( 'FY %1$d-%2$d', $start_year, $start_year + 1 );
	}

	public function resolve_start_year_from_date( $date, $start_month = null, $start_day = null ) {
		$date_ts = strtotime( (string) $date );
		if ( ! $date_ts ) {
			$date_ts = time();
		}

		if ( null === $start_month || null === $start_day ) {
			$settings    = $this->get_settings();
			$start_month = null !== $start_month ? $start_month : (int) $settings['start_month'];
			$start_day   = null !== $start_day ? $start_day : (int) $settings['start_day'];
		}

		$start_month = max( 1, min( 12, absint( $start_month ) ) );
		$start_day   = max( 1, min( 31, absint( $start_day ) ) );
		$year        = (int) gmdate( 'Y', $date_ts );
		$boundary    = strtotime( sprintf( '%04d-%02d-%02d', $year, $start_month, $start_day ) );

		if ( false === $boundary ) {
			return $year;
		}

		return $date_ts < $boundary ? $year - 1 : $year;
	}
}
