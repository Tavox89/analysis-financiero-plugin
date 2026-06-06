<?php

namespace ASDLabs\Finance\Integrations\Approvals;

use ASDLabs\Finance\Core\Contracts\Module as ModuleContract;

final class Module implements ModuleContract {
	public function register() {
		add_action( 'init', array( $this, 'register_policies' ) );
	}

	public function register_policies() {
		( new ApprovalBridge() )->register_policies();
	}
}
