<?php

namespace ASDLabs\Finance\Admin;

use ASDLabs\Finance\Core\Contracts\Module;
use ASDLabs\Finance\Finance\AccountsRepository;
use ASDLabs\Finance\Finance\CancellationService;
use ASDLabs\Finance\Finance\CommitmentSettlementService;
use ASDLabs\Finance\Finance\ContactOverviewService;
use ASDLabs\Finance\Finance\ContactsRepository;
use ASDLabs\Finance\Finance\CurrenciesService;
use ASDLabs\Finance\Finance\DocumentFilesRepository;
use ASDLabs\Finance\Finance\DocumentsRepository;
use ASDLabs\Finance\Finance\EmployeeAdvancesRepository;
use ASDLabs\Finance\Finance\EmployeeProfilesRepository;
use ASDLabs\Finance\Finance\EventsRepository;
use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\InstallmentPlansRepository;
use ASDLabs\Finance\Finance\PaymentMethodsService;
use ASDLabs\Finance\Finance\PayrollPeriodsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationService;
use ASDLabs\Finance\Finance\PaymentsRepository;
use ASDLabs\Finance\Finance\ProfileCreditPayoutService;
use ASDLabs\Finance\Finance\ReceiptBrandingService;
use ASDLabs\Finance\Finance\RulesRepository;
use ASDLabs\Finance\Finance\ServiceProfilesRepository;
use ASDLabs\Finance\Finance\SourceLinksRepository;
use ASDLabs\Finance\Integrations\Woo\ProfileOrderSettlementService;

final class CrudController implements Module {
	public function register() {
		add_action( 'admin_post_asdl_fin_save_account', array( $this, 'save_account' ) );
		add_action( 'admin_post_asdl_fin_save_contact', array( $this, 'save_contact' ) );
		add_action( 'admin_post_asdl_fin_update_contact_profile', array( $this, 'update_contact_profile' ) );
		add_action( 'admin_post_asdl_fin_delete_contact', array( $this, 'delete_contact' ) );
		add_action( 'admin_post_asdl_fin_link_wp_profile', array( $this, 'link_wp_profile' ) );
		add_action( 'admin_post_asdl_fin_promote_contact_to_user', array( $this, 'promote_contact_to_user' ) );
		add_action( 'admin_post_asdl_fin_quick_link_wp_profile', array( $this, 'quick_link_wp_profile' ) );
		add_action( 'admin_post_asdl_fin_quick_create_external_profile', array( $this, 'quick_create_external_profile' ) );
		add_action( 'admin_post_asdl_fin_save_document', array( $this, 'save_document' ) );
		add_action( 'admin_post_asdl_fin_update_document', array( $this, 'update_document' ) );
		add_action( 'admin_post_asdl_fin_save_service_profile', array( $this, 'save_service_profile' ) );
		add_action( 'admin_post_asdl_fin_set_service_profile_status', array( $this, 'set_service_profile_status' ) );
		add_action( 'admin_post_asdl_fin_generate_service_document', array( $this, 'generate_service_document' ) );
		add_action( 'admin_post_asdl_fin_save_employee_profile', array( $this, 'save_employee_profile' ) );
		add_action( 'admin_post_asdl_fin_save_salary_advance', array( $this, 'save_salary_advance' ) );
		add_action( 'admin_post_asdl_fin_save_payroll_period', array( $this, 'save_payroll_period' ) );
		add_action( 'admin_post_asdl_fin_mark_payroll_period_paid', array( $this, 'mark_payroll_period_paid' ) );
		add_action( 'admin_post_asdl_fin_save_payment_method', array( $this, 'save_payment_method' ) );
		add_action( 'admin_post_asdl_fin_save_currency', array( $this, 'save_currency' ) );
		add_action( 'admin_post_asdl_fin_save_receipt_branding', array( $this, 'save_receipt_branding' ) );
		add_action( 'admin_post_asdl_fin_save_fiscal_year_settings', array( $this, 'save_fiscal_year_settings' ) );
		add_action( 'admin_post_asdl_fin_save_payment', array( $this, 'save_payment' ) );
		add_action( 'admin_post_asdl_fin_cancel_payment', array( $this, 'cancel_payment' ) );
		add_action( 'admin_post_asdl_fin_cancel_document', array( $this, 'cancel_document' ) );
		add_action( 'admin_post_asdl_fin_cancel_salary_advance', array( $this, 'cancel_salary_advance' ) );
		add_action( 'admin_post_asdl_fin_cancel_installment_plan', array( $this, 'cancel_installment_plan' ) );
		add_action( 'admin_post_asdl_fin_settle_profile_orders', array( $this, 'settle_profile_orders' ) );
		add_action( 'admin_post_asdl_fin_apply_profile_credit', array( $this, 'apply_profile_credit' ) );
		add_action( 'admin_post_asdl_fin_pay_profile_credit', array( $this, 'pay_profile_credit' ) );
		add_action( 'admin_post_asdl_fin_allocate_payment', array( $this, 'allocate_payment' ) );
		add_action( 'admin_post_asdl_fin_save_installment_plan', array( $this, 'save_installment_plan' ) );
		add_action( 'admin_post_asdl_fin_apply_commitment_payment', array( $this, 'apply_commitment_payment' ) );
		add_action( 'admin_post_asdl_fin_save_rule', array( $this, 'save_rule' ) );
		add_action( 'admin_post_asdl_fin_toggle_rule', array( $this, 'toggle_rule' ) );
	}

