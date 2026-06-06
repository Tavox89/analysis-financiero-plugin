<?php
declare(strict_types=1);

namespace {
	error_reporting( E_ALL );

	function remove_accents( $value ) {
		$value     = (string) $value;
		$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );

		return false !== $converted ? $converted : $value;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	final class SearchIndexWpdbStub {
		public function esc_like( $value ) {
			return addcslashes( (string) $value, '_%\\' );
		}
	}

	require_once dirname( __DIR__ ) . '/src/Finance/SearchIndexService.php';

	use ASDLabs\Finance\Finance\SearchIndexService;

	function assert_same( $expected, $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new \RuntimeException(
				$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
			);
		}
	}

	function assert_true( $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	assert_same(
		'jose daniel romero',
		SearchIndexService::normalize_text( 'José Daniel Romero' ),
		'Debe normalizar acentos y mayusculas.'
	);

	assert_same(
		array( 'jose', 'romero' ),
		SearchIndexService::tokenize( 'José,   Romero' ),
		'Debe tokenizar con limpieza de puntuacion y espacios.'
	);

	assert_true(
		SearchIndexService::matches_query( 'jose romero', 'José Daniel Romero' ),
		'La busqueda multi token debe matchear en cualquier orden.'
	);

	assert_true(
		SearchIndexService::matches_query( 'romero jose', 'José Daniel Romero' ),
		'La busqueda debe permitir orden invertido.'
	);

	assert_true(
		SearchIndexService::matches_query( 'miguel silva V20280609', 'Miguel Silva V20280609' ),
		'La busqueda debe conservar documentos y tokens alfanumericos.'
	);

	assert_true(
		SearchIndexService::matches_query( 'miguel@example.com', 'Miguel Silva miguel@example.com' ),
		'La busqueda debe encontrar correos al tokenizar separadores.'
	);

	$params = array();
	$sql    = SearchIndexService::build_token_like_clause(
		new SearchIndexWpdbStub(),
		array( 'd.search_index', 'c.search_index' ),
		'jose romero',
		$params
	);

	assert_same(
		'(d.search_index LIKE %s OR c.search_index LIKE %s) AND (d.search_index LIKE %s OR c.search_index LIKE %s)',
		$sql,
		'La clausula SQL debe aplicar OR por columnas y AND por token.'
	);

	assert_same(
		array( '%jose%', '%jose%', '%romero%', '%romero%' ),
		$params,
		'Los parametros SQL deben generarse por token y por columna.'
	);

	assert_same(
		123,
		SearchIndexService::numeric_identifier_value( '123' ),
		'Debe conservar el atajo numerico exacto.'
	);

	assert_same(
		null,
		SearchIndexService::numeric_identifier_value( 'abc123' ),
		'No debe tratar alfanumericos como IDs exactos.'
	);

	echo "search-index-regression: OK\n";
}
