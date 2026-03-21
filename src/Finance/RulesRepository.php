<?php

namespace ASDLabs\Finance\Finance;

final class RulesRepository extends BaseRepository {
	protected $table_key = 'rules';

	public function all( $limit = 100 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY is_active DESC, priority ASC, id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_rule' ), $rows );
	}

	public function active( $limit = 200 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE is_active = 1
				ORDER BY priority ASC, id ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$rules = array_map( array( $this, 'hydrate_rule' ), $rows );

		usort(
			$rules,
			array( $this, 'compare_rules' )
		);

		return $rules;
	}

	public function find( $rule_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$rule_id = absint( $rule_id );
		if ( $rule_id <= 0 ) {
			return null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$rule_id
			),
			ARRAY_A
		);

		return empty( $row ) ? null : $this->hydrate_rule( $row );
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_rules_missing', 'La tabla de reglas aun no esta disponible.' );
		}

		$rule_name = sanitize_text_field( $data['rule_name'] ?? '' );
		if ( '' === $rule_name ) {
			return $this->error( 'asdl_fin_rule_name', 'Debes indicar un nombre para la regla.' );
		}

		$actions = $this->sanitize_actions( $data );
		if ( empty( array_filter( $actions ) ) ) {
			return $this->error( 'asdl_fin_rule_actions', 'La regla necesita al menos una accion de clasificacion.' );
		}

		$conditions  = $this->sanitize_conditions( $data );
		$scope_type  = sanitize_key( $data['scope_type'] ?? 'source' );
		$source_type = sanitize_key( $data['source_type'] ?? ( $conditions['source_type'] ?? '' ) );
		$wpdb        = $this->db();
		$now         = $this->now();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'rule_name'       => $rule_name,
				'priority'        => max( 1, (int) ( $data['priority'] ?? 100 ) ),
				'scope_type'      => $scope_type,
				'source_type'     => $source_type,
				'is_active'       => ! empty( $data['is_active'] ) ? 1 : 0,
				'conditions_json' => wp_json_encode( $conditions ),
				'actions_json'    => wp_json_encode( $actions ),
				'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_rule_insert', 'No se pudo guardar la regla.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function set_active( $rule_id, $is_active ) {
		if ( ! $this->has_table() ) {
			return false;
		}

		$result = $this->db()->update(
			$this->table(),
			array(
				'is_active'  => $is_active ? 1 : 0,
				'updated_at' => $this->now(),
			),
			array( 'id' => absint( $rule_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function first_match( array $context ) {
		foreach ( $this->active() as $rule ) {
			if ( $this->matches( $rule, $context ) ) {
				return $rule;
			}
		}

		return null;
	}

	private function hydrate_rule( array $row ) {
		$row['id']             = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['priority']       = isset( $row['priority'] ) ? (int) $row['priority'] : 100;
		$row['is_active']      = ! empty( $row['is_active'] );
		$row['conditions']     = $this->decode_json_array( $row['conditions_json'] ?? '' );
		$row['actions']        = $this->decode_json_array( $row['actions_json'] ?? '' );
		$row['scope_weight']   = $this->scope_weight( $row['scope_type'] ?? '' );

		return $row;
	}

	private function compare_rules( array $left, array $right ) {
		if ( $left['scope_weight'] === $right['scope_weight'] ) {
			if ( $left['priority'] === $right['priority'] ) {
				return $left['id'] <=> $right['id'];
			}

			return $left['priority'] <=> $right['priority'];
		}

		return $left['scope_weight'] <=> $right['scope_weight'];
	}

	private function matches( array $rule, array $context ) {
		$conditions = $rule['conditions'] ?? array();

		foreach ( $conditions as $key => $expected ) {
			if ( '' === $expected || null === $expected ) {
				continue;
			}

			$actual = $context[ $key ] ?? null;

			switch ( $key ) {
				case 'contact_id':
				case 'account_id':
				case 'wp_user_id':
					if ( absint( $actual ) !== absint( $expected ) ) {
						return false;
					}
					break;
				case 'external_reference_contains':
				case 'title_contains':
					$haystack = 'external_reference_contains' === $key ? (string) ( $context['external_reference'] ?? '' ) : (string) ( $context['title'] ?? '' );
					if ( false === stripos( $haystack, (string) $expected ) ) {
						return false;
					}
					break;
				default:
					if ( sanitize_key( (string) $actual ) !== sanitize_key( (string) $expected ) ) {
						return false;
					}
					break;
			}
		}

		return true;
	}

	private function sanitize_conditions( array $data ) {
		$conditions = array(
			'document_type'                => sanitize_key( $data['condition_document_type'] ?? $data['document_type_condition'] ?? '' ),
			'source_type'                  => sanitize_key( $data['condition_source_type'] ?? $data['source_type'] ?? '' ),
			'contact_type'                 => sanitize_key( $data['condition_contact_type'] ?? '' ),
			'contact_id'                   => ! empty( $data['condition_contact_id'] ) ? absint( $data['condition_contact_id'] ) : 0,
			'account_type'                 => sanitize_key( $data['condition_account_type'] ?? '' ),
			'account_id'                   => ! empty( $data['condition_account_id'] ) ? absint( $data['condition_account_id'] ) : 0,
			'category_key'                 => sanitize_key( $data['condition_category_key'] ?? '' ),
			'operational_status'           => sanitize_key( $data['condition_operational_status'] ?? '' ),
			'financial_intent'             => sanitize_key( $data['condition_financial_intent'] ?? '' ),
			'external_reference_contains'  => sanitize_text_field( $data['condition_external_reference_contains'] ?? '' ),
			'title_contains'               => sanitize_text_field( $data['condition_title_contains'] ?? '' ),
		);

		return array_filter(
			$conditions,
			static function ( $value ) {
				return ! ( '' === $value || 0 === $value || null === $value );
			}
		);
	}

	private function sanitize_actions( array $data ) {
		return array(
			'financial_intent' => sanitize_key( $data['action_financial_intent'] ?? $data['financial_intent'] ?? '' ),
			'balance_nature'   => sanitize_key( $data['action_balance_nature'] ?? $data['balance_nature'] ?? '' ),
			'category_key'     => sanitize_key( $data['action_category_key'] ?? $data['category_key'] ?? '' ),
			'subcategory_key'  => sanitize_key( $data['action_subcategory_key'] ?? $data['subcategory_key'] ?? '' ),
		);
	}

	private function decode_json_array( $json ) {
		$decoded = json_decode( (string) $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function scope_weight( $scope_type ) {
		switch ( sanitize_key( (string) $scope_type ) ) {
			case 'document':
			case 'source':
				return 10;
			case 'contact':
				return 20;
			case 'account':
				return 30;
			case 'category':
				return 40;
			default:
				return 50;
		}
	}
}
