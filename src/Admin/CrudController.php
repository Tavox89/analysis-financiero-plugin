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
use ASDLabs\Finance\Finance\FinancialMasterReportService;
use ASDLabs\Finance\Finance\FinancialReportExportService;
use ASDLabs\Finance\Finance\FiscalYearService;
use ASDLabs\Finance\Finance\InstallmentPlansRepository;
use ASDLabs\Finance\Finance\MonthlyCloseSnapshotService;
use ASDLabs\Finance\Finance\PaymentMethodsService;
use ASDLabs\Finance\Finance\PayrollPeriodsRepository;
use ASDLabs\Finance\Finance\PaymentAllocationService;
use ASDLabs\Finance\Finance\PaymentAllocationsRepository;
use ASDLabs\Finance\Finance\PaymentsRepository;
use ASDLabs\Finance\Finance\ProfileCreditPayoutService;
use ASDLabs\Finance\Finance\ReceiptBrandingService;
use ASDLabs\Finance\Finance\RulesRepository;
use ASDLabs\Finance\Finance\RuntimeRefreshService;
use ASDLabs\Finance\Finance\ServiceProfilesRepository;
use ASDLabs\Finance\Finance\SourceLinksRepository;
use ASDLabs\Finance\Integrations\Woo\DualPricingService;
use ASDLabs\Finance\Integrations\Woo\OrderSyncService;
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
		add_action( 'wp_ajax_asdl_fin_save_payment_method_inline', array( $this, 'save_payment_method_inline' ) );
		add_action( 'wp_ajax_asdl_fin_save_currency_inline', array( $this, 'save_currency_inline' ) );
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
		add_action( 'admin_post_asdl_fin_export_master_report', array( $this, 'export_master_report' ) );
		add_action( 'admin_post_asdl_fin_generate_monthly_close', array( $this, 'generate_monthly_close' ) );
		add_action( 'admin_post_asdl_fin_mark_monthly_close_official', array( $this, 'mark_monthly_close_official' ) );
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			$contact_id
		);

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
			: ( 'asdl-fin-expenses' === $return_page
				? 'Gasto registrado correctamente.'
				: 'Movimiento registrado correctamente.' );

		$this->log_event( 'document', $document_id, 'created', $message );
		do_action( 'asdl_fin_document_created', $document_id );

		$attachment_result = $this->store_document_file( $document_id );
		if ( is_wp_error( $attachment_result ) ) {
			$message = 'asdl-fin-services' === $return_page
				? 'Servicio registrado correctamente, pero el comprobante no pudo adjuntarse.'
				: ( 'asdl-fin-expenses' === $return_page
					? 'Gasto registrado correctamente, pero el comprobante no pudo adjuntarse.'
					: 'Movimiento registrado correctamente, pero el comprobante no pudo adjuntarse.' );
		} elseif ( ! empty( $attachment_result['attachment_id'] ) ) {
			$message = 'asdl-fin-services' === $return_page
				? 'Servicio registrado correctamente con comprobante adjunto.'
				: ( 'asdl-fin-expenses' === $return_page
					? 'Gasto registrado correctamente con comprobante adjunto.'
					: 'Movimiento registrado correctamente con comprobante adjunto.' );
		}

		$redirect_args = array(
			'document_id'           => $document_id,
			'asdl_fin_notice'       => 'success',
			'asdl_fin_notice_text'  => rawurlencode( $message ),
		);

		$this->invalidate_document_context( $document_id );

		if ( 'asdl-fin-contacts' === $return_page ) {
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			$contact_id
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
		$this->invalidate_service_profile_context( (int) $result );

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
		$this->invalidate_service_profile_context( (int) $result );

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
		$this->invalidate_service_profile_context( (int) ( $result['profile_id'] ?? 0 ) );
		$this->invalidate_document_context( (int) ( $result['document_id'] ?? 0 ) );

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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			$contact_id
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			$contact_id
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

		$result = ( new PaymentMethodsService() )->save( wp_unslash( $_POST ) );

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
			! empty( $result['is_update'] ) ? 'payment_method_updated' : 'payment_method_created',
			sprintf( 'Metodo de pago guardado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) )
		);

		$message = sprintf( 'Metodo de pago guardado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) );
		if ( ! empty( $result['alias_fused'] ) ) {
			$message = sprintf( 'Se actualizo el metodo base %s en lugar de crear un duplicado.', sanitize_text_field( $result['label'] ?? '' ) );
		}

		$this->redirect(
			'asdl-fin-settings',
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $message ),
			)
		);
	}

	public function save_payment_method_inline() {
		if ( false === check_ajax_referer( 'asdl_fin_save_payment_method_inline', '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'La sesion del catalogo expiro. Recarga la pagina e intenta de nuevo.',
				),
				403
			);
		}

		$can_manage = function_exists( 'wc_current_user_has_role' )
			? current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' )
			: current_user_can( 'manage_options' );

		if ( ! $can_manage ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para registrar metodos de pago.',
				),
				403
			);
		}

		$result = ( new PaymentMethodsService() )->save( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		$this->log_event(
			'settings',
			0,
			! empty( $result['is_update'] ) ? 'payment_method_updated' : 'payment_method_created',
			sprintf( 'Metodo de pago guardado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) )
		);

		$dual_pricing = new DualPricingService();
		$method       = ( new PaymentMethodsService() )->method_snapshot( $result['key'] ?? '' );
		$eligibility  = $dual_pricing->get_method_eligibility_snapshot( $result['key'] ?? '' );
		$message      = sprintf( 'Metodo de pago guardado correctamente: %s.', sanitize_text_field( $result['label'] ?? '' ) );

		if ( ! empty( $result['alias_fused'] ) ) {
			$message = sprintf( 'Se actualizo el metodo base %s en lugar de crear un duplicado.', sanitize_text_field( $result['label'] ?? '' ) );
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'method' => array(
					'key'             => sanitize_key( (string) ( $result['key'] ?? '' ) ),
					'label'           => sanitize_text_field( (string) ( $result['label'] ?? '' ) ),
					'dualEligible'    => ! empty( $eligibility['eligible'] ),
					'dualSourceLabel' => sanitize_text_field( (string) ( $eligibility['source_label'] ?? 'No elegible' ) ),
					'kind'            => sanitize_key( (string) ( $method['kind'] ?? 'default' ) ),
					'isUpdate'        => ! empty( $result['is_update'] ),
					'aliasFused'      => ! empty( $result['alias_fused'] ),
				),
				'dualPricingMethodKeys' => array_values( $dual_pricing->get_divisa_method_keys() ),
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

	public function save_currency_inline() {
		if ( false === check_ajax_referer( 'asdl_fin_save_currency_inline', '_ajax_nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'La sesion del catalogo de monedas expiro. Recarga la pagina e intenta de nuevo.',
				),
				403
			);
		}

		$can_manage = function_exists( 'wc_current_user_has_role' )
			? current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' )
			: current_user_can( 'manage_options' );

		if ( ! $can_manage ) {
			wp_send_json_error(
				array(
					'message' => 'No tienes permisos para registrar monedas.',
				),
				403
			);
		}

		$result = ( new CurrenciesService() )->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				400
			);
		}

		$this->log_event(
			'settings',
			0,
			'currency_created',
			sprintf( 'Moneda registrada correctamente: %s.', sanitize_text_field( $result['code'] ?? '' ) )
		);

		wp_send_json_success(
			array(
				'message'  => sprintf( 'Moneda registrada correctamente: %s.', sanitize_text_field( $result['code'] ?? '' ) ),
				'currency' => array(
					'code'  => sanitize_text_field( (string) ( $result['code'] ?? '' ) ),
					'label' => sanitize_text_field( (string) ( $result['label'] ?? '' ) ),
					'kind'  => 'custom',
				),
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			(int) ( $result['contact_id'] ?? $contact_id )
		);

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
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-documents';
		$this->guard_active_fiscal_context( $return_page );
		$repository  = new DocumentsRepository();
		$result      = $repository->update_manual( $document_id, wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
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
		$this->invalidate_document_context( $document_id );

		$attachment_result = $this->store_document_file( $document_id );
		$message           = 'asdl-fin-expenses' === $return_page ? 'Gasto actualizado correctamente.' : 'Movimiento actualizado correctamente.';

		if ( is_wp_error( $attachment_result ) ) {
			$message = 'asdl-fin-expenses' === $return_page
				? 'Gasto actualizado correctamente, pero el comprobante no pudo adjuntarse.'
				: 'Movimiento actualizado correctamente, pero el comprobante no pudo adjuntarse.';
		} elseif ( ! empty( $attachment_result['attachment_id'] ) ) {
			$message = 'asdl-fin-expenses' === $return_page
				? 'Gasto actualizado correctamente con comprobante adjunto.'
				: 'Movimiento actualizado correctamente con comprobante adjunto.';
		}

		$this->redirect(
			$return_page,
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
		$this->invalidate_payment_context( (int) ( $result['payment_id'] ?? 0 ), (int) ( $result['contact_id'] ?? absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ) ) );

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
		$return_page = isset( $_POST['return_page'] ) ? sanitize_key( wp_unslash( $_POST['return_page'] ) ) : 'asdl-fin-documents';
		$this->guard_active_fiscal_context( $return_page );

		$result = ( new CancellationService() )->cancel_document(
			absint( wp_unslash( $_POST['document_id'] ?? 0 ) ),
			wp_unslash( $_POST['cancel_reason'] ?? '' ),
			array(
				'origin' => 'admin',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
				array(
					'document_id'           => absint( wp_unslash( $_POST['document_id'] ?? 0 ) ),
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}
		$this->invalidate_document_context( (int) ( $result['document_id'] ?? absint( wp_unslash( $_POST['document_id'] ?? 0 ) ) ), (int) ( $result['contact_id'] ?? absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ) ) );

		$this->redirect(
			$return_page,
			array(
				'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'asdl-fin-expenses' === $return_page ? 'Gasto anulado correctamente.' : 'Movimiento anulado correctamente.' ),
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			absint( wp_unslash( $_POST['contact_id'] ?? 0 ) )
		);

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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			(int) ( $result['contact_id'] ?? absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ) )
		);

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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_HISTORICAL_DATA,
			),
			(int) ( $result['contact_id'] ?? 0 )
		);

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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_HISTORICAL_DATA,
			),
			(int) ( $result['contact_id'] ?? 0 )
		);

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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
			),
			(int) ( $result['contact_id'] ?? 0 )
		);

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
		$this->invalidate_document_context( (int) ( $result['document_id'] ?? 0 ) );
		$this->invalidate_payment_context( (int) ( $result['payment_id'] ?? 0 ) );

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
		$return_page = sanitize_key( (string) ( wp_unslash( $_POST['return_page'] ?? 'asdl-fin-installments' ) ) );
		if ( '' === $return_page ) {
			$return_page = 'asdl-fin-installments';
		}

		$this->guard_active_fiscal_context( $return_page );

		$repository = new InstallmentPlansRepository();
		$result     = $repository->create( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				$return_page,
				array(
					'contact_id'           => absint( wp_unslash( $_POST['contact_id'] ?? 0 ) ),
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event( 'installment_plan', (int) $result, 'created', 'Compromiso registrado correctamente.' );
		do_action( 'asdl_fin_installment_plan_created', (int) $result );
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			absint( wp_unslash( $_POST['contact_id'] ?? 0 ) )
		);

		$this->redirect(
			$return_page,
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
		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
				RuntimeRefreshService::SCOPE_PAYROLL,
			),
			(int) ( $result['contact_id'] ?? wp_unslash( $_POST['contact_id'] ?? 0 ) )
		);

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

	public function export_master_report() {
		$this->guard_request( 'asdl_fin_export_master_report' );

		try {
			$service = new FinancialMasterReportService();
			$payload = $service->get_payload( $this->report_request_args_from_post() );
		} catch ( \Throwable $throwable ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'report_mode'          => sanitize_key( (string) ( wp_unslash( $_POST['report_mode'] ?? 'total' ) ) ),
					'report_month'         => sanitize_text_field( (string) ( wp_unslash( $_POST['report_month'] ?? '' ) ) ),
					'report_run'           => 1,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'No se pudo exportar el reporte: ' . $throwable->getMessage() ),
				)
			);
		}

		( new FinancialReportExportService() )->send_csv( $payload, $service->export_filename( $payload ) );
	}

	public function generate_monthly_close() {
		$this->guard_request( 'asdl_fin_generate_monthly_close' );

		$request_args = $this->report_request_args_from_post();
		$service      = new FinancialMasterReportService();
		$context      = $service->resolve_context( $request_args );

		if ( 'monthly_close' !== sanitize_key( (string) ( $context['mode'] ?? '' ) ) ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'El cierre oficial solo se puede generar en modo mensual.' ),
				)
			);
		}

		try {
			$payload = $service->get_payload(
				array_merge(
					$request_args,
					array(
						'snapshot_id' => 0,
					)
				)
			);
		} catch ( \Throwable $throwable ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'report_mode'          => 'monthly_close',
					'report_month'         => sanitize_text_field( (string) ( $context['month_key'] ?? '' ) ),
					'report_run'           => 1,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( 'No se pudo generar el cierre mensual: ' . $throwable->getMessage() ),
				)
			);
		}

		$gate = (array) ( $payload['meta']['monthly_close_gate'] ?? array() );
		if ( empty( $gate['can_generate_official'] ) ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'report_mode'          => 'monthly_close',
					'report_month'         => sanitize_text_field( (string) ( $context['month_key'] ?? '' ) ),
					'report_run'           => 1,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( sanitize_text_field( (string) ( $gate['reason'] ?? 'Este mes solo puede verse como provisional por ahora.' ) ) ),
				)
			);
		}

		$snapshot_id  = ( new MonthlyCloseSnapshotService() )->create_snapshot(
			array(
				'month_key'       => $context['month_key'],
				'range_from'      => $context['range_from'],
				'range_to'        => $context['range_to'],
				'fiscal_context'  => (array) ( $context['fiscal_context'] ?? array() ),
				'filters'         => array(
					'mode'          => $context['mode'],
					'range_from'    => $context['range_from'],
					'range_to'      => $context['range_to'],
					'month_key'     => $context['month_key'],
					'sales_filters' => (array) ( $context['sales_filters'] ?? array() ),
				),
				'payload'         => $payload,
				'status'          => 'generated',
				'is_official'     => false,
			)
		);

		if ( is_wp_error( $snapshot_id ) ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'report_mode'         => 'monthly_close',
					'report_month'        => sanitize_text_field( (string) ( $context['month_key'] ?? '' ) ),
					'asdl_fin_notice'     => 'error',
					'asdl_fin_notice_text'=> rawurlencode( $snapshot_id->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'report_snapshot',
			(int) $snapshot_id,
			'created',
			sprintf( 'Cierre mensual generado para %s.', sanitize_text_field( (string) ( $context['month_key'] ?? '' ) ) )
		);

		$this->redirect(
			'asdl-fin-reports',
				array(
					'report_mode'          => 'monthly_close',
					'report_month'         => sanitize_text_field( (string) ( $context['month_key'] ?? '' ) ),
					'report_run'           => 1,
					'report_snapshot_id'   => (int) $snapshot_id,
					'asdl_fin_notice'      => 'success',
					'asdl_fin_notice_text' => rawurlencode( 'Nueva version del cierre mensual generada correctamente.' ),
			)
		);
	}

	public function mark_monthly_close_official() {
		$this->guard_request( 'asdl_fin_mark_monthly_close_official' );

		$snapshot_id = absint( wp_unslash( $_POST['snapshot_id'] ?? 0 ) );
		$result      = ( new MonthlyCloseSnapshotService() )->mark_official( $snapshot_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'asdl-fin-reports',
				array(
					'report_mode'          => sanitize_key( (string) ( wp_unslash( $_POST['report_mode'] ?? 'monthly_close' ) ) ),
					'report_month'         => sanitize_text_field( (string) ( wp_unslash( $_POST['report_month'] ?? '' ) ) ),
					'report_snapshot_id'   => $snapshot_id,
					'asdl_fin_notice'      => 'error',
					'asdl_fin_notice_text' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->log_event(
			'report_snapshot',
			$snapshot_id,
			'official',
			sprintf( 'Version oficial del cierre mensual actualizada: #%d.', $snapshot_id )
		);

		$this->redirect(
			'asdl-fin-reports',
			array(
				'report_mode'          => sanitize_key( (string) ( wp_unslash( $_POST['report_mode'] ?? 'monthly_close' ) ) ),
				'report_month'         => sanitize_text_field( (string) ( wp_unslash( $_POST['report_month'] ?? '' ) ) ),
				'report_snapshot_id'   => $snapshot_id,
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( 'La version seleccionada ahora es la oficial del mes.' ),
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
		$this->invalidate_created_entity_runtime( $entity_type, (int) $result );

		$this->redirect(
			$fallback_page,
			array(
				'asdl_fin_notice'      => 'success',
				'asdl_fin_notice_text' => rawurlencode( $success_message ),
			)
		);
	}

	private function invalidate_created_entity_runtime( $entity_type, $entity_id ) {
		switch ( sanitize_key( (string) $entity_type ) ) {
			case 'document':
				$this->invalidate_document_context( (int) $entity_id );
				break;
			case 'payment':
				$this->invalidate_payment_context( (int) $entity_id );
				break;
			case 'installment_plan':
				$this->invalidate_runtime_scopes(
					array(
						RuntimeRefreshService::SCOPE_CONTACT,
						RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
						RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
						RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
					),
					absint( wp_unslash( $_POST['contact_id'] ?? 0 ) )
				);
				break;
			case 'contact':
				$this->invalidate_runtime_scopes(
					array(
						RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
					)
				);
				break;
		}
	}

	private function invalidate_runtime_scopes( array $scopes, $contact_id = 0 ) {
		RuntimeRefreshService::invalidate(
			$scopes,
			array(
				'contact_id' => absint( $contact_id ),
			)
		);
	}

	private function invalidate_document_context( $document_id, $fallback_contact_id = 0 ) {
		$document   = ( new DocumentsRepository() )->find( $document_id );
		$contact_id = (int) ( $document['contact_id'] ?? 0 );

		if ( $contact_id <= 0 ) {
			$contact_id = absint( $fallback_contact_id );
		}

		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
			),
			$contact_id
		);

		$this->invalidate_order_runtime_for_document( $document );
	}

	private function invalidate_payment_context( $payment_id, $fallback_contact_id = 0 ) {
		$payment    = ( new PaymentsRepository() )->find( $payment_id );
		$contact_id = (int) ( $payment['contact_id'] ?? 0 );

		if ( $contact_id <= 0 ) {
			$contact_id = absint( $fallback_contact_id );
		}

		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_RECEIVABLES,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
			),
			$contact_id
		);

		$this->invalidate_order_runtime_for_payment( $payment_id );
	}

	private function invalidate_order_runtime_for_payment( $payment_id ) {
		$payment_id = absint( $payment_id );

		if ( $payment_id <= 0 ) {
			return;
		}

		$allocations = ( new PaymentAllocationsRepository() )->for_payment( $payment_id, 200 );
		if ( empty( $allocations ) ) {
			return;
		}

		$documents = new DocumentsRepository();

		foreach ( $allocations as $allocation ) {
			$document_id = (int) ( $allocation['document_id'] ?? 0 );
			if ( $document_id <= 0 ) {
				continue;
			}

			$document = $documents->find( $document_id );
			if ( $this->document_touches_order_runtime( $document ) ) {
				OrderSyncService::invalidate_cached_views();
				return;
			}
		}
	}

	private function invalidate_order_runtime_for_document( $document ) {
		if ( $this->document_touches_order_runtime( $document ) ) {
			OrderSyncService::invalidate_cached_views();
		}
	}

	private function document_touches_order_runtime( $document ) {
		if ( empty( $document['id'] ) ) {
			return false;
		}

		$document_id         = (int) $document['id'];
		$document_type       = sanitize_key( (string) ( $document['document_type'] ?? '' ) );
		$source_type         = sanitize_key( (string) ( $document['source_type'] ?? '' ) );
		$external_reference  = (string) ( $document['external_reference'] ?? '' );
		$source_links        = ( new SourceLinksRepository() )->find_for_document( $document_id );

		if ( ! empty( $source_links ) ) {
			return true;
		}

		if ( in_array( $document_type, array( 'woo_sale' ), true ) ) {
			return true;
		}

		if ( in_array( $source_type, array( 'woocommerce', 'openpos' ), true ) ) {
			return true;
		}

		return 0 === strpos( $external_reference, 'shop_order:' );
	}

	private function invalidate_service_profile_context( $profile_id ) {
		$profile    = ( new ServiceProfilesRepository() )->find( $profile_id );
		$contact_id = (int) ( $profile['contact_id'] ?? 0 );

		$this->invalidate_runtime_scopes(
			array(
				RuntimeRefreshService::SCOPE_CONTACT,
				RuntimeRefreshService::SCOPE_DASHBOARD_SUMMARY,
				RuntimeRefreshService::SCOPE_DASHBOARD_PAYABLES,
			),
			$contact_id
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

		foreach ( array( 'contact_id', 'range_from', 'range_to', 'order_limit', 'from_date', 'to_date', 'limit', 'expense_search', 'expense_financial_status', 'expense_payment_status', 'expense_range_from', 'expense_range_to', 'expense_open_only', 'expense_has_contact', 'expense_contact_id' ) as $key ) {
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

			$args[ $key ] = in_array( $key, array( 'contact_id', 'order_limit', 'limit', 'expense_contact_id' ), true )
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

	private function report_request_args_from_post() {
		$args = array(
			'run'                     => 1,
			'mode'                    => sanitize_key( (string) ( wp_unslash( $_POST['report_mode'] ?? 'total' ) ) ),
			'range_from'              => sanitize_text_field( (string) ( wp_unslash( $_POST['report_range_from'] ?? '' ) ) ),
			'range_to'                => sanitize_text_field( (string) ( wp_unslash( $_POST['report_range_to'] ?? '' ) ) ),
			'month_key'               => sanitize_text_field( (string) ( wp_unslash( $_POST['report_month'] ?? '' ) ) ),
			'snapshot_id'             => absint( wp_unslash( $_POST['report_snapshot_id'] ?? 0 ) ),
			'sales_exclude_categories'=> sanitize_text_field( (string) ( wp_unslash( $_POST['sales_exclude_categories'] ?? '' ) ) ),
			'fiscal_year'             => absint( wp_unslash( $_POST['fiscal_year'] ?? 0 ) ),
		);

		if ( isset( $_POST['sales_statuses'] ) ) {
			$args['sales_statuses'] = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						(array) wp_unslash( $_POST['sales_statuses'] )
					)
				)
			);
		}

		if ( isset( $_POST['pending_statuses'] ) ) {
			$args['pending_statuses'] = array_values(
				array_filter(
					array_map(
						static function ( $value ) {
							return sanitize_text_field( (string) $value );
						},
						(array) wp_unslash( $_POST['pending_statuses'] )
					)
				)
			);
		}

		return $args;
	}
}
