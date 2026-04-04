<?php

namespace ASDLabs\Finance\Finance;

use ASDLabs\Finance\Core\Tables;

final class ContactsRepository extends BaseRepository {
	protected $table_key = 'contacts';
	protected $contact_cache_by_id = array();
	protected $contact_cache_by_wp_user_id = array();
	protected $contact_cache_by_email = array();

	public function all( $limit = 50 ) {
		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb  = $this->db();
		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				ORDER BY id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_profile' ), $rows );
	}

	public function search( $term, $limit = 100 ) {
		$term = sanitize_text_field( (string) $term );
		if ( '' === $term ) {
			return $this->all( $limit );
		}

		if ( ! $this->has_table() ) {
			return array();
		}

		$wpdb    = $this->db();
		$limit   = max( 1, (int) $limit );
		$like    = '%' . $wpdb->esc_like( $term ) . '%';
		$numeric = absint( $term );
		$has_id  = $numeric > 0 && (string) $numeric === preg_replace( '/\D+/', '', $term );
		$query   = "
			SELECT *
			FROM {$this->table()}
			WHERE (
				display_name LIKE %s
				OR legal_name LIKE %s
				OR email LIKE %s
				OR phone LIKE %s
				OR document_id LIKE %s
			)";
		$params  = array( $like, $like, $like, $like, $like );

		if ( $has_id ) {
			$query   .= ' OR id = %d OR wp_user_id = %d';
			$params[] = $numeric;
			$params[] = $numeric;
		}

		$query   .= ' ORDER BY id DESC LIMIT %d';
		$params[] = $limit;

		$rows = $wpdb->get_results(
			$wpdb->prepare( $query, ...$params ),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate_profile' ), $rows );
	}

	public function list_for_admin( $term = '', $limit = 100 ) {
		$contacts = '' !== trim( (string) $term )
			? $this->search( $term, $limit )
			: $this->all( $limit );

		return $this->append_finance_state( $contacts );
	}

	public function list_for_api( array $args = array() ) {
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = ( $page - 1 ) * $limit;
		$search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$origin = sanitize_key( (string) ( $args['profile_origin'] ?? $args['origin'] ?? '' ) );
		$role   = sanitize_key( (string) ( $args['role'] ?? '' ) );

		if ( ! $this->has_table() ) {
			return array(
				'items' => array(),
				'meta'  => array(
					'count'       => 0,
					'total'       => 0,
					'page'        => $page,
					'limit'       => $limit,
					'total_pages' => 0,
					'filters'     => array(
						'search'         => $search,
						'status'         => $status,
						'profile_origin' => $origin,
						'role'           => $role,
						'is_customer'    => $this->normalize_bool_filter( $args['is_customer'] ?? null ),
						'is_employee'    => $this->normalize_bool_filter( $args['is_employee'] ?? null ),
						'is_supplier'    => $this->normalize_bool_filter( $args['is_supplier'] ?? null ),
					),
				),
			);
		}

		$wpdb    = $this->db();
		$where   = array( '1=1' );
		$params  = array();
		$filters = array(
			'search'         => $search,
			'status'         => $status,
			'profile_origin' => $origin,
			'role'           => $role,
			'is_customer'    => $this->normalize_bool_filter( $args['is_customer'] ?? null ),
			'is_employee'    => $this->normalize_bool_filter( $args['is_employee'] ?? null ),
			'is_supplier'    => $this->normalize_bool_filter( $args['is_supplier'] ?? null ),
		);

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$numeric = absint( $search );
			$has_id  = $numeric > 0 && (string) $numeric === preg_replace( '/\D+/', '', $search );
			$sql     = '(
				display_name LIKE %s
				OR legal_name LIKE %s
				OR email LIKE %s
				OR phone LIKE %s
				OR document_id LIKE %s';
			$sql_params = array( $like, $like, $like, $like, $like );

			if ( $has_id ) {
				$sql         .= ' OR id = %d OR wp_user_id = %d';
				$sql_params[] = $numeric;
				$sql_params[] = $numeric;
			}

			$sql    .= ')';
			$where[] = $sql;
			$params  = array_merge( $params, $sql_params );
		}

		if ( '' !== $status ) {
			$where[] = 'status = %s';
			$params[] = $status;
		}

		if ( '' !== $origin ) {
			$where[] = 'profile_origin = %s';
			$params[] = $origin;
		}

		if ( in_array( $role, array( 'customer', 'employee', 'supplier' ), true ) ) {
			$where[] = 'is_' . $role . ' = 1';
		}

		foreach ( array( 'customer', 'employee', 'supplier' ) as $flag_name ) {
			$value = $filters[ 'is_' . $flag_name ];
			if ( null === $value ) {
				continue;
			}

			$where[] = 'is_' . $flag_name . ' = %d';
			$params[] = $value;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where_sql}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE {$where_sql}
				ORDER BY id DESC
				LIMIT %d OFFSET %d",
				...array_merge( $params, array( $limit, $offset ) )
			),
			ARRAY_A
		);
		$items     = $this->append_finance_state( array_map( array( $this, 'hydrate_profile' ), $rows ) );

		return array(
			'items' => $items,
			'meta'  => array(
				'count'       => count( $items ),
				'total'       => $total,
				'page'        => $page,
				'limit'       => $limit,
				'total_pages' => $total > 0 ? (int) ceil( $total / $limit ) : 0,
				'filters'     => $filters,
			),
		);
	}

	public function options() {
		$options = array();

		foreach ( $this->all( 200 ) as $contact ) {
			$options[ (int) $contact['id'] ] = $this->option_label( $contact );
		}

		return $options;
	}

	public function find( $contact_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $contact_id, $this->contact_cache_by_id ) ) {
			return $this->contact_cache_by_id[ $contact_id ] ?: null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE id = %d
				LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$this->contact_cache_by_id[ $contact_id ] = false;
			return null;
		}

		return $this->prime_contact_cache( $this->hydrate_profile( $row ) );
	}

	public function find_by_wp_user_id( $wp_user_id ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$wp_user_id = absint( $wp_user_id );
		if ( $wp_user_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $wp_user_id, $this->contact_cache_by_wp_user_id ) ) {
			return $this->contact_cache_by_wp_user_id[ $wp_user_id ] ?: null;
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				WHERE wp_user_id = %d
				ORDER BY id DESC
				LIMIT 1",
				$wp_user_id
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$this->contact_cache_by_wp_user_id[ $wp_user_id ] = false;
			return null;
		}

		return $this->prime_contact_cache( $this->hydrate_profile( $row ) );
	}

	public function find_by_email( $email, $only_unlinked = false ) {
		if ( ! $this->has_table() ) {
			return null;
		}

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return null;
		}

		$cache_key = ( $only_unlinked ? 'unlinked:' : 'all:' ) . $email;
		if ( array_key_exists( $cache_key, $this->contact_cache_by_email ) ) {
			return $this->contact_cache_by_email[ $cache_key ] ?: null;
		}

		$where_sql = 'WHERE email = %s';
		if ( $only_unlinked ) {
			$where_sql .= ' AND (wp_user_id IS NULL OR wp_user_id = 0)';
		}

		$row = $this->db()->get_row(
			$this->db()->prepare(
				"SELECT *
				FROM {$this->table()}
				{$where_sql}
				ORDER BY id DESC
				LIMIT 1",
				$email
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			$this->contact_cache_by_email[ $cache_key ] = false;
			return null;
		}

		return $this->prime_contact_cache( $this->hydrate_profile( $row ), $cache_key );
	}

	private function prime_contact_cache( array $contact, $email_cache_key = '' ) {
		$contact_id = absint( $contact['id'] ?? 0 );
		$wp_user_id = absint( $contact['wp_user_id'] ?? 0 );
		$email      = sanitize_email( (string) ( $contact['email'] ?? '' ) );

		if ( $contact_id > 0 ) {
			$this->contact_cache_by_id[ $contact_id ] = $contact;
		}

		if ( $wp_user_id > 0 ) {
			$this->contact_cache_by_wp_user_id[ $wp_user_id ] = $contact;
		}

		if ( '' !== $email ) {
			$this->contact_cache_by_email[ 'all:' . $email ] = $contact;
			if ( '' !== $email_cache_key ) {
				$this->contact_cache_by_email[ $email_cache_key ] = $contact;
			}
		}

		return $contact;
	}

	public function find_or_create_from_wp_user( $wp_user_id, $contact_type = 'mixed' ) {
		$result = $this->link_existing_wp_user(
			$wp_user_id,
			$this->flags_from_hint( $contact_type )
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) ( $result['contact_id'] ?? 0 );
	}

	public function link_existing_wp_user( $wp_user_id, array $role_flags = array() ) {
		$wp_user_id = absint( $wp_user_id );
		if ( $wp_user_id <= 0 ) {
			return $this->error( 'asdl_fin_wp_user_missing', 'Debes seleccionar un usuario valido.' );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			return $this->error( 'asdl_fin_wp_user_missing', 'No se encontro el usuario seleccionado.' );
		}

		$existing_flags = $this->flags_from_wp_roles( $user->roles );
		$flags          = $this->merge_flags( $existing_flags, $role_flags );
		$existing       = $this->find_by_wp_user_id( $wp_user_id );

		if ( ! empty( $existing['id'] ) ) {
			$this->persist_update(
				(int) $existing['id'],
				$this->normalize_profile_payload(
					array(
						'display_name'   => $user->display_name,
						'email'          => $user->user_email,
						'wp_user_id'     => $wp_user_id,
					'profile_origin' => 'wp_user',
					'is_customer'    => $flags['is_customer'],
					'is_employee'    => $flags['is_employee'],
					'is_supplier'    => $flags['is_supplier'],
					'internal_use_profile' => ! empty( $role_flags['internal_use_profile'] ) ? 1 : 0,
					'supplier_kind'  => $role_flags['supplier_kind'] ?? ( $existing['supplier_kind'] ?? '' ),
					'status'         => $existing['status'] ?? 'active',
				),
					$existing
				)
			);

			return array(
				'contact_id'       => (int) $existing['id'],
				'wp_user_id'       => $wp_user_id,
				'username'         => (string) $user->user_login,
				'created_profile'  => false,
				'linked_existing'  => true,
				'created_user'     => false,
			);
		}

		$email_match = '' !== $user->user_email ? $this->find_by_email( $user->user_email, true ) : null;
		if ( ! empty( $email_match['id'] ) ) {
			$updated = $this->link_profile_to_wp_user( (int) $email_match['id'], $wp_user_id, $flags );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			return array(
				'contact_id'       => (int) $email_match['id'],
				'wp_user_id'       => $wp_user_id,
				'username'         => (string) $user->user_login,
				'created_profile'  => false,
				'linked_existing'  => true,
				'created_user'     => false,
			);
		}

		$result = $this->create(
			array(
				'display_name'   => $user->display_name,
				'legal_name'     => '',
				'wp_user_id'     => $wp_user_id,
				'profile_origin' => 'wp_user',
				'is_customer'    => $flags['is_customer'],
				'is_employee'    => $flags['is_employee'],
				'is_supplier'    => $flags['is_supplier'],
				'internal_use_profile' => ! empty( $role_flags['internal_use_profile'] ) ? 1 : 0,
				'supplier_kind'  => $role_flags['supplier_kind'] ?? '',
				'email'          => $user->user_email,
				'phone'          => '',
				'document_id'    => '',
				'status'         => 'active',
				'notes'          => 'Perfil creado automaticamente a partir de un usuario de WordPress.',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'contact_id'       => (int) $result,
			'wp_user_id'       => $wp_user_id,
			'username'         => (string) $user->user_login,
			'created_profile'  => true,
			'linked_existing'  => false,
			'created_user'     => false,
		);
	}

	public function promote_external_to_wp_user( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		$contact = $this->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		if ( ! empty( $contact['wp_user_id'] ) ) {
			return $this->error( 'asdl_fin_profile_has_user', 'Este perfil ya esta vinculado a un usuario de WordPress.' );
		}

		$email = sanitize_email( $contact['email'] ?? '' );
		if ( '' === $email ) {
			return $this->error( 'asdl_fin_profile_email_required', 'Debes registrar un correo para convertir este perfil en usuario.' );
		}

		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user ) {
			$link = $this->link_profile_to_wp_user(
				$contact_id,
				(int) $existing_user->ID,
				array(
					'is_customer' => true,
				)
			);

			if ( is_wp_error( $link ) ) {
				return $link;
			}

			return array(
				'contact_id'       => $contact_id,
				'wp_user_id'       => (int) $existing_user->ID,
				'username'         => (string) $existing_user->user_login,
				'created_profile'  => false,
				'linked_existing'  => true,
				'created_user'     => false,
			);
		}

		$username = $this->generate_unique_username( $email, $contact['display_name'] ?? '' );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 18, true, true ),
				'user_email'   => $email,
				'display_name' => sanitize_text_field( $contact['display_name'] ?? '' ),
				'role'         => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$linked = $this->link_profile_to_wp_user(
			$contact_id,
			(int) $user_id,
			array(
				'is_customer' => true,
			)
		);

		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return array(
			'contact_id'       => $contact_id,
			'wp_user_id'       => (int) $user_id,
			'username'         => $username,
			'created_profile'  => false,
			'linked_existing'  => false,
			'created_user'     => true,
		);
	}

	public function create( array $data ) {
		if ( ! $this->has_table() ) {
			return $this->error( 'asdl_fin_contacts_missing', 'La tabla de perfiles aun no esta disponible.' );
		}

		$payload      = $this->normalize_profile_payload( $data );
		$display_name = $payload['display_name'];

		if ( '' === $display_name ) {
			return $this->error( 'asdl_fin_contact_name', 'Debes indicar el nombre principal del perfil.' );
		}

		$wpdb = $this->db();
		$now  = $this->now();

		$result = $wpdb->insert(
			$this->table(),
			array(
				'display_name'   => $payload['display_name'],
				'legal_name'     => $payload['legal_name'],
				'contact_type'   => $payload['contact_type'],
				'profile_origin' => $payload['profile_origin'],
				'wp_user_id'     => $payload['wp_user_id'],
				'is_customer'    => $payload['is_customer'],
				'is_employee'    => $payload['is_employee'],
				'is_supplier'    => $payload['is_supplier'],
				'internal_use_profile' => $payload['internal_use_profile'],
				'supplier_kind'  => $payload['supplier_kind'],
				'email'          => $payload['email'],
				'phone'          => $payload['phone'],
				'document_id'    => $payload['document_id'],
				'payment_terms'  => $payload['payment_terms'],
				'status'         => $payload['status'],
				'notes'          => $payload['notes'],
				'meta_json'      => null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->error( 'asdl_fin_contact_insert', 'No se pudo guardar el perfil.' );
		}

		return (int) $wpdb->insert_id;
	}

	public function mark_as_employee( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return false;
		}

		$contact = $this->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return false;
		}

		$updated = $this->persist_update(
			$contact_id,
			$this->normalize_profile_payload(
				array(
					'is_employee' => true,
				),
				$contact
			)
		);

		return (bool) $updated;
	}

	public function update( $contact_id, array $data ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		$existing = $this->find( $contact_id );
		if ( empty( $existing['id'] ) ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		$payload = $this->normalize_profile_payload( $data, $existing );

		if ( '' === $payload['display_name'] ) {
			return $this->error( 'asdl_fin_contact_name', 'Debes indicar el nombre principal del perfil.' );
		}

		$updated = $this->persist_update( $contact_id, $payload );
		if ( ! $updated ) {
			return $this->error( 'asdl_fin_contact_update', 'No se pudo actualizar el perfil.' );
		}

		return $contact_id;
	}

	public function finance_state_for_contact( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return array(
				'can_delete' => false,
			);
		}

		$map = $this->finance_state_map( array( $contact_id ) );

		return $map[ $contact_id ] ?? array(
			'can_delete' => false,
		);
	}

	public function delete_empty_profile( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		$contact = $this->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		if ( ! empty( $contact['wp_user_id'] ) ) {
			return $this->error(
				'asdl_fin_profile_delete_wp_user',
				'Los perfiles enlazados a usuarios de WordPress no se eliminan desde esta accion rapida.'
			);
		}

		$finance_state = $this->finance_state_for_contact( $contact_id );
		if ( empty( $finance_state['can_delete'] ) ) {
			return $this->error(
				'asdl_fin_profile_delete_blocked',
				$finance_state['delete_block_reason'] ?? 'Este perfil no se puede eliminar porque ya tiene actividad o saldo dentro de Finanzas ASD.'
			);
		}

		if ( $this->has_operational_orders( $contact ) ) {
			return $this->error(
				'asdl_fin_profile_delete_orders',
				'Este perfil no se puede eliminar porque ya tiene pedidos operativos asociados en Woo/OpenPOS.'
			);
		}

		$wpdb                  = $this->db();
		$employee_profiles     = Tables::name( 'employee_profiles' );
		$events_table          = Tables::name( 'events' );
		$deleted_employee_rows = 0;

		$this->begin_transaction();

		$deleted_employee_rows = $wpdb->delete(
			$employee_profiles,
			array( 'contact_id' => $contact_id ),
			array( '%d' )
		);

		$wpdb->delete(
			$events_table,
			array(
				'entity_type' => 'contact',
				'entity_id'   => $contact_id,
			),
			array( '%s', '%d' )
		);

		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $contact_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			$this->rollback_transaction();

			return $this->error( 'asdl_fin_profile_delete_failed', 'No se pudo eliminar el perfil seleccionado.' );
		}

		$this->commit_transaction();

		return array(
			'contact_id'              => $contact_id,
			'display_name'            => sanitize_text_field( (string) ( $contact['display_name'] ?? '' ) ),
			'deleted_employee_rows'   => max( 0, (int) $deleted_employee_rows ),
			'removed_finance_profile' => true,
		);
	}

	private function hydrate_profile( array $row ) {
		$wp_user_id = ! empty( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : 0;
		$flags      = $this->flags_from_row( $row );

		$row['id']                  = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['wp_user_id']          = $wp_user_id;
		$row['profile_origin']      = sanitize_key( $row['profile_origin'] ?? ( $wp_user_id > 0 ? 'wp_user' : 'external' ) );
		$row['is_customer']         = $flags['is_customer'] ? 1 : 0;
		$row['is_employee']         = $flags['is_employee'] ? 1 : 0;
		$row['is_supplier']         = $flags['is_supplier'] ? 1 : 0;
		$row['internal_use_profile']= ! empty( $row['internal_use_profile'] ) ? 1 : 0;
		$row['supplier_kind']       = $this->normalize_supplier_kind( $row['supplier_kind'] ?? '', ! empty( $row['is_supplier'] ) );
		$row['contact_type']        = $this->derive_contact_type( $flags );
		$row['profile_roles']       = $this->profile_roles( $flags );
		$row['profile_roles_label'] = $this->profile_roles_label( $flags );

		return $row;
	}

	private function append_finance_state( array $contacts ) {
		if ( empty( $contacts ) ) {
			return array();
		}

		$contact_ids = array_map(
			static function ( array $contact ) {
				return (int) ( $contact['id'] ?? 0 );
			},
			$contacts
		);
		$contact_ids = array_filter( $contact_ids );
		$state_map   = $this->finance_state_map( $contact_ids );

		foreach ( $contacts as &$contact ) {
			$state = $state_map[ (int) $contact['id'] ] ?? array();
			if ( ! empty( $contact['wp_user_id'] ) ) {
				$state['can_delete']          = false;
				$state['delete_block_reason'] = 'Los perfiles enlazados a usuarios de WordPress no se eliminan desde esta accion rapida.';
			}

			$contact['finance_state']     = $state;
			$contact['can_delete']        = ! empty( $state['can_delete'] );
			$contact['can_promote_user']  = empty( $contact['wp_user_id'] ) && ! empty( $contact['email'] );
		}
		unset( $contact );

		return $contacts;
	}

	private function has_operational_orders( array $contact ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		$args = array(
			'limit'  => 1,
			'return' => 'ids',
			'status' => array_keys( wc_get_order_statuses() ),
		);

		if ( ! empty( $contact['wp_user_id'] ) ) {
			$args['customer_id'] = absint( $contact['wp_user_id'] );
		} elseif ( ! empty( $contact['email'] ) ) {
			$args['billing_email'] = sanitize_email( $contact['email'] );
		} else {
			return false;
		}

		$orders = wc_get_orders( $args );

		return ! empty( $orders );
	}

	private function finance_state_map( array $contact_ids ) {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', $contact_ids ) ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$wpdb         = $this->db();
		$placeholders = implode( ', ', array_fill( 0, count( $contact_ids ), '%d' ) );
		$tables       = array(
			'documents'         => Tables::name( 'documents' ),
			'payments'          => Tables::name( 'payments' ),
			'employee_profiles' => Tables::name( 'employee_profiles' ),
			'employee_advances' => Tables::name( 'employee_advances' ),
			'payroll_periods'   => Tables::name( 'payroll_periods' ),
			'installment_plans' => Tables::name( 'installment_plans' ),
		);
		$defaults     = array();

		foreach ( $contact_ids as $contact_id ) {
			$defaults[ $contact_id ] = array(
				'document_count'          => 0,
				'payment_count'           => 0,
				'employee_profile_count'  => 0,
				'advance_count'           => 0,
				'payroll_count'           => 0,
				'commitment_count'        => 0,
				'receivable_total'        => 0.0,
				'payable_total'           => 0.0,
				'unapplied_payment_total' => 0.0,
				'advance_balance_total'   => 0.0,
				'commitment_balance_total'=> 0.0,
				'has_finance_data'        => false,
				'has_balance'             => false,
				'can_delete'              => true,
				'delete_block_reason'     => '',
			);
		}

		$document_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					contact_id,
					COUNT(*) AS document_count,
					COALESCE(SUM(CASE WHEN balance_nature = 'receivable' AND balance > 0 THEN balance ELSE 0 END), 0) AS receivable_total,
					COALESCE(SUM(CASE WHEN balance_nature = 'payable' AND balance > 0 THEN balance ELSE 0 END), 0) AS payable_total
				FROM {$tables['documents']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $document_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( ! isset( $defaults[ $contact_id ] ) ) {
				continue;
			}

			$defaults[ $contact_id ]['document_count']   = (int) $row['document_count'];
			$defaults[ $contact_id ]['receivable_total'] = (float) $row['receivable_total'];
			$defaults[ $contact_id ]['payable_total']    = (float) $row['payable_total'];
		}

		$payment_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					contact_id,
					COUNT(*) AS payment_count,
					COALESCE(SUM(CASE WHEN available_amount > 0 AND COALESCE(method_key, '') <> 'salary_advance' THEN available_amount ELSE 0 END), 0) AS unapplied_payment_total
				FROM {$tables['payments']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $payment_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( ! isset( $defaults[ $contact_id ] ) ) {
				continue;
			}

			$defaults[ $contact_id ]['payment_count']           = (int) $row['payment_count'];
			$defaults[ $contact_id ]['unapplied_payment_total'] = (float) $row['unapplied_payment_total'];
		}

		$employee_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT contact_id, COUNT(*) AS employee_profile_count
				FROM {$tables['employee_profiles']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $employee_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( isset( $defaults[ $contact_id ] ) ) {
				$defaults[ $contact_id ]['employee_profile_count'] = (int) $row['employee_profile_count'];
			}
		}

		$advance_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					contact_id,
					COUNT(*) AS advance_count,
					COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS advance_balance_total
				FROM {$tables['employee_advances']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $advance_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( ! isset( $defaults[ $contact_id ] ) ) {
				continue;
			}

			$defaults[ $contact_id ]['advance_count']         = (int) $row['advance_count'];
			$defaults[ $contact_id ]['advance_balance_total'] = (float) $row['advance_balance_total'];
		}

		$payroll_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT contact_id, COUNT(*) AS payroll_count
				FROM {$tables['payroll_periods']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $payroll_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( isset( $defaults[ $contact_id ] ) ) {
				$defaults[ $contact_id ]['payroll_count'] = (int) $row['payroll_count'];
			}
		}

		$commitment_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					contact_id,
					COUNT(*) AS commitment_count,
					COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS commitment_balance_total
				FROM {$tables['installment_plans']}
				WHERE contact_id IN ({$placeholders})
				GROUP BY contact_id",
				...$contact_ids
			),
			ARRAY_A
		);

		foreach ( $commitment_rows as $row ) {
			$contact_id = (int) $row['contact_id'];
			if ( ! isset( $defaults[ $contact_id ] ) ) {
				continue;
			}

			$defaults[ $contact_id ]['commitment_count']         = (int) $row['commitment_count'];
			$defaults[ $contact_id ]['commitment_balance_total'] = (float) $row['commitment_balance_total'];
		}

		foreach ( $defaults as $contact_id => &$state ) {
			$state['has_finance_data'] = $state['document_count'] > 0
				|| $state['payment_count'] > 0
				|| $state['advance_count'] > 0
				|| $state['payroll_count'] > 0
				|| $state['commitment_count'] > 0;
			$state['has_balance'] = $state['receivable_total'] > 0
				|| $state['payable_total'] > 0
				|| $state['unapplied_payment_total'] > 0
				|| $state['advance_balance_total'] > 0
				|| $state['commitment_balance_total'] > 0;
			$state['can_delete'] = ! $state['has_finance_data'] && ! $state['has_balance'];

			if ( ! $state['can_delete'] ) {
				$state['delete_block_reason'] = 'Este perfil no se puede eliminar porque ya tiene movimientos, pagos, compromisos o saldos dentro de Finanzas ASD.';
			}
		}
		unset( $state );

		return $defaults;
	}

	private function option_label( array $contact ) {
		$parts = array( $contact['display_name'] );

		if ( ! empty( $contact['profile_roles_label'] ) ) {
			$parts[] = $contact['profile_roles_label'];
		}

		if ( ! empty( $contact['is_supplier'] ) ) {
			$parts[] = $this->label_for_supplier_kind( $contact['supplier_kind'] ?? 'general' );
		}

		$parts[] = 'wp_user' === ( $contact['profile_origin'] ?? '' ) ? 'Usuario WP' : 'Externo';

		return implode( ' | ', array_filter( $parts ) );
	}

	private function normalize_bool_filter( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		$normalized = strtolower( sanitize_text_field( (string) $value ) );
		if ( in_array( $normalized, array( '1', 'true', 'yes' ), true ) ) {
			return 1;
		}

		if ( in_array( $normalized, array( '0', 'false', 'no' ), true ) ) {
			return 0;
		}

		return null;
	}

	private function link_profile_to_wp_user( $contact_id, $wp_user_id, array $flags = array() ) {
		$contact_id = absint( $contact_id );
		$wp_user_id = absint( $wp_user_id );

		if ( $contact_id <= 0 || $wp_user_id <= 0 ) {
			return $this->error( 'asdl_fin_profile_link_data', 'Faltan datos para vincular el perfil con el usuario.' );
		}

		$contact = $this->find( $contact_id );
		if ( empty( $contact['id'] ) ) {
			return $this->error( 'asdl_fin_profile_missing', 'No se encontro el perfil solicitado.' );
		}

		$duplicate = $this->find_by_wp_user_id( $wp_user_id );
		if ( ! empty( $duplicate['id'] ) && (int) $duplicate['id'] !== $contact_id ) {
			return $this->error( 'asdl_fin_profile_duplicate_user', 'Ese usuario ya esta vinculado a otro perfil financiero.' );
		}

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			return $this->error( 'asdl_fin_wp_user_missing', 'No se encontro el usuario seleccionado.' );
		}

		$merged_flags = $this->merge_flags(
			$this->flags_from_row( $contact ),
			$this->flags_from_wp_roles( $user->roles ),
			$flags
		);

		$updated = $this->persist_update(
			$contact_id,
			$this->normalize_profile_payload(
				array(
					'display_name'   => $contact['display_name'] ?: $user->display_name,
					'legal_name'     => $contact['legal_name'] ?? '',
					'profile_origin' => 'wp_user',
					'wp_user_id'     => $wp_user_id,
					'is_customer'    => $merged_flags['is_customer'],
					'is_employee'    => $merged_flags['is_employee'],
					'is_supplier'    => $merged_flags['is_supplier'],
					'internal_use_profile' => ! empty( $flags['internal_use_profile'] ) || ! empty( $contact['internal_use_profile'] ) ? 1 : 0,
					'supplier_kind'  => $contact['supplier_kind'] ?? '',
					'email'          => $contact['email'] ?: $user->user_email,
					'phone'          => $contact['phone'] ?? '',
					'document_id'    => $contact['document_id'] ?? '',
					'payment_terms'  => $contact['payment_terms'] ?? '',
					'status'         => $contact['status'] ?? 'active',
					'notes'          => $contact['notes'] ?? '',
				),
				$contact
			)
		);

		if ( ! $updated ) {
			return $this->error( 'asdl_fin_profile_link_failed', 'No se pudo vincular el perfil con el usuario seleccionado.' );
		}

		return $contact_id;
	}

	private function normalize_profile_payload( array $data, array $existing = array() ) {
		$wp_user_id     = ! empty( $data['wp_user_id'] ) ? absint( $data['wp_user_id'] ) : ( ! empty( $existing['wp_user_id'] ) ? absint( $existing['wp_user_id'] ) : 0 );
		$profile_origin = sanitize_key( $data['profile_origin'] ?? ( ! empty( $existing['profile_origin'] ) ? $existing['profile_origin'] : '' ) );
		$contact_type   = sanitize_key( $data['contact_type'] ?? ( $existing['contact_type'] ?? '' ) );
		$explicit_flags = array(
			'is_customer' => array_key_exists( 'is_customer', $data ),
			'is_employee' => array_key_exists( 'is_employee', $data ),
			'is_supplier' => array_key_exists( 'is_supplier', $data ),
		);
		if ( $explicit_flags['is_customer'] || $explicit_flags['is_employee'] || $explicit_flags['is_supplier'] ) {
			$flags = $this->flags_from_input( $data );
		} else {
			$flags = $this->merge_flags( $this->flags_from_row( $existing ), $this->flags_from_hint( $contact_type ), $this->flags_from_input( $data ) );
		}
		$supplier_kind  = $this->normalize_supplier_kind(
			$data['supplier_kind'] ?? ( $existing['supplier_kind'] ?? '' ),
			! empty( $flags['is_supplier'] )
		);

		if ( '' === $profile_origin ) {
			$profile_origin = $wp_user_id > 0 ? 'wp_user' : 'external';
		}

		return array(
			'display_name'   => sanitize_text_field( $data['display_name'] ?? ( $existing['display_name'] ?? '' ) ),
			'legal_name'     => sanitize_text_field( $data['legal_name'] ?? ( $existing['legal_name'] ?? '' ) ),
			'contact_type'   => $this->derive_contact_type( $flags ),
			'profile_origin' => $profile_origin,
			'wp_user_id'     => $wp_user_id > 0 ? $wp_user_id : null,
			'is_customer'    => $flags['is_customer'] ? 1 : 0,
			'is_employee'    => $flags['is_employee'] ? 1 : 0,
			'is_supplier'    => $flags['is_supplier'] ? 1 : 0,
			'internal_use_profile' => ! empty( $data['internal_use_profile'] ) || ( ! array_key_exists( 'internal_use_profile', $data ) && ! empty( $existing['internal_use_profile'] ) ) ? 1 : 0,
			'supplier_kind'  => $supplier_kind,
			'email'          => sanitize_email( $data['email'] ?? ( $existing['email'] ?? '' ) ),
			'phone'          => sanitize_text_field( $data['phone'] ?? ( $existing['phone'] ?? '' ) ),
			'document_id'    => sanitize_text_field( $data['document_id'] ?? ( $existing['document_id'] ?? '' ) ),
			'payment_terms'  => sanitize_text_field( $data['payment_terms'] ?? ( $existing['payment_terms'] ?? '' ) ),
			'status'         => sanitize_key( $data['status'] ?? ( $existing['status'] ?? 'active' ) ),
			'notes'          => sanitize_textarea_field( $data['notes'] ?? ( $existing['notes'] ?? '' ) ),
		);
	}

	private function persist_update( $contact_id, array $payload ) {
		$result = $this->db()->update(
			$this->table(),
			array_merge(
				$payload,
				array(
					'updated_at' => $this->now(),
				)
			),
			array( 'id' => absint( $contact_id ) ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	private function flags_from_input( array $data ) {
		return array(
			'is_customer' => ! empty( $data['is_customer'] ),
			'is_employee' => ! empty( $data['is_employee'] ),
			'is_supplier' => ! empty( $data['is_supplier'] ),
		);
	}

	private function flags_from_hint( $contact_type ) {
		switch ( sanitize_key( (string) $contact_type ) ) {
			case 'client':
				return array(
					'is_customer' => true,
					'is_employee' => false,
					'is_supplier' => false,
				);
			case 'employee':
				return array(
					'is_customer' => false,
					'is_employee' => true,
					'is_supplier' => false,
				);
			case 'supplier':
				return array(
					'is_customer' => false,
					'is_employee' => false,
					'is_supplier' => true,
				);
			case 'mixed':
			default:
				return array(
					'is_customer' => false,
					'is_employee' => false,
					'is_supplier' => false,
				);
		}
	}

	private function flags_from_row( array $row ) {
		$contact_type = sanitize_key( $row['contact_type'] ?? '' );

		$is_customer = array_key_exists( 'is_customer', $row ) ? ! empty( $row['is_customer'] ) : in_array( $contact_type, array( 'client', 'mixed' ), true );
		$is_employee = array_key_exists( 'is_employee', $row ) ? ! empty( $row['is_employee'] ) : in_array( $contact_type, array( 'employee', 'mixed' ), true );
		$is_supplier = array_key_exists( 'is_supplier', $row ) ? ! empty( $row['is_supplier'] ) : in_array( $contact_type, array( 'supplier', 'mixed' ), true );

		return array(
			'is_customer' => $is_customer,
			'is_employee' => $is_employee,
			'is_supplier' => $is_supplier,
		);
	}

	private function flags_from_wp_roles( array $roles ) {
		$roles = array_map( 'sanitize_key', $roles );

		return array(
			'is_customer' => ! empty( array_intersect( $roles, array( 'customer' ) ) ),
			'is_employee' => ! empty( array_intersect( $roles, array( 'employee' ) ) ),
			'is_supplier' => false,
		);
	}

	private function merge_flags( ...$flag_sets ) {
		$merged = array(
			'is_customer' => false,
			'is_employee' => false,
			'is_supplier' => false,
		);

		foreach ( $flag_sets as $flag_set ) {
			if ( ! is_array( $flag_set ) ) {
				continue;
			}

			foreach ( $merged as $key => $value ) {
				if ( array_key_exists( $key, $flag_set ) && $flag_set[ $key ] ) {
					$merged[ $key ] = true;
				}
			}
		}

		return $merged;
	}

	private function derive_contact_type( array $flags ) {
		$true_flags = array_filter(
			$flags,
			static function ( $value ) {
				return ! empty( $value );
			}
		);

		if ( count( $true_flags ) > 1 ) {
			return 'mixed';
		}

		if ( ! empty( $flags['is_employee'] ) ) {
			return 'employee';
		}

		if ( ! empty( $flags['is_supplier'] ) ) {
			return 'supplier';
		}

		if ( ! empty( $flags['is_customer'] ) ) {
			return 'client';
		}

		return 'mixed';
	}

	private function profile_roles( array $flags ) {
		$roles = array();

		if ( ! empty( $flags['is_customer'] ) ) {
			$roles[] = 'Cliente';
		}

		if ( ! empty( $flags['is_employee'] ) ) {
			$roles[] = 'Empleado';
		}

		if ( ! empty( $flags['is_supplier'] ) ) {
			$roles[] = 'Proveedor';
		}

		return $roles;
	}

	private function profile_roles_label( array $flags ) {
		$roles = $this->profile_roles( $flags );

		return empty( $roles ) ? 'Tercero externo' : implode( ' / ', $roles );
	}

	private function normalize_supplier_kind( $value, $is_supplier ) {
		if ( ! $is_supplier ) {
			return '';
		}

		$value = sanitize_key( (string) $value );
		if ( in_array( $value, array( 'services', 'products', 'mixed', 'general' ), true ) ) {
			return $value;
		}

		return 'general';
	}

	private function label_for_supplier_kind( $value ) {
		$labels = array(
			'general'  => 'Proveedor por clasificar',
			'services' => 'Proveedor de servicios',
			'products' => 'Proveedor de productos',
			'mixed'    => 'Proveedor mixto',
		);

		$value = sanitize_key( (string) $value );

		return $labels[ $value ] ?? $labels['general'];
	}

	private function generate_unique_username( $email, $display_name ) {
		$base = '';

		if ( false !== strpos( (string) $email, '@' ) ) {
			$base = strstr( (string) $email, '@', true );
		}

		if ( '' === $base ) {
			$base = sanitize_title( (string) $display_name );
		}

		$base = sanitize_user( $base, true );
		if ( '' === $base ) {
			$base = 'perfil_asd';
		}

		$username = $base;
		$index    = 1;

		while ( username_exists( $username ) ) {
			$username = $base . $index;
			++$index;
		}

		return $username;
	}
}
