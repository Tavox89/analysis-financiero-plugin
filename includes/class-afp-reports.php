<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AFP_Reports {
    private static $product_cost_cache = array();
    private static $product_category_cache = array();
    private static $order_exclude_cache = array();

    public static function parse_exclude_categories( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) {
            return array();
        }

        $parts = preg_split( '/[;,]+/', $raw );
        if ( ! is_array( $parts ) ) {
            return array();
        }

        $term_ids = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( $part === '' ) {
                continue;
            }

            if ( ctype_digit( $part ) ) {
                $term = get_term( (int) $part, 'product_cat' );
            } else {
                $term = get_term_by( 'slug', $part, 'product_cat' );
            }

            if ( $term && ! is_wp_error( $term ) ) {
                $term_ids[] = (int) $term->term_id;
            }
        }

        return array_values( array_unique( $term_ids ) );
    }

    public static function get_summary( $start_date, $end_date, array $exclude_term_ids = array(), array $overrides = array() ) {
        $settings = AFP_Settings::get_settings();
        if ( ! empty( $overrides['sales_statuses'] ) && is_array( $overrides['sales_statuses'] ) ) {
            $settings['sales_statuses'] = $overrides['sales_statuses'];
        }
        if ( ! empty( $overrides['pending_statuses'] ) && is_array( $overrides['pending_statuses'] ) ) {
            $settings['pending_statuses'] = $overrides['pending_statuses'];
        }
        $cache_key = self::build_cache_key( $start_date, $end_date, $exclude_term_ids, $settings );

        if ( $settings['cache_minutes'] > 0 ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $response = self::calculate_summary_payload( $start_date, $end_date, $exclude_term_ids, $settings, true );

        list( $compare_start, $compare_end ) = self::get_previous_range( $start_date, $end_date );
        $compare_payload = self::calculate_summary_payload( $compare_start, $compare_end, $exclude_term_ids, $settings, false );

        $response['compare'] = array(
            'range' => array(
                'start' => $compare_start,
                'end' => $compare_end,
            ),
            'totals' => $compare_payload['totals'],
            'pendiente' => $compare_payload['pendiente'],
            'pendiente_count' => $compare_payload['pendiente_count'],
            'series' => $compare_payload['series'],
        );

        if ( $settings['cache_minutes'] > 0 ) {
            set_transient( $cache_key, $response, $settings['cache_minutes'] * MINUTE_IN_SECONDS );
        }

        return $response;
    }

    private static function calculate_summary_payload( $start_date, $end_date, array $exclude_term_ids, array $settings, $include_details ) {
        $date_range = self::build_date_range( $start_date, $end_date );
        $exclude_term_ids = array_values( array_filter( array_map( 'absint', $exclude_term_ids ) ) );

        $totals = array(
            'ventas_brutas'   => 0,
            'reembolsos'      => 0,
            'ventas_netas'    => 0,
            'inversion'       => 0,
            'pedidos'         => 0,
            'unidades'        => 0,
            'margen_bruto_pct' => 0,
            'tasa_reembolso'  => 0,
            'costo_promedio_unidad' => 0,
            'venta_promedio_unidad' => 0,
            'top_mas_vendido' => array(),
            'top_menos_vendido' => array(),
        );

        $day_totals = array();
        $product_totals = array();
        $category_totals = array();

        self::process_orders(
            $settings['sales_statuses'],
            $date_range,
            $exclude_term_ids,
            $settings,
            $totals,
            $day_totals,
            $product_totals,
            $category_totals
        );

        $pending_data = self::process_pending(
            $settings['pending_statuses'],
            $date_range,
            $exclude_term_ids,
            $settings
        );

        if ( ! empty( $settings['include_refunds'] ) ) {
            $totals['reembolsos'] = self::process_refunds( $date_range, $exclude_term_ids, $settings );
        }

        $totals['ventas_netas'] = $totals['ventas_brutas'] - $totals['reembolsos'];
        $ganancia_bruta = $totals['ventas_netas'] - $totals['inversion'];

        if ( $totals['ventas_netas'] > 0 ) {
            $totals['margen_bruto_pct'] = ( $ganancia_bruta / $totals['ventas_netas'] ) * 100;
        }

        if ( $totals['ventas_brutas'] > 0 ) {
            $totals['tasa_reembolso'] = ( $totals['reembolsos'] / $totals['ventas_brutas'] ) * 100;
        }

        if ( $totals['unidades'] > 0 ) {
            $totals['costo_promedio_unidad'] = $totals['inversion'] / $totals['unidades'];
            $totals['venta_promedio_unidad'] = $totals['ventas_netas'] / $totals['unidades'];
        }

        if ( $include_details ) {
            $totals['top_mas_vendido'] = self::format_top_days( $day_totals, 'desc', 5 );
            $totals['top_menos_vendido'] = self::format_top_days( $day_totals, 'asc', 5 );
        }

        $series = self::build_series( $start_date, $end_date, $day_totals );

        $limit = ! empty( $settings['top_limit'] ) ? (int) $settings['top_limit'] : 10;
        $products = $include_details ? self::format_top_entities( $product_totals, $limit ) : array();
        $categories = $include_details ? self::format_top_categories( $category_totals, $limit ) : array();

        return array(
            'totals' => $totals,
            'pendiente' => $pending_data['total'],
            'pendiente_count' => $pending_data['count'],
            'productos' => $products,
            'categorias' => $categories,
            'series' => $series,
        );
    }

    private static function get_previous_range( $start_date, $end_date ) {
        $timezone = wp_timezone();
        $start = new DateTime( $start_date, $timezone );
        $end = new DateTime( $end_date, $timezone );
        $diff = $start->diff( $end )->days;

        $compare_end = clone $start;
        $compare_end->modify( '-1 day' );

        $compare_start = clone $compare_end;
        $compare_start->modify( '-' . $diff . ' days' );

        return array( $compare_start->format( 'Y-m-d' ), $compare_end->format( 'Y-m-d' ) );
    }

    private static function build_series( $start_date, $end_date, array $day_totals ) {
        $series = array();
        $timezone = wp_timezone();
        $start = new DateTime( $start_date, $timezone );
        $end = new DateTime( $end_date, $timezone );
        $end->setTime( 0, 0, 0 );

        $current = clone $start;
        while ( $current <= $end ) {
            $key = $current->format( 'Y-m-d' );
            $series[] = array(
                'fecha' => $key,
                'total' => isset( $day_totals[ $key ] ) ? (float) $day_totals[ $key ] : 0,
            );
            $current->modify( '+1 day' );
        }

        return $series;
    }

    private static function build_cache_key( $start_date, $end_date, array $exclude_term_ids, array $settings ) {
        $payload = array(
            'start' => $start_date,
            'end' => $end_date,
            'exclude' => $exclude_term_ids,
            'settings' => array(
                'cost_meta_key' => $settings['cost_meta_key'],
                'sales_statuses' => $settings['sales_statuses'],
                'pending_statuses' => $settings['pending_statuses'],
                'sales_basis' => $settings['sales_basis'],
                'exclude_mode' => $settings['exclude_mode'],
                'include_refunds' => $settings['include_refunds'],
                'top_limit' => $settings['top_limit'],
            ),
        );

        return 'afp_report_' . md5( wp_json_encode( $payload ) );
    }

    private static function build_date_range( $start_date, $end_date ) {
        return $start_date . ' 00:00:00...' . $end_date . ' 23:59:59';
    }

    private static function process_orders( array $statuses, $date_range, array $exclude_term_ids, array $settings, array &$totals, array &$day_totals, array &$product_totals, array &$category_totals ) {
        $page = 1;
        $limit = 200;
        $max_pages = 1;

        do {
            $args = array(
                'type' => 'shop_order',
                'status' => $statuses,
                'limit' => $limit,
                'paginate' => true,
                'page' => $page,
                'date_created' => $date_range,
                'orderby' => 'date',
                'order' => 'ASC',
            );

            $result = wc_get_orders( $args );
            $orders = array();

            if ( is_object( $result ) && isset( $result->orders ) ) {
                $orders = $result->orders;
                $max_pages = max( 1, (int) $result->max_num_pages );
            } elseif ( is_array( $result ) ) {
                $orders = $result;
                $max_pages = 1;
            }

            foreach ( $orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                if ( self::should_exclude_order( $order, $exclude_term_ids, $settings ) ) {
                    continue;
                }

                $order_totals = self::calculate_order_totals( $order, $exclude_term_ids, $settings, $product_totals, $category_totals );

                $totals['ventas_brutas'] += $order_totals['ventas'];
                $totals['inversion'] += $order_totals['inversion'];
                $totals['pedidos'] += 1;
                $totals['unidades'] += $order_totals['unidades'];

                $date = $order->get_date_created();
                if ( $date ) {
                    $day_key = $date->date( 'Y-m-d' );
                    if ( ! isset( $day_totals[ $day_key ] ) ) {
                        $day_totals[ $day_key ] = 0;
                    }
                    $day_totals[ $day_key ] += $order_totals['ventas'];
                }
            }

            $page++;
        } while ( $page <= $max_pages );
    }

    private static function process_pending( array $statuses, $date_range, array $exclude_term_ids, array $settings ) {
        $page = 1;
        $limit = 200;
        $max_pages = 1;
        $pending_total = 0;
        $pending_count = 0;

        do {
            $args = array(
                'type' => 'shop_order',
                'status' => $statuses,
                'limit' => $limit,
                'paginate' => true,
                'page' => $page,
                'date_created' => $date_range,
                'orderby' => 'date',
                'order' => 'ASC',
            );

            $result = wc_get_orders( $args );
            $orders = array();

            if ( is_object( $result ) && isset( $result->orders ) ) {
                $orders = $result->orders;
                $max_pages = max( 1, (int) $result->max_num_pages );
            } elseif ( is_array( $result ) ) {
                $orders = $result;
                $max_pages = 1;
            }

            foreach ( $orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                if ( self::should_exclude_order( $order, $exclude_term_ids, $settings ) ) {
                    continue;
                }

                $pending_sales = self::calculate_order_sales( $order, $exclude_term_ids, $settings );
                if ( $pending_sales <= 0 ) {
                    continue;
                }

                $pending_total += $pending_sales;
                $pending_count += 1;
            }

            $page++;
        } while ( $page <= $max_pages );

        return array(
            'total' => $pending_total,
            'count' => $pending_count,
        );
    }

    private static function process_refunds( $date_range, array $exclude_term_ids, array $settings ) {
        $page = 1;
        $limit = 200;
        $max_pages = 1;
        $refund_total = 0;

        do {
            $args = array(
                'type' => 'shop_order_refund',
                'limit' => $limit,
                'paginate' => true,
                'page' => $page,
                'date_created' => $date_range,
                'orderby' => 'date',
                'order' => 'ASC',
            );

            $result = wc_get_orders( $args );
            $refunds = array();

            if ( is_object( $result ) && isset( $result->orders ) ) {
                $refunds = $result->orders;
                $max_pages = max( 1, (int) $result->max_num_pages );
            } elseif ( is_array( $result ) ) {
                $refunds = $result;
                $max_pages = 1;
            }

            foreach ( $refunds as $refund ) {
                if ( ! $refund instanceof WC_Order_Refund ) {
                    continue;
                }

                $refund_amount = self::calculate_refund_amount( $refund, $exclude_term_ids, $settings );
                $refund_total += $refund_amount;
            }

            $page++;
        } while ( $page <= $max_pages );

        return $refund_total;
    }

    private static function calculate_refund_amount( WC_Order_Refund $refund, array $exclude_term_ids, array $settings ) {
        $refund_amount = 0;

        if ( empty( $exclude_term_ids ) ) {
            return abs( (float) $refund->get_amount() );
        }

        if ( $settings['exclude_mode'] === 'order' ) {
            $parent_id = $refund->get_parent_id();
            if ( $parent_id ) {
                $parent = wc_get_order( $parent_id );
                if ( $parent && self::should_exclude_order( $parent, $exclude_term_ids, $settings ) ) {
                    return 0;
                }
            }
            return abs( (float) $refund->get_amount() );
        }

        $items = $refund->get_items( 'line_item' );
        if ( ! empty( $items ) ) {
            foreach ( $items as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }

                $product = $item->get_product();
                if ( ! $product ) {
                    continue;
                }

                if ( self::item_has_excluded_category( $product, $exclude_term_ids ) ) {
                    continue;
                }

                $line_total = (float) $item->get_total();
                if ( 0 === $line_total ) {
                    $line_total = (float) $item->get_subtotal();
                }
                $refund_amount += abs( $line_total );
            }
            return $refund_amount;
        }

        return abs( (float) $refund->get_amount() );
    }

    private static function should_exclude_order( WC_Order $order, array $exclude_term_ids, array $settings ) {
        if ( empty( $exclude_term_ids ) ) {
            return false;
        }

        if ( $settings['exclude_mode'] !== 'order' ) {
            return false;
        }

        $order_id = $order->get_id();
        if ( isset( self::$order_exclude_cache[ $order_id ] ) ) {
            return self::$order_exclude_cache[ $order_id ];
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( self::item_has_excluded_category( $product, $exclude_term_ids ) ) {
                self::$order_exclude_cache[ $order_id ] = true;
                return true;
            }
        }

        self::$order_exclude_cache[ $order_id ] = false;
        return false;
    }

    private static function calculate_order_totals( WC_Order $order, array $exclude_term_ids, array $settings, array &$product_totals, array &$category_totals ) {
        $ventas = 0;
        $inversion = 0;
        $unidades = 0;

        $basis = $settings['sales_basis'];
        $line_mode = $settings['exclude_mode'] === 'line';

        if ( ! $line_mode && in_array( $basis, array( 'order_total', 'order_total_no_tax' ), true ) ) {
            $ventas = (float) $order->get_total();
            if ( $basis === 'order_total_no_tax' ) {
                $ventas -= (float) $order->get_total_tax();
            }
            $ventas = max( 0, $ventas );
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( $line_mode && self::item_has_excluded_category( $product, $exclude_term_ids ) ) {
                continue;
            }

            $qty = (float) $item->get_quantity();
            $unidades += $qty;
            $line_total = self::calculate_line_total( $item, $basis );

            if ( $line_mode || in_array( $basis, array( 'items_net', 'items_gross' ), true ) ) {
                $ventas += $line_total;
            }

            $cost = self::get_product_cost( $product, $settings['cost_meta_key'] );
            if ( $cost > 0 ) {
                $inversion += $cost * $qty;
            }

            $product_key = $product->get_id();
            $product_name = $product->get_name();
            if ( $product_name === '' ) {
                $product_name = $item->get_name();
            }

            if ( ! isset( $product_totals[ $product_key ] ) ) {
                $product_totals[ $product_key ] = array(
                    'nombre' => $product_name,
                    'cantidad' => 0,
                    'total' => 0,
                );
            }

            $product_totals[ $product_key ]['cantidad'] += $qty;
            $product_totals[ $product_key ]['total'] += $line_total;

            $category_ids = self::get_product_category_ids( $product );
            foreach ( $category_ids as $cat_id ) {
                if ( ! isset( $category_totals[ $cat_id ] ) ) {
                    $category_totals[ $cat_id ] = array(
                        'cantidad' => 0,
                        'total' => 0,
                    );
                }
                $category_totals[ $cat_id ]['cantidad'] += $qty;
                $category_totals[ $cat_id ]['total'] += $line_total;
            }
        }

        return array(
            'ventas' => $ventas,
            'inversion' => $inversion,
            'unidades' => $unidades,
        );
    }

    private static function calculate_order_sales( WC_Order $order, array $exclude_term_ids, array $settings ) {
        $basis = $settings['sales_basis'];
        $line_mode = $settings['exclude_mode'] === 'line';

        if ( ! $line_mode && in_array( $basis, array( 'order_total', 'order_total_no_tax' ), true ) ) {
            $ventas = (float) $order->get_total();
            if ( $basis === 'order_total_no_tax' ) {
                $ventas -= (float) $order->get_total_tax();
            }
            return max( 0, $ventas );
        }

        $ventas = 0;
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( $line_mode && self::item_has_excluded_category( $product, $exclude_term_ids ) ) {
                continue;
            }

            $ventas += self::calculate_line_total( $item, $basis );
        }

        return $ventas;
    }

    private static function calculate_line_total( WC_Order_Item_Product $item, $basis ) {
        if ( $basis === 'items_gross' ) {
            return (float) $item->get_subtotal();
        }

        return (float) $item->get_total();
    }

    private static function item_has_excluded_category( WC_Product $product, array $exclude_term_ids ) {
        if ( empty( $exclude_term_ids ) ) {
            return false;
        }

        $category_ids = self::get_product_category_ids( $product );
        return (bool) array_intersect( $exclude_term_ids, $category_ids );
    }

    private static function get_product_category_ids( WC_Product $product ) {
        $source_id = $product->get_id();
        if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $source_id = $product->get_parent_id();
        }

        if ( isset( self::$product_category_cache[ $source_id ] ) ) {
            return self::$product_category_cache[ $source_id ];
        }

        $ids = wp_get_post_terms( $source_id, 'product_cat', array( 'fields' => 'ids' ) );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        self::$product_category_cache[ $source_id ] = $ids;
        return $ids;
    }

    private static function get_product_cost( WC_Product $product, $meta_key ) {
        $product_id = $product->get_id();
        if ( isset( self::$product_cost_cache[ $product_id ] ) ) {
            return self::$product_cost_cache[ $product_id ];
        }

        $cost = (float) get_post_meta( $product_id, $meta_key, true );
        if ( $cost <= 0 && $product->is_type( 'variation' ) && $product->get_parent_id() ) {
            $parent_id = $product->get_parent_id();
            if ( $meta_key === '_wc_cog_cost' ) {
                $parent_cost = (float) get_post_meta( $parent_id, '_wc_cog_cost_variable', true );
                if ( $parent_cost > 0 ) {
                    $cost = $parent_cost;
                }
            }

            if ( $cost <= 0 ) {
                $cost = (float) get_post_meta( $parent_id, $meta_key, true );
            }
        }

        self::$product_cost_cache[ $product_id ] = $cost;
        return $cost;
    }

    private static function format_top_days( array $day_totals, $direction, $limit ) {
        if ( empty( $day_totals ) ) {
            return array();
        }

        if ( $direction === 'desc' ) {
            arsort( $day_totals, SORT_NUMERIC );
        } else {
            asort( $day_totals, SORT_NUMERIC );
        }

        $result = array();
        $count = 0;
        foreach ( $day_totals as $date => $total ) {
            $result[] = array(
                'fecha' => $date,
                'total' => (float) $total,
            );
            $count++;
            if ( $count >= $limit ) {
                break;
            }
        }

        return $result;
    }

    private static function format_top_entities( array $totals, $limit ) {
        if ( empty( $totals ) ) {
            return array();
        }

        $list = array_values( $totals );
        usort(
            $list,
            function ( $a, $b ) {
                return $b['total'] <=> $a['total'];
            }
        );

        $list = array_slice( $list, 0, $limit );

        foreach ( $list as &$row ) {
            $row['total'] = (float) $row['total'];
            $row['cantidad'] = (float) $row['cantidad'];
        }

        return $list;
    }

    private static function format_top_categories( array $totals, $limit ) {
        if ( empty( $totals ) ) {
            return array();
        }

        $list = array();
        foreach ( $totals as $term_id => $data ) {
            $term = get_term( $term_id, 'product_cat' );
            $name = $term && ! is_wp_error( $term ) ? $term->name : 'Categoria ' . $term_id;
            $list[] = array(
                'nombre' => $name,
                'cantidad' => (float) $data['cantidad'],
                'total' => (float) $data['total'],
            );
        }

        usort(
            $list,
            function ( $a, $b ) {
                return $b['total'] <=> $a['total'];
            }
        );

        return array_slice( $list, 0, $limit );
    }
}