	public function save_account() {
		$this->guard_request( 'asdl_fin_save_account' );

		$repository = new AccountsRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		$this->handle_result( $result, 'account', 'Cuenta registrada correctamente.', 'asdl-fin-accounts', 'asdl_fin_account_created' );
	}

	public function save_contact() {
		$this->guard_request( 'asdl_fin_save_contact' );

		$repository = new ContactsRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		$this->handle_result( $result, 'contact', 'Perfil externo registrado correctamente.', 'asdl-fin-contacts', 'asdl_fin_contact_created' );
	}

	public function update_contact_profile() {
		$this->guard_request( 'asdl_fin_update_contact_profile' );

		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$result     = ( new ContactsRepository() )->update( $contact_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => $contact_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( 'contact', $contact_id, 'updated', 'Configuracion del perfil actualizada.' );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => $contact_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Perfil actualizado correctamente.' ),
			)
		);
	}

	public function delete_contact() {
		$this->guard_request( 'asdl_fin_delete_contact' );

		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$result     = ( new ContactsRepository() )->delete_empty_profile( $contact_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode(
					sprintf(
						'Perfil eliminado correctamente: %s.',
						sanitize_text_field( $result['display_name'] ?? '#' . $contact_id )
					)
				),
			)
		);
	}

	public function link_wp_profile() {
		$this->guard_request( 'asdl_fin_link_wp_profile' );

		$flags = $this->contact_role_flags_from_request();
		$flags['supplier_kind'] = sanitize_key( wp_unslash( $_POST['supplier_kind'] ?? '' ) );

		$result = ( new ContactsRepository() )->link_existing_wp_user(
			absint( wp_unslash( $_POST['wp_user_id'] ?? 0 ) ),
			$flags
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$message = ! empty( $result['created_profile'] )
			? 'Perfil vinculado correctamente a partir del usuario seleccionado.'
			: 'El perfil del usuario fue actualizado o vinculado correctamente.';

		( new EventsRepository() )->log(
			'contact',
			(int) $result['contact_id'],
			'linked_wp_user',
			$message,
			$result
		);

		do_action( 'asdl_fin_profile_linked', $result );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => (int) $result['contact_id'],
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function promote_contact_to_user() {
		$this->guard_request( 'asdl_fin_promote_contact_to_user' );

		$result = ( new ContactsRepository() )->promote_external_to_wp_user(
			absint( wp_unslash( $_POST['contact_id'] ?? 0 ) )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$message = ! empty( $result['created_user'] )
			? sprintf( 'Perfil enlazado correctamente. Se creo el usuario interno: %s.', sanitize_text_field( $result['username'] ?? '' ) )
			: 'El perfil quedo vinculado correctamente a un usuario interno existente.';

		( new EventsRepository() )->log(
			'contact',
			(int) $result['contact_id'],
			'promoted_to_wp_user',
			$message,
			$result
		);

		do_action( 'asdl_fin_profile_promoted', $result );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => (int) $result['contact_id'],
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function quick_link_wp_profile() {
		$this->guard_request( 'asdl_fin_quick_link_wp_profile' );

		$flags = $this->contact_role_flags_from_request();
		$flags['supplier_kind'] = sanitize_key( wp_unslash( $_POST['supplier_kind'] ?? '' ) );
		$result = ( new ContactsRepository() )->link_existing_wp_user(
			absint( wp_unslash( $_POST['wp_user_id'] ?? 0 ) ),
			$flags
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-finanzas',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( 'contact', (int) $result['contact_id'], 'linked_wp_user', 'Perfil enlazado rapidamente desde pendientes globales.' );
		do_action( 'asdl_fin_profile_linked', $result );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => (int) $result['contact_id'],
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Perfil enlazado y abierto correctamente.' ),
			)
		);
	}

	public function quick_create_external_profile() {
		$this->guard_request( 'asdl_fin_quick_create_external_profile' );

		$contacts = new ContactsRepository();
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$existing = '' !== $email ? $contacts->find_by_email( $email ) : null;
		$flags    = $this->contact_role_flags_from_request();

		if ( ! empty( $existing['id'] ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => (int) $existing['id'],
					'asdl_fin_notice'      => 'success',
					'asdl_fin_notice_text' => rawurlencode( 'Se abrio el perfil ya existente para este pendiente.' ),
				)
			);
		}

		$result = $contacts->create(
			array(
				'display_name'   => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
				'profile_origin' => 'external',
				'is_customer'    => $flags['is_customer'],
				'is_employee'    => $flags['is_employee'],
				'is_supplier'    => $flags['is_supplier'],
				'supplier_kind'  => sanitize_key( wp_unslash( $_POST['supplier_kind'] ?? '' ) ),
				'email'          => $email,
				'status'         => 'active',
				'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? 'Perfil creado desde pendientes globales.' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-finanzas',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( 'contact', (int) $result, 'created', 'Perfil externo creado rapidamente desde pendientes globales.' );
		do_action( 'asdl_fin_contact_created', (int) $result );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => (int) $result,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Perfil creado y abierto correctamente.' ),
			)
		);
	}

	public function save_document() {
		$this->guard_request( 'asdl_fin_save_document' );

		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-documents';
		$this->guard_active_fiscal_context( $return_page );
		$contact_id  = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$range_from  = sanitize_text_field( wp_unslash( $_POST['range_from'] ?? '' ) );
		$range_to    = sanitize_text_field( wp_unslash( $_POST['range_to'] ?? '' ) );
		$order_limit = absint( wp_unslash( $_POST['order_limit'] ?? 25 ) );

		$repository = new DocumentsRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$error_args = array(
				'asdl_fin_notice'      => 'error',
				'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
			);

			if ( 'asdl-fin-contacts' === $return_page ) {
				$error_args['contact_id']  = $contact_id;
				$error_args['range_from']  = $range_from;
				$error_args['range_to']    = $range_to;
				$error_args['order_limit'] = $order_limit;
			}

			$this->redirect(
				$return_page,
				$error_args
			);
		}

		$document_id = (int) $result;
		$message     = 'asdl-fin-services' === $return_page
			? 'Servicio registrado correctamente.'
			: 'Movimiento registrado correctamente.';

		$this->log_event( 'document', $document_id, 'created', $message );
		do_action( 'asdl_fin_document_created', $document_id );

		$attachment_result = $this->store_document_file( $document_id );
		if ( is_wp_error( $attachment_result ) ) {
			$message = 'asdl-fin-services' === $return_page
				? 'Servicio registrado correctamente, pero el comprobante no pudo adjuntarse.'
				: 'Movimiento registrado correctamente, pero el comprobante no pudo adjuntarse.';
		} elseif ( ! empty( $attachment_result['attachment_id'] ) ) {
			$message = 'asdl-fin-services' === $return_page
				? 'Servicio registrado correctamente con comprobante adjunto.'
				: 'Movimiento registrado correctamente con comprobante adjunto.';
		}

		$redirect_args = array(
			'document_id'           => $document_id,
			'asdl_fin_notice'       => 'success',
			'asdl_fin_notice_text'  => rawurlencode( $message ),
		);

		if ( 'asdl-fin-contacts' === $return_page ) {
			ContactOverviewService::bump_contact_snapshot_cache_version( $contact_id );
			$redirect_args['contact_id']  = $contact_id;
			$redirect_args['range_from']  = $range_from;
			$redirect_args['range_to']    = $range_to;
			$redirect_args['order_limit'] = $order_limit;
		}

		$this->redirect(
			$return_page,
			$redirect_args
		);
	}

	public function save_employee_profile() {
		$this->guard_request( 'asdl_fin_save_employee_profile' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$contact_id  = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$repository  = new EmployeeProfilesRepository();
		$result      = $repository->save_for_contact( $contact_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => $contact_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'employee_profile',
			(int) $result,
			'saved',
			'Configuracion laboral guardada correctamente.'
		);

		do_action(
			'asdl_fin_employee_profile_saved',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => $contact_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Configuracion laboral guardada correctamente.' ),
			)
		);
	}

	public function save_service_profile() {
		$this->guard_request( 'asdl_fin_save_service_profile' );
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-services';
		$this->guard_active_fiscal_context( $return_page );

		$repository = new ServiceProfilesRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'service_profile',
			(int) $result,
			'created',
			'Servicio recurrente guardado correctamente.'
		);

		do_action( 'asdl_fin_service_profile_created', (int) $result );

		$this->redirect(
			$return_page,
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Servicio recurrente guardado correctamente.' ),
			)
		);
	}

	public function set_service_profile_status() {
		$this->guard_request( 'asdl_fin_set_service_profile_status' );
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-services';
		$this->guard_active_fiscal_context( $return_page );

		$repository = new ServiceProfilesRepository();
		$result     = $repository->set_status(
			absint( wp_unslash( $_POST['profile_id'] ?? 0 ) ),
			wp_unslash( $_POST['status'] ?? '' )
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$status = sanitize_key( (string) wp_unslash( $_POST['status'] ?? '' ) );
		$this->log_event(
			'service_profile',
			(int) $result,
			'status_changed',
			'active' === $status ? 'Servicio recurrente activado correctamente.' : 'Servicio recurrente pausado correctamente.'
		);

		$this->redirect(
			$return_page,
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'active' === $status ? 'Servicio recurrente activado correctamente.' : 'Servicio recurrente pausado correctamente.' ),
			)
		);
	}

	public function generate_service_document() {
		$this->guard_request( 'asdl_fin_generate_service_document' );
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-services';
		$this->guard_active_fiscal_context( $return_page );

		$repository = new ServiceProfilesRepository();
		$result     = $repository->generate_document(
			absint( wp_unslash( $_POST['profile_id'] ?? 0 ) ),
			array(
				'issue_date' => sanitize_text_field( (string) wp_unslash( $_POST['issue_date'] ?? '' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'document',
			(int) ( $result['document_id'] ?? 0 ),
			'created',
			'Servicio emitido correctamente desde la cola recurrente.'
		);

		do_action( 'asdl_fin_service_document_generated', $result );

		$this->redirect(
			$return_page,
			array(
				'document_id'          => (int) ( $result['document_id'] ?? 0 ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Servicio emitido correctamente desde la cola operativa.' ),
			)
		);
	}

	public function save_salary_advance() {
		$this->guard_request( 'asdl_fin_save_salary_advance' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$result     = ( new EmployeeAdvancesRepository() )->create_for_contact( $contact_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => $contact_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'salary_advance',
			(int) $result,
			'created',
			'Adelanto de sueldo registrado correctamente.'
		);

		do_action(
			'asdl_fin_salary_advance_saved',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => $contact_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Adelanto de sueldo registrado correctamente.' ),
			)
		);
	}

	public function save_payroll_period() {
		$this->guard_request( 'asdl_fin_save_payroll_period' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$result     = ( new PayrollPeriodsRepository() )->create_for_contact( $contact_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => $contact_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'payroll_period',
			(int) $result,
			'created',
			'Periodo de nomina generado correctamente.'
		);

		do_action(
			'asdl_fin_payroll_period_created',
			array(
				'id'         => (int) $result,
				'contact_id' => $contact_id,
			)
		);

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => $contact_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Periodo de nomina generado correctamente.' ),
			)
		);
	}

	public function save_payment_method() {
		$this->guard_request( 'asdl_fin_save_payment_method' );

		$result = ( new PaymentMethodsService() )->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-settings',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'settings',
			0,
			'payment_method_created',
			sprintf( 'Metodo de pago registrado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) )
		);

		$this->redirect(
			'asdl-fin-settings',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( sprintf( 'Metodo de pago registrado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) ) ),
			)
		);
	}

	public function save_currency() {
		$this->guard_request( 'asdl_fin_save_currency' );

		$result = ( new CurrenciesService() )->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-settings',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'settings',
			0,
			'currency_created',
			sprintf( 'Moneda registrada correctamente: %s.', sanitize_text_field( $result['code'] ?? '' ) )
		);

		$this->redirect(
			'asdl-fin-settings',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( sprintf( 'Moneda registrada correctamente: %s.', sanitize_text_field( $result['code'] ?? '' ) ) ),
			)
		);
	}

	public function save_receipt_branding() {
		$this->guard_request( 'asdl_fin_save_receipt_branding' );

		$result = ( new ReceiptBrandingService() )->save( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-settings',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'settings',
			0,
			'receipt_branding_saved',
			'Configuracion de comprobantes actualizada correctamente.'
		);

		$this->redirect(
			'asdl-fin-settings',
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Configuracion de comprobantes actualizada correctamente.' ),
			)
		);
	}

	public function save_fiscal_year_settings() {
		$this->guard_request( 'asdl_fin_save_fiscal_year_settings' );

		( new FiscalYearService() )->save( wp_unslash( $_POST ) );

		$this->log_event(
			'settings',
			0,
			'fiscal_year_saved',
			'Configuracion del ejercicio fiscal actualizada correctamente.'
		);

		$this->redirect(
			'asdl-fin-settings',
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Configuracion del ejercicio fiscal actualizada correctamente.' ),
			)
		);
	}

	public function mark_payroll_period_paid() {
		$this->guard_request( 'asdl_fin_mark_payroll_period_paid' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-payroll';
		$payroll_id = absint( wp_unslash( $_POST['payroll_id'] ?? 0 ) );
		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		$result     = ( new PayrollPeriodsRepository() )->mark_paid( $payroll_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$args = array(
				'asdl_fin_notice'      => 'error',
				'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
			);
			if ( 'asdl-fin-contacts' === $return_page && $contact_id > 0 ) {
				$args['contact_id'] = $contact_id;
			}

			$this->redirect(
				$return_page,
				$args
			);
		}

		$this->log_event(
			'payroll_period',
			(int) ( $result['id'] ?? $payroll_id ),
			'paid',
			'Periodo de nomina procesado correctamente.'
		);

		do_action( 'asdl_fin_payroll_period_paid', $result );

		$args = array(
			'asdl_fin_notice'      => 'success',
			'asdl_fin_notice_text' => rawurlencode( 'Periodo de nomina procesado correctamente.' ),
		);
		if ( 'asdl-fin-contacts' === $return_page ) {
			$args['contact_id'] = $contact_id ?: (int) ( $result['contact_id'] ?? 0 );
		}

		$this->redirect( $return_page, $args );
	}

	public function update_document() {
		$this->guard_request( 'asdl_fin_update_document' );

		$document_id = absint( wp_unslash( $_POST['document_id'] ?? 0 ) );
		$repository  = new DocumentsRepository();
		$result      = $repository->update_manual( $document_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-documents',
				array(
					'document_id'           => $document_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		( new SourceLinksRepository() )->set_override_lock_for_document( $document_id, ! empty( $_POST['manual_override'] ) );

		$events = new EventsRepository();
		$events->log(
			'document',
			$document_id,
			'updated',
			'Movimiento actualizado correctamente.',
			array(
				'entity_type'     => 'document',
				'entity_id'       => $document_id,
				'manual_override' => ! empty( $_POST['manual_override'] ),
				'origin'          => 'admin',
			)
		);

		do_action( 'asdl_fin_document_updated', $document_id );

		$attachment_result = $this->store_document_file( $document_id );
		$message           = 'Movimiento actualizado correctamente.';

		if ( is_wp_error( $attachment_result ) ) {
			$message = 'Movimiento actualizado correctamente, pero el comprobante no pudo adjuntarse.';
		} elseif ( ! empty( $attachment_result['attachment_id'] ) ) {
			$message = 'Movimiento actualizado correctamente con comprobante adjunto.';
		}

		$this->redirect(
			'asdl-fin-documents',
			array(
				'document_id'           => $document_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function save_payment() {
		$this->guard_request( 'asdl_fin_save_payment' );
		$this->guard_active_fiscal_context( 'asdl-fin-payments' );

		$repository = new PaymentsRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		$this->handle_result( $result, 'payment', 'Pago o abono registrado correctamente.', 'asdl-fin-payments', 'asdl_fin_payment_recorded' );
	}

	public function cancel_payment() {
		$this->guard_request( 'asdl_fin_cancel_payment' );
		$this->guard_active_fiscal_context( 'asdl-fin-payments' );

		$result = ( new CancellationService() )->cancel_payment(
			absint( wp_unslash( $_POST['payment_id'] ?? 0 ) ),
			wp_unslash( $_POST['cancel_reason'] ?? '' ),
			array(
				'origin' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-payments',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			'asdl-fin-payments',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Pago anulado correctamente.' ),
			)
		);
	}

	public function cancel_document() {
		$this->guard_request( 'asdl_fin_cancel_document' );
		$this->guard_active_fiscal_context( 'asdl-fin-documents' );

		$result = ( new CancellationService() )->cancel_document(
			absint( wp_unslash( $_POST['document_id'] ?? 0 ) ),
			wp_unslash( $_POST['cancel_reason'] ?? '' ),
			array(
				'origin' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-documents',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			'asdl-fin-documents',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Movimiento anulado correctamente.' ),
			)
		);
	}

	public function cancel_salary_advance() {
		$this->guard_request( 'asdl_fin_cancel_salary_advance' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$result = ( new CancellationService() )->cancel_salary_advance(
			absint( wp_unslash( $_POST['advance_id'] ?? 0 ) ),
			wp_unslash( $_POST['cancel_reason'] ?? '' ),
			array(
				'origin' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Adelanto anulado correctamente.' ),
			)
		);
	}

	public function cancel_installment_plan() {
		$this->guard_request( 'asdl_fin_cancel_installment_plan' );
		$this->guard_active_fiscal_context( 'asdl-fin-installments' );

		$result = ( new CancellationService() )->cancel_installment_plan(
			absint( wp_unslash( $_POST['plan_id'] ?? 0 ) ),
			wp_unslash( $_POST['cancel_reason'] ?? '' ),
			array(
				'origin' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-installments',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			'asdl-fin-installments',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Compromiso anulado correctamente.' ),
			)
		);
	}

	public function settle_profile_orders() {
		$this->guard_request( 'asdl_fin_settle_profile_orders' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$payload  = wp_unslash( $_POST );
		$service  = new ProfileOrderSettlementService();
		$requires_preview_confirmation = empty( $payload['dual_discount_preview_confirmed'] );
		$preview_signature            = sanitize_text_field( (string) ( $payload['dual_discount_preview_signature'] ?? '' ) );
		$execution_key               = '';

		if ( $requires_preview_confirmation ) {
			$preview = $service->preview_oldest_first( $payload );

			if ( is_wp_error( $preview ) ) {
				$this->redirect(
					'asdl-fin-contacts',
					array(
						'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
						'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
						'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
						'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
						'asdl_fin_notice'      => 'error',
						'asdl_fin_notice_text' => rawurlencode( $preview->get_error_message() ),
					)
				);
			}

			if ( ! empty( $preview['requires_preview'] ) ) {
				$this->redirect(
					'asdl-fin-contacts',
					array(
						'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
						'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
						'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
						'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
						'asdl_fin_notice'      => 'error',
						'asdl_fin_notice_text' => rawurlencode( 'Debes revisar y confirmar la vista previa del abono con precio dual antes de aplicarlo.' ),
					)
				);
			}
		}
		else {
			$preview = $service->preview_oldest_first( $payload );

			if ( is_wp_error( $preview ) ) {
				$this->redirect(
					'asdl-fin-contacts',
					array(
						'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
						'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
						'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
						'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
						'asdl_fin_notice'      => 'error',
						'asdl_fin_notice_text' => rawurlencode( $preview->get_error_message() ),
					)
				);
			}

			if ( ! empty( $preview['requires_preview'] ) ) {
				$current_signature = sanitize_text_field( (string) ( $preview['preview_signature'] ?? '' ) );

				if ( '' === $preview_signature || '' === $current_signature || ! hash_equals( $current_signature, $preview_signature ) ) {
					$this->redirect(
						'asdl-fin-contacts',
						array(
							'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
							'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
							'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
							'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
							'asdl_fin_notice'      => 'error',
							'asdl_fin_notice_text' => rawurlencode( 'La simulacion del abono cambio o ya no es valida. Revisa de nuevo la vista previa antes de confirmar.' ),
						)
					);
				}

				$execution_key      = 'asdl_fin_dual_settlement_' . md5( absint( $payload['contact_id'] ?? 0 ) . '|' . $current_signature );
				$existing_execution = get_transient( $execution_key );

				if ( is_array( $existing_execution ) && 'done' === ( $existing_execution['state'] ?? '' ) ) {
					$this->redirect(
						'asdl-fin-contacts',
						array(
							'contact_id'           => absint( $existing_execution['contact_id'] ?? ( $payload['contact_id'] ?? 0 ) ),
							'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
							'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
							'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
							'asdl_fin_notice'      => 'success',
							'asdl_fin_notice_text' => rawurlencode( (string) ( $existing_execution['message'] ?? 'Este abono con precio dual ya habia sido aplicado.' ) ),
						)
					);
				}

				if ( is_array( $existing_execution ) && 'processing' === ( $existing_execution['state'] ?? '' ) ) {
					$this->redirect(
						'asdl-fin-contacts',
						array(
							'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
							'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
							'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
							'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
							'asdl_fin_notice'      => 'error',
							'asdl_fin_notice_text' => rawurlencode( 'Este abono con precio dual ya se esta procesando. Espera antes de volver a intentarlo.' ),
						)
					);
				}

				set_transient(
					$execution_key,
					array(
						'state'      => 'processing',
						'contact_id' => absint( $payload['contact_id'] ?? 0 ),
						'started_at' => time(),
					),
					15 * MINUTE_IN_SECONDS
				);
			}
		}

		$result = $service->settle_oldest_first( $payload );

		if ( is_wp_error( $result ) ) {
			if ( '' !== $execution_key ) {
				delete_transient( $execution_key );
			}

			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
					'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
					'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
					'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$message = sprintf(
			'Abono aplicado correctamente. Pedidos cerrados: %1$d | Pedidos parciales: %2$d | Saldo sin aplicar: %3$s',
			count( $result['closed_order_ids'] ?? array() ),
			count( $result['partial_order_ids'] ?? array() ),
			number_format_i18n( (float) ( $result['unapplied_total'] ?? 0 ), 2 )
		);

		if ( ! empty( $result['dual_discount_applied'] ) ) {
			$message .= sprintf(
				' | Descuento dual aplicado: %1$s',
				number_format_i18n( (float) ( $result['dual_discount_total'] ?? 0 ), 2 )
			);
		}

		if ( '' !== $execution_key ) {
			set_transient(
				$execution_key,
				array(
					'state'       => 'done',
					'contact_id'  => absint( $result['contact_id'] ?? 0 ),
					'payment_id'  => absint( $result['payment_id'] ?? 0 ),
					'message'     => $message,
					'completed_at'=> time(),
				),
				DAY_IN_SECONDS
			);
		}

		do_action( 'asdl_fin_profile_payment_applied', $result );
		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $result['contact_id'] ?? 0 ) );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => absint( $result['contact_id'] ?? 0 ),
				'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
				'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
				'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function apply_profile_credit() {
		$this->guard_request( 'asdl_fin_apply_profile_credit' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$result = ( new ProfileOrderSettlementService() )->apply_credit_balance( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'range_from'           => sanitize_text_field( wp_unslash( $_POST['range_from'] ?? '' ) ),
					'range_to'             => sanitize_text_field( wp_unslash( $_POST['range_to'] ?? '' ) ),
					'order_limit'          => absint( wp_unslash( $_POST['order_limit'] ?? 25 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$message = sprintf(
			'Saldo a favor aplicado. Usado: %1$s | Pedidos cerrados: %2$d | Pedidos parciales: %3$d',
			number_format_i18n( (float) ( $result['applied_total'] ?? 0 ), 2 ),
			count( $result['closed_order_ids'] ?? array() ),
			count( $result['partial_order_ids'] ?? array() )
		);

		do_action( 'asdl_fin_profile_credit_applied', $result );
		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $result['contact_id'] ?? 0 ) );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => absint( $result['contact_id'] ?? 0 ),
				'range_from'           => sanitize_text_field( wp_unslash( $_POST['range_from'] ?? '' ) ),
				'range_to'             => sanitize_text_field( wp_unslash( $_POST['range_to'] ?? '' ) ),
				'order_limit'          => absint( wp_unslash( $_POST['order_limit'] ?? 25 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function pay_profile_credit() {
		$this->guard_request( 'asdl_fin_pay_profile_credit' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$payload = wp_unslash( $_POST );
		$result  = ( new ProfileCreditPayoutService() )->pay_profile( $payload );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( $payload['contact_id'] ?? 0 ),
					'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
					'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
					'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$message = sprintf(
			'Pago al perfil registrado. Usado: %1$s | Documentos liquidados: %2$d | Documentos parciales: %3$d',
			number_format_i18n( (float) ( $result['total_applied'] ?? 0 ), 2 ),
			count( $result['settled_document_ids'] ?? array() ),
			count( $result['partial_document_ids'] ?? array() )
		);

		$this->log_event( 'payment', (int) ( $result['payment_id'] ?? 0 ), 'created', 'Pago al perfil registrado correctamente.' );
		do_action( 'asdl_fin_profile_credit_paid', $result );
		ContactOverviewService::bump_contact_snapshot_cache_version( (int) ( $result['contact_id'] ?? 0 ) );

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => absint( $result['contact_id'] ?? 0 ),
				'range_from'           => sanitize_text_field( $payload['range_from'] ?? '' ),
				'range_to'             => sanitize_text_field( $payload['range_to'] ?? '' ),
				'order_limit'          => absint( $payload['order_limit'] ?? 25 ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function allocate_payment() {
		$this->guard_request( 'asdl_fin_allocate_payment' );
		$this->guard_active_fiscal_context( 'asdl-fin-payments' );

		$service = new PaymentAllocationService();
		$result  = $service->allocate( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-payments',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$events = new EventsRepository();
		$events->log(
			'payment_allocation',
			(int) $result['allocation_id'],
			'created',
			'Asignacion registrada correctamente.',
			$result
		);

		do_action( 'asdl_fin_payment_allocated', $result );

		$this->redirect(
			'asdl-fin-payments',
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Asignacion registrada correctamente.' ),
			)
		);
	}

	public function save_installment_plan() {
		$this->guard_request( 'asdl_fin_save_installment_plan' );
		$this->guard_active_fiscal_context( 'asdl-fin-installments' );

		$repository = new InstallmentPlansRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-installments',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( 'installment_plan', (int) $result, 'created', 'Compromiso registrado correctamente.' );
		do_action( 'asdl_fin_installment_plan_created', (int) $result );

		$this->redirect(
			'asdl-fin-installments',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'Compromiso registrado correctamente.' ),
			)
		);
	}

	public function apply_commitment_payment() {
		$this->guard_request( 'asdl_fin_apply_commitment_payment' );
		$this->guard_active_fiscal_context( 'asdl-fin-contacts' );

		$service = new CommitmentSettlementService();
		$result  = $service->apply( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-contacts',
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'installment_plan',
			(int) ( $result['plan_id'] ?? 0 ),
			'payment_applied',
			'Movimiento aplicado correctamente sobre el compromiso.'
		);

		do_action( 'asdl_fin_commitment_payment_applied', $result );

		$message = sprintf(
			'Movimiento aplicado al compromiso. Monto aplicado: %1$s | Saldo restante: %2$s',
			number_format_i18n( (float) ( $result['applied_total'] ?? 0 ), 2 ),
			number_format_i18n( (float) ( $result['plan_balance'] ?? 0 ), 2 )
		);

		$this->redirect(
			'asdl-fin-contacts',
			array(
				'contact_id'           => absint( $result['contact_id'] ?? wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function save_rule() {
		$this->guard_request( 'asdl_fin_save_rule' );

		$repository = new RulesRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		$this->handle_result( $result, 'rule', 'Regla registrada correctamente.', 'asdl-fin-rules', 'asdl_fin_rule_created' );
	}

	public function toggle_rule() {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta accion.', 'asd-labs-finanzas' ) );
		}

		$rule_id   = absint( wp_unslash( $_GET['rule_id'] ?? 0 ) );
		$is_active = ! empty( $_GET['is_active'] );

		check_admin_referer( 'asdl_fin_toggle_rule_' . $rule_id );

		$result = ( new RulesRepository() )->set_active( $rule_id, $is_active );

		if ( ! $result ) {
			$this->redirect(
				'asdl-fin-rules',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'No se pudo actualizar el estado de la regla.' ),
				)
			);
		}

		$this->log_event(
			'rule',
			$rule_id,
			'updated',
			$is_active ? 'Regla activada correctamente.' : 'Regla desactivada correctamente.'
		);

		$this->redirect(
			'asdl-fin-rules',
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $is_active ? 'Regla activada correctamente.' : 'Regla desactivada correctamente.' ),
			)
		);
	}

	private function guard_request( $nonce_action ) {
		if ( ! current_user_can( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta accion.', 'asd-labs-finanzas' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function handle_result( $result, $entity_type, $success_message, $fallback_page, $hook_name ) {
		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$fallback_page,
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( $entity_type, (int) $result, 'created', $success_message );

		do_action( $hook_name, (int) $result );

		$this->redirect(
			$fallback_page,
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $success_message ),
			)
		);
	}

	private function store_document_file( $document_id ) {
		return ( new DocumentFilesRepository() )->store_uploaded_file( $document_id, 'document_file' );
	}

	private function log_event( $entity_type, $entity_id, $event_type, $message ) {
		$events = new EventsRepository();
		$events->log(
			$entity_type,
			$entity_id,
			$event_type,
			$message,
			array(
				'entity_type' => $entity_type,
				'entity_id'   => (int) $entity_id,
			)
		);
	}

	private function contact_role_flags_from_request( $default_customer = true ) {
		$flags = array(
			'is_customer'          => ! empty( $_POST['is_customer'] ),
			'is_employee'          => ! empty( $_POST['is_employee'] ),
			'is_supplier'          => ! empty( $_POST['is_supplier'] ),
			'internal_use_profile' => ! empty( $_POST['internal_use_profile'] ),
		);

		if ( $default_customer && ! $flags['is_customer'] && ! $flags['is_employee'] && ! $flags['is_supplier'] ) {
			$flags['is_customer'] = true;
		}

		return $flags;
	}

	private function guard_active_fiscal_context( $fallback_page ) {
		$selected_year = absint( wp_unslash( $_POST['fiscal_year'] ?? 0 ) );
		if ( $selected_year <= 0 ) {
			return;
		}

		$context = ( new FiscalYearService() )->get_context( $selected_year );
		if ( ! empty( $context['is_active'] ) ) {
			return;
		}

		$this->redirect(
			$fallback_page,
			array(
				'asdl_fin_notice'      => 'error',
				'asdl_fin_notice_text' => rawurlencode( 'Estas viendo un ejercicio fiscal historico. Para evitar errores, esta accion solo se permite en el ejercicio activo.' ),
			)
		);
	}

	private function redirect( $fallback_page, array $args ) {
		$page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : $fallback_page;
		$args = array_merge(
			array(
				'page' => $page,
			),
			$args
		);

		foreach ( array( 'contact_id', 'range_from', 'range_to', 'order_limit', 'from_date', 'to_date', 'limit' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				continue;
			}

			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = wp_unslash( $_POST[ $key ] );
			if ( '' === $value || null === $value ) {
				continue;
			}

			$args[ $key ] = in_array( $key, array( 'contact_id', 'order_limit', 'limit' ), true )
				? absint( $value )
				: sanitize_text_field( (string) $value );
		}

		$fiscal_year = absint( wp_unslash( $_POST['fiscal_year'] ?? 0 ) );
		if ( $fiscal_year > 0 ) {
			$args['fiscal_year'] = $fiscal_year;
		}

		$url            = add_query_arg( $args, admin_url( 'admin.php' ) );
		$return_section = isset( $_POST['return_section'] ) ? sanitize_key( wp_unslash( $_POST['return_section'] ) ) : '';

		if ( '' !== $return_section ) {
			$url .= '#' . $return_section;
		}

		wp_safe_redirect( $url );
		exit;
	}
}
