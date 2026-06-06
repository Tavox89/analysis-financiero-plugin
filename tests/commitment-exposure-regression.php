<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	function absint( $value ) {
		return abs( (int) $value );
	}

	function remove_accents( $value ) {
		$value     = (string) $value;
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );

		return false !== $converted ? $converted : $value;
	}

	function sanitize_key( $value ) {
		$value = strtolower( remove_accents( (string) $value ) );
		$value = preg_replace( '/[^a-z0-9_:-]/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	require_once dirname( __DIR__ ) . '/src/Finance/CommitmentExposureService.php';

	use ASDLabs\Finance\Finance\CommitmentExposureService;

	function assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	function assert_float_same( float $expected, $actual, string $message ): void {
		$actual = (float) $actual;
		if ( abs( $expected - $actual ) > 0.00001 ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	$recovery_plan = CommitmentExposureService::enrich_plan(
		array(
			'id'                   => 101,
			'contact_id'           => 24,
			'status'               => 'active',
			'balance'              => 242.29,
			'commitment_origin'    => 'store_debt',
			'settlement_direction' => 'receivable',
			'document_id'          => 0,
			'title'                => 'Recuperacion de tienda',
		),
		array(
			'order_backing_totals' => array(
				24 => 257.29,
			),
		)
	);

	assert_same( true, $recovery_plan['is_recovery_plan'], 'store_debt debe inferirse como recovery plan.' );
	assert_float_same( 242.29, $recovery_plan['planned_recovery_balance'], 'La deuda ya reconocida debe quedar como planificada.' );
	assert_float_same( 0.0, $recovery_plan['additional_exposure_balance'], 'No debe crear exposicion adicional cuando la deuda base sigue abierta.' );
	assert_same( 'planned_recovery', $recovery_plan['presentation_bucket'], 'Debe quedar marcado como deuda ya planificada.' );

	$standalone_plan = CommitmentExposureService::enrich_plan(
		array(
			'id'                   => 102,
			'contact_id'           => 24,
			'status'               => 'active',
			'balance'              => 80.00,
			'commitment_origin'    => 'loan',
			'settlement_direction' => 'receivable',
			'document_id'          => 0,
			'title'                => 'Prestamo independiente',
		)
	);

	assert_same( false, $standalone_plan['is_recovery_plan'], 'Un prestamo independiente no debe tratarse como recovery plan.' );
	assert_float_same( 0.0, $standalone_plan['planned_recovery_balance'], 'El prestamo independiente no tiene deuda respaldada ya reconocida.' );
	assert_float_same( 80.0, $standalone_plan['additional_exposure_balance'], 'El prestamo independiente si suma como exposicion nueva.' );
	assert_same( 'standalone', $standalone_plan['presentation_bucket'], 'Debe quedar como obligacion independiente.' );

	$excess_plan = CommitmentExposureService::enrich_plan(
		array(
			'id'                   => 103,
			'contact_id'           => 24,
			'status'               => 'active',
			'balance'              => 300.00,
			'commitment_origin'    => 'manual_charge',
			'settlement_direction' => 'receivable',
			'document_id'          => 501,
			'title'                => 'Compromiso con exceso',
		),
		array(
			'document_backing_totals' => array(
				501 => 250.00,
			),
		)
	);

	assert_same( true, $excess_plan['is_recovery_plan'], 'El compromiso ligado a documento debe ser recovery plan.' );
	assert_float_same( 250.0, $excess_plan['planned_recovery_balance'], 'Solo la parte respaldada debe quedar como deuda planificada.' );
	assert_float_same( 50.0, $excess_plan['additional_exposure_balance'], 'El excedente si debe sumarse como exposicion adicional.' );
	assert_same( 'recovery_with_excess', $excess_plan['presentation_bucket'], 'El bucket debe distinguir el excedente real.' );

	$orphaned_plan = CommitmentExposureService::enrich_plan(
		array(
			'id'                   => 104,
			'contact_id'           => 24,
			'status'               => 'active',
			'balance'              => 60.00,
			'commitment_origin'    => 'manual_charge',
			'settlement_direction' => 'receivable',
			'document_id'          => 502,
			'title'                => 'Compromiso sin deuda base activa',
		),
		array(
			'document_backing_totals' => array(),
		)
	);

	assert_same( true, $orphaned_plan['is_recovery_plan'], 'El compromiso documentado conserva su origen de recovery.' );
	assert_float_same( 0.0, $orphaned_plan['planned_recovery_balance'], 'Sin deuda base abierta ya no hay saldo planificado.' );
	assert_float_same( 60.0, $orphaned_plan['additional_exposure_balance'], 'Si ya no existe la deuda base, el compromiso pasa a ser la fuente activa del saldo.' );
	assert_same( 'recovery_without_backing', $orphaned_plan['presentation_bucket'], 'Debe quedar visible como recovery sin respaldo abierto.' );

	$summary = CommitmentExposureService::summarize_receivable_plans(
		array(
			array(
				'id'                   => 101,
				'contact_id'           => 24,
				'status'               => 'active',
				'balance'              => 242.29,
				'commitment_origin'    => 'store_debt',
				'settlement_direction' => 'receivable',
				'document_id'          => 0,
			),
			array(
				'id'                   => 102,
				'contact_id'           => 24,
				'status'               => 'active',
				'balance'              => 80.00,
				'commitment_origin'    => 'loan',
				'settlement_direction' => 'receivable',
				'document_id'          => 0,
			),
			array(
				'id'                   => 103,
				'contact_id'           => 24,
				'status'               => 'active',
				'balance'              => 300.00,
				'commitment_origin'    => 'manual_charge',
				'settlement_direction' => 'receivable',
				'document_id'          => 501,
			),
			array(
				'id'                   => 104,
				'contact_id'           => 24,
				'status'               => 'active',
				'balance'              => 60.00,
				'commitment_origin'    => 'manual_charge',
				'settlement_direction' => 'receivable',
				'document_id'          => 502,
			),
		),
		array(
			'order_backing_totals'    => array(
				24 => 257.29,
			),
			'document_backing_totals' => array(
				501 => 250.00,
			),
		)
	);

	assert_float_same( 682.29, $summary['balance_total'], 'El saldo total de compromisos abiertos debe sumar todos los planes receivable abiertos.' );
	assert_float_same( 492.29, $summary['planned_recovery_total'], 'La deuda ya planificada debe sumar solo la parte respaldada por deuda abierta.' );
	assert_float_same( 190.0, $summary['additional_exposure_total'], 'La exposicion real adicional debe sumar standalone, excedentes y huérfanos.' );
	assert_same( 1, $summary['standalone_count'], 'Debe contar el compromiso independiente.' );
	assert_same( 1, $summary['recovery_excess_count'], 'Debe contar el recovery con excedente.' );
	assert_same( 1, $summary['orphaned_recovery_count'], 'Debe contar el recovery sin deuda base abierta.' );

	$document_meta = CommitmentExposureService::creation_metadata(
		array(
			'document_id'          => 999,
			'commitment_origin'    => 'manual_charge',
			'settlement_direction' => 'receivable',
		)
	);
	assert_same( 'recovery_existing_debt', $document_meta['exposure_kind'], 'Los compromisos nuevos ligados a documento deben guardarse como recovery.' );
	assert_same( 'document', $document_meta['backing_source_type'], 'La fuente de respaldo debe guardarse como documento.' );

	$store_meta = CommitmentExposureService::creation_metadata(
		array(
			'document_id'          => 0,
			'commitment_origin'    => 'store_debt',
			'settlement_direction' => 'receivable',
		)
	);
	assert_same( 'orders', $store_meta['backing_source_type'], 'store_debt nuevo debe quedar ligado a pedidos abiertos del perfil.' );

	echo "commitment-exposure-regression: OK\n";
}
