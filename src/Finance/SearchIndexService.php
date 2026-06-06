<?php

namespace ASDLabs\Finance\Finance;

final class SearchIndexService {
	public static function normalize_text( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}

		$value = strtr(
			$value,
			array(
				'Á' => 'A', 'À' => 'A', 'Ä' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
				'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
				'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E', 'Ē' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ę' => 'E', 'Ě' => 'E',
				'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
				'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I', 'Ĩ' => 'I', 'Ī' => 'I', 'Ĭ' => 'I', 'Į' => 'I', 'İ' => 'I',
				'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
				'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ō' => 'O', 'Ŏ' => 'O', 'Ő' => 'O',
				'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
				'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U', 'Ũ' => 'U', 'Ū' => 'U', 'Ŭ' => 'U', 'Ů' => 'U', 'Ű' => 'U', 'Ų' => 'U',
				'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
				'Ñ' => 'N', 'ñ' => 'n',
				'Ç' => 'C', 'ç' => 'c',
				'Ý' => 'Y', 'Ÿ' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
			)
		);

		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_D );
			if ( is_string( $normalized ) && '' !== $normalized ) {
				$value = $normalized;
			}
		}

		$value = preg_replace( '/[\x{0300}-\x{036f}]+/u', '', $value );

		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
			if ( false !== $converted ) {
				$value = $converted;
			}
		}

		$value = str_replace( array( "'", '`', '´', '’' ), '', $value );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}

	public static function tokenize( $value ) {
		$normalized = self::normalize_text( $value );
		if ( '' === $normalized ) {
			return array();
		}

		$tokens = preg_split( '/\s+/', $normalized );
		if ( ! is_array( $tokens ) ) {
			return array();
		}

		$tokens = array_values(
			array_filter(
				array_unique(
					array_map(
						static function ( $token ) {
							return trim( (string) $token );
						},
						$tokens
					)
				),
				static function ( $token ) {
					return '' !== $token;
				}
			)
		);

		return $tokens;
	}

	public static function build_index( array $parts ) {
		$tokens = array();

		foreach ( $parts as $part ) {
			$tokens = array_merge( $tokens, self::tokenize( $part ) );
		}

		if ( empty( $tokens ) ) {
			return '';
		}

		return implode( ' ', array_values( array_unique( $tokens ) ) );
	}

	public static function matches_query( $query, $haystack ) {
		$tokens = self::tokenize( $query );
		if ( empty( $tokens ) ) {
			return true;
		}

		$normalized_haystack = self::normalize_text( $haystack );
		if ( '' === $normalized_haystack ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			if ( false === strpos( $normalized_haystack, $token ) ) {
				return false;
			}
		}

		return true;
	}

	public static function numeric_identifier_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^\d+$/', $value ) ) {
			return null;
		}

		$numeric = function_exists( 'absint' ) ? absint( $value ) : abs( (int) $value );
		return $numeric > 0 ? $numeric : null;
	}

	public static function build_token_like_clause( $wpdb, array $columns, $search, array &$params ) {
		$columns = array_values(
			array_filter(
				array_map(
					static function ( $column ) {
						return trim( (string) $column );
					},
					$columns
				),
				static function ( $column ) {
					return '' !== $column;
				}
			)
		);
		$tokens  = self::tokenize( $search );

		if ( empty( $columns ) || empty( $tokens ) ) {
			return '';
		}

		$clauses = array();

		foreach ( $tokens as $token ) {
			$token_clauses = array();
			$like          = '%' . self::escape_like( $wpdb, $token ) . '%';

			foreach ( $columns as $column ) {
				$token_clauses[] = "{$column} LIKE %s";
				$params[]        = $like;
			}

			$clauses[] = '(' . implode( ' OR ', $token_clauses ) . ')';
		}

		return implode( ' AND ', $clauses );
	}

	private static function escape_like( $wpdb, $value ) {
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'esc_like' ) ) {
			return $wpdb->esc_like( $value );
		}

		return addcslashes( (string) $value, '_%\\' );
	}
}
