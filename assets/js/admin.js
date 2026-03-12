(function ($) {
    function getROIComment(roi) {
        if (roi < 0) {
            return '(Perdidas)';
        }
        if (roi < 10) {
            return '(Baja rentabilidad)';
        }
        if (roi < 30) {
            return '(Rentabilidad media)';
        }
        return '(Alta rentabilidad)';
    }

    function renderTable(selector, rows, emptyText) {
        var $tbody = $(selector);
        $tbody.empty();

        if (rows && rows.length) {
            rows.forEach(function (row) {
                if (row.fecha !== undefined) {
                    var total = parseFloat(row.total || 0).toFixed(2);
                    var fechaLabel = formatDateWithDay(row.fecha);
                    $tbody.append('<tr><td>' + fechaLabel + '</td><td>' + total + '</td></tr>');
                    return;
                }

                var totalValue = parseFloat(row.total || 0).toFixed(2);
                $tbody.append('<tr><td>' + row.nombre + '</td><td>' + row.cantidad + '</td><td>' + totalValue + '</td></tr>');
            });
        } else {
            var colspan = $tbody.closest('table').find('thead th').length || 2;
            $tbody.append('<tr><td colspan="' + colspan + '">' + (emptyText || 'Sin datos') + '</td></tr>');
        }
    }

    function formatDateWithDay(dateStr) {
        if (!dateStr || !/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
            return dateStr || '';
        }

        var parts = dateStr.split('-');
        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10) - 1;
        var day = parseInt(parts[2], 10);
        var date = new Date(year, month, day);

        if (isNaN(date.getTime())) {
            return dateStr;
        }

        var dayNames = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        var dayName = dayNames[date.getDay()] || '';

        return dateStr + ' (' + dayName + ')';
    }

    function formatNumber(value, decimals) {
        var num = parseFloat(value);
        if (isNaN(num)) {
            num = 0;
        }
        return num.toFixed(decimals);
    }

    function setDelta($el, current, previous, mode) {
        var deltaText = 'N/A';
        var deltaValue = 0;

        if (!isFinite(previous) || previous === 0) {
            deltaText = 'N/A';
        } else {
            if (mode === 'pp') {
                deltaValue = current - previous;
                deltaText = (deltaValue >= 0 ? '+' : '') + deltaValue.toFixed(2) + ' pp';
            } else {
                deltaValue = ((current - previous) / Math.abs(previous)) * 100;
                deltaText = (deltaValue >= 0 ? '+' : '') + deltaValue.toFixed(2) + '%';
            }
        }

        $el.text(deltaText);
        $el.toggleClass('afp-negative', deltaValue < 0);
    }

    function renderComparison(currentTotals, compareData) {
        var $comparePanel = $('.afp-panel').has('#afp_compare_range');
        if (!compareData || !compareData.totals) {
            $comparePanel.hide();
            return;
        }

        $comparePanel.show();
        $('#afp_compare_range').text(
            'Periodo anterior: ' + compareData.range.start + ' a ' + compareData.range.end
        );

        var prevTotals = compareData.totals || {};
        var currentVentasNetas = parseFloat(currentTotals.ventas_netas) || 0;
        var prevVentasNetas = parseFloat(prevTotals.ventas_netas) || 0;
        var currentInversion = parseFloat(currentTotals.inversion) || 0;
        var prevInversion = parseFloat(prevTotals.inversion) || 0;
        var currentPedidos = parseFloat(currentTotals.pedidos) || 0;
        var prevPedidos = parseFloat(prevTotals.pedidos) || 0;
        var currentUnidades = parseFloat(currentTotals.unidades) || 0;
        var prevUnidades = parseFloat(prevTotals.unidades) || 0;
        var currentMargen = parseFloat(currentTotals.margen_bruto_pct) || 0;
        var prevMargen = parseFloat(prevTotals.margen_bruto_pct) || 0;

        $('#afp_compare_ventas_netas').text(formatNumber(currentVentasNetas, 2));
        $('#afp_compare_ventas_netas_prev').text(formatNumber(prevVentasNetas, 2));
        setDelta($('#afp_compare_ventas_netas_delta'), currentVentasNetas, prevVentasNetas, 'pct');

        var gananciaBruta = currentVentasNetas - currentInversion;
        var gananciaBrutaPrev = prevVentasNetas - prevInversion;
        $('#afp_compare_ganancia_bruta').text(formatNumber(gananciaBruta, 2));
        $('#afp_compare_ganancia_bruta_prev').text(formatNumber(gananciaBrutaPrev, 2));
        setDelta($('#afp_compare_ganancia_bruta_delta'), gananciaBruta, gananciaBrutaPrev, 'pct');

        $('#afp_compare_pedidos').text(formatNumber(currentPedidos, 0));
        $('#afp_compare_pedidos_prev').text(formatNumber(prevPedidos, 0));
        setDelta($('#afp_compare_pedidos_delta'), currentPedidos, prevPedidos, 'pct');

        $('#afp_compare_unidades').text(formatNumber(currentUnidades, 0));
        $('#afp_compare_unidades_prev').text(formatNumber(prevUnidades, 0));
        setDelta($('#afp_compare_unidades_delta'), currentUnidades, prevUnidades, 'pct');

        $('#afp_compare_margen').text(formatNumber(currentMargen, 2) + '%');
        $('#afp_compare_margen_prev').text(formatNumber(prevMargen, 2) + '%');
        setDelta($('#afp_compare_margen_delta'), currentMargen, prevMargen, 'pp');
    }

    function renderSalesChart(series, compareSeries) {
        var canvas = document.getElementById('afp_sales_chart');
        if (!canvas) {
            return;
        }

        var ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }
        var container = canvas.parentElement;
        var width = container ? container.clientWidth : 600;
        if (!width || width < 50) {
            width = 600;
        }
        var height = canvas.getAttribute('height') ? parseInt(canvas.getAttribute('height'), 10) : 240;

        var ratio = window.devicePixelRatio || 1;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

        ctx.clearRect(0, 0, width, height);

        if (!series || !series.length) {
            ctx.fillStyle = '#94a3b8';
            ctx.font = '12px sans-serif';
            ctx.fillText('Sin datos para graficar', 10, 20);
            return;
        }

        var allSeries = series.slice();
        if (compareSeries && compareSeries.length) {
            allSeries = allSeries.concat(compareSeries);
        }

        var maxValue = Math.max.apply(null, allSeries.map(function (item) {
            return parseFloat(item.total) || 0;
        }));
        if (maxValue <= 0) {
            maxValue = 1;
        }

        var padding = { top: 20, right: 20, bottom: 30, left: 40 };
        var plotWidth = width - padding.left - padding.right;
        var plotHeight = height - padding.top - padding.bottom;

        ctx.strokeStyle = '#e5e7eb';
        ctx.lineWidth = 1;
        for (var i = 0; i <= 4; i++) {
            var y = padding.top + (plotHeight * i / 4);
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();
        }

        function drawLine(data, color, dashed) {
            if (!data || !data.length) {
                return;
            }
            ctx.save();
            ctx.strokeStyle = color;
            ctx.lineWidth = 2;
            if (dashed) {
                ctx.setLineDash([6, 4]);
            }
            ctx.beginPath();
            data.forEach(function (point, index) {
                var value = parseFloat(point.total) || 0;
                var x = padding.left + (data.length === 1 ? 0 : (plotWidth * index / (data.length - 1)));
                var y = padding.top + plotHeight - ((value / maxValue) * plotHeight);
                if (index === 0) {
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
            });
            ctx.stroke();
            ctx.restore();
        }

        drawLine(compareSeries, '#94a3b8', true);
        drawLine(series, '#1d4ed8', false);

        ctx.fillStyle = '#64748b';
        ctx.font = '11px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(series[0].fecha, padding.left, height - 10);
        ctx.textAlign = 'right';
        ctx.fillText(series[series.length - 1].fecha, width - padding.right, height - 10);
    }

    function scheduleChartRender(series, compareSeries, startDate, endDate) {
        setTimeout(function () {
            requestAnimationFrame(function () {
                $('#afp_chart_range').text('Periodo actual: ' + startDate + ' a ' + endDate);
                renderSalesChart(series, compareSeries);
            });
        }, 0);
    }

    function recalc() {
        var ventasNetas = parseFloat($('#afp_ventas_netas').text()) || 0;
        var inversion = parseFloat($('#afp_costo_productos').text()) || 0;
        var gananciaBruta = ventasNetas - inversion;

        var gastosAdic = parseFloat($('#afp_gastos_adicionales').val()) || 0;
        var costosOp = parseFloat($('#afp_costos_operativos').val()) || 0;
        var costosAdm = parseFloat($('#afp_costos_administrativos').val()) || 0;

        var totalGastosCostos = inversion + gastosAdic + costosOp + costosAdm;
        $('#afp_total_gastos_costos').text(totalGastosCostos.toFixed(2));

        var gananciaNeta = gananciaBruta - (gastosAdic + costosOp + costosAdm);
        $('#afp_ganancia_neta').text(gananciaNeta.toFixed(2));
        $('#afp_card_ganancia_neta').text(gananciaNeta.toFixed(2));

        var pendiente = parseFloat($('#afp_pendiente_pago').text()) || 0;
        var gananciaNetaProy = gananciaNeta + pendiente;
        $('#afp_ganancia_neta_proyectada').text(gananciaNetaProy.toFixed(2));

        var roiActual = 0;
        if (inversion > 0) {
            roiActual = (gananciaNeta / inversion) * 100;
        }
        $('#afp_roi_actual').text(roiActual.toFixed(2));
        $('#afp_roi_actual_explain').text(getROIComment(roiActual));
        $('#afp_card_roi_actual').text(roiActual.toFixed(2));

        var roiProy = 0;
        if (inversion > 0) {
            roiProy = (gananciaNetaProy / inversion) * 100;
        }
        $('#afp_roi_proyectado').text(roiProy.toFixed(2));
        $('#afp_roi_proyectado_explain').text(getROIComment(roiProy));

        var margenNeto = 0;
        if (ventasNetas > 0) {
            margenNeto = (gananciaNeta / ventasNetas) * 100;
        }
        $('#afp_metric_margen_neto_pct').text(margenNeto.toFixed(2) + '%');

        if (typeof AFPAdmin !== 'undefined' && AFPAdmin.alertMarginThreshold !== undefined) {
            var threshold = parseFloat(AFPAdmin.alertMarginThreshold) || 0;
            $('#afp_alert_threshold').text(threshold.toFixed(1));
            if (ventasNetas > 0 && margenNeto < threshold) {
                $('#afp_alert').show();
            } else {
                $('#afp_alert').hide();
            }
        }
    }

    function formatDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function getCheckedValues(selector) {
        return $(selector + ':checked')
            .map(function () {
                return this.value;
            })
            .get();
    }

    function applyRange(range) {
        var now = new Date();
        var start;
        var end = new Date(now);

        if (range === 'today') {
            start = new Date(now);
        } else if (range === 'yesterday') {
            start = new Date(now);
            start.setDate(start.getDate() - 1);
            end = new Date(start);
        } else if (range === 'last7') {
            start = new Date(now);
            start.setDate(start.getDate() - 6);
        } else if (range === 'thismonth') {
            start = new Date(now.getFullYear(), now.getMonth(), 1);
        } else if (range === 'lastmonth') {
            start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            end = new Date(now.getFullYear(), now.getMonth(), 0);
        } else {
            return;
        }

        $('#afp_start_date').val(formatDate(start));
        $('#afp_end_date').val(formatDate(end));
    }

    $(function () {
        var $btnLoad = $('#afp_btn_load');
        var $btnExport = $('#afp_btn_export');
        var $loader = $('#afp_loader');
        var $results = $('#afp_results');
        var lastParams = null;
        var lastChartSeries = [];
        var lastCompareSeries = [];
        var lastChartStart = '';
        var lastChartEnd = '';

        $('#afp_gastos_adicionales, #afp_costos_operativos, #afp_costos_administrativos').on('input', recalc);

        $('.afp-presets button').on('click', function () {
            applyRange($(this).data('range'));
        });

        $btnLoad.on('click', function () {
            var startDate = $('#afp_start_date').val();
            var endDate = $('#afp_end_date').val();
            var excludeCategories = $('#afp_exclude_categories').val();
            var salesStatuses = getCheckedValues('.afp-sales-status');
            var pendingStatuses = getCheckedValues('.afp-pending-status');

            if (!startDate || !endDate) {
                alert('Por favor selecciona ambas fechas.');
                return;
            }

            $loader.show();
            $results.hide();
            $btnExport.prop('disabled', true);

            lastParams = {
                start_date: startDate,
                end_date: endDate,
                exclude_categories: excludeCategories,
                sales_statuses: salesStatuses,
                pending_statuses: pendingStatuses
            };

            $.ajax({
                url: AFPAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'afp_get_data',
                    start_date: startDate,
                    end_date: endDate,
                    exclude_categories: excludeCategories,
                    sales_statuses: salesStatuses,
                    pending_statuses: pendingStatuses,
                    security: AFPAdmin.nonce
                },
                success: function (response) {
                    $loader.hide();

                    if (response.success) {
                        var data = response.data || {};
                        var totals = data.totals || {};

                        var ventasBrutas = parseFloat(totals.ventas_brutas) || 0;
                        var reembolsos = parseFloat(totals.reembolsos) || 0;
                        var ventasNetas = parseFloat(totals.ventas_netas) || 0;
                        var inversion = parseFloat(totals.inversion) || 0;
                        var pendiente = parseFloat(data.pendiente) || 0;
                        var pedidos = parseFloat(totals.pedidos) || 0;
                        var unidades = parseFloat(totals.unidades) || 0;
                        var margenBrutoPct = parseFloat(totals.margen_bruto_pct) || 0;
                        var tasaReembolso = parseFloat(totals.tasa_reembolso) || 0;
                        var costoPromedio = parseFloat(totals.costo_promedio_unidad) || 0;
                        var ventaPromedio = parseFloat(totals.venta_promedio_unidad) || 0;
                        var pendientesCount = parseFloat(data.pendiente_count) || 0;
                        var series = data.series || [];
                        var compareData = data.compare || null;
                        var compareSeries = compareData && compareData.series ? compareData.series : [];

                        $('#afp_ventas_brutas').text(ventasBrutas.toFixed(2));
                        $('#afp_reembolsos').text(reembolsos.toFixed(2));
                        $('#afp_ventas_netas').text(ventasNetas.toFixed(2));
                        $('#afp_costo_productos').text(inversion.toFixed(2));
                        $('#afp_pendiente_pago').text(pendiente.toFixed(2));

                        var gananciaBruta = ventasNetas - inversion;
                        $('#afp_ganancia_bruta').text(gananciaBruta.toFixed(2));

                        $('#afp_card_ventas_netas').text(ventasNetas.toFixed(2));
                        $('#afp_card_inversion').text(inversion.toFixed(2));
                        $('#afp_card_ganancia_bruta').text(gananciaBruta.toFixed(2));

                        $('#afp_metric_pedidos').text(pedidos.toFixed(0));
                        $('#afp_metric_unidades').text(unidades.toFixed(0));
                        $('#afp_metric_margen_bruto_pct').text(margenBrutoPct.toFixed(2));
                        $('#afp_metric_tasa_reembolso').text(tasaReembolso.toFixed(2) + '%');
                        $('#afp_metric_costo_promedio').text(costoPromedio.toFixed(2));
                        $('#afp_metric_venta_promedio').text(ventaPromedio.toFixed(2));
                        $('#afp_metric_pedidos_pendientes').text(pendientesCount.toFixed(0));

                        renderTable('#afp_top_ventas', totals.top_mas_vendido || []);
                        renderTable('#afp_bottom_ventas', totals.top_menos_vendido || []);
                        renderTable('#afp_top_productos', data.productos || [], 'Sin productos');
                        renderTable('#afp_top_categorias', data.categorias || [], 'Sin categorias');

                        recalc();
                        $results.show();
                        lastChartSeries = series;
                        lastCompareSeries = compareSeries;
                        lastChartStart = startDate;
                        lastChartEnd = endDate;
                        scheduleChartRender(series, compareSeries, startDate, endDate);
                        renderComparison(totals, compareData);
                        $btnExport.prop('disabled', false);
                    } else {
                        alert('Error al obtener datos: ' + (response.data ? response.data : 'Desconocido'));
                    }
                },
                error: function () {
                    $loader.hide();
                    alert('Error en la peticion AJAX.');
                }
            });
        });

        $btnExport.on('click', function () {
            if (!lastParams) {
                alert('Primero carga un analisis.');
                return;
            }

            var $form = $('<form method="post" action="' + AFPAdmin.ajaxUrl + '"></form>');
            $form.append('<input type="hidden" name="action" value="afp_export_csv" />');
            $form.append('<input type="hidden" name="security" value="' + AFPAdmin.nonce + '" />');
            $form.append('<input type="hidden" name="start_date" value="' + lastParams.start_date + '" />');
            $form.append('<input type="hidden" name="end_date" value="' + lastParams.end_date + '" />');
            $form.append('<input type="hidden" name="exclude_categories" value="' + (lastParams.exclude_categories || '') + '" />');

            if (Array.isArray(lastParams.sales_statuses)) {
                lastParams.sales_statuses.forEach(function (status) {
                    $form.append('<input type="hidden" name="sales_statuses[]" value="' + status + '" />');
                });
            }

            if (Array.isArray(lastParams.pending_statuses)) {
                lastParams.pending_statuses.forEach(function (status) {
                    $form.append('<input type="hidden" name="pending_statuses[]" value="' + status + '" />');
                });
            }
            $('body').append($form);
            $form.submit();
            $form.remove();
        });

        $(window).on('resize', function () {
            if (!$('#afp_results').is(':visible')) {
                return;
            }
            if (!lastChartStart || !lastChartEnd) {
                return;
            }
            scheduleChartRender(lastChartSeries, lastCompareSeries, lastChartStart, lastChartEnd);
        });
    });
})(jQuery);
