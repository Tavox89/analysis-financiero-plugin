<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AFP_Settings {
    const OPTION_NAME = 'afp_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_submenu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public static function get_defaults() {
        return array(
            'cost_source'      => 'yith',
            'cost_meta_key'    => 'yith_cog_cost',
            'custom_cost_meta_key' => '',
            'sales_statuses'   => array( 'wc-completed' ),
            'pending_statuses' => array( 'wc-pending', 'wc-on-hold' ),
            'sales_basis'      => 'order_total',
            'exclude_mode'     => 'order',
            'include_refunds'  => 1,
            'cache_minutes'    => 0,
            'top_limit'        => 10,
            'alert_margin_threshold' => 10,
        );
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_NAME, array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }
        return array_merge( self::get_defaults(), $saved );
    }

    public static function sanitize_status_list( $raw, array $fallback ) {
        $statuses = wc_get_order_statuses();
        $valid_keys = array_keys( $statuses );

        $values = is_array( $raw ) ? $raw : array( $raw );
        $values = array_map( 'sanitize_text_field', $values );
        $values = array_values( array_intersect( $values, $valid_keys ) );

        return ! empty( $values ) ? $values : $fallback;
    }

    public function add_settings_submenu() {
        add_submenu_page(
            'woocommerce',
            'Ajustes Analisis Financiero',
            'Ajustes Analisis Financiero',
            'manage_woocommerce',
            'analysis-financiero-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'afp_settings_group',
            self::OPTION_NAME,
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'afp_settings_main',
            'Configuracion General',
            '__return_null',
            'analysis-financiero-settings'
        );

        add_settings_field(
            'cost_source',
            'Fuente de costo',
            array( $this, 'render_cost_source_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'custom_cost_meta_key',
            'Meta personalizada',
            array( $this, 'render_cost_meta_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'sales_statuses',
            'Estados para ventas',
            array( $this, 'render_sales_statuses_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'pending_statuses',
            'Estados para pendientes',
            array( $this, 'render_pending_statuses_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'sales_basis',
            'Base de ventas',
            array( $this, 'render_sales_basis_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'exclude_mode',
            'Modo de exclusion',
            array( $this, 'render_exclude_mode_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'include_refunds',
            'Incluir reembolsos',
            array( $this, 'render_include_refunds_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'cache_minutes',
            'Cache de reportes (minutos)',
            array( $this, 'render_cache_minutes_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'top_limit',
            'Limite de Top productos/categorias',
            array( $this, 'render_top_limit_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );

        add_settings_field(
            'alert_margin_threshold',
            'Alerta de margen neto (%)',
            array( $this, 'render_alert_margin_field' ),
            'analysis-financiero-settings',
            'afp_settings_main'
        );
    }

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>Ajustes Analisis Financiero</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'afp_settings_group' );
        do_settings_sections( 'analysis-financiero-settings' );
        submit_button( 'Guardar cambios' );
        echo '</form>';
        echo '<script>
            (function(){
                var source = document.getElementById("afp_cost_source");
                var customInput = document.getElementById("afp_custom_cost_meta");
                if (!source || !customInput) { return; }
                function toggleCustom(){
                    var enabled = source.value === "custom";
                    customInput.disabled = !enabled;
                }
                source.addEventListener("change", toggleCustom);
                toggleCustom();
            })();
        </script>';
        echo '</div>';
    }

    public function sanitize_settings( $input ) {
        $defaults = self::get_defaults();
        $output = array();

        $cost_source = isset( $input['cost_source'] ) ? sanitize_text_field( $input['cost_source'] ) : $defaults['cost_source'];
        if ( ! in_array( $cost_source, array( 'yith', 'skyverge', 'custom' ), true ) ) {
            $cost_source = $defaults['cost_source'];
        }

        $custom_key = isset( $input['custom_cost_meta_key'] ) ? sanitize_key( $input['custom_cost_meta_key'] ) : '';
        $output['custom_cost_meta_key'] = $custom_key;
        $output['cost_source'] = $cost_source;

        if ( $cost_source === 'yith' ) {
            $output['cost_meta_key'] = 'yith_cog_cost';
        } elseif ( $cost_source === 'skyverge' ) {
            $output['cost_meta_key'] = '_wc_cog_cost';
        } else {
            $output['cost_meta_key'] = $custom_key !== '' ? $custom_key : $defaults['cost_meta_key'];
        }

        $output['sales_statuses'] = $this->sanitize_statuses( $input, 'sales_statuses', $defaults['sales_statuses'] );
        $output['pending_statuses'] = $this->sanitize_statuses( $input, 'pending_statuses', $defaults['pending_statuses'] );

        $allowed_basis = array( 'order_total', 'order_total_no_tax', 'items_net', 'items_gross' );
        $output['sales_basis'] = ( isset( $input['sales_basis'] ) && in_array( $input['sales_basis'], $allowed_basis, true ) )
            ? $input['sales_basis']
            : $defaults['sales_basis'];

        $output['exclude_mode'] = ( isset( $input['exclude_mode'] ) && in_array( $input['exclude_mode'], array( 'order', 'line' ), true ) )
            ? $input['exclude_mode']
            : $defaults['exclude_mode'];

        $output['include_refunds'] = isset( $input['include_refunds'] ) ? 1 : 0;

        $cache = isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : $defaults['cache_minutes'];
        $output['cache_minutes'] = min( $cache, 1440 );

        $top_limit = isset( $input['top_limit'] ) ? absint( $input['top_limit'] ) : $defaults['top_limit'];
        $output['top_limit'] = $top_limit > 0 ? min( $top_limit, 50 ) : $defaults['top_limit'];

        $threshold = isset( $input['alert_margin_threshold'] ) ? (float) $input['alert_margin_threshold'] : $defaults['alert_margin_threshold'];
        $output['alert_margin_threshold'] = max( 0, min( 100, $threshold ) );

        return $output;
    }

    private function sanitize_statuses( $input, $key, $default ) {
        $raw = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
        return self::sanitize_status_list( $raw, $default );
    }

    public function render_cost_meta_field() {
        $settings = self::get_settings();
        printf(
            '<input type="text" name="%1$s[custom_cost_meta_key]" value="%2$s" class="regular-text" id="afp_custom_cost_meta" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $settings['custom_cost_meta_key'] )
        );
        echo '<p class="description">Solo se usa si seleccionas \"Personalizada\".</p>';
    }

    public function render_cost_source_field() {
        $settings = self::get_settings();
        $options = array(
            'yith' => 'YITH Cost of Goods (meta: yith_cog_cost)',
            'skyverge' => 'WooCommerce Cost of Goods (SkyVerge) (meta: _wc_cog_cost)',
            'custom' => 'Personalizada',
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[cost_source]" id="afp_cost_source">';
        foreach ( $options as $key => $label ) {
            $selected = selected( $settings['cost_source'], $key, false );
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), $selected, esc_html( $label ) );
        }
        echo '</select>';
        echo '<p class="description">Selecciona la fuente del costo del producto.</p>';
    }

    public function render_sales_statuses_field() {
        $settings = self::get_settings();
        $statuses = wc_get_order_statuses();
        foreach ( $statuses as $status_key => $label ) {
            $checked = in_array( $status_key, $settings['sales_statuses'], true ) ? 'checked' : '';
            printf(
                '<label style="display:block;"><input type="checkbox" name="%1$s[sales_statuses][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr( self::OPTION_NAME ),
                esc_attr( $status_key ),
                $checked,
                esc_html( $label )
            );
        }
        echo '<p class="description">Pedidos que se consideran ventas reales.</p>';
    }

    public function render_pending_statuses_field() {
        $settings = self::get_settings();
        $statuses = wc_get_order_statuses();
        foreach ( $statuses as $status_key => $label ) {
            $checked = in_array( $status_key, $settings['pending_statuses'], true ) ? 'checked' : '';
            printf(
                '<label style="display:block;"><input type="checkbox" name="%1$s[pending_statuses][]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr( self::OPTION_NAME ),
                esc_attr( $status_key ),
                $checked,
                esc_html( $label )
            );
        }
        echo '<p class="description">Pedidos pendientes o en espera para facturas por cobrar.</p>';
    }

    public function render_sales_basis_field() {
        $settings = self::get_settings();
        $options = array(
            'order_total' => 'Total del pedido (incluye impuestos y envio)',
            'order_total_no_tax' => 'Total del pedido sin impuestos',
            'items_net' => 'Items netos (sin impuestos/envio, con descuentos)',
            'items_gross' => 'Items brutos (sin impuestos/envio, sin descuentos)',
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[sales_basis]">';
        foreach ( $options as $key => $label ) {
            $selected = selected( $settings['sales_basis'], $key, false );
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), $selected, esc_html( $label ) );
        }
        echo '</select>';
        echo '<p class="description">Define como se calculan las ventas.</p>';
    }

    public function render_exclude_mode_field() {
        $settings = self::get_settings();
        $options = array(
            'order' => 'Excluir el pedido completo si contiene la categoria',
            'line' => 'Excluir solo los productos de la categoria',
        );
        echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[exclude_mode]">';
        foreach ( $options as $key => $label ) {
            $selected = selected( $settings['exclude_mode'], $key, false );
            printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), $selected, esc_html( $label ) );
        }
        echo '</select>';
        echo '<p class="description">Cuando se excluyen categorias desde el reporte.</p>';
    }

    public function render_include_refunds_field() {
        $settings = self::get_settings();
        $checked = ! empty( $settings['include_refunds'] ) ? 'checked' : '';
        printf(
            '<label><input type="checkbox" name="%1$s[include_refunds]" value="1" %2$s /> Descontar reembolsos del periodo</label>',
            esc_attr( self::OPTION_NAME ),
            $checked
        );
    }

    public function render_cache_minutes_field() {
        $settings = self::get_settings();
        printf(
            '<input type="number" name="%1$s[cache_minutes]" value="%2$d" min="0" max="1440" />',
            esc_attr( self::OPTION_NAME ),
            (int) $settings['cache_minutes']
        );
        echo '<p class="description">0 para desactivar cache. Maximo 1440.</p>';
    }

    public function render_top_limit_field() {
        $settings = self::get_settings();
        printf(
            '<input type="number" name="%1$s[top_limit]" value="%2$d" min="1" max="50" />',
            esc_attr( self::OPTION_NAME ),
            (int) $settings['top_limit']
        );
        echo '<p class="description">Cantidad de productos/categorias a mostrar en el Top.</p>';
    }

    public function render_alert_margin_field() {
        $settings = self::get_settings();
        printf(
            '<input type="number" name="%1$s[alert_margin_threshold]" value="%2$s" min="0" max="100" step="0.1" />',
            esc_attr( self::OPTION_NAME ),
            esc_attr( $settings['alert_margin_threshold'] )
        );
        echo '<p class="description">Se mostrara una alerta cuando el margen neto sea menor a este valor.</p>';
    }
}
