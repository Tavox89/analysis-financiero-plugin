(function () {
    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case '\'':
                    return '&#039;';
                default:
                    return char;
            }
        });
    }

    function contactOriginLabel(origin) {
        return origin === 'wp_user' ? 'Usuario WP' : 'Externo';
    }

    function contactStatusLabel(status) {
        switch (status) {
            case 'active':
                return 'Activo';
            case 'inactive':
                return 'Inactivo';
            default:
                return status || 'Sin definir';
        }
    }

    function contactStatusTone(status) {
        switch (status) {
            case 'active':
                return 'success';
            case 'inactive':
                return 'neutral';
            default:
                return 'neutral';
        }
    }

    function renderPill(label, tone) {
        return '<span class="asdl-fin-pill asdl-fin-pill-' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(label) + '</span>';
    }

    function fiscalYearHiddenField() {
        var fiscalYear = Number((ASDLFinanceAdmin || {}).currentFiscalYear || 0);

        if (!fiscalYear) {
            return '';
        }

        return '<input type="hidden" name="fiscal_year" value="' + escapeHtml(fiscalYear) + '" />';
    }

    function requestRuntimeHtml(action, nonce, data) {
        if (!window.ASDLFinanceAdmin || !ASDLFinanceAdmin.ajaxUrl || !nonce) {
            return Promise.reject(new Error('runtime_unavailable'));
        }

        var body = new URLSearchParams();
        body.set('action', action);
        body.set('_ajax_nonce', nonce);

        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value === undefined || value === null || value === '') {
                return;
            }
            body.set(key, value);
        });

        return fetch(ASDLFinanceAdmin.ajaxUrl, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                if (!response.ok || !json || json.success === false) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo cargar este bloque.');
                }
                return json.data || {};
            });
        });
    }

    function requestAdminAjax(action, nonce, data) {
        if (!window.ASDLFinanceAdmin || !ASDLFinanceAdmin.ajaxUrl || !nonce) {
            return Promise.reject(new Error('runtime_unavailable'));
        }

        var body = new URLSearchParams();
        body.set('action', action);
        body.set('_ajax_nonce', nonce);

        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (value === undefined || value === null || value === '') {
                return;
            }

            body.set(key, value);
        });

        return fetch(ASDLFinanceAdmin.ajaxUrl, {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (json) {
                if (!response.ok || !json || json.success === false) {
                    throw new Error((json && json.data && json.data.message) || 'No se pudo completar esta accion.');
                }

                return json.data || {};
            });
        });
    }

    function collectRuntimeParams(container) {
        var payload = {};

        Array.prototype.slice.call(container.attributes || []).forEach(function (attribute) {
            if (!attribute || attribute.name.indexOf('data-runtime-param-') !== 0) {
                return;
            }

            var key = attribute.name.replace('data-runtime-param-', '').replace(/-/g, '_');

            if (!key) {
                return;
            }

            payload[key] = attribute.value;
        });

        return payload;
    }

    function runtimePriorityWeight(priority) {
        switch (String(priority || 'normal')) {
            case 'high':
                return 0;
            case 'low':
                return 2;
            default:
                return 1;
        }
    }

    function loadRuntimeContainer(container) {
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var action = container.getAttribute('data-runtime-action') || '';
        var nonceKey = container.getAttribute('data-runtime-nonce') || '';
        var nonce = runtimeNonces[nonceKey] || '';
        var title = container.getAttribute('data-runtime-title') || 'No se pudo cargar este bloque.';

        if (!action || !nonce) {
            return Promise.resolve();
        }

        if (container.dataset.runtimeLoading === '1' || container.dataset.runtimeLoaded === '1') {
            return Promise.resolve();
        }

        container.dataset.runtimeLoading = '1';
        container.dataset.runtimeState = 'loading';
        container.classList.remove('is-runtime-error');
        container.classList.add('is-runtime-loading');

        return requestRuntimeHtml(action, nonce, collectRuntimeParams(container)).then(function (payload) {
            container.innerHTML = payload.html || '';
            container.dataset.runtimeLoaded = '1';
            container.dataset.runtimeState = 'loaded';
            delete container.dataset.runtimeLoading;
            container.classList.remove('is-runtime-loading');
            initializeDynamicAdminContent(container);
        }).catch(function (error) {
            container.innerHTML = buildRuntimeErrorHtml(
                title,
                (error && error.message) || 'No se pudo cargar este bloque.'
            );
            delete container.dataset.runtimeLoading;
            delete container.dataset.runtimeLoaded;
            container.dataset.runtimeState = 'error';
            container.classList.remove('is-runtime-loading');
            container.classList.add('is-runtime-error');
        });
    }

    function scheduleRuntimeBatch(containers) {
        if (!containers.length) {
            return Promise.resolve();
        }

        return Promise.allSettled(containers.map(function (container) {
            return loadRuntimeContainer(container);
        })).then(function () {
            return undefined;
        });
    }

    function setupAdminRuntimeLoaders(root) {
        var scope = root || document;
        var containers = Array.prototype.slice.call(scope.querySelectorAll('[data-runtime-action]')).filter(function (container) {
            if (container.dataset.runtimeDiscovered === '1') {
                return false;
            }

            container.dataset.runtimeDiscovered = '1';
            return true;
        });

        if (!containers.length) {
            return;
        }

        containers.sort(function (left, right) {
            return runtimePriorityWeight(left.getAttribute('data-runtime-priority')) - runtimePriorityWeight(right.getAttribute('data-runtime-priority'));
        });

        var high = containers.filter(function (container) {
            return (container.getAttribute('data-runtime-priority') || 'normal') === 'high';
        });
        var normal = containers.filter(function (container) {
            return (container.getAttribute('data-runtime-priority') || 'normal') === 'normal';
        });
        var low = containers.filter(function (container) {
            return (container.getAttribute('data-runtime-priority') || 'normal') === 'low';
        });

        scheduleRuntimeBatch(high).then(function () {
            return scheduleRuntimeBatch(normal);
        }).then(function () {
            var runLowPriority = function () {
                scheduleRuntimeBatch(low);
            };

            if (!low.length) {
                return;
            }

            if (window.requestIdleCallback) {
                window.requestIdleCallback(runLowPriority, {timeout: 600});
                return;
            }

            window.setTimeout(runLowPriority, 80);
        });
    }

    function buildRuntimeErrorHtml(title, message) {
        return ''
            + '<div class="asdl-fin-empty asdl-fin-runtime-error">'
            + '<strong>' + escapeHtml(title || 'No se pudo cargar este bloque.') + '</strong>'
            + '<p>' + escapeHtml(message || 'Intenta recargar el bloque de nuevo.') + '</p>'
            + '<div class="asdl-fin-inline-actions">'
            + '<button type="button" class="button button-secondary" data-runtime-retry>Reintentar</button>'
            + '</div>'
            + '</div>';
    }

    function formatToolMoney(amount, currency) {
        var numeric = Number(amount || 0);
        var code = String(currency || 'USD').toUpperCase();

        if (!Number.isFinite(numeric)) {
            numeric = 0;
        }

        if (code === 'USD') {
            return numeric.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '$';
        }

        return numeric.toLocaleString('es-VE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ' + code;
    }

    function formatToolTimestamp(value) {
        if (!value) {
            return 'Sin actividad';
        }

        var normalized = String(value).trim().replace(' ', 'T');
        var date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return date.toLocaleString('es-VE', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function historicalStatusTone(status) {
        switch (String(status || '')) {
            case 'completed':
            case 'indexed':
                return 'success';
            case 'running':
            case 'pending':
                return 'warning';
            case 'error':
                return 'danger';
            default:
                return 'neutral';
        }
    }

    function refreshDateWeekdayOutputs(root) {
        (root || document).querySelectorAll('.asdl-fin-field input[type="date"]').forEach(function (input) {
            updateDateWeekdayOutput(input);
        });
    }

    function initializeDynamicAdminContent(root) {
        refreshDateWeekdayOutputs(root || document);
        setupAdminRuntimeLoaders(root || document);
        setupContactPickers(root || document);
        setupWpUserPickers(root || document);
        setupSupplierKindToggles(root || document);
        setupProfileContextDisclosures(root || document);
        setupInlineTabs(root || document);
        setupConsumptionHistorySelectors(root || document);
        setupDashboardQueueFilters();
        setupDashboardTables();
        setupSortableStaticTables();
        setupOrderSettlementPreview();
        setupOrderAssumptionModal();
        setupPayrollPaymentModal();
        setupHistoricalTools();
        setupPayrollManualSettlementModal();
        setupCommitmentForms();
        setupSalaryAdvanceForms();
        setupPayrollPeriodForms();
    }

    function renderContactRow(contact) {
        var detailUrl = (ASDLFinanceAdmin.contactsPage || '') + '&contact_id=' + encodeURIComponent(contact.id || 0);
        var userMeta = Number(contact.wp_user_id || 0) > 0
            ? 'Usuario WP #' + Number(contact.wp_user_id || 0)
            : 'Sin usuario enlazado';
        var missingEmailForLink = !Number(contact.wp_user_id || 0) && !contact.email;
        var emailCell = '<div class="asdl-fin-stack"><strong>' + escapeHtml(contact.email || '—') + '</strong>';
        var actions = '<a class="button button-small" href="' + escapeHtml(detailUrl) + '">Ver detalle</a>';

        if (missingEmailForLink) {
            emailCell += '<small class="asdl-fin-table-note">Falta correo para poder vincularlo o crear su usuario interno.</small>';
        }

        emailCell += '</div>';

        if (contact.can_delete) {
            actions += ''
                + '<form method="post" action="' + escapeHtml(ASDLFinanceAdmin.adminPostUrl || '') + '" onsubmit="return window.confirm(\'Solo se eliminara el perfil financiero vacio. El usuario WordPress no se borrara.\');">'
                + '<input type="hidden" name="action" value="asdl_fin_delete_contact" />'
                + '<input type="hidden" name="return_page" value="asdl-fin-contacts" />'
                + '<input type="hidden" name="contact_id" value="' + escapeHtml(contact.id || 0) + '" />'
                + fiscalYearHiddenField()
                + '<input type="hidden" name="_wpnonce" value="' + escapeHtml((ASDLFinanceAdmin.actionNonces || {}).deleteContact || '') + '" />'
                + '<button type="submit" class="button button-secondary small">Eliminar</button>'
                + '</form>';
        }

        if (!Number(contact.wp_user_id || 0) && contact.email) {
            actions += ''
                + '<form method="post" action="' + escapeHtml(ASDLFinanceAdmin.adminPostUrl || '') + '">'
                + '<input type="hidden" name="action" value="asdl_fin_promote_contact_to_user" />'
                + '<input type="hidden" name="return_page" value="asdl-fin-contacts" />'
                + '<input type="hidden" name="contact_id" value="' + escapeHtml(contact.id || 0) + '" />'
                + fiscalYearHiddenField()
                + '<input type="hidden" name="_wpnonce" value="' + escapeHtml((ASDLFinanceAdmin.actionNonces || {}).promoteContact || '') + '" />'
                + '<button type="submit" class="button button-secondary small">Vincular o crear usuario interno</button>'
                + '</form>';
        }

        return ''
            + '<tr>'
            + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(contact.display_name || '') + '</strong><small>' + escapeHtml(userMeta) + '</small></div></td>'
            + '<td>' + renderPill(contactOriginLabel(contact.profile_origin), contact.profile_origin === 'wp_user' ? 'success' : 'neutral') + '</td>'
            + '<td>' + escapeHtml(contact.profile_roles_label || '') + '</td>'
            + '<td>' + emailCell + '</td>'
            + '<td>' + renderPill(contactStatusLabel(contact.status), contactStatusTone(contact.status)) + '</td>'
            + '<td><div class="asdl-fin-table-action-cell"><div class="asdl-fin-inline-actions">' + actions + '</div></div></td>'
            + '</tr>';
    }

    function fetchContactsApi(term, options) {
        if (!window.ASDLFinanceAdmin || !ASDLFinanceAdmin.restBase || !ASDLFinanceAdmin.nonce) {
            return Promise.reject(new Error('contacts_unavailable'));
        }

        var settings = options || {};
        var limit = Math.max(1, parseInt(settings.limit || '20', 10) || 20);
        var filters = settings.filters || {};
        var endpoint = ASDLFinanceAdmin.restBase.replace(/\/+$/, '') + '/contacts?limit=' + encodeURIComponent(limit);

        if (term) {
            endpoint += '&search=' + encodeURIComponent(term);
        }

        Object.keys(filters).forEach(function (key) {
            var value = filters[key];
            if (value === undefined || value === null || value === '') {
                return;
            }

            endpoint += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
        });

        return fetch(endpoint, {
            headers: {
                'X-WP-Nonce': ASDLFinanceAdmin.nonce
            },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('contacts_fetch_failed');
            }

            return response.json();
        }).then(function (payload) {
            var data = payload && payload.data ? payload.data : payload || {};
            var meta = payload && payload.meta ? payload.meta : (data.meta || {});
            var items = Array.isArray(data.items) ? data.items : (Array.isArray(payload.items) ? payload.items : []);

            return {
                items: items,
                meta: meta || {}
            };
        });
    }

    function fetchWpUsersApi(term, options) {
        var settings = options || {};
        var limit = Math.max(5, parseInt(settings.limit || '10', 10) || 10);
        var actionNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.actionNonces) || {};
        var nonce = actionNonces.searchWpUsers || '';

        if (!nonce) {
            return Promise.reject(new Error('wp_users_unavailable'));
        }

        return requestAdminAjax('asdl_fin_search_wp_users', nonce, {
            search: term,
            limit: limit
        }).then(function (payload) {
            return {
                items: Array.isArray(payload.items) ? payload.items : []
            };
        });
    }

    function syncContactSearchUrl(term) {
        if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
        }

        try {
            var url = new URL(window.location.href);

            if (term) {
                url.searchParams.set('profile_search', term);
            } else {
                url.searchParams.delete('profile_search');
            }

            window.history.replaceState({profile_search: term}, '', url.toString());
        } catch (error) {
            // Ignore URL sync failures.
        }
    }

    function collectContactPickerFilters(field) {
        var filters = {};

        Array.prototype.slice.call(field.attributes || []).forEach(function (attribute) {
            if (!attribute || attribute.name.indexOf('data-contact-picker-filter-') !== 0) {
                return;
            }

            var key = attribute.name.replace('data-contact-picker-filter-', '').replace(/-/g, '_');
            if (!key) {
                return;
            }

            filters[key] = attribute.value;
        });

        return filters;
    }

    function contactPickerSelectionMeta(contact) {
        var meta = [];

        if (contact && contact.email) {
            meta.push(contact.email);
        }

        if (contact && contact.profile_roles_label) {
            meta.push(contact.profile_roles_label);
        } else if (contact && contact.profile_origin) {
            meta.push(contactOriginLabel(contact.profile_origin));
        }

        return meta.join(' | ');
    }

    function renderContactPickerOption(contact) {
        var meta = contactPickerSelectionMeta(contact);

        return ''
            + '<button type="button" class="asdl-fin-contact-picker-option" data-contact-picker-option data-contact-id="' + escapeHtml(contact.id || 0) + '" data-contact-label="' + escapeHtml(contact.display_name || '') + '" data-contact-meta="' + escapeHtml(meta) + '">'
            + '<strong>' + escapeHtml(contact.display_name || 'Sin nombre') + '</strong>'
            + '<small>' + escapeHtml(meta || ('ID #' + Number(contact.id || 0))) + '</small>'
            + '</button>';
    }

    function wpUserPickerSelectionMeta(user) {
        var meta = [];

        if (user && user.user_email) {
            meta.push(user.user_email);
        }

        if (user && user.user_login) {
            meta.push('@' + user.user_login);
        }

        if (user && user.roles_label) {
            meta.push(user.roles_label);
        }

        return meta.join(' | ');
    }

    function renderWpUserPickerOption(user) {
        var meta = wpUserPickerSelectionMeta(user);

        return ''
            + '<button type="button" class="asdl-fin-contact-picker-option" data-wp-user-picker-option data-user-id="' + escapeHtml(user.id || 0) + '" data-user-label="' + escapeHtml(user.display_name || '') + '" data-user-meta="' + escapeHtml(meta) + '" data-user-email="' + escapeHtml(user.user_email || '') + '" data-user-login="' + escapeHtml(user.user_login || '') + '" data-user-roles="' + escapeHtml(user.roles_label || '') + '">'
            + '<strong>' + escapeHtml(user.display_name || 'Sin nombre') + '</strong>'
            + '<small>' + escapeHtml(meta || ('ID #' + Number(user.id || 0))) + '</small>'
            + '</button>';
    }

    function setupContactSearch() {
        var form = document.querySelector('[data-contact-search-form]');
        var input = document.querySelector('[data-contact-search-input]');
        var meta = document.querySelector('[data-contact-search-meta]');
        var runtimeContainer = document.querySelector('.asdl-fin-runtime-container[data-runtime-param-section-key="contacts-table"]');

        if (!form || !input || !runtimeContainer) {
            return;
        }

        var debounceTimer = 0;
        var fallbackAction = form.getAttribute('action') || '';
        var minChars = 2;

        function setMeta(term, count) {
            if (!meta) {
                return;
            }

            if (!term) {
                meta.hidden = true;
                meta.innerHTML = '';
                return;
            }

            meta.hidden = false;
            meta.innerHTML = '<strong>' + escapeHtml(count) + '</strong><span>resultado(s) para &quot;' + escapeHtml(term) + '&quot;</span>';
        }

        function setHint(message) {
            if (!meta) {
                return;
            }

            meta.hidden = false;
            meta.innerHTML = '<span>' + escapeHtml(message) + '</span>';
        }

        function setLoading(isLoading) {
            form.classList.toggle('is-loading', !!isLoading);
        }

        function countRenderedContacts() {
            var body = runtimeContainer.querySelector('[data-contact-search-body]');

            if (!body) {
                return 0;
            }

            return Array.prototype.slice.call(body.querySelectorAll('tr')).filter(function (row) {
                return !row.querySelector('.asdl-fin-empty');
            }).length;
        }

        if (form.dataset.contactSearchReady === '1') {
            if (runtimeContainer.dataset.runtimeLoaded === '1') {
                setMeta(input.value.trim(), countRenderedContacts());
            }
            return;
        }

        form.dataset.contactSearchReady = '1';

        function fetchContacts(term) {
            var normalizedTerm = (term || '').trim();

            setLoading(true);
            setHint(normalizedTerm ? 'Buscando perfiles y terceros...' : 'Cargando listado general...');
            runtimeContainer.dataset.runtimeParamProfileSearch = normalizedTerm;
            delete runtimeContainer.dataset.runtimeLoaded;

            return loadRuntimeContainer(runtimeContainer).then(function () {
                syncContactSearchUrl(normalizedTerm);
                setMeta(normalizedTerm, countRenderedContacts());
                setLoading(false);
            }).catch(function (error) {
                setLoading(false);

                if (fallbackAction) {
                    var params = new URLSearchParams(new FormData(form));

                    if (normalizedTerm) {
                        params.set('profile_search', normalizedTerm);
                    } else {
                        params.delete('profile_search');
                    }

                    window.location.href = fallbackAction + '?' + params.toString();
                }
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            window.clearTimeout(debounceTimer);
            if (input.value.trim() && input.value.trim().length < minChars) {
                setHint('Escribe al menos 2 caracteres para buscar rapido.');
                return;
            }
            fetchContacts(input.value.trim());
        });

        input.addEventListener('input', function () {
            var term = input.value.trim();
            window.clearTimeout(debounceTimer);

            if (!term) {
                debounceTimer = window.setTimeout(function () {
                    fetchContacts('');
                }, 250);
                return;
            }

            if (term.length < minChars) {
                setHint('Escribe al menos 2 caracteres para buscar rapido.');
                return;
            }

            debounceTimer = window.setTimeout(function () {
                fetchContacts(term);
            }, 250);
        });
    }

    function setupContactPickers(root) {
        (root || document).querySelectorAll('[data-contact-picker]').forEach(function (field) {
            if (field.dataset.contactPickerReady === '1') {
                return;
            }

            field.dataset.contactPickerReady = '1';

            var hidden = field.querySelector('[data-contact-picker-hidden]');
            var input = field.querySelector('[data-contact-picker-input]');
            var results = field.querySelector('[data-contact-picker-results]');
            var clear = field.querySelector('[data-contact-picker-clear]');
            var selection = field.querySelector('[data-contact-picker-selection]');
            var selectionLabel = field.querySelector('[data-contact-picker-selection-label]');
            var selectionMeta = field.querySelector('[data-contact-picker-selection-meta]');
            var form = field.closest('form');
            var debounceTimer = 0;
            var minChars = Math.max(2, parseInt(field.getAttribute('data-contact-picker-min-chars') || '2', 10) || 2);
            var limit = Math.max(5, parseInt(field.getAttribute('data-contact-picker-limit') || '10', 10) || 10);
            var required = field.getAttribute('data-contact-picker-required') === '1';
            var filters = collectContactPickerFilters(field);

            if (!hidden || !input || !results) {
                return;
            }

            hidden.disabled = false;

            function updateRequiredState() {
                if (!required) {
                    input.removeAttribute('aria-invalid');
                    return;
                }

                if (hidden.value) {
                    input.removeAttribute('aria-invalid');
                }
            }

            function setSelection(contact) {
                var hasSelection = !!(contact && Number(contact.id || 0) > 0);
                hidden.value = hasSelection ? String(contact.id) : '';

                if (hasSelection) {
                    input.value = String(contact.display_name || '');
                    if (selection) {
                        selection.hidden = false;
                    }
                    if (selectionLabel) {
                        selectionLabel.textContent = String(contact.display_name || '');
                    }
                    if (selectionMeta) {
                        selectionMeta.textContent = String(contactPickerSelectionMeta(contact) || ('ID #' + Number(contact.id || 0)));
                    }
                } else {
                    input.value = '';
                    if (selection) {
                        selection.hidden = true;
                    }
                    if (selectionLabel) {
                        selectionLabel.textContent = '';
                    }
                    if (selectionMeta) {
                        selectionMeta.textContent = '';
                    }
                }

                if (clear) {
                    clear.hidden = !hasSelection;
                }

                results.hidden = true;
                results.innerHTML = '';
                updateRequiredState();
            }

            function normalizeInitialState() {
                var initialId = Number(hidden.value || 0);

                if (initialId > 0) {
                    setSelection({
                        id: initialId,
                        display_name: input.value || '',
                        email: '',
                        profile_roles_label: selectionMeta ? selectionMeta.textContent || '' : ''
                    });
                    return;
                }

                if (selection) {
                    selection.hidden = true;
                }
                if (selectionLabel) {
                    selectionLabel.textContent = '';
                }
                if (selectionMeta) {
                    selectionMeta.textContent = '';
                }
                if (clear) {
                    clear.hidden = true;
                }
                results.hidden = true;
                results.innerHTML = '';
            }

            function setResultsLoading() {
                results.hidden = false;
                results.innerHTML = '<div class="asdl-fin-contact-picker-empty">Buscando perfiles...</div>';
            }

            function setResultsMessage(message) {
                results.hidden = false;
                results.innerHTML = '<div class="asdl-fin-contact-picker-empty">' + escapeHtml(message) + '</div>';
            }

            function searchContacts(term) {
                if (!term) {
                    results.hidden = true;
                    results.innerHTML = '';
                    return;
                }

                if (term.length < minChars) {
                    setResultsMessage('Escribe al menos ' + minChars + ' caracteres.');
                    return;
                }

                setResultsLoading();
                fetchContactsApi(term, {limit: limit, filters: filters}).then(function (payload) {
                    var items = Array.isArray(payload.items) ? payload.items : [];

                    if (!items.length) {
                        setResultsMessage(field.getAttribute('data-contact-picker-empty-text') || 'No se encontraron perfiles con ese termino.');
                        return;
                    }

                    results.hidden = false;
                    results.innerHTML = items.map(renderContactPickerOption).join('');
                }).catch(function () {
                    setResultsMessage('No se pudo buscar ahora. Intenta otra vez.');
                });
            }

            input.addEventListener('input', function () {
                window.clearTimeout(debounceTimer);

                if (hidden.value) {
                    hidden.value = '';
                    if (selection) {
                        selection.hidden = true;
                    }
                    if (clear) {
                        clear.hidden = false;
                    }
                }

                debounceTimer = window.setTimeout(function () {
                    searchContacts(input.value.trim());
                }, 250);
            });

            input.addEventListener('focus', function () {
                if (hidden.value && !input.value.trim()) {
                    return;
                }

                if (input.value.trim().length >= minChars) {
                    searchContacts(input.value.trim());
                }
            });

            results.addEventListener('click', function (event) {
                var option = event.target.closest('[data-contact-picker-option]');
                if (!option) {
                    return;
                }

                event.preventDefault();
                setSelection({
                    id: Number(option.getAttribute('data-contact-id') || 0),
                    display_name: option.getAttribute('data-contact-label') || '',
                    email: '',
                    profile_roles_label: option.getAttribute('data-contact-meta') || ''
                });
            });

            if (clear) {
                clear.addEventListener('click', function (event) {
                    event.preventDefault();
                    setSelection(null);
                    input.focus();
                });
            }

            document.addEventListener('click', function (event) {
                if (field.contains(event.target)) {
                    return;
                }

                results.hidden = true;
            });

            if (form) {
                form.addEventListener('submit', function (event) {
                    if (!required || hidden.value) {
                        return;
                    }

                    event.preventDefault();
                    input.setAttribute('aria-invalid', 'true');
                    input.focus();
                    setResultsMessage('Selecciona un perfil valido antes de continuar.');
                });
            }

            normalizeInitialState();
            updateRequiredState();
        });
    }

    function setupWpUserPickers(root) {
        (root || document).querySelectorAll('[data-wp-user-picker]').forEach(function (field) {
            if (field.dataset.wpUserPickerReady === '1') {
                return;
            }

            field.dataset.wpUserPickerReady = '1';

            var hidden = field.querySelector('[data-wp-user-picker-hidden]');
            var input = field.querySelector('[data-wp-user-picker-input]');
            var results = field.querySelector('[data-wp-user-picker-results]');
            var clear = field.querySelector('[data-wp-user-picker-clear]');
            var selection = field.querySelector('[data-wp-user-picker-selection]');
            var selectionLabel = field.querySelector('[data-wp-user-picker-selection-label]');
            var selectionMeta = field.querySelector('[data-wp-user-picker-selection-meta]');
            var form = field.closest('form');
            var debounceTimer = 0;
            var minChars = Math.max(2, parseInt(field.getAttribute('data-wp-user-picker-min-chars') || '2', 10) || 2);
            var limit = Math.max(5, parseInt(field.getAttribute('data-wp-user-picker-limit') || '10', 10) || 10);
            var required = field.getAttribute('data-wp-user-picker-required') === '1';

            if (!hidden || !input || !results) {
                return;
            }

            hidden.disabled = false;

            function updateRequiredState() {
                if (!required) {
                    input.removeAttribute('aria-invalid');
                    return;
                }

                if (hidden.value) {
                    input.removeAttribute('aria-invalid');
                }
            }

            function setSelection(user) {
                var hasSelection = !!(user && Number(user.id || 0) > 0);
                hidden.value = hasSelection ? String(user.id) : '';

                if (hasSelection) {
                    input.value = String(user.display_name || '');
                    if (selection) {
                        selection.hidden = false;
                    }
                    if (selectionLabel) {
                        selectionLabel.textContent = String(user.display_name || '');
                    }
                    if (selectionMeta) {
                        selectionMeta.textContent = String(wpUserPickerSelectionMeta(user) || ('ID #' + Number(user.id || 0)));
                    }
                } else {
                    input.value = '';
                    if (selection) {
                        selection.hidden = true;
                    }
                    if (selectionLabel) {
                        selectionLabel.textContent = '';
                    }
                    if (selectionMeta) {
                        selectionMeta.textContent = '';
                    }
                }

                if (clear) {
                    clear.hidden = !hasSelection;
                }

                results.hidden = true;
                results.innerHTML = '';
                updateRequiredState();
            }

            function normalizeInitialState() {
                var initialId = Number(hidden.value || 0);

                if (initialId > 0) {
                    setSelection({
                        id: initialId,
                        display_name: input.value || '',
                        user_email: '',
                        user_login: '',
                        roles_label: selectionMeta ? selectionMeta.textContent || '' : ''
                    });
                    return;
                }

                if (selection) {
                    selection.hidden = true;
                }
                if (selectionLabel) {
                    selectionLabel.textContent = '';
                }
                if (selectionMeta) {
                    selectionMeta.textContent = '';
                }
                if (clear) {
                    clear.hidden = true;
                }
                results.hidden = true;
                results.innerHTML = '';
            }

            function setResultsMessage(message) {
                results.hidden = false;
                results.innerHTML = '<div class="asdl-fin-contact-picker-empty">' + escapeHtml(message) + '</div>';
            }

            function setResultsLoading() {
                setResultsMessage('Buscando usuarios...');
            }

            function searchUsers(term) {
                if (!term) {
                    results.hidden = true;
                    results.innerHTML = '';
                    return;
                }

                if (term.length < minChars) {
                    setResultsMessage('Escribe al menos ' + minChars + ' caracteres.');
                    return;
                }

                setResultsLoading();
                fetchWpUsersApi(term, {limit: limit}).then(function (payload) {
                    var items = Array.isArray(payload.items) ? payload.items : [];

                    if (!items.length) {
                        setResultsMessage(field.getAttribute('data-wp-user-picker-empty-text') || 'No se encontraron usuarios con ese termino.');
                        return;
                    }

                    results.hidden = false;
                    results.innerHTML = items.map(renderWpUserPickerOption).join('');
                }).catch(function () {
                    setResultsMessage('No se pudo buscar usuarios ahora. Intenta otra vez.');
                });
            }

            input.addEventListener('input', function () {
                window.clearTimeout(debounceTimer);

                if (hidden.value) {
                    hidden.value = '';
                    if (selection) {
                        selection.hidden = true;
                    }
                    if (clear) {
                        clear.hidden = false;
                    }
                }

                debounceTimer = window.setTimeout(function () {
                    searchUsers(input.value.trim());
                }, 250);
            });

            input.addEventListener('focus', function () {
                if (hidden.value && !input.value.trim()) {
                    return;
                }

                if (input.value.trim().length >= minChars) {
                    searchUsers(input.value.trim());
                }
            });

            results.addEventListener('click', function (event) {
                var option = event.target.closest('[data-wp-user-picker-option]');
                if (!option) {
                    return;
                }

                event.preventDefault();
                setSelection({
                    id: Number(option.getAttribute('data-user-id') || 0),
                    display_name: option.getAttribute('data-user-label') || '',
                    user_email: option.getAttribute('data-user-email') || '',
                    user_login: option.getAttribute('data-user-login') || '',
                    roles_label: option.getAttribute('data-user-roles') || option.getAttribute('data-user-meta') || ''
                });
            });

            if (clear) {
                clear.addEventListener('click', function (event) {
                    event.preventDefault();
                    setSelection(null);
                    input.focus();
                });
            }

            document.addEventListener('click', function (event) {
                if (field.contains(event.target)) {
                    return;
                }

                results.hidden = true;
            });

            if (form) {
                form.addEventListener('submit', function (event) {
                    if (!required || hidden.value) {
                        return;
                    }

                    event.preventDefault();
                    input.setAttribute('aria-invalid', 'true');
                    input.focus();
                    setResultsMessage('Selecciona un usuario valido antes de continuar.');
                });
            }

            normalizeInitialState();
            updateRequiredState();
        });
    }

    function setupProfileContextDisclosures(root) {
        var scope = root || document;
        var hash = window.location.hash ? window.location.hash.replace(/^#/, '') : '';

        scope.querySelectorAll('[data-profile-context-disclosure]').forEach(function (details) {
            if (details.dataset.profileContextReady === '1') {
                return;
            }

            details.dataset.profileContextReady = '1';
        });

        if (!hash) {
            return;
        }

        var directTarget = document.getElementById(hash);

        if (directTarget && directTarget.matches('[data-profile-context-disclosure]')) {
            directTarget.open = true;
            return;
        }

        if (directTarget) {
            var nestedDisclosure = directTarget.querySelector('[data-profile-context-disclosure]');
            if (nestedDisclosure) {
                nestedDisclosure.open = true;
                return;
            }

            var parentDisclosure = directTarget.closest('[data-profile-context-disclosure]');
            if (parentDisclosure) {
                parentDisclosure.open = true;
            }
        }
    }

    function setupSupplierKindToggles(root) {
        (root || document).querySelectorAll('form').forEach(function (form) {
            if (form.dataset.supplierKindToggleReady === '1') {
                return;
            }

            var supplierCheckbox = form.querySelector('[data-supplier-kind-toggle], input[name="is_supplier"]');
            var supplierSelect = form.querySelector('[data-supplier-kind-select], select[name="supplier_kind"]');

            if (!supplierCheckbox || !supplierSelect) {
                return;
            }

            form.dataset.supplierKindToggleReady = '1';

            var note = form.querySelector('[data-supplier-kind-note]');

            function syncSupplierKindState() {
                var enabled = !!supplierCheckbox.checked;
                supplierSelect.disabled = !enabled;
                supplierSelect.setAttribute('aria-disabled', enabled ? 'false' : 'true');

                if (note) {
                    note.hidden = !enabled;
                }
            }

            supplierCheckbox.addEventListener('change', syncSupplierKindState);
            syncSupplierKindState();
        });
    }

    function setupInlineTabs(root) {
        (root || document).querySelectorAll('[data-inline-tabs]').forEach(function (tabs) {
            if (tabs.dataset.inlineTabsReady === '1') {
                return;
            }

            tabs.dataset.inlineTabsReady = '1';

            var triggers = Array.prototype.slice.call(tabs.querySelectorAll('[data-inline-tab-trigger]'));
            var panels = Array.prototype.slice.call(tabs.querySelectorAll('[data-inline-tab-panel]'));
            var defaultTab = tabs.getAttribute('data-inline-tab-default') || (triggers[0] ? triggers[0].getAttribute('data-inline-tab-trigger') : '');

            function activateTab(tabKey) {
                triggers.forEach(function (trigger) {
                    var isActive = trigger.getAttribute('data-inline-tab-trigger') === tabKey;
                    trigger.classList.toggle('is-active', isActive);
                    trigger.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });

                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-inline-tab-panel') !== tabKey;
                });
            }

            triggers.forEach(function (trigger) {
                trigger.addEventListener('click', function () {
                    activateTab(trigger.getAttribute('data-inline-tab-trigger') || '');
                });
            });

            activateTab(defaultTab);
        });
    }

    function setupConsumptionHistorySelectors(root) {
        (root || document).querySelectorAll('[data-consumption-history-selector]').forEach(function (select) {
            if (select.dataset.consumptionHistoryReady === '1') {
                return;
            }

            select.dataset.consumptionHistoryReady = '1';

            var wrapper = select.closest('[data-inline-tab-panel="historical"]');
            if (!wrapper) {
                return;
            }

            var panels = Array.prototype.slice.call(wrapper.querySelectorAll('[data-consumption-history-panel]'));

            function updateSelectedYear() {
                var selectedYear = select.value;

                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-consumption-history-panel') !== selectedYear;
                });
            }

            select.addEventListener('change', updateSelectedYear);
            updateSelectedYear();
        });
    }

    function normalizeSortableValue(value, type) {
        if (type === 'number') {
            var numeric = Number(String(value || '').replace(/[^0-9.-]/g, ''));
            return Number.isFinite(numeric) ? numeric : 0;
        }

        if (type === 'date') {
            var normalized = String(value || '').trim().replace(' ', 'T');
            var timestamp = Date.parse(normalized);
            return Number.isFinite(timestamp) ? timestamp : 0;
        }

        return String(value || '').trim().toLowerCase();
    }

    function setupSortableTable(table, rows, onChange) {
        if (!table || !rows || !rows.length || table._asdlSortable) {
            return table ? (table._asdlSortable || null) : null;
        }

        var headerRow = table.querySelector('thead tr');
        var allHeaders = headerRow ? Array.prototype.slice.call(headerRow.children) : [];
        var sortableHeaders = allHeaders.filter(function (header) {
            return header.hasAttribute('data-sort-type');
        });

        if (!sortableHeaders.length) {
            return null;
        }

        var state = {
            columnIndex: null,
            direction: null
        };

        rows.forEach(function (row, index) {
            if (!row.dataset.sortOriginalIndex) {
                row.dataset.sortOriginalIndex = String(index);
            }
        });

        function updateHeaderState() {
            sortableHeaders.forEach(function (header) {
                var button = header.querySelector('[data-sort-trigger]');
                var indicator = header.querySelector('.asdl-fin-sort-indicator');
                var isActive = state.columnIndex === Number(header.dataset.sortColumnIndex || '-1');
                var symbol = '↕';

                header.setAttribute('aria-sort', isActive
                    ? (state.direction === 'desc' ? 'descending' : 'ascending')
                    : 'none');

                if (button) {
                    button.classList.toggle('is-active', isActive);
                }

                if (isActive) {
                    symbol = state.direction === 'desc' ? '↓' : '↑';
                }

                if (indicator) {
                    indicator.textContent = symbol;
                }
            });
        }

        function sortedRows(sourceRows) {
            var working = Array.prototype.slice.call(sourceRows || []);

            if (state.columnIndex === null || !state.direction) {
                return working;
            }

            working.sort(function (leftRow, rightRow) {
                var header = sortableHeaders.find(function (candidate) {
                    return Number(candidate.dataset.sortColumnIndex || '-1') === state.columnIndex;
                });
                var type = header ? (header.getAttribute('data-sort-type') || 'text') : 'text';
                var leftCell = leftRow.children[state.columnIndex];
                var rightCell = rightRow.children[state.columnIndex];
                var leftValue = normalizeSortableValue(
                    leftCell ? (leftCell.getAttribute('data-sort-value') || leftCell.textContent || '') : '',
                    type
                );
                var rightValue = normalizeSortableValue(
                    rightCell ? (rightCell.getAttribute('data-sort-value') || rightCell.textContent || '') : '',
                    type
                );
                var comparison = 0;

                if (leftValue < rightValue) {
                    comparison = -1;
                } else if (leftValue > rightValue) {
                    comparison = 1;
                } else {
                    comparison = Number(leftRow.dataset.sortOriginalIndex || '0') - Number(rightRow.dataset.sortOriginalIndex || '0');
                }

                return state.direction === 'desc' ? comparison * -1 : comparison;
            });

            return working;
        }

        sortableHeaders.forEach(function (header) {
            var button = header.querySelector('[data-sort-trigger]');
            var columnIndex = allHeaders.indexOf(header);

            header.dataset.sortColumnIndex = String(columnIndex);

            if (!button) {
                return;
            }

            button.addEventListener('click', function () {
                var defaultDirection = header.getAttribute('data-sort-default') || 'asc';

                if (state.columnIndex !== columnIndex) {
                    state.columnIndex = columnIndex;
                    state.direction = defaultDirection;
                } else {
                    state.direction = state.direction === 'desc' ? 'asc' : 'desc';
                }

                updateHeaderState();

                if (typeof onChange === 'function') {
                    onChange();
                }
            });
        });

        updateHeaderState();
        table._asdlSortable = {
            getRows: sortedRows
        };

        return table._asdlSortable;
    }

    function setupDashboardTables() {
        document.querySelectorAll('.asdl-fin-table[data-dashboard-per-page]').forEach(function (table) {
            if (table.dataset.dashboardReady === '1') {
                return;
            }

            if (table.dataset.dashboardCustomPagination === '1') {
                return;
            }

            var tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }

            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            var basePerPage = parseInt(table.getAttribute('data-dashboard-per-page') || '0', 10);
            var expandedPerPage = parseInt(table.getAttribute('data-dashboard-per-page-expanded') || '0', 10);
            var activePerPage = 0;
            var canExpand = false;
            var isExpanded = false;
            var sorter;
            var currentPage = 1;
            var footer = null;
            var counter = null;
            var pager = null;
            var footerMeta = null;
            var toggle = null;
            var previous = null;
            var next = null;
            var label = null;

            if (!rows.length) {
                table.dataset.dashboardReady = '1';
                return;
            }

            basePerPage = basePerPage > 0 ? basePerPage : rows.length;
            expandedPerPage = expandedPerPage > basePerPage ? expandedPerPage : 0;
            canExpand = expandedPerPage > 0 && rows.length > basePerPage;
            activePerPage = basePerPage;
            sorter = setupSortableTable(table, rows, function () {
                currentPage = 1;
                renderPage();
            });

            if (rows.length > basePerPage || canExpand) {
                footer = document.createElement('div');
                counter = document.createElement('div');
                pager = document.createElement('div');
                footerMeta = document.createElement('div');
                previous = document.createElement('button');
                next = document.createElement('button');
                label = document.createElement('span');

                footer.className = 'asdl-fin-table-footer';
                counter.className = 'asdl-fin-table-counter';
                pager.className = 'asdl-fin-table-pager';
                footerMeta.className = 'asdl-fin-table-footer-meta';
                label.className = 'asdl-fin-table-page-label';

                previous.type = 'button';
                previous.className = 'button button-small';
                previous.textContent = 'Atras';

                next.type = 'button';
                next.className = 'button button-small';
                next.textContent = 'Siguiente';

                if (canExpand) {
                    toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'button button-small asdl-fin-table-display-toggle';
                }

                if (toggle) {
                    footerMeta.appendChild(toggle);
                }

                footerMeta.appendChild(counter);
                pager.appendChild(previous);
                pager.appendChild(label);
                pager.appendChild(next);
                footer.appendChild(footerMeta);
                footer.appendChild(pager);
                table.parentNode.insertBefore(footer, table.nextSibling);
            }

            function renderPage() {
                var orderedRows = sorter ? sorter.getRows(rows) : rows.slice();
                var pageSize = activePerPage > 0 ? activePerPage : orderedRows.length;
                var start = (currentPage - 1) * pageSize;
                var end = start + pageSize;
                var totalPages = Math.max(1, Math.ceil(orderedRows.length / pageSize));

                if (currentPage > totalPages) {
                    currentPage = totalPages;
                    start = (currentPage - 1) * pageSize;
                    end = start + pageSize;
                }

                orderedRows.forEach(function (row) {
                    tbody.appendChild(row);
                });

                orderedRows.forEach(function (row, index) {
                    row.hidden = index < start || index >= end;
                });

                if (previous && next && label && counter) {
                    previous.disabled = currentPage <= 1;
                    next.disabled = currentPage >= totalPages;
                    label.textContent = 'Pagina ' + currentPage + ' de ' + totalPages;
                    counter.textContent = 'Mostrando ' + Math.min(pageSize, Math.max(orderedRows.length - start, 0)) + ' de ' + orderedRows.length + ' registro(s)';
                }

                if (toggle) {
                    toggle.textContent = isExpanded ? 'Ver menos' : 'Ver mas';
                    toggle.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
                }
            }

            if (previous && next) {
                previous.addEventListener('click', function () {
                    if (currentPage <= 1) {
                        return;
                    }

                    currentPage -= 1;
                    renderPage();
                });

                next.addEventListener('click', function () {
                    var pageSize = activePerPage > 0 ? activePerPage : rows.length;
                    var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));

                    if (currentPage >= totalPages) {
                        return;
                    }

                    currentPage += 1;
                    renderPage();
                });
            }

            if (toggle) {
                toggle.addEventListener('click', function () {
                    isExpanded = !isExpanded;
                    activePerPage = isExpanded ? expandedPerPage : basePerPage;
                    currentPage = 1;
                    renderPage();
                });
            }

            renderPage();
            table.dataset.dashboardReady = '1';
        });
    }

    function setupDashboardQueueFilters() {
        document.querySelectorAll('[data-dashboard-queue]').forEach(function (queue) {
            if (queue.dataset.dashboardQueueReady === '1') {
                return;
            }

            var table = queue.querySelector('.asdl-fin-table[data-dashboard-custom-pagination="1"]');
            var tbody = table ? table.querySelector('tbody') : null;
            var rows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr[data-dashboard-row="1"]')) : [];
            var emptyRow = tbody ? tbody.querySelector('tr[data-dashboard-empty-row]') : null;
            var searchInput = queue.querySelector('[data-dashboard-filter-search]');
            var originSelect = queue.querySelector('[data-dashboard-filter-origin]');
            var rangeSelect = queue.querySelector('[data-dashboard-filter-range]');
            var resetButton = queue.querySelector('[data-dashboard-filter-reset]');
            var meta = queue.querySelector('[data-dashboard-filter-meta]');
            var perPage = parseInt((table && table.getAttribute('data-dashboard-per-page')) || '0', 10);
            var currentPage = 1;
            var footer;
            var counter;
            var pager;
            var previous;
            var next;
            var label;
            var sorter;

            if (!table || !tbody || !rows.length) {
                queue.dataset.dashboardQueueReady = '1';
                return;
            }

            perPage = perPage > 0 ? perPage : rows.length;
            sorter = setupSortableTable(table, rows, function () {
                currentPage = 1;
                renderPage();
            });
            footer = document.createElement('div');
            counter = document.createElement('div');
            pager = document.createElement('div');
            previous = document.createElement('button');
            next = document.createElement('button');
            label = document.createElement('span');

            footer.className = 'asdl-fin-table-footer';
            counter.className = 'asdl-fin-table-counter';
            pager.className = 'asdl-fin-table-pager';
            label.className = 'asdl-fin-table-page-label';

            previous.type = 'button';
            previous.className = 'button button-small';
            previous.textContent = 'Atras';

            next.type = 'button';
            next.className = 'button button-small';
            next.textContent = 'Siguiente';

            pager.appendChild(previous);
            pager.appendChild(label);
            pager.appendChild(next);
            footer.appendChild(counter);
            footer.appendChild(pager);
            table.parentNode.insertBefore(footer, table.nextSibling);

            function activeFilters() {
                return {
                    search: searchInput ? searchInput.value.trim().toLowerCase() : '',
                    origin: originSelect ? originSelect.value.trim() : '',
                    range: rangeSelect ? rangeSelect.value.trim() : ''
                };
            }

            function matchesRow(row, filters) {
                var searchText = String(row.dataset.searchText || '').toLowerCase();
                var origins = String(row.dataset.originFlags || '').split(/\s+/).filter(Boolean);
                var ranges = String(row.dataset.rangeFlags || '').split(/\s+/).filter(Boolean);

                if (filters.search && searchText.indexOf(filters.search) === -1) {
                    return false;
                }

                if (filters.origin && origins.indexOf(filters.origin) === -1) {
                    return false;
                }

                if (filters.range && ranges.indexOf(filters.range) === -1) {
                    return false;
                }

                return true;
            }

            function filteredRows() {
                var filters = activeFilters();
                var orderedRows = sorter ? sorter.getRows(rows) : rows.slice();

                return orderedRows.filter(function (row) {
                    return matchesRow(row, filters);
                });
            }

            function renderMeta(filteredCount) {
                var filters = activeFilters();
                var hasFilter = !!(filters.search || filters.origin || filters.range);

                if (!meta) {
                    return;
                }

                if (!hasFilter) {
                    meta.hidden = true;
                    meta.textContent = '';
                    return;
                }

                meta.hidden = false;
                meta.textContent = filteredCount + ' grupo(s) visibles con el filtro actual';
            }

            function renderPage() {
                var visibleRows = filteredRows();
                var totalVisible = visibleRows.length;
                var totalPages = Math.max(1, Math.ceil(Math.max(totalVisible, 1) / perPage));
                var start;
                var end;

                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }

                start = (currentPage - 1) * perPage;
                end = start + perPage;

                visibleRows.forEach(function (row) {
                    tbody.appendChild(row);
                });

                rows.forEach(function (row) {
                    row.hidden = true;
                });

                if (!totalVisible) {
                    if (emptyRow) {
                        emptyRow.hidden = false;
                    }

                    previous.disabled = true;
                    next.disabled = true;
                    label.textContent = 'Sin resultados';
                    counter.textContent = '0 de ' + rows.length + ' grupo(s)';
                    renderMeta(0);
                    return;
                }

                if (emptyRow) {
                    emptyRow.hidden = true;
                    tbody.appendChild(emptyRow);
                }

                visibleRows.forEach(function (row, index) {
                    row.hidden = index < start || index >= end;
                });

                previous.disabled = currentPage <= 1;
                next.disabled = currentPage >= totalPages;
                label.textContent = 'Pagina ' + currentPage + ' de ' + totalPages;
                counter.textContent = 'Mostrando ' + Math.min(perPage, Math.max(totalVisible - start, 0)) + ' de ' + totalVisible + ' grupo(s)';
                renderMeta(totalVisible);
            }

            function resetFilters() {
                if (searchInput) {
                    searchInput.value = '';
                }

                if (originSelect) {
                    originSelect.value = '';
                }

                if (rangeSelect) {
                    rangeSelect.value = '';
                }

                currentPage = 1;
                renderPage();
            }

            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    currentPage = 1;
                    renderPage();
                });
            }

            if (originSelect) {
                originSelect.addEventListener('change', function () {
                    currentPage = 1;
                    renderPage();
                });
            }

            if (rangeSelect) {
                rangeSelect.addEventListener('change', function () {
                    currentPage = 1;
                    renderPage();
                });
            }

            if (resetButton) {
                resetButton.addEventListener('click', resetFilters);
            }

            previous.addEventListener('click', function () {
                if (currentPage <= 1) {
                    return;
                }

                currentPage -= 1;
                renderPage();
            });

            next.addEventListener('click', function () {
                if (next.disabled) {
                    return;
                }

                currentPage += 1;
                renderPage();
            });

            renderPage();
            queue.dataset.dashboardQueueReady = '1';
        });
    }

    function setupSortableStaticTables() {
        document.querySelectorAll('.asdl-fin-table[data-sortable-table="1"]:not([data-dashboard-custom-pagination="1"]):not([data-dashboard-per-page])').forEach(function (table) {
            if (table.dataset.sortableReady === '1') {
                return;
            }

            var tbody = table.querySelector('tbody');
            var rows = tbody ? Array.prototype.slice.call(tbody.querySelectorAll('tr')) : [];
            var sorter;

            if (!tbody || !rows.length) {
                table.dataset.sortableReady = '1';
                return;
            }

            sorter = setupSortableTable(table, rows, function () {
                sorter.getRows(rows).forEach(function (row) {
                    tbody.appendChild(row);
                });
            });

            if (sorter) {
                sorter.getRows(rows).forEach(function (row) {
                    tbody.appendChild(row);
                });
            }

            table.dataset.sortableReady = '1';
        });
    }

    function populateOrderListModal(trigger) {
        var modalName = trigger.getAttribute('data-modal-target') || '';
        var modal = document.querySelector('[data-modal="' + modalName + '"]');
        var body = modal ? modal.querySelector('[data-order-list-body]') : null;
        var title = modal ? modal.querySelector('[data-order-list-title]') : null;
        var items = [];
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};

        if (!modal || !body) {
            return Promise.resolve(null);
        }

        if (title) {
            title.textContent = (trigger.getAttribute('data-group-label') || 'Pendientes agrupados') + ' - detalle pendiente';
        }

        if (trigger.hasAttribute('data-receivable-group-detail')) {
            renderSettlementPreviewLoading(body);

            return requestRuntimeHtml('asdl_fin_receivable_group_detail', runtimeNonces.receivableDetail, {
                contact_id: trigger.getAttribute('data-contact-id') || '',
                wp_user_id: trigger.getAttribute('data-wp-user-id') || '',
                email: trigger.getAttribute('data-email') || '',
                display_name: trigger.getAttribute('data-group-label') || '',
                range_from: trigger.closest('[data-dashboard-runtime-section]') ? trigger.closest('[data-dashboard-runtime-section]').getAttribute('data-range-from') || '' : '',
                range_to: trigger.closest('[data-dashboard-runtime-section]') ? trigger.closest('[data-dashboard-runtime-section]').getAttribute('data-range-to') || '' : ''
            }).then(function (payload) {
                items = Array.isArray(payload.items) ? payload.items : [];
                if (!items.length) {
                    body.innerHTML = '<div class="asdl-fin-empty"><strong>Sin pendientes disponibles.</strong><p>No encontramos pedidos u otros cobros editables para este grupo.</p></div>';
                    return modal;
                }

                body.innerHTML = buildOrderListModalItemsHtml(items);
                return modal;
            }).catch(function (error) {
                body.innerHTML = '<div class="asdl-fin-empty"><strong>No se pudo cargar el detalle.</strong><p>' + escapeHtml((error && error.message) || 'Intenta abrir este grupo de nuevo.') + '</p></div>';
                return modal;
            });
        }

        try {
            items = JSON.parse(trigger.getAttribute('data-order-list') || '[]');
        } catch (error) {
            items = [];
        }

        if (!items.length) {
            body.innerHTML = '<div class="asdl-fin-empty"><strong>Sin pendientes disponibles.</strong><p>No encontramos pedidos u otros cobros editables para este grupo.</p></div>';
            return Promise.resolve(modal);
        }

        body.innerHTML = buildOrderListModalItemsHtml(items);

        return Promise.resolve(modal);
    }

    function buildOrderListModalItemsHtml(items) {
        return items.map(function (item) {
            var meta = [];
            var actions = [];

            if (item.status) {
                meta.push('<span>' + escapeHtml(item.status) + '</span>');
            }

            if (item.date) {
                meta.push('<span>' + escapeHtml(item.date) + '</span>');
            }

            if (item.total) {
                meta.push('<span>' + escapeHtml(item.total) + '</span>');
            }

            if (item.range_label) {
                meta.push('<span>' + escapeHtml(item.range_label) + '</span>');
            }

            if (item.open_url) {
                actions.push('<a class="button button-secondary button-small" href="' + escapeHtml(item.open_url) + '">' + escapeHtml(item.open_label || 'Abrir') + '</a>');
            }

            if (item.manage_url && item.manage_url !== item.open_url) {
                actions.push('<a class="button button-secondary button-small" href="' + escapeHtml(item.manage_url) + '">' + escapeHtml(item.manage_label || 'Gestionar') + '</a>');
            }

            return ''
                + '<div class="asdl-fin-order-item">'
                + '<div class="asdl-fin-order-item-top"><span class="asdl-fin-order-item-kind">' + escapeHtml(item.kind_label || 'Pendiente') + '</span>'
                + '<strong class="asdl-fin-order-item-link">' + escapeHtml(item.label || 'Pendiente') + '</strong></div>'
                + (item.description ? '<p class="asdl-fin-order-item-description">' + escapeHtml(item.description) + '</p>' : '')
                + '<div class="asdl-fin-order-item-meta">' + meta.join('<span class="asdl-fin-order-meta-sep">·</span>') + '</div>'
                + (actions.length ? '<div class="asdl-fin-order-item-actions">' + actions.join('') + '</div>' : '')
                + '</div>';
        }).join('');
    }

    function setModalState(modal, isOpen) {
        if (!modal) {
            return;
        }

        var wasOpen = !modal.hidden;

        if (isOpen) {
            if (!wasOpen) {
                modal.hidden = false;
                document.body.classList.add('asdl-fin-modal-open');

                var focusField = modal.querySelector('input, select, textarea, button');
                if (focusField) {
                    window.setTimeout(function () {
                        focusField.focus();
                    }, 20);
                }
            }

            return;
        }

        if (!wasOpen) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('asdl-fin-modal-open');
    }

    function formatCurrencyAmount(amount, currency) {
        var numeric = Number(amount || 0);
        var code = String(currency || 'USD').toUpperCase();

        if (!Number.isFinite(numeric)) {
            numeric = 0;
        }

        try {
            return new Intl.NumberFormat('es-VE', {
                style: 'currency',
                currency: code,
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(numeric);
        } catch (error) {
            return numeric.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' ' + code;
        }
    }

    function formatPreviewDateLabel(value) {
        if (!value) {
            return 'Sin fecha';
        }

        var match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!match) {
            return String(value);
        }

        var label = match[3] + '/' + match[2] + '/' + match[1];
        if (match[4] && match[5]) {
            label += ' ' + match[4] + ':' + match[5];
        }

        return label;
    }

    function settlementPreviewStatusTone(statusKey) {
        return statusKey === 'closed' ? 'success' : 'warning';
    }

    function renderSettlementPreviewEmpty(body, title, description, extraClass) {
        if (!body) {
            return;
        }

        body.innerHTML = ''
            + '<div class="asdl-fin-empty ' + escapeHtml(extraClass || '') + '">'
            + '<strong>' + escapeHtml(title || 'Sin simulacion cargada.') + '</strong>'
            + '<p>' + escapeHtml(description || 'Selecciona el metodo y el monto del abono para calcular la vista previa.') + '</p>'
            + '</div>';
    }

    function renderSettlementPreviewLoading(body) {
        if (!body) {
            return;
        }

        body.innerHTML = buildSettlementPreviewLoadingHtml();
    }

    function buildSettlementPreviewLoadingHtml() {
        return ''
            + '<div class="asdl-fin-settlement-preview-loading">'
            + '<span class="asdl-fin-spinner" aria-hidden="true"></span>'
            + '<div class="asdl-fin-stack">'
            + '<strong>Calculando vista previa...</strong>'
            + '<small>Estamos simulando como quedaran los pedidos y validando si este abono se aplicara al instante o por lotes.</small>'
            + '</div>'
            + '</div>';
    }

    function buildSettlementPreviewHtml(preview) {
        var summary = preview && preview.summary ? preview.summary : {};
        var items = Array.isArray(preview && preview.items) ? preview.items : [];
        var currency = preview && preview.currency ? preview.currency : 'USD';
        var paymentMethod = preview && preview.payment_method ? preview.payment_method : {};
        var discount = preview && preview.discount ? preview.discount : {};
        var executionMode = preview && preview.execution_mode ? preview.execution_mode : 'runner';
        var rateSnapshot = preview && preview.rate_snapshot && typeof preview.rate_snapshot === 'object'
            ? preview.rate_snapshot
            : null;
        var rateValue = rateSnapshot
            ? (rateSnapshot.rate || rateSnapshot.value || rateSnapshot.amount || rateSnapshot.bs_per_usd || '')
            : '';
        var rateDate = rateSnapshot
            ? (rateSnapshot.date || rateSnapshot.updated_at || rateSnapshot.updatedAt || '')
            : '';
        var meta = [
            '<span><strong>Metodo:</strong> ' + escapeHtml(paymentMethod.label || paymentMethod.key || 'Sin definir') + '</span>',
            '<span><strong>Moneda:</strong> ' + escapeHtml(currency) + '</span>',
            '<span><strong>Precio dual:</strong> ' + (preview && preview.uses_dual ? escapeHtml(Number(discount.percent || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + '%' : 'No aplica') + '</span>',
            '<span><strong>Ejecucion:</strong> ' + escapeHtml(executionMode === 'fast_path' ? 'Aplicacion inmediata' : 'Runner por lotes') + '</span>'
        ];

        if (rateValue !== '') {
            meta.push('<span><strong>Tasa de referencia:</strong> ' + escapeHtml(String(rateValue)) + '</span>');
        }

        if (rateDate) {
            meta.push('<span><strong>Corte:</strong> ' + escapeHtml(formatPreviewDateLabel(rateDate)) + '</span>');
        }

        if (!items.length) {
            return ''
                + '<div class="asdl-fin-empty">'
                + '<strong>Sin pedidos simulados.</strong>'
                + '<p>No encontramos pedidos cobrables para construir la vista previa del abono.</p>'
                + '</div>';
        }

        return ''
            + '<div class="asdl-fin-settlement-preview-meta">' + meta.join('') + '</div>'
            + '<div class="asdl-fin-settlement-preview-summary">'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Monto recibido</strong><span>' + escapeHtml(formatCurrencyAmount(summary.requested_total || 0, currency)) + '</span><small>Efectivo/divisa disponible para repartir.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Descuento aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(summary.discount_applied_total || 0, currency)) + '</span><small>Rebaja total concedida por precio dual.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(summary.covered_total || 0, currency)) + '</span><small>Deuda real que quedara gestionada en pedidos.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Remanente</strong><span>' + escapeHtml(formatCurrencyAmount(summary.unapplied_total || 0, currency)) + '</span><small>Saldo que no logra aplicarse sobre pedidos abiertos.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos cerrados</strong><span>' + escapeHtml(String(summary.closed_count || 0)) + '</span><small>Pedidos que quedaran liquidados.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos parciales</strong><span>' + escapeHtml(String(summary.partial_count || 0)) + '</span><small>Pedidos que seguiran abiertos despues del abono.</small></div>'
            + '</div>'
            + '<div class="asdl-fin-settlement-preview-note">' + escapeHtml(
                preview && preview.uses_dual
                    ? 'Esta simulacion sigue el orden por antiguedad: procesa primero los pedidos mas viejos y aplica el descuento precio dual solo sobre la porcion realmente cubierta en cada pedido.'
                    : 'Esta simulacion sigue el orden por antiguedad: procesa primero los pedidos mas viejos y deja parcial el siguiente si el monto no alcanza.'
            ) + '</div>'
            + '<div class="asdl-fin-table-wrap">'
            + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-settlement-preview-table">'
            + '<thead><tr>'
            + '<th>Pedido</th>'
            + '<th>Fecha</th>'
            + '<th>Deuda original</th>'
            + '<th>Descuento</th>'
            + '<th>Consumo cliente</th>'
            + '<th>Total cubierto</th>'
            + '<th>Saldo restante</th>'
            + '<th>Estado final</th>'
            + '</tr></thead>'
            + '<tbody>'
            + items.map(function (item) {
                return ''
                    + '<tr>'
                    + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(item.order_label || item.order_number || 'Pedido') + '</strong>'
                    + (item.edit_url ? '<a href="' + escapeHtml(item.edit_url) + '" target="_blank" rel="noopener">Abrir pedido</a>' : '')
                    + '</div></td>'
                    + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(formatPreviewDateLabel(item.date_created || '')) + '</strong>'
                    + '<small>' + escapeHtml(item.sequence ? 'Orden #' + item.sequence : '') + '</small></div></td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.document_balance || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.discount_applied_total || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.payment_applied_total || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.covered_total || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.remaining_document_balance || 0, item.currency || currency)) + '</td>'
                    + '<td>' + renderPill(item.status_label || 'Pendiente', settlementPreviewStatusTone(item.status_key)) + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table></div>';
    }

    function renderSettlementPreview(body, preview) {
        if (!body) {
            return;
        }

        body.innerHTML = buildSettlementPreviewHtml(preview);
    }

    function settlementExecutionLabel(mode) {
        return mode === 'fast_path' ? 'Aplicacion inmediata' : 'Runner por lotes';
    }

    function settlementBatchStatusLabel(status) {
        switch (String(status || '')) {
            case 'pending':
                return 'Pendiente';
            case 'running':
                return 'Procesando';
            case 'completed':
                return 'Completado';
            case 'completed_with_errors':
                return 'Completado con errores';
            case 'failed':
                return 'Fallido';
            default:
                return status || 'Sin estado';
        }
    }

    function settlementBatchStatusTone(status) {
        switch (String(status || '')) {
            case 'completed':
                return 'success';
            case 'completed_with_errors':
                return 'warning';
            case 'failed':
                return 'danger';
            case 'pending':
            case 'running':
                return 'neutral';
            default:
                return 'neutral';
        }
    }

    function buildSettlementProcessingHtml(snapshot) {
        var job = snapshot && snapshot.job ? snapshot.job : {};
        var batch = snapshot && snapshot.batch ? snapshot.batch : {};
        var currency = batch.currency || 'USD';
        var processedCount = Number(job.processed_count || 0);
        var itemCount = Math.max(0, Number(job.item_count || 0));
        var percent = itemCount > 0 ? Math.min(100, Math.round((processedCount / itemCount) * 100)) : 0;

        return ''
            + '<div class="asdl-fin-settlement-progress-panel">'
            + '<div class="asdl-fin-settlement-preview-summary">'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Estado</strong><span>' + escapeHtml(settlementBatchStatusLabel(job.status)) + '</span><small>' + escapeHtml(settlementExecutionLabel(job.execution_mode)) + '.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Procesados</strong><span>' + escapeHtml(String(processedCount)) + ' / ' + escapeHtml(String(itemCount)) + '</span><small>Pedidos ya aplicados dentro del lote actual.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(job.processed_total || 0, currency)) + '</span><small>Deuda ya gestionada en esta corrida.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Descuento aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(job.discount_total || 0, currency)) + '</span><small>Descuento tecnico acumulado hasta ahora.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Ultimo lote</strong><span>' + escapeHtml(String(job.last_batch || 0)) + '</span><small>Cantidad de pedidos procesados en la ultima tanda.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Errores</strong><span>' + escapeHtml(String(job.errors_count || 0)) + '</span><small>Pedidos omitidos o con error durante la corrida.</small></div>'
            + '</div>'
            + '<div class="asdl-fin-settlement-progress-meter" aria-hidden="true"><span style="width:' + escapeHtml(String(percent)) + '%"></span></div>'
            + '<div class="asdl-fin-settlement-progress-copy"><strong>' + escapeHtml(String(percent)) + '% completado</strong><small>El abono sigue corriendo por lotes pequenos para evitar bloqueos o timeouts.</small></div>'
            + '</div>';
    }

    function renderSettlementProcessing(body, snapshot) {
        if (!body) {
            return;
        }

        body.innerHTML = buildSettlementProcessingHtml(snapshot);
    }

    function buildSettlementResultHtml(snapshot) {
        var job = snapshot && snapshot.job ? snapshot.job : {};
        var batch = snapshot && snapshot.batch ? snapshot.batch : {};
        var result = snapshot && snapshot.result ? snapshot.result : {};
        var errors = Array.isArray(snapshot && snapshot.errors) ? snapshot.errors : [];
        var currency = batch.currency || 'USD';
        var status = settlementBatchStatusLabel(job.status);
        var tone = settlementBatchStatusTone(job.status);
        var cards = ''
            + '<div class="asdl-fin-settlement-preview-summary">'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Estado final</strong><span>' + escapeHtml(status) + '</span><small>Lote #' + escapeHtml(String(job.batch_id || 0)) + '.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total recibido</strong><span>' + escapeHtml(formatCurrencyAmount(job.total_received || 0, currency)) + '</span><small>Monto confirmado para este abono.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(result.covered_total || job.processed_total || 0, currency)) + '</span><small>Deuda finalmente gestionada.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Descuento total</strong><span>' + escapeHtml(formatCurrencyAmount(result.dual_discount_total || job.discount_total || 0, currency)) + '</span><small>Descuento dual aplicado por el runner.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos cerrados</strong><span>' + escapeHtml(String((result.closed_order_ids || []).length || 0)) + '</span><small>Pedidos liquidados por completo.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Parciales / errores</strong><span>' + escapeHtml(String((result.partial_order_ids || []).length || 0)) + ' / ' + escapeHtml(String(job.errors_count || 0)) + '</span><small>Incluye parciales, omitidos y errores.</small></div>'
            + '</div>';

        var note = '<div class="asdl-fin-note-box"><strong>Resultado listo.</strong><div>' + escapeHtml('Estado: ' + status + '.') + ' ' + escapeHtml('Puedes actualizar el perfil para ver los saldos y pedidos recalculados.') + '</div></div>';

        if (!errors.length) {
            return cards + note;
        }

        return cards
            + note
            + '<div class="asdl-fin-note-box"><strong>Pedidos con error u omision</strong><div class="asdl-fin-table-wrap"><table class="widefat striped asdl-fin-table asdl-fin-table-compact"><thead><tr><th>Pedido</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>'
            + errors.map(function (item) {
                return '<tr>'
                    + '<td>' + escapeHtml(item.order_number || item.external_order_id || 'Pedido') + '</td>'
                    + '<td>' + escapeHtml(settlementBatchStatusLabel(item.status || 'error')) + '</td>'
                    + '<td>' + escapeHtml(item.error_message || 'No se pudo aplicar este pedido.') + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table></div></div>';
    }

    function renderSettlementResult(body, snapshot) {
        if (!body) {
            return;
        }

        body.innerHTML = buildSettlementResultHtml(snapshot);
    }

    function setAsyncButtonState(button, isLoading, idleLabel, loadingLabel) {
        if (!button) {
            return;
        }

        var idle = idleLabel || button.dataset.idleLabel || button.textContent || button.value || '';
        var loading = loadingLabel || idle;
        button.dataset.idleLabel = idle;
        button.classList.toggle('is-loading', !!isLoading);

        if (button.tagName === 'INPUT') {
            button.value = isLoading ? loading : idle;
            button.disabled = !!isLoading;
            return;
        }

        if (isLoading) {
            button.innerHTML = '<span class="asdl-fin-spinner asdl-fin-spinner-inline" aria-hidden="true"></span><span>' + escapeHtml(loading) + '</span>';
            button.disabled = true;
            return;
        }

        button.textContent = idle;
        button.disabled = false;
    }

    function refreshCurrentContactDetailRuntime() {
        var containers = Array.prototype.slice.call(document.querySelectorAll(
            '[data-runtime-action="asdl_fin_admin_runtime"][data-runtime-param-page-key="contacts"][data-contact-runtime-refreshable="1"]'
        ));
        var legacyContainer = document.querySelector('[data-runtime-action="asdl_fin_admin_runtime"][data-runtime-param-page-key="contacts"][data-runtime-param-section-key="contact-full"]');
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};

        if (!containers.length && legacyContainer) {
            containers = [legacyContainer];
        }

        if (!containers.length || !runtimeNonces.adminRuntime) {
            return Promise.resolve();
        }

        return Promise.allSettled(containers.map(function (container) {
            delete container.dataset.runtimeLoaded;
            delete container.dataset.runtimeLoading;
            container.classList.add('is-runtime-loading');
            return requestRuntimeHtml('asdl_fin_admin_runtime', runtimeNonces.adminRuntime, collectRuntimeParams(container)).then(function (payload) {
                container.innerHTML = payload.html || '';
                container.dataset.runtimeLoaded = '1';
                container.dataset.runtimeState = 'loaded';
                delete container.dataset.runtimeLoading;
                container.classList.remove('is-runtime-loading');
                container.classList.remove('is-runtime-error');
                initializeDynamicAdminContent(container);
            }).catch(function () {
                container.classList.remove('is-runtime-loading');
                return null;
            });
        })).then(function () {
            return undefined;
        });
    }

    function findSettlementSubmitButton(form) {
        if (!form) {
            return null;
        }

        return form.querySelector('button[type="submit"], input[type="submit"]');
    }

    function setSettlementFormLoading(form, isLoading, loadingLabel) {
        var button = findSettlementSubmitButton(form);

        if (!button) {
            return;
        }

        setAsyncButtonState(
            button,
            isLoading,
            button.dataset.idleLabel || button.textContent || button.value || 'Aplicar abono a pedidos',
            loadingLabel || 'Calculando...'
        );
    }

    function resetSettlementFormLoading(form) {
        var button = findSettlementSubmitButton(form);

        if (!button) {
            return;
        }

        setAsyncButtonState(
            button,
            false,
            button.dataset.idleLabel || button.textContent || button.value || 'Aplicar abono a pedidos'
        );
    }

    function setupOrderSettlementPreview() {
        var modal = document.querySelector('[data-modal="order-settlement-preview"]');
        var body = modal ? modal.querySelector('[data-settlement-preview-body]') : null;
        var confirmButton = modal ? modal.querySelector('[data-settlement-preview-confirm]') : null;
        var secondaryButton = modal ? modal.querySelector('[data-settlement-preview-secondary]') : null;
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var activeState = {
            form: null,
            preview: null,
            formSignature: '',
            batchId: 0,
            controller: null,
            timer: 0,
            stage: 'idle'
        };

        if (!modal || !body || !confirmButton || !secondaryButton || !window.ASDLFinanceAdmin || modal.dataset.previewReady === '1') {
            return;
        }

        if (!runtimeNonces.orderSettlementPreview || !runtimeNonces.orderSettlementStart || !runtimeNonces.orderSettlementContinue || !runtimeNonces.orderSettlementStatus || !runtimeNonces.orderSettlementResult) {
            return;
        }

        function getRelevantSignature(form) {
            var accountInput = form.querySelector('[name="account_id"]');
            var methodInput = form.querySelector('[data-payment-method-select]');
            var totalInput = form.querySelector('[data-settlement-total]');
            var currencyInput = form.querySelector('[data-settlement-currency]');
            var dateInput = form.querySelector('[data-settlement-payment-date]');
            return [
                (accountInput && accountInput.value) || '',
                (methodInput && methodInput.value) || '',
                (totalInput && totalInput.value) || '',
                (currencyInput && currencyInput.value) || '',
                (dateInput && dateInput.value) || '',
                form.getAttribute('data-order-settlement-origin') || 'profile_settlement'
            ].join('|');
        }

        function getPreviewPayload(form) {
            var formData = new FormData(form);
            return {
                origin: form.getAttribute('data-order-settlement-origin') || 'profile_settlement',
                contact_id: Number(formData.get('contact_id') || 0),
                account_id: formData.get('account_id') || '',
                payment_date: formData.get('payment_date') || '',
                total: formData.get('total') || '',
                currency: formData.get('currency') || '',
                method_key: formData.get('method_key') || '',
                reference: formData.get('reference') || '',
                notes: formData.get('notes') || ''
            };
        }

        function setActionState(primaryLabel, primaryDisabled, secondaryLabel, primaryLoading) {
            setAsyncButtonState(confirmButton, !!primaryLoading, 'Confirmar y aplicar', primaryLabel || 'Confirmar y aplicar');
            if (!primaryLoading) {
                confirmButton.textContent = primaryLabel || 'Confirmar y aplicar';
                confirmButton.disabled = !!primaryDisabled;
            }
            secondaryButton.textContent = secondaryLabel || 'Cancelar';
        }

        function clearTimer() {
            window.clearTimeout(activeState.timer);
            activeState.timer = 0;
        }

        function resetActiveState(form) {
            clearTimer();
            activeState.form = form || null;
            activeState.preview = null;
            activeState.formSignature = '';
            activeState.batchId = 0;
            activeState.stage = 'idle';
            if (activeState.controller && typeof activeState.controller.abort === 'function') {
                activeState.controller.abort();
            }
            activeState.controller = null;
        }

        function resetPreviewConfirmation(form) {
            var confirmedInput = form ? form.querySelector('[data-settlement-preview-confirmed]') : null;
            var signatureInput = form ? form.querySelector('[data-settlement-preview-signature]') : null;
            if (confirmedInput) {
                confirmedInput.value = '0';
            }
            if (signatureInput) {
                signatureInput.value = '';
            }
        }

        function syncHiddenSignature(form, previewSignature) {
            var confirmedInput = form ? form.querySelector('[data-settlement-preview-confirmed]') : null;
            var signatureInput = form ? form.querySelector('[data-settlement-preview-signature]') : null;

            if (confirmedInput) {
                confirmedInput.value = '0';
            }
            if (signatureInput) {
                signatureInput.value = previewSignature || '';
            }
        }

        function renderPreviewState(preview) {
            activeState.preview = preview || null;
            activeState.stage = 'preview';
            renderSettlementPreview(body, preview || {});
            setActionState('Confirmar y aplicar', !preview || !preview.preview_signature, 'Cancelar');
            if (activeState.form) {
                resetSettlementFormLoading(activeState.form);
            }
            setModalState(modal, true);
        }

        function renderProcessingState(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            activeState.batchId = Number(job.batch_id || 0);
            activeState.stage = 'processing';
            renderSettlementProcessing(body, snapshot || {});
            setActionState('Procesando...', true, 'Seguir en segundo plano', true);
            if (activeState.form) {
                setSettlementFormLoading(activeState.form, true, 'Procesando abono...');
            }
            setModalState(modal, true);
        }

        function renderResultState(snapshot) {
            activeState.stage = 'result';
            renderSettlementResult(body, snapshot || {});
            setActionState('Actualizar perfil', false, 'Cerrar');
            if (activeState.form) {
                resetSettlementFormLoading(activeState.form);
            }
            setModalState(modal, true);
        }

        function scheduleContinue(batchId) {
            clearTimer();

            if (!batchId) {
                return;
            }

            activeState.timer = window.setTimeout(function () {
                runContinueRequest(batchId, 0);
            }, 220);
        }

        function runContinueRequest(batchId, attempt) {
            requestAdminAjax('asdl_fin_order_settlement_continue', runtimeNonces.orderSettlementContinue, {
                batch_id: batchId
            }).then(function (payload) {
                handleSettlementSnapshot(payload.snapshot || {});
            }).catch(function (error) {
                if ((attempt || 0) < 2) {
                    activeState.timer = window.setTimeout(function () {
                        runContinueRequest(batchId, (attempt || 0) + 1);
                    }, 600 * ((attempt || 0) + 1));
                    return;
                }

                activeState.stage = 'result';
                renderSettlementPreviewEmpty(
                    body,
                    'No se pudo continuar el abono.',
                    (error && error.message) || 'Ocurrio un error al continuar el runner por lotes.',
                    'asdl-fin-settlement-preview-error'
                );
                if (activeState.form) {
                    resetSettlementFormLoading(activeState.form);
                }
                setActionState('Actualizar perfil', false, 'Cerrar');
            });
        }

        function handleSettlementSnapshot(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            var status = String(job.status || '');

            if (status === 'pending' || status === 'running') {
                renderProcessingState(snapshot);
                scheduleContinue(Number(job.batch_id || 0));
                return;
            }

            if (status === 'completed' || status === 'completed_with_errors') {
                requestAdminAjax('asdl_fin_order_settlement_result', runtimeNonces.orderSettlementResult, {
                    batch_id: Number(job.batch_id || activeState.batchId || 0)
                }).then(function (payload) {
                    renderResultState(payload.snapshot || snapshot || {});
                    refreshCurrentContactDetailRuntime();
                }).catch(function () {
                    renderResultState(snapshot || {});
                    refreshCurrentContactDetailRuntime();
                });
                return;
            }

            renderSettlementPreviewEmpty(
                body,
                'No se pudo continuar el abono.',
                'No encontramos un estado valido para este runner de abonos.',
                'asdl-fin-settlement-preview-error'
            );
            if (activeState.form) {
                resetSettlementFormLoading(activeState.form);
            }
            setActionState('Actualizar perfil', false, 'Cerrar');
        }

        function callPreview(form, signature) {
            var payload = getPreviewPayload(form);

            if (!payload.contact_id) {
                renderSettlementPreviewEmpty(body, 'Perfil no valido.', 'No encontramos el perfil asociado al abono.');
                if (form) {
                    resetSettlementFormLoading(form);
                }
                setActionState('Confirmar y aplicar', true, 'Cancelar');
                setModalState(modal, true);
                return;
            }

            resetActiveState(form);
            activeState.form = form;
            activeState.formSignature = signature;
            renderSettlementPreviewLoading(body);
            setSettlementFormLoading(form, true, 'Calculando vista previa...');
            setActionState('Calculando...', true, 'Cancelar', true);
            setModalState(modal, true);

            requestAdminAjax('asdl_fin_order_settlement_status', runtimeNonces.orderSettlementStatus, {
                contact_id: payload.contact_id,
                origin: payload.origin
            }).then(function (statusPayload) {
                var snapshot = statusPayload && statusPayload.snapshot ? statusPayload.snapshot : {};
                var job = snapshot && snapshot.job ? snapshot.job : {};

                if (job && (job.status === 'pending' || job.status === 'running')) {
                    handleSettlementSnapshot(snapshot);
                    return null;
                }

                return requestAdminAjax('asdl_fin_order_settlement_preview', runtimeNonces.orderSettlementPreview, payload).then(function (previewPayload) {
                    var preview = previewPayload && previewPayload.preview ? previewPayload.preview : {};
                    activeState.form = form;
                    activeState.formSignature = signature;
                    syncHiddenSignature(form, preview.preview_signature || '');
                    renderPreviewState(preview);
                    return preview;
                });
            }).catch(function (error) {
                renderSettlementPreviewEmpty(
                    body,
                    'No se pudo generar la vista previa.',
                    (error && error.message) || 'Ocurrio un error al calcular el abono.',
                    'asdl-fin-settlement-preview-error'
                );
                resetSettlementFormLoading(form);
                setActionState('Confirmar y aplicar', true, 'Cancelar');
                setModalState(modal, true);
            });
        }

        document.addEventListener('submit', function (event) {
            var form = event.target && event.target.closest ? event.target.closest('[data-order-settlement-preview-form]') : null;

            if (!form) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }

            callPreview(form, getRelevantSignature(form));
        }, true);

        document.querySelectorAll('[data-order-settlement-preview-form]').forEach(function (form) {
            if (form.dataset.previewSetup === '1') {
                return;
            }

            ['input', 'change'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    if (!event.target || !event.target.matches(
                        '[name="account_id"], [data-settlement-payment-date], [data-settlement-total], [data-settlement-currency], [data-payment-method-select]'
                    )) {
                        return;
                    }

                    resetPreviewConfirmation(form);
                    resetSettlementFormLoading(form);
                });
            });

            var submitButton = findSettlementSubmitButton(form);
            if (submitButton && !submitButton.dataset.idleLabel) {
                submitButton.dataset.idleLabel = submitButton.textContent || submitButton.value || 'Aplicar abono a pedidos';
            }

            form.dataset.previewSetup = '1';
        });

        confirmButton.addEventListener('click', function () {
            var form = activeState.form;
            if (!form) {
                return;
            }

            if (activeState.stage === 'result') {
                refreshCurrentContactDetailRuntime().finally(function () {
                    setModalState(modal, false);
                });
                return;
            }

            if (activeState.stage !== 'preview' || !activeState.preview) {
                return;
            }

            if (getRelevantSignature(form) !== activeState.formSignature) {
                renderSettlementPreviewEmpty(
                    body,
                    'Falta recalcular la vista previa.',
                    'Los datos del formulario cambiaron despues de la simulacion. Calcula la vista previa otra vez antes de confirmar.'
                );
                resetSettlementFormLoading(form);
                setActionState('Confirmar y aplicar', true, 'Cancelar');
                return;
            }

            syncHiddenSignature(form, activeState.preview.preview_signature || '');
            setSettlementFormLoading(form, true, 'Procesando abono...');
            setActionState('Iniciando...', true, 'Seguir en segundo plano', true);
            renderSettlementPreviewLoading(body);

            requestAdminAjax('asdl_fin_order_settlement_start', runtimeNonces.orderSettlementStart, Object.assign({}, getPreviewPayload(form), {
                preview_signature: activeState.preview.preview_signature || ''
            })).then(function (payload) {
                handleSettlementSnapshot(payload.snapshot || {});
            }).catch(function (error) {
                renderSettlementPreviewEmpty(
                    body,
                    'No se pudo iniciar el abono.',
                    (error && error.message) || 'Ocurrio un error al iniciar el runner del abono.',
                    'asdl-fin-settlement-preview-error'
                );
                resetSettlementFormLoading(form);
                setActionState('Confirmar y aplicar', true, 'Cerrar');
            });
        });

        secondaryButton.addEventListener('click', function () {
            if (activeState.form && activeState.stage !== 'processing') {
                resetSettlementFormLoading(activeState.form);
            }
        });

        modal.dataset.previewReady = '1';
    }

    function setupOrderAssumptionModal() {
        var modal = document.querySelector('[data-modal="order-assumption-preview"]');
        var body = modal ? modal.querySelector('[data-order-assumption-body]') : null;
        var previewButton = modal ? modal.querySelector('[data-order-assumption-preview]') : null;
        var confirmButton = modal ? modal.querySelector('[data-order-assumption-confirm]') : null;
        var secondaryButton = modal ? modal.querySelector('[data-order-assumption-secondary]') : null;
        var title = modal ? modal.querySelector('[data-order-assumption-title]') : null;
        var description = modal ? modal.querySelector('[data-order-assumption-description]') : null;
        var contextBox = modal ? modal.querySelector('[data-order-assumption-context]') : null;
        var modeInput = modal ? modal.querySelector('[data-order-assumption-mode]') : null;
        var noteInput = modal ? modal.querySelector('[data-order-assumption-note]') : null;
        var approvedInput = modal ? modal.querySelector('[data-order-assumption-approved]') : null;
        var approvedWrapper = modal ? modal.querySelector('[data-order-assumption-approved-wrapper]') : null;
        var confirmWrapper = modal ? modal.querySelector('[data-order-assumption-confirm-wrapper]') : null;
        var confirmCheckbox = modal ? modal.querySelector('[data-order-assumption-confirm-non-internal]') : null;
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var state = {
            contactId: 0,
            contactLabel: '',
            origin: 'profile_order_assumption',
            preview: null,
            batchId: 0,
            stage: 'idle',
            timer: 0,
            internalUseProfile: false,
            selectedItemKeys: []
        };

        if (!modal || !body || !previewButton || !confirmButton || !secondaryButton || !modeInput || !noteInput || !window.ASDLFinanceAdmin || modal.dataset.assumptionReady === '1') {
            return;
        }

        if (!runtimeNonces.orderAssumptionPreview || !runtimeNonces.orderAssumptionStart || !runtimeNonces.orderAssumptionContinue || !runtimeNonces.orderAssumptionStatus || !runtimeNonces.orderAssumptionResult || !runtimeNonces.orderAssumptionReverseItem || !runtimeNonces.orderAssumptionReverseBatch) {
            return;
        }

        function clearTimer() {
            window.clearTimeout(state.timer);
            state.timer = 0;
        }

        function assumptionStatusLabel(status) {
            switch (String(status || '')) {
                case 'pending':
                    return 'Pendiente';
                case 'running':
                    return 'Procesando';
                case 'completed':
                    return 'Completado';
                case 'completed_with_errors':
                    return 'Completado con incidencias';
                case 'failed':
                    return 'Fallido';
                case 'reversed':
                    return 'Revertido';
                case 'partially_reversed':
                    return 'Revertido parcial';
                case 'applied':
                    return 'Asumido';
                case 'skipped':
                    return 'Omitido';
                case 'reversed_item':
                    return 'Revertido';
                default:
                    return status || 'Sin estado';
            }
        }

        function assumptionStatusTone(status) {
            switch (String(status || '')) {
                case 'completed':
                case 'applied':
                case 'reversed':
                    return 'success';
                case 'completed_with_errors':
                case 'skipped':
                case 'partially_reversed':
                    return 'warning';
                case 'failed':
                case 'error':
                    return 'danger';
                default:
                    return 'neutral';
            }
        }

        function resetState() {
            clearTimer();
            state.preview = null;
            state.batchId = 0;
            state.stage = 'idle';
            state.internalUseProfile = false;
            state.selectedItemKeys = [];
        }

        function syncModeFields() {
            var isGift = modeInput && modeInput.value === 'gift';

            if (approvedWrapper) {
                approvedWrapper.hidden = !isGift;
            }

            if (approvedInput && !isGift) {
                approvedInput.value = '';
            }
        }

        function updateContextBox(extraCopy) {
            if (!contextBox) {
                return;
            }

            var kind = state.internalUseProfile ? 'Perfil interno' : 'Perfil regular';
            var copy = extraCopy || (state.internalUseProfile
                ? 'Este perfil ya esta marcado para consumo interno o regalos de tienda.'
                : 'Si este perfil no es interno, necesitaras confirmacion adicional antes de asumir los pedidos.');

            contextBox.innerHTML = ''
                + '<strong>' + escapeHtml(state.contactLabel || 'Perfil sin seleccionar') + '</strong>'
                + '<div>' + escapeHtml(kind + '. ' + copy) + '</div>';
        }

        function setActionState(previewLabel, previewDisabled, confirmLabel, confirmDisabled, previewLoading, confirmLoading, secondaryLabel) {
            setAsyncButtonState(previewButton, !!previewLoading, 'Revisar pedidos', previewLabel || 'Revisar pedidos');
            if (!previewLoading) {
                previewButton.textContent = previewLabel || 'Revisar pedidos';
                previewButton.disabled = !!previewDisabled;
            }

            setAsyncButtonState(confirmButton, !!confirmLoading, 'Aplicar como gasto/regalo', confirmLabel || 'Aplicar como gasto/regalo');
            if (!confirmLoading) {
                confirmButton.textContent = confirmLabel || 'Aplicar como gasto/regalo';
                confirmButton.disabled = !!confirmDisabled;
            }

            secondaryButton.textContent = secondaryLabel || 'Cerrar';
        }

        function resetForm() {
            if (modeInput) {
                modeInput.value = 'expense';
            }
            if (noteInput) {
                noteInput.value = '';
            }
            if (approvedInput) {
                approvedInput.value = '';
            }
            if (confirmCheckbox) {
                confirmCheckbox.checked = false;
            }
            if (confirmWrapper) {
                confirmWrapper.hidden = true;
            }
            syncModeFields();
        }

        function getPayload() {
            return {
                origin: state.origin || 'profile_order_assumption',
                contact_id: Number(state.contactId || 0),
                mode: (modeInput && modeInput.value) || 'expense',
                note: (noteInput && noteInput.value) || '',
                approved_by_label: (approvedInput && approvedInput.value) || '',
                confirm_non_internal: confirmCheckbox && confirmCheckbox.checked ? '1' : ''
            };
        }

        function renderAssumptionEmpty(titleText, descriptionText, extraClass) {
            if (!body) {
                return;
            }

            body.innerHTML = ''
                + '<div class="asdl-fin-empty ' + escapeHtml(extraClass || '') + '">'
                + '<strong>' + escapeHtml(titleText || 'Sin vista previa cargada.') + '</strong>'
                + '<p>' + escapeHtml(descriptionText || 'Configura el modo y la nota para revisar qué pedidos podrán asumirse.') + '</p>'
                + '</div>';
        }

        function renderAssumptionLoading(copy) {
            if (!body) {
                return;
            }

            body.innerHTML = ''
                + '<div class="asdl-fin-settlement-preview-loading">'
                + '<span class="asdl-fin-spinner" aria-hidden="true"></span>'
                + '<div class="asdl-fin-stack">'
                + '<strong>' + escapeHtml(copy || 'Calculando vista previa...') + '</strong>'
                + '<small>Estamos validando qué pedidos siguen abiertos, cobrables y sin pagos reales previos.</small>'
                + '</div>'
                + '</div>';
        }

        function assumptionItemKey(item) {
            if (!item) {
                return '';
            }

            if (item.item_key) {
                return String(item.item_key);
            }

            return [
                item.source_kind || 'current_live',
                item.provider || '',
                Number(item.external_order_id || 0),
                Number(item.document_id || 0)
            ].join(':');
        }

        function getAssumptionSelectedItems(preview) {
            var items = Array.isArray(preview && preview.items) ? preview.items : [];
            var selectedMap = {};

            (state.selectedItemKeys || []).forEach(function (key) {
                if (key) {
                    selectedMap[String(key)] = true;
                }
            });

            return items.filter(function (item) {
                return !!selectedMap[assumptionItemKey(item)];
            });
        }

        function getAssumptionSelectedKeysCsv() {
            return (state.selectedItemKeys || []).filter(Boolean).join(',');
        }

        function buildAssumptionSelectionSummary(preview) {
            var selectedItems = getAssumptionSelectedItems(preview);
            var totals = {
                selectedCount: selectedItems.length,
                selectedTotal: 0,
                currentTotal: 0,
                historicalTotal: 0
            };

            selectedItems.forEach(function (item) {
                var amount = Number(item && item.balance_before ? item.balance_before : 0);
                totals.selectedTotal += amount;

                if (String(item && item.source_kind || '') === 'historical_index') {
                    totals.historicalTotal += amount;
                } else {
                    totals.currentTotal += amount;
                }
            });

            return totals;
        }

        function buildAssumptionPreviewHtml(preview) {
            var summary = preview && preview.summary ? preview.summary : {};
            var items = Array.isArray(preview && preview.items) ? preview.items : [];
            var blockedItems = Array.isArray(preview && preview.blocked_items) ? preview.blocked_items : [];
            var selection = buildAssumptionSelectionSummary(preview);
            var selectAllChecked = items.length > 0 && selection.selectedCount === items.length;
            var warning = '';

            if (preview && preview.requires_profile_confirmation) {
                warning = ''
                    + '<div class="asdl-fin-note-box">'
                    + '<strong>Confirmacion reforzada requerida.</strong>'
                    + '<div>Este perfil no esta marcado como interno. Si vas a asumir estos pedidos, confirma explicitamente que representan gasto o regalo de la tienda.</div>'
                    + '</div>';
            }

            return ''
                + warning
                + '<div class="asdl-fin-settlement-preview-summary">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Perfil</strong><span>' + escapeHtml(preview && preview.internal_use_profile ? 'Interno' : 'Regular') + '</span><small>' + escapeHtml(preview && preview.contact_label ? preview.contact_label : state.contactLabel) + '</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Modo</strong><span>' + escapeHtml(preview && preview.mode_label ? preview.mode_label : 'Gasto') + '</span><small>Subtipo contable de la asuncion.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Elegibles</strong><span>' + escapeHtml(String(summary.eligible_count || 0)) + '</span><small>Pedidos que sí pueden asumirse en este lote.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Seleccionados</strong><span>' + escapeHtml(String(selection.selectedCount || 0)) + '</span><small>Monto: ' + escapeHtml(formatCurrencyAmount(selection.selectedTotal || 0, 'USD')) + '</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Bloqueados</strong><span>' + escapeHtml(String(summary.blocked_count || 0)) + '</span><small>Pedidos que ya no son elegibles o ya tienen pagos reales.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Total elegible</strong><span>' + escapeHtml(formatCurrencyAmount(summary.assumed_total || 0, 'USD')) + '</span><small>Monto total que podria salir de por cobrar.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Actual / historico</strong><span>' + escapeHtml(formatCurrencyAmount(selection.currentTotal || 0, 'USD')) + '</span><small>Historico: ' + escapeHtml(formatCurrencyAmount(selection.historicalTotal || 0, 'USD')) + '</small></div>'
                + '</div>'
                + '<div class="asdl-fin-note-box"><strong>Gestion independiente del abono.</strong><div>Esta accion no usa los campos del abono. Marca solo los pedidos que la tienda realmente asumira como gasto o regalo.</div></div>'
                + (items.length ? ''
                    + '<div class="asdl-fin-table-wrap">'
                    + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact">'
                    + '<thead><tr><th colspan="7"><label class="asdl-fin-checkbox-row"><input type="checkbox" data-order-assumption-select-all ' + (selectAllChecked ? 'checked' : '') + ' /> <strong>Seleccionar todos los mostrados</strong></label><span class="asdl-fin-table-note">Seleccionados: ' + escapeHtml(String(selection.selectedCount || 0)) + ' de ' + escapeHtml(String(items.length || 0)) + ' · ' + escapeHtml(formatCurrencyAmount(selection.selectedTotal || 0, 'USD')) + '</span></th></tr><tr><th></th><th>Pedido</th><th>Fecha</th><th>Origen</th><th>Ejercicio</th><th>Saldo</th><th>Acceso</th></tr></thead><tbody>'
                    + items.map(function (item) {
                        var itemKey = assumptionItemKey(item);
                        var checked = (state.selectedItemKeys || []).indexOf(itemKey) !== -1;
                        return ''
                            + '<tr>'
                            + '<td><input type="checkbox" data-order-assumption-item value="' + escapeHtml(itemKey) + '" ' + (checked ? 'checked' : '') + ' /></td>'
                            + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(item.order_label || item.order_number || 'Pedido') + '</strong><small>' + escapeHtml(item.display_name || state.contactLabel || '') + '</small></div></td>'
                            + '<td>' + escapeHtml(formatPreviewDateLabel(item.issue_date || '')) + '</td>'
                            + '<td>' + renderPill(item.source_kind === 'historical_index' ? 'Historico' : 'Actual', item.source_kind === 'historical_index' ? 'warning' : 'neutral') + '</td>'
                            + '<td>' + escapeHtml(String(item.fiscal_year || '—')) + '</td>'
                            + '<td>' + escapeHtml(formatCurrencyAmount(item.balance_before || 0, item.currency || 'USD')) + '</td>'
                            + '<td>' + (item.edit_url ? '<a class="button button-secondary button-small" href="' + escapeHtml(item.edit_url) + '" target="_blank" rel="noopener">Abrir pedido</a>' : '<span class="asdl-fin-label">Sin enlace</span>') + '</td>'
                            + '</tr>';
                    }).join('')
                    + '</tbody></table></div>'
                    : '<div class="asdl-fin-empty"><strong>Sin pedidos elegibles.</strong><p>No encontramos pedidos que puedan asumirse con las reglas actuales.</p></div>')
                + (blockedItems.length ? ''
                    + '<div class="asdl-fin-note-box"><strong>Pedidos bloqueados</strong><div>Estos pedidos no entraran al lote. Revisa el motivo antes de continuar.</div></div>'
                    + '<div class="asdl-fin-table-wrap">'
                    + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact">'
                    + '<thead><tr><th>Pedido</th><th>Fecha</th><th>Saldo</th><th>Motivo</th></tr></thead><tbody>'
                    + blockedItems.map(function (item) {
                        return ''
                            + '<tr>'
                            + '<td>' + escapeHtml(item.order_label || item.order_number || 'Pedido') + '</td>'
                            + '<td>' + escapeHtml(formatPreviewDateLabel(item.issue_date || '')) + '</td>'
                            + '<td>' + escapeHtml(formatCurrencyAmount(item.balance_before || 0, item.currency || 'USD')) + '</td>'
                            + '<td>' + escapeHtml(item.blocked_reason || 'No elegible') + '</td>'
                            + '</tr>';
                    }).join('')
                    + '</tbody></table></div>'
                    : '');
        }

        function buildAssumptionProcessingHtml(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            var processedCount = Number(job.processed_count || 0);
            var itemCount = Math.max(0, Number(job.item_count || 0));
            var percent = itemCount > 0 ? Math.min(100, Math.round((processedCount / itemCount) * 100)) : 0;

            return ''
                + '<div class="asdl-fin-settlement-progress-panel">'
                + '<div class="asdl-fin-settlement-preview-summary">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Estado</strong><span>' + escapeHtml(assumptionStatusLabel(job.status)) + '</span><small>Runner por lotes.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Procesados</strong><span>' + escapeHtml(String(processedCount)) + ' / ' + escapeHtml(String(itemCount)) + '</span><small>Pedidos ya gestionados en esta corrida.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Total asumido</strong><span>' + escapeHtml(formatCurrencyAmount(job.assumed_total || 0, 'USD')) + '</span><small>Saldo ya reclasificado como consumo interno.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Actual</strong><span>' + escapeHtml(formatCurrencyAmount(job.current_total || 0, 'USD')) + '</span><small>Parte del ejercicio activo ya resuelta.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Historico</strong><span>' + escapeHtml(formatCurrencyAmount(job.historical_total || 0, 'USD')) + '</span><small>Parte historica ya resuelta.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Errores</strong><span>' + escapeHtml(String(job.errors_count || 0)) + '</span><small>Incluye omitidos y fallos parciales.</small></div>'
                + '</div>'
                + '<div class="asdl-fin-settlement-progress-meter" aria-hidden="true"><span style="width:' + escapeHtml(String(percent)) + '%"></span></div>'
                + '<div class="asdl-fin-settlement-progress-copy"><strong>' + escapeHtml(String(percent)) + '% completado</strong><small>La asuncion sigue corriendo en tandas pequenas para evitar bloqueos y timeouts.</small></div>'
                + '</div>';
        }

        function buildAssumptionResultHtml(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            var result = snapshot && snapshot.result ? snapshot.result : {};
            var items = Array.isArray(snapshot && snapshot.items) ? snapshot.items : [];
            var blockedItems = Array.isArray(snapshot && snapshot.blocked_items) ? snapshot.blocked_items : [];
            var reversibleItems = items.filter(function (item) {
                return String(item.status || '') === 'applied';
            });

            var actions = '';
            if (reversibleItems.length) {
                actions = '<div class="asdl-fin-inline-actions"><button type="button" class="button button-secondary" data-order-assumption-reverse-batch="' + escapeHtml(String(job.batch_id || 0)) + '">Revertir lote</button></div>';
            }

            return ''
                + '<div class="asdl-fin-settlement-preview-summary">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Estado final</strong><span>' + escapeHtml(assumptionStatusLabel(job.status)) + '</span><small>Lote #' + escapeHtml(String(job.batch_id || 0)) + '.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Total asumido</strong><span>' + escapeHtml(formatCurrencyAmount(result.assumed_total || job.assumed_total || 0, 'USD')) + '</span><small>Pedidos reclasificados como consumo interno.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Actual / historico</strong><span>' + escapeHtml(formatCurrencyAmount(result.current_total || job.current_total || 0, 'USD')) + '</span><small>Historico: ' + escapeHtml(formatCurrencyAmount(result.historical_total || job.historical_total || 0, 'USD')) + '</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Asumidos</strong><span>' + escapeHtml(String(result.applied_count || 0)) + '</span><small>Pedidos cerrados como gasto o regalo.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Bloqueados</strong><span>' + escapeHtml(String(job.blocked_count || 0)) + '</span><small>No entraron al lote.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Incidencias</strong><span>' + escapeHtml(String((result.skipped_count || 0) + (result.error_count || 0))) + '</span><small>Omitidos, errores o reversas parciales.</small></div>'
                + '</div>'
                + '<div class="asdl-fin-note-box"><strong>Resultado listo.</strong><div>Los pedidos gestionados ya deben haber salido de por cobrar, quedar cerrados en Woo/OpenPOS y estar marcados como gasto o regalo interno.</div></div>'
                + actions
                + (items.length ? ''
                    + '<div class="asdl-fin-table-wrap">'
                    + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact">'
                    + '<thead><tr><th>Pedido</th><th>Origen</th><th>Monto</th><th>Estado</th><th>Gestion</th></tr></thead><tbody>'
                    + items.map(function (item) {
                        var status = String(item.status || '');
                        var canReverse = status === 'applied';
                        var meta = item.meta || {};
                        return ''
                            + '<tr>'
                            + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(meta.order_label || item.order_number || item.external_order_id || 'Pedido') + '</strong><small>' + escapeHtml(meta.display_name || '') + '</small></div></td>'
                            + '<td>' + renderPill(item.source_kind === 'historical_index' ? 'Historico' : 'Actual', item.source_kind === 'historical_index' ? 'warning' : 'neutral') + '</td>'
                            + '<td>' + escapeHtml(formatCurrencyAmount(meta.assumed_amount || item.balance_before || 0, meta.currency || 'USD')) + '</td>'
                            + '<td><div class="asdl-fin-stack">' + renderPill(assumptionStatusLabel(status), assumptionStatusTone(status)) + (item.error_message ? '<small>' + escapeHtml(item.error_message) + '</small>' : '') + '</div></td>'
                            + '<td>' + (canReverse ? '<button type="button" class="button button-secondary button-small" data-order-assumption-reverse-item="' + escapeHtml(String(item.id || 0)) + '">Revertir</button>' : '<span class="asdl-fin-label">Sin accion</span>') + '</td>'
                            + '</tr>';
                    }).join('')
                    + '</tbody></table></div>'
                    : '')
                + (blockedItems.length ? ''
                    + '<div class="asdl-fin-note-box"><strong>Bloqueados desde preview</strong><div>Estos pedidos no se asumieron por reglas de elegibilidad o porque ya habian cambiado al confirmar.</div></div>'
                    + '<div class="asdl-fin-table-wrap">'
                    + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact">'
                    + '<thead><tr><th>Pedido</th><th>Motivo</th></tr></thead><tbody>'
                    + blockedItems.map(function (item) {
                        return '<tr><td>' + escapeHtml(item.order_label || item.order_number || 'Pedido') + '</td><td>' + escapeHtml(item.blocked_reason || 'No elegible') + '</td></tr>';
                    }).join('')
                    + '</tbody></table></div>'
                    : '');
        }

        function renderPreviewState(preview) {
            state.preview = preview || null;
            state.stage = 'preview';
            state.internalUseProfile = !!(preview && preview.internal_use_profile);
            state.selectedItemKeys = Array.isArray(preview && preview.items)
                ? preview.items.map(function (item) { return assumptionItemKey(item); }).filter(Boolean)
                : [];
            if (confirmWrapper) {
                confirmWrapper.hidden = !(preview && preview.requires_profile_confirmation);
            }
            updateContextBox(preview && preview.requires_profile_confirmation
                ? 'Este perfil no esta marcado como interno. Debes confirmar explicitamente la asuncion.'
                : '');
            body.innerHTML = buildAssumptionPreviewHtml(preview || {});
            setActionState('Revisar pedidos', false, 'Aplicar como gasto/regalo', !(preview && preview.preview_signature && state.selectedItemKeys.length > 0), false, false, 'Cerrar');
            setModalState(modal, true);
        }

        function renderProcessingState(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            state.batchId = Number(job.batch_id || 0);
            state.stage = 'processing';
            body.innerHTML = buildAssumptionProcessingHtml(snapshot || {});
            setActionState('Actualizando...', true, 'Procesando...', true, true, true, 'Seguir en segundo plano');
            setModalState(modal, true);
        }

        function renderResultState(snapshot) {
            state.stage = 'result';
            state.batchId = Number(snapshot && snapshot.job ? snapshot.job.batch_id || 0 : 0);
            body.innerHTML = buildAssumptionResultHtml(snapshot || {});
            setActionState('Nueva vista previa', false, 'Actualizar vista', false, false, false, 'Cerrar');
            setModalState(modal, true);
        }

        function syncAffectedViews() {
            if (String(state.origin || '').indexOf('profile_') === 0) {
                return refreshCurrentContactDetailRuntime();
            }

            return Promise.resolve();
        }

        function refreshAfterAssumption() {
            if (String(state.origin || '').indexOf('profile_') === 0) {
                return refreshCurrentContactDetailRuntime();
            }

            window.location.reload();
            return Promise.resolve();
        }

        function scheduleContinue(batchId) {
            clearTimer();
            if (!batchId) {
                return;
            }

            state.timer = window.setTimeout(function () {
                runContinueRequest(batchId, 0);
            }, 220);
        }

        function runContinueRequest(batchId, attempt) {
            requestAdminAjax('asdl_fin_order_assumption_continue', runtimeNonces.orderAssumptionContinue, {
                batch_id: batchId
            }).then(function (payload) {
                handleSnapshot(payload.snapshot || {});
            }).catch(function (error) {
                if ((attempt || 0) < 2) {
                    state.timer = window.setTimeout(function () {
                        runContinueRequest(batchId, (attempt || 0) + 1);
                    }, 700 * ((attempt || 0) + 1));
                    return;
                }

                renderAssumptionEmpty(
                    'No se pudo continuar la asuncion.',
                    (error && error.message) || 'Ocurrio un error al continuar el runner por lotes.',
                    'asdl-fin-settlement-preview-error'
                );
                setActionState('Revisar pedidos', false, 'Actualizar vista', false, false, false, 'Cerrar');
            });
        }

        function handleSnapshot(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            var status = String(job.status || '');

            if (status === 'pending' || status === 'running') {
                renderProcessingState(snapshot);
                scheduleContinue(Number(job.batch_id || 0));
                return;
            }

            if (status === 'completed' || status === 'completed_with_errors' || status === 'reversed' || status === 'partially_reversed') {
                requestAdminAjax('asdl_fin_order_assumption_result', runtimeNonces.orderAssumptionResult, {
                    batch_id: Number(job.batch_id || state.batchId || 0)
                }).then(function (payload) {
                    renderResultState(payload.snapshot || snapshot || {});
                    syncAffectedViews();
                }).catch(function () {
                    renderResultState(snapshot || {});
                    syncAffectedViews();
                });
                return;
            }

            renderAssumptionEmpty(
                'No se pudo continuar la asuncion.',
                'No encontramos un estado valido para este lote de asuncion.',
                'asdl-fin-settlement-preview-error'
            );
            setActionState('Revisar pedidos', false, 'Actualizar vista', false, false, false, 'Cerrar');
        }

        function loadPreview() {
            var payload = getPayload();

            if (!payload.contact_id) {
                renderAssumptionEmpty('Perfil no valido.', 'Abre esta herramienta desde una fila o desde un perfil concreto para poder gestionar pedidos como gasto o regalo.');
                setActionState('Revisar pedidos', true, 'Aplicar como gasto/regalo', true, false, false, 'Cerrar');
                return;
            }

            renderAssumptionLoading('Revisando pedidos...');
            setActionState('Revisando...', true, 'Aplicar como gasto/regalo', true, true, false, 'Cerrar');

            requestAdminAjax('asdl_fin_order_assumption_preview', runtimeNonces.orderAssumptionPreview, payload).then(function (payloadResponse) {
                renderPreviewState(payloadResponse && payloadResponse.preview ? payloadResponse.preview : {});
            }).catch(function (error) {
                renderAssumptionEmpty(
                    'No se pudo generar la vista previa.',
                    (error && error.message) || 'Ocurrio un error al validar los pedidos que intentas asumir.',
                    'asdl-fin-settlement-preview-error'
                );
                setActionState('Revisar pedidos', false, 'Aplicar como gasto/regalo', true, false, false, 'Cerrar');
            });
        }

        function openForTrigger(trigger) {
            resetState();
            resetForm();

            state.contactId = Number(trigger.getAttribute('data-contact-id') || 0);
            state.contactLabel = trigger.getAttribute('data-contact-label') || 'Perfil';
            state.origin = trigger.getAttribute('data-assumption-origin') || 'profile_order_assumption';
            state.internalUseProfile = trigger.getAttribute('data-contact-internal') === '1';

            if (title) {
                title.textContent = 'Gestionar pedidos de ' + (state.contactLabel || 'este perfil');
            }

            if (description) {
                description.textContent = 'Esta gestion es independiente del abono. Revisa la vista previa y marca solo los pedidos que la tienda asumira como gasto o regalo.';
            }

            updateContextBox('');
            renderAssumptionEmpty('Sin vista previa cargada.', 'Configura el modo, agrega la nota y revisa los pedidos elegibles para esta gestion.');
            setActionState('Revisar pedidos', !state.contactId, 'Aplicar como gasto/regalo', true, false, false, 'Cerrar');
            setModalState(modal, true);

            requestAdminAjax('asdl_fin_order_assumption_status', runtimeNonces.orderAssumptionStatus, {
                contact_id: state.contactId,
                origin: state.origin
            }).then(function (payload) {
                var snapshot = payload && payload.snapshot ? payload.snapshot : {};
                var job = snapshot && snapshot.job ? snapshot.job : {};

                if (job && (job.status === 'pending' || job.status === 'running')) {
                    handleSnapshot(snapshot);
                }
            }).catch(function () {
                return null;
            });
        }

        function reverseBatch(batchId) {
            renderAssumptionLoading('Revirtiendo lote...');
            setActionState('Revisar pedidos', true, 'Procesando...', true, true, true, 'Cerrar');

            requestAdminAjax('asdl_fin_order_assumption_reverse_batch', runtimeNonces.orderAssumptionReverseBatch, {
                batch_id: batchId
            }).then(function (payload) {
                renderResultState(payload.snapshot || {});
                syncAffectedViews();
            }).catch(function (error) {
                renderAssumptionEmpty(
                    'No se pudo revertir el lote.',
                    (error && error.message) || 'Ocurrio un error al intentar revertir esta asuncion.',
                    'asdl-fin-settlement-preview-error'
                );
                setActionState('Revisar pedidos', false, 'Actualizar vista', false, false, false, 'Cerrar');
            });
        }

        function reverseItem(itemId) {
            renderAssumptionLoading('Revirtiendo pedido...');
            setActionState('Revisar pedidos', true, 'Procesando...', true, true, true, 'Cerrar');

            requestAdminAjax('asdl_fin_order_assumption_reverse_item', runtimeNonces.orderAssumptionReverseItem, {
                batch_id: state.batchId,
                item_id: itemId
            }).then(function (payload) {
                renderResultState(payload.snapshot || {});
                syncAffectedViews();
            }).catch(function (error) {
                renderAssumptionEmpty(
                    'No se pudo revertir el pedido.',
                    (error && error.message) || 'Ocurrio un error al revertir este item del lote.',
                    'asdl-fin-settlement-preview-error'
                );
                setActionState('Revisar pedidos', false, 'Actualizar vista', false, false, false, 'Cerrar');
            });
        }

        modeInput.addEventListener('change', syncModeFields);

        previewButton.addEventListener('click', function () {
            loadPreview();
        });

        body.addEventListener('change', function (event) {
            var itemCheckbox = event.target.closest('[data-order-assumption-item]');
            var selectAllCheckbox = event.target.closest('[data-order-assumption-select-all]');
            var selectedMap;

            if (!state.preview || !Array.isArray(state.preview.items) || !state.preview.items.length) {
                return;
            }

            if (selectAllCheckbox) {
                state.selectedItemKeys = selectAllCheckbox.checked
                    ? state.preview.items.map(function (item) { return assumptionItemKey(item); }).filter(Boolean)
                    : [];
                body.innerHTML = buildAssumptionPreviewHtml(state.preview || {});
                setActionState('Revisar pedidos', false, 'Aplicar como gasto/regalo', !state.selectedItemKeys.length, false, false, 'Cerrar');
                return;
            }

            if (!itemCheckbox) {
                return;
            }

            selectedMap = {};
            (state.selectedItemKeys || []).forEach(function (key) {
                if (key) {
                    selectedMap[String(key)] = true;
                }
            });

            if (itemCheckbox.checked) {
                selectedMap[String(itemCheckbox.value || '')] = true;
            } else {
                delete selectedMap[String(itemCheckbox.value || '')];
            }

            state.selectedItemKeys = Object.keys(selectedMap).filter(Boolean);
            body.innerHTML = buildAssumptionPreviewHtml(state.preview || {});
            setActionState('Revisar pedidos', false, 'Aplicar como gasto/regalo', !state.selectedItemKeys.length, false, false, 'Cerrar');
        });

        confirmButton.addEventListener('click', function () {
            if (state.stage === 'result') {
                refreshAfterAssumption().finally(function () {
                    setModalState(modal, false);
                });
                return;
            }

            if (state.stage !== 'preview' || !state.preview || !state.preview.preview_signature) {
                return;
            }

            renderAssumptionLoading('Iniciando lote...');
            setActionState('Actualizando...', true, 'Procesando...', true, true, true, 'Seguir en segundo plano');

            requestAdminAjax('asdl_fin_order_assumption_start', runtimeNonces.orderAssumptionStart, Object.assign({}, getPayload(), {
                preview_signature: state.preview.preview_signature || '',
                selected_item_keys: getAssumptionSelectedKeysCsv()
            })).then(function (payload) {
                handleSnapshot(payload.snapshot || {});
            }).catch(function (error) {
                renderAssumptionEmpty(
                    'No se pudo iniciar la asuncion.',
                    (error && error.message) || 'Ocurrio un error al iniciar el lote de asuncion.',
                    'asdl-fin-settlement-preview-error'
                );
                setActionState('Revisar pedidos', false, 'Aplicar como gasto/regalo', true, false, false, 'Cerrar');
            });
        });

        body.addEventListener('click', function (event) {
            var reverseBatchButton = event.target.closest('[data-order-assumption-reverse-batch]');
            if (reverseBatchButton) {
                event.preventDefault();
                if (window.confirm('Esto restaurara los pedidos asumidos de este lote. Quieres continuar?')) {
                    reverseBatch(Number(reverseBatchButton.getAttribute('data-order-assumption-reverse-batch') || 0));
                }
                return;
            }

            var reverseItemButton = event.target.closest('[data-order-assumption-reverse-item]');
            if (reverseItemButton) {
                event.preventDefault();
                if (window.confirm('Esto restaurara el saldo original del pedido seleccionado. Quieres continuar?')) {
                    reverseItem(Number(reverseItemButton.getAttribute('data-order-assumption-reverse-item') || 0));
                }
            }
        });

        secondaryButton.addEventListener('click', function () {
            clearTimer();
        });

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-order-assumption-open]');
            if (!trigger) {
                return;
            }

            event.preventDefault();
            openForTrigger(trigger);
        });

        modal.dataset.assumptionReady = '1';
    }

    function setupPayrollManualSettlementModal() {
        var modal = document.querySelector('[data-modal="payroll-manual-settlement"]');
        var body = modal ? modal.querySelector('[data-payroll-debt-body]') : null;
        var confirmButton = modal ? modal.querySelector('[data-payroll-debt-confirm]') : null;
        var state = {
            form: null,
            snapshot: null,
            controller: null,
            selectedKey: '',
            amount: '',
            previewKey: '',
            previewSignature: '',
            previewExecutionMode: '',
            previewHtml: '',
            loading: false
        };

        if (!modal || !body || !confirmButton || !window.ASDLFinanceAdmin || !ASDLFinanceAdmin.restBase || modal.dataset.payrollDebtReady === '1') {
            return;
        }

        function parseNumber(value) {
            var normalized = String(value || '').replace(',', '.');
            var numeric = Number(normalized);
            return Number.isFinite(numeric) ? numeric : 0;
        }

        function getFormContext(form) {
            if (!form) {
                return null;
            }

            var contactInput = form.querySelector('[name="contact_id"]');
            var accountInput = form.querySelector('[name="payment_account_id"]');
            var paidAtInput = form.querySelector('[name="paid_at"]');
            var currency = form.getAttribute('data-payroll-currency') || '';
            var cashPreview = parseNumber(form.getAttribute('data-payroll-cash-preview') || '0');
            var netPreview = parseNumber(form.getAttribute('data-payroll-net-preview') || '0');
            var accountFallback = form.getAttribute('data-payroll-account-fallback') || '';

            return {
                contactId: Number(contactInput ? contactInput.value : 0),
                accountId: (accountInput && accountInput.value) ? accountInput.value : accountFallback,
                paidAt: paidAtInput ? paidAtInput.value : '',
                currency: String(currency || 'USD').toUpperCase(),
                cashPreview: cashPreview,
                netPreview: netPreview
            };
        }

        function getSelectionFromState() {
            if (!state.snapshot || !state.selectedKey) {
                return null;
            }

            return (state.snapshot.targets || []).find(function (target) {
                var key = String(target.target_type || '') + ':' + String(Number(target.target_id || 0));
                return key === state.selectedKey;
            }) || null;
        }

        function selectionIsDual(context, target) {
            return !!(context && target && target.target_type === 'store_orders' && context.currency === 'USD');
        }

        function getPreviewPayload(form, target, amount) {
            var context = getFormContext(form);

            return {
                contact_id: context ? context.contactId : 0,
                account_id: context ? context.accountId : '',
                payment_date: context ? context.paidAt : '',
                total: amount,
                currency: context ? context.currency : 'USD',
                method_key: 'payroll_deduction',
                reference: '',
                notes: '',
                payment_type: 'adjustment',
                force_dual_discount: !!selectionIsDual(context, target)
            };
        }

        function getPreviewKey(form, target, amount) {
            var payload = getPreviewPayload(form, target, amount);
            return [
                payload.contact_id,
                payload.account_id,
                payload.payment_date,
                payload.currency,
                payload.total,
                payload.force_dual_discount ? '1' : '0',
                target ? target.target_type : '',
                target ? Number(target.target_id || 0) : 0
            ].join('|');
        }

        function defaultSummaryText(summaryElement) {
            return summaryElement ? (summaryElement.getAttribute('data-default-summary') || summaryElement.innerHTML || '') : '';
        }

        function updateFormSelectionSummary(form, target, amount, forceDual) {
            if (!form) {
                return;
            }

            var summary = form.querySelector('[data-payroll-manual-summary]');
            var clearButton = form.querySelector('[data-payroll-manual-clear]');
            var button = form.querySelector('[data-payroll-debt-open]');
            var context = getFormContext(form);

            if (!summary) {
                return;
            }

            if (!target || amount <= 0) {
                summary.innerHTML = defaultSummaryText(summary);
                if (clearButton) {
                    clearButton.hidden = true;
                }
                if (button) {
                    button.classList.remove('is-configured');
                }
                delete form.dataset.payrollManualForceDual;
                return;
            }

            var netAfter = Math.max(0, (context ? context.netPreview : 0) - amount);
            var label = 'Abono manual listo: ' + (target.label || 'Destino') + ' · ' + formatCurrencyAmount(amount, context ? context.currency : 'USD');
            if (forceDual) {
                label += ' · Precio dual';
            }

            summary.innerHTML = '<small>' + escapeHtml(label) + '. Neto estimado despues de este descuento: ' + escapeHtml(formatCurrencyAmount(netAfter, context ? context.currency : 'USD')) + '.</small>';
            if (clearButton) {
                clearButton.hidden = false;
            }
            if (button) {
                button.classList.add('is-configured');
            }
            form.dataset.payrollManualForceDual = forceDual ? '1' : '0';
        }

        function clearFormSelection(form) {
            if (!form) {
                return;
            }

            [
                ['[data-payroll-manual-target-type]', ''],
                ['[data-payroll-manual-target-id]', '0'],
                ['[data-payroll-manual-amount]', ''],
                ['[data-payroll-manual-force-dual]', '0'],
                ['[data-payroll-manual-preview-signature]', '']
            ].forEach(function (config) {
                var field = form.querySelector(config[0]);
                if (field) {
                    field.value = config[1];
                }
            });

            updateFormSelectionSummary(form, null, 0, false);
        }

        function applySelectionToForm(form, target, amount, forceDual, previewSignature) {
            if (!form || !target || amount <= 0) {
                clearFormSelection(form);
                return;
            }

            var targetType = form.querySelector('[data-payroll-manual-target-type]');
            var targetId = form.querySelector('[data-payroll-manual-target-id]');
            var amountField = form.querySelector('[data-payroll-manual-amount]');
            var dualField = form.querySelector('[data-payroll-manual-force-dual]');
            var signatureField = form.querySelector('[data-payroll-manual-preview-signature]');

            if (targetType) {
                targetType.value = target.target_type || '';
            }
            if (targetId) {
                targetId.value = String(Number(target.target_id || 0));
            }
            if (amountField) {
                amountField.value = String(Number(amount).toFixed(2));
            }
            if (dualField) {
                dualField.value = forceDual ? '1' : '0';
            }
            if (signatureField) {
                signatureField.value = forceDual ? (previewSignature || '') : '';
            }

            updateFormSelectionSummary(form, target, amount, forceDual);
        }

        function buildTargetCard(target, context, isSelected) {
            var amount = formatCurrencyAmount(target.amount || 0, target.currency || (context ? context.currency : 'USD'));
            var lines = [
                '<strong>' + escapeHtml(target.label || 'Destino') + '</strong>',
                '<small>' + escapeHtml(target.kind_label || 'Deuda') + ' · ' + escapeHtml(amount) + '</small>'
            ];

            if (target.count && Number(target.count) > 1) {
                lines.push('<small>' + escapeHtml(String(target.count)) + ' item(s) abiertos.</small>');
            }

            if (target.oldest_date) {
                lines.push('<small>Mas antiguo: ' + escapeHtml(formatPreviewDateLabel(target.oldest_date)) + '</small>');
            }

            if (target.description) {
                lines.push('<small>' + escapeHtml(target.description) + '</small>');
            }

            return ''
                + '<button type="button" class="asdl-fin-payroll-target-card' + (isSelected ? ' is-selected' : '') + '"'
                + ' data-payroll-target-key="' + escapeHtml(String(target.target_type || '') + ':' + String(Number(target.target_id || 0))) + '">'
                + lines.join('')
                + '</button>';
        }

        function renderModalBody() {
            if (state.loading) {
                body.innerHTML = buildSettlementPreviewLoadingHtml();
                confirmButton.disabled = true;
                confirmButton.textContent = 'Usar en esta nomina';
                return;
            }

            if (!state.snapshot || !Array.isArray(state.snapshot.targets) || !state.snapshot.targets.length) {
                body.innerHTML = ''
                    + '<div class="asdl-fin-empty">'
                    + '<strong>Sin deudas abiertas.</strong>'
                    + '<p>Este empleado no tiene deudas pendientes para descontar manualmente desde nomina.</p>'
                    + '</div>';
                confirmButton.disabled = true;
                confirmButton.textContent = 'Usar en esta nomina';
                return;
            }

            var context = getFormContext(state.form);
            var summary = state.snapshot.summary || {};
            var target = getSelectionFromState();
            var targetAmount = target ? parseNumber(target.amount || 0) : 0;
            var availableAmount = context ? context.cashPreview : 0;
            var currentAmount = parseNumber(state.amount);
            var validAmount = !!target && currentAmount > 0 && currentAmount <= targetAmount + 0.00001 && currentAmount <= availableAmount + 0.00001;
            var dualMode = selectionIsDual(context, target);
            var runnerRequired = dualMode && state.previewExecutionMode === 'runner';
            var amountHelp = '';

            if (!target) {
                amountHelp = 'Selecciona primero el destino que quieres descontar desde esta nomina.';
            } else if (availableAmount <= 0) {
                amountHelp = 'No queda neto disponible para descontar manualmente en esta nomina.';
            } else {
                amountHelp = 'Disponible para descontar ahora: ' + formatCurrencyAmount(availableAmount, context ? context.currency : 'USD') + '.';
                if (dualMode) {
                    amountHelp += ' Para deuda de tienda en USD se aplicara precio dual.';
                }
            }

            body.innerHTML = ''
                + '<div class="asdl-fin-payroll-debt-summary-grid">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Deuda total</strong><span>' + escapeHtml(formatCurrencyAmount(summary.total_amount || 0, context ? context.currency : 'USD')) + '</span><small>Total abierto detectado para este empleado.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Tienda</strong><span>' + escapeHtml(formatCurrencyAmount(summary.store_total || 0, context ? context.currency : 'USD')) + '</span><small>' + escapeHtml(String(summary.store_count || 0)) + ' pedido(s) o factura(s) de tienda.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Documentos</strong><span>' + escapeHtml(formatCurrencyAmount(summary.document_total || 0, context ? context.currency : 'USD')) + '</span><small>' + escapeHtml(String(summary.document_count || 0)) + ' documento(s) o prestamo(s).</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Compromisos</strong><span>' + escapeHtml(formatCurrencyAmount(summary.commitment_total || 0, context ? context.currency : 'USD')) + '</span><small>' + escapeHtml(String(summary.commitment_count || 0)) + ' acuerdo(s) activos.</small></div>'
                + '</div>'
                + '<div class="asdl-fin-payroll-debt-note">El descuento manual se prepara aqui y se aplica solo cuando confirmes el pago de la nomina.</div>'
                + '<div class="asdl-fin-payroll-target-list">'
                + state.snapshot.targets.map(function (item) {
                    return buildTargetCard(item, context, state.selectedKey === String(item.target_type || '') + ':' + String(Number(item.target_id || 0)));
                }).join('')
                + '</div>'
                + '<div class="asdl-fin-payroll-debt-config">'
                + '<label class="asdl-fin-field"><span>Monto a descontar ahora</span><input type="number" min="0" step="0.01" value="' + escapeHtml(state.amount || '') + '" data-payroll-debt-amount /></label>'
                + '<div class="asdl-fin-payroll-debt-help">' + escapeHtml(amountHelp) + '</div>'
                + '</div>'
                + (state.previewHtml ? '<div class="asdl-fin-payroll-debt-preview">' + state.previewHtml + '</div>' : '')
                + (runnerRequired ? '<div class="asdl-fin-note-box"><strong>Runner requerido</strong><div>Este abono de tienda es demasiado grande para ejecutarlo dentro del pago de nomina. Procesalo desde el perfil del empleado para usar el runner por lotes.</div></div>' : '');

            if (!validAmount) {
                confirmButton.disabled = true;
                confirmButton.textContent = 'Usar en esta nomina';
                return;
            }

            if (runnerRequired) {
                confirmButton.disabled = true;
                confirmButton.textContent = 'Gestionar desde perfil';
                return;
            }

            confirmButton.disabled = false;
            confirmButton.textContent = dualMode && !state.previewHtml ? 'Vista previa' : 'Usar en esta nomina';
        }

        function loadDebts(form) {
            var context = getFormContext(form);

            if (!context || !context.contactId) {
                return;
            }

            if (state.controller && typeof state.controller.abort === 'function') {
                state.controller.abort();
            }

            state.form = form;
            state.snapshot = null;
            state.selectedKey = '';
            state.amount = '';
            state.previewKey = '';
            state.previewSignature = '';
            state.previewExecutionMode = '';
            state.previewHtml = '';
            state.loading = true;
            renderModalBody();
            setModalState(modal, true);

            state.controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            fetch(
                ASDLFinanceAdmin.restBase.replace(/\/+$/, '') + '/contacts/' + encodeURIComponent(context.contactId) + '/payroll-open-debts',
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': ASDLFinanceAdmin.nonce
                    },
                    signal: state.controller ? state.controller.signal : undefined
                }
            ).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    if (!response.ok) {
                        throw new Error((json && json.message) || 'No se pudieron cargar las deudas del empleado.');
                    }
                    return json;
                });
            }).then(function (payload) {
                var data = payload && payload.data ? payload.data : payload || {};
                var firstTarget = Array.isArray(data.targets) && data.targets.length ? data.targets[0] : null;
                var defaultAmount = firstTarget ? Math.min(parseNumber(firstTarget.amount || 0), context.cashPreview) : 0;

                state.snapshot = data;
                state.selectedKey = firstTarget ? String(firstTarget.target_type || '') + ':' + String(Number(firstTarget.target_id || 0)) : '';
                state.amount = defaultAmount > 0 ? String(defaultAmount.toFixed(2)) : '';
                state.previewKey = '';
                state.previewSignature = '';
                state.previewExecutionMode = '';
                state.previewHtml = '';
                state.loading = false;
                renderModalBody();
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }

                state.loading = false;
                state.snapshot = null;
                body.innerHTML = ''
                    + '<div class="asdl-fin-empty asdl-fin-settlement-preview-error">'
                    + '<strong>No se pudieron cargar las deudas.</strong>'
                    + '<p>' + escapeHtml((error && error.message) || 'Ocurrio un error al revisar las deudas abiertas del empleado.') + '</p>'
                    + '</div>';
                confirmButton.disabled = true;
                confirmButton.textContent = 'Usar en esta nomina';
            });
        }

        function loadDualPreview(form, target, amount) {
            var previewPayload = getPreviewPayload(form, target, amount);

            state.loading = true;
            state.previewHtml = '';
            state.previewExecutionMode = '';
            renderModalBody();

            fetch(
                ASDLFinanceAdmin.restBase.replace(/\/+$/, '') + '/contacts/' + encodeURIComponent(previewPayload.contact_id) + '/settle-orders/preview',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ASDLFinanceAdmin.nonce
                    },
                    body: JSON.stringify(previewPayload)
                }
            ).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    if (!response.ok) {
                        throw new Error((json && json.message) || 'No se pudo calcular la vista previa dual.');
                    }
                    return json;
                });
            }).then(function (payload) {
                var preview = payload && payload.data ? payload.data : payload || {};
                state.loading = false;
                state.previewKey = getPreviewKey(form, target, amount);
                state.previewSignature = preview.preview_signature || '';
                state.previewExecutionMode = preview.execution_mode || '';
                state.previewHtml = buildSettlementPreviewHtml(preview);
                renderModalBody();
            }).catch(function (error) {
                state.loading = false;
                state.previewKey = '';
                state.previewSignature = '';
                state.previewExecutionMode = '';
                state.previewHtml = ''
                    + '<div class="asdl-fin-empty asdl-fin-settlement-preview-error">'
                    + '<strong>No se pudo generar la vista previa dual.</strong>'
                    + '<p>' + escapeHtml((error && error.message) || 'Ocurrio un error al simular el abono sobre deuda de tienda.') + '</p>'
                    + '</div>';
                renderModalBody();
            });
        }

        confirmButton.addEventListener('click', function () {
            var form = state.form;
            var context = getFormContext(form);
            var target = getSelectionFromState();
            var amount = parseNumber(state.amount);

            if (!form || !context || !target || amount <= 0) {
                return;
            }

            if (amount > parseNumber(target.amount || 0) + 0.00001 || amount > context.cashPreview + 0.00001) {
                renderModalBody();
                return;
            }

            if (selectionIsDual(context, target)) {
                var previewKey = getPreviewKey(form, target, amount);
                if (!state.previewHtml || !state.previewSignature || state.previewKey !== previewKey) {
                    loadDualPreview(form, target, amount);
                    return;
                }

                if (state.previewExecutionMode === 'runner') {
                    renderModalBody();
                    return;
                }
            }

            applySelectionToForm(form, target, amount, selectionIsDual(context, target), state.previewSignature);
            setModalState(modal, false);
        });

        body.addEventListener('click', function (event) {
            var targetButton = event.target.closest('[data-payroll-target-key]');
            if (!targetButton) {
                return;
            }

            state.selectedKey = targetButton.getAttribute('data-payroll-target-key') || '';
            state.previewKey = '';
            state.previewSignature = '';
            state.previewExecutionMode = '';
            state.previewHtml = '';

            if (!state.amount) {
                var selected = getSelectionFromState();
                var context = getFormContext(state.form);
                if (selected && context) {
                    var suggested = Math.min(parseNumber(selected.amount || 0), context.cashPreview);
                    state.amount = suggested > 0 ? String(suggested.toFixed(2)) : '';
                }
            }

            renderModalBody();
        });

        body.addEventListener('input', function (event) {
            var amountInput = event.target.closest('[data-payroll-debt-amount]');
            if (!amountInput) {
                return;
            }

            state.amount = amountInput.value;
            state.previewKey = '';
            state.previewSignature = '';
            state.previewExecutionMode = '';
            state.previewHtml = '';
            renderModalBody();
        });

        document.addEventListener('click', function (event) {
            var openButton = event.target.closest('[data-payroll-debt-open]');
            if (openButton) {
                event.preventDefault();
                loadDebts(openButton.closest('form'));
                return;
            }

            var clearButton = event.target.closest('[data-payroll-manual-clear]');
            if (clearButton) {
                event.preventDefault();
                clearFormSelection(clearButton.closest('form'));
            }
        });

        document.querySelectorAll('[data-payroll-manual-summary]').forEach(function (summary) {
            if (!summary.getAttribute('data-default-summary')) {
                summary.setAttribute('data-default-summary', summary.innerHTML);
            }
        });

        document.querySelectorAll('[data-payroll-manual-settlement-form]').forEach(function (form) {
            ['change', 'input'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    if (!event.target || !event.target.matches('[name="paid_at"], [name="payment_account_id"]')) {
                        return;
                    }

                    if (form.dataset.payrollManualForceDual === '1') {
                        clearFormSelection(form);
                    }
                });
            });
        });

        ['change', 'input'].forEach(function (eventName) {
            document.addEventListener(eventName, function (event) {
                var field = event.target;
                var form;

                if (!field || !field.matches('[data-payroll-manual-settlement-form] [name="paid_at"], [data-payroll-manual-settlement-form] [name="payment_account_id"]')) {
                    return;
                }

                form = field.closest('[data-payroll-manual-settlement-form]');
                if (!form || form.dataset.payrollManualForceDual !== '1') {
                    return;
                }

                clearFormSelection(form);
            });
        });

        modal.dataset.payrollDebtReady = '1';
    }

    function setupPayrollPaymentModal() {
        var modal = document.querySelector('[data-modal="payroll-payment"]');
        var title = modal ? modal.querySelector('[data-payroll-payment-title]') : null;
        var description = modal ? modal.querySelector('[data-payroll-payment-description]') : null;
        var body = modal ? modal.querySelector('[data-payroll-payment-body]') : null;

        if (!modal || !title || !description || !body || !window.ASDLFinanceAdmin || !ASDLFinanceAdmin.restBase || modal.dataset.payrollPaymentReady === '1') {
            return;
        }

        function renderEmptyState(headline, message, toneClass) {
            body.innerHTML = ''
                + '<div class="asdl-fin-empty' + (toneClass ? ' ' + toneClass : '') + '">'
                + '<strong>' + escapeHtml(headline || 'No se pudo completar esta accion.') + '</strong>'
                + '<p>' + escapeHtml(message || 'Intenta de nuevo desde la cola o desde el perfil del empleado.') + '</p>'
                + '</div>';
        }

        function buildSuccessState(message) {
            body.innerHTML = ''
                + '<div class="asdl-fin-note-box">'
                + '<strong>Pago procesado correctamente.</strong>'
                + '<div>' + escapeHtml(message || 'Actualizando la vista para reflejar el periodo pagado.') + '</div>'
                + '</div>';
        }

        function buildFeedbackBox(form, message) {
            var feedback = form ? form.querySelector('[data-payroll-process-feedback]') : null;

            if (!form) {
                return null;
            }

            if (!feedback) {
                feedback = document.createElement('div');
                feedback.setAttribute('data-payroll-process-feedback', '1');
                feedback.className = 'asdl-fin-empty asdl-fin-settlement-preview-error';
                form.insertBefore(feedback, form.firstChild);
            }

            feedback.innerHTML = '<strong>No se pudo procesar el pago.</strong><p>' + escapeHtml(message || 'Ocurrio un error al registrar este pago de nomina.') + '</p>';
            return feedback;
        }

        function serializeForm(form) {
            var payload = {};

            new FormData(form).forEach(function (value, key) {
                payload[key] = value;
            });

            return payload;
        }

        function refreshPayrollContext() {
            var contactRuntime = document.querySelector('[data-runtime-action="asdl_fin_admin_runtime"][data-runtime-param-page-key="contacts"]');

            if (contactRuntime) {
                return refreshCurrentContactDetailRuntime().then(function () {
                    setModalState(modal, false);
                });
            }

            window.location.reload();
            return Promise.resolve();
        }

        function openFromTrigger(trigger) {
            var templateId = trigger.getAttribute('data-payroll-payment-template') || '';
            var template = templateId ? document.getElementById(templateId) : null;
            var injectedForm;
            var summary;

            title.textContent = trigger.getAttribute('data-payroll-payment-title') || 'Procesar pago de nomina';
            description.textContent = trigger.getAttribute('data-payroll-payment-description') || 'Confirma cuenta, metodo, referencia y notas antes de registrar el pago.';

            if (!template) {
                renderEmptyState('No se encontro el formulario de pago.', 'Recarga la pagina e intenta abrir este periodo otra vez.', 'asdl-fin-settlement-preview-error');
                setModalState(modal, true);
                return;
            }

            body.innerHTML = template.innerHTML;
            injectedForm = body.querySelector('[data-payroll-process-modal-form]');
            summary = injectedForm ? injectedForm.querySelector('[data-payroll-manual-summary]') : null;

            if (summary && !summary.getAttribute('data-default-summary')) {
                summary.setAttribute('data-default-summary', summary.innerHTML);
            }

            initializeDynamicAdminContent(body);
            setModalState(modal, true);
        }

        document.addEventListener('click', function (event) {
            var openTrigger = event.target.closest('[data-payroll-payment-open]');
            if (!openTrigger) {
                return;
            }

            event.preventDefault();
            openFromTrigger(openTrigger);
        });

        body.addEventListener('submit', function (event) {
            var form = event.target.closest('[data-payroll-process-modal-form]');
            var payrollField;
            var payrollId;
            var submitButton;
            var payload;
            var endpoint;

            if (!form) {
                return;
            }

            event.preventDefault();

            if (form.dataset.payrollSubmitting === '1') {
                return;
            }

            payrollField = form.querySelector('[name="payroll_id"]');
            payrollId = Number(payrollField ? payrollField.value : 0);

            if (!payrollId) {
                renderEmptyState('Falta el periodo de nomina.', 'No pudimos identificar el periodo que se intentaba pagar.', 'asdl-fin-settlement-preview-error');
                return;
            }

            submitButton = findSettlementSubmitButton(form);
            payload = serializeForm(form);
            endpoint = ASDLFinanceAdmin.restBase.replace(/\/+$/, '') + '/payroll-periods/' + encodeURIComponent(payrollId) + '/mark-paid';

            form.dataset.payrollSubmitting = '1';
            setAsyncButtonState(submitButton, true, 'Procesar pago', 'Procesando pago...');

            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ASDLFinanceAdmin.nonce
                },
                body: JSON.stringify(payload)
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (json) {
                    if (!response.ok) {
                        throw new Error((json && json.message) || 'No se pudo registrar el pago de nomina.');
                    }
                    return json && json.data ? json.data : json;
                });
            }).then(function (result) {
                buildSuccessState((result && result.message) || 'Periodo de nomina procesado correctamente.');
                return refreshPayrollContext();
            }).catch(function (error) {
                buildFeedbackBox(form, (error && error.message) || 'No se pudo completar esta accion.');
                setAsyncButtonState(submitButton, false, 'Procesar pago');
                delete form.dataset.payrollSubmitting;
            });
        });

        modal.dataset.payrollPaymentReady = '1';
    }

    function toggleEmployeePayrollFields(form) {
        if (!form) {
            return;
        }

        var select = form.querySelector('[data-employee-frequency-select]');
        var weekdayField = form.querySelector('[data-employee-weekday-field]');
        var monthdayField = form.querySelector('[data-employee-monthday-field]');
        var biweeklyField = form.querySelector('[data-employee-biweekly-field]');
        var contractTypeSelect = form.querySelector('[data-employee-contract-type-select]');
        var contractEndField = form.querySelector('[data-employee-contract-end-field]');
        var employmentStatusSelect = form.querySelector('[data-employee-status-select]');
        var terminationFields = form.querySelectorAll('[data-employee-termination-field]');
        var nextPaymentField = form.querySelector('[data-employee-next-payment-field]');
        var value = select ? select.value : 'monthly';
        var contractType = contractTypeSelect ? contractTypeSelect.value : '';
        var employmentStatus = employmentStatusSelect ? employmentStatusSelect.value : 'active';

        function setFieldVisibility(field, visible) {
            if (!field) {
                return;
            }

            field.hidden = !visible;
            field.classList.toggle('is-hidden', !visible);
        }

        if (weekdayField) {
            setFieldVisibility(weekdayField, value !== 'monthly');
        }

        if (monthdayField) {
            setFieldVisibility(monthdayField, value === 'monthly');
        }

        if (biweeklyField) {
            setFieldVisibility(biweeklyField, value === 'biweekly');
        }

        if (contractEndField) {
            setFieldVisibility(contractEndField, contractType === 'fixed_term' || contractType === 'temporary');
        }

        if (terminationFields.length) {
            terminationFields.forEach(function (field) {
                setFieldVisibility(field, employmentStatus === 'ended');
            });
        }

        if (nextPaymentField) {
            setFieldVisibility(nextPaymentField, employmentStatus !== 'ended');
        }

        updateEmployeeProfileProjection(form);
    }

    function toIsoDateString(date) {
        if (!date || !(date instanceof Date)) {
            return '';
        }

        return date.getUTCFullYear() + '-'
            + String(date.getUTCMonth() + 1).padStart(2, '0') + '-'
            + String(date.getUTCDate()).padStart(2, '0');
    }

    function daysInUtcMonth(year, monthIndex) {
        return new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();
    }

    function selectedOptionText(select, fallback) {
        if (!select) {
            return fallback || 'Sin definir';
        }

        var option = select.options[select.selectedIndex];
        return option && option.textContent ? option.textContent.trim() : (fallback || 'Sin definir');
    }

    function frequencyProjectionLabel(value) {
        switch (value) {
            case 'weekly':
                return 'Semanal';
            case 'biweekly':
                return 'Quincenal';
            case 'monthly':
            default:
                return 'Mensual';
        }
    }

    function contractStatusLabel(value) {
        switch (value) {
            case 'active':
                return 'Activo';
            case 'renewal_due':
                return 'Por renovar';
            case 'expired':
                return 'Vencido';
            case 'ended':
                return 'Finalizado';
            case 'not_configured':
            default:
                return 'Sin contrato';
        }
    }

    function recoveryModeLabel(value) {
        return value === 'manual' ? 'Gestion manual' : 'Proximo pago';
    }

    function resolveReferenceDate(form) {
        var today = new Date();
        var todayUtc = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
        var effectiveInput = form.querySelector('[data-employee-effective-from]');
        var hireInput = form.querySelector('[data-employee-hire-date]');
        var effectiveDate = parseIsoDate(effectiveInput ? effectiveInput.value : '');
        var hireDate = parseIsoDate(hireInput ? hireInput.value : '');
        var reference = todayUtc;

        if (effectiveDate && effectiveDate > reference) {
            reference = effectiveDate;
        } else if (hireDate && hireDate > reference) {
            reference = hireDate;
        }

        return reference;
    }

    function nextWeeklyDate(reference, weekdayValue) {
        var weekday = Number(weekdayValue || 0);
        var next = new Date(reference.getTime());
        var diff = (weekday - next.getUTCDay() + 7) % 7;
        next.setUTCDate(next.getUTCDate() + diff);
        return next;
    }

    function nextBiweeklyDate(reference, anchorValue, weekdayValue) {
        var anchor = parseIsoDate(anchorValue || '');

        if (!anchor) {
            return nextWeeklyDate(reference, weekdayValue);
        }

        var next = new Date(anchor.getTime());
        while (next < reference) {
            next.setUTCDate(next.getUTCDate() + 14);
        }
        return next;
    }

    function nextMonthlyDate(reference, dayValue) {
        var day = Math.max(1, Math.min(31, Number(dayValue || 1)));
        var year = reference.getUTCFullYear();
        var month = reference.getUTCMonth();
        var candidateDay = Math.min(day, daysInUtcMonth(year, month));
        var next = new Date(Date.UTC(year, month, candidateDay));

        if (next < reference) {
            month += 1;
            if (month > 11) {
                year += 1;
                month = 0;
            }

            candidateDay = Math.min(day, daysInUtcMonth(year, month));
            next = new Date(Date.UTC(year, month, candidateDay));
        }

        return next;
    }

    function resolveContractStatus(form) {
        var employmentStatus = form.querySelector('[data-employee-status-select]');
        var contractType = form.querySelector('[data-employee-contract-type-select]');
        var contractEndInput = form.querySelector('[data-employee-contract-end-input]');
        var contractStartInput = form.querySelector('[data-employee-contract-start]');
        var today = new Date();
        var todayUtc = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
        var employmentValue = employmentStatus ? employmentStatus.value : 'active';
        var contractTypeValue = contractType ? contractType.value : '';
        var contractEndDate = parseIsoDate(contractEndInput ? contractEndInput.value : '');
        var contractStartDate = parseIsoDate(contractStartInput ? contractStartInput.value : '');
        var referenceStart = resolveReferenceDate(form);
        var result = {
            key: 'not_configured',
            label: 'Sin contrato',
            eligible: employmentValue === 'active',
            summary: 'Completa el contrato si necesitas control de renovacion o vencimiento.'
        };

        if (employmentValue === 'ended') {
            result.key = 'ended';
            result.label = contractStatusLabel('ended');
            result.eligible = false;
            result.summary = 'Empleado finalizado. No deberia entrar en nuevas nominas.';
            return result;
        }

        if ((contractTypeValue === 'fixed_term' || contractTypeValue === 'temporary') && contractEndDate) {
            var daysRemaining = Math.round((contractEndDate.getTime() - todayUtc.getTime()) / 86400000);

            if (daysRemaining < 0) {
                result.key = 'expired';
                result.label = contractStatusLabel('expired');
                result.eligible = false;
                result.summary = 'El contrato vencio. Conviene renovarlo antes de seguir operando nomina normal.';
                return result;
            }

            if (daysRemaining <= 15) {
                result.key = 'renewal_due';
                result.label = contractStatusLabel('renewal_due');
                result.eligible = employmentValue === 'active';
                result.summary = 'El contrato esta por vencer. La nomina sigue habilitada, pero conviene renovarlo a tiempo.';
                return result;
            }

            result.key = 'active';
            result.label = contractStatusLabel('active');
            result.eligible = employmentValue === 'active';
            result.summary = 'Contrato vigente con fecha de fin controlada.';
            return result;
        }

        if (contractTypeValue || contractStartDate || referenceStart) {
            result.key = 'active';
            result.label = contractStatusLabel('active');
            result.eligible = employmentValue === 'active';
            result.summary = employmentValue === 'paused'
                ? 'Empleado en pausa. Revisa antes de generar una nueva nomina.'
                : 'Configuracion laboral apta para calcular proxima nomina.';
        }

        return result;
    }

    function resolveProjectedNextPayment(form) {
        var nextPaymentInput = form.querySelector('[data-employee-next-payment-input]');
        var frequencySelect = form.querySelector('[data-employee-frequency-select]');
        var weekdaySelect = form.querySelector('[data-employee-payday-weekday]');
        var monthdayInput = form.querySelector('[data-employee-payday-monthday]');
        var biweeklyAnchor = form.querySelector('[data-employee-cycle-anchor]');
        var employmentStatus = form.querySelector('[data-employee-status-select]');
        var manualValue = nextPaymentInput ? nextPaymentInput.value : '';
        var frequency = frequencySelect ? frequencySelect.value : 'monthly';

        if (employmentStatus && employmentStatus.value === 'ended') {
            return {
                iso: '',
                label: 'No aplica',
                manual: false
            };
        }

        if (manualValue) {
            return {
                iso: manualValue,
                label: formatIsoDate(manualValue),
                manual: true
            };
        }

        var reference = resolveReferenceDate(form);
        var nextDate;

        if (frequency === 'weekly') {
            nextDate = nextWeeklyDate(reference, weekdaySelect ? weekdaySelect.value : '0');
        } else if (frequency === 'biweekly') {
            nextDate = nextBiweeklyDate(reference, biweeklyAnchor ? biweeklyAnchor.value : '', weekdaySelect ? weekdaySelect.value : '0');
        } else {
            nextDate = nextMonthlyDate(reference, monthdayInput ? monthdayInput.value : '1');
        }

        var iso = toIsoDateString(nextDate);
        return {
            iso: iso,
            label: formatIsoDate(iso),
            manual: false
        };
    }

    function updateEmployeeProfileProjection(form) {
        if (!form) {
            return;
        }

        var projection = form.querySelector('[data-employee-profile-projection]');
        if (!projection) {
            return;
        }

        var frequencySelect = form.querySelector('[data-employee-frequency-select]');
        var accountSelect = form.querySelector('[data-employee-default-account-select]');
        var salaryInput = form.querySelector('[data-employee-salary-amount]');
        var currencyInput = form.querySelector('[data-employee-salary-currency]');
        var summary = form.querySelector('[data-employee-profile-summary]');
        var contractStatus = resolveContractStatus(form);
        var nextPayment = resolveProjectedNextPayment(form);
        var employmentStatus = form.querySelector('[data-employee-status-select]');
        var accountFallback = form.getAttribute('data-default-account-label') || 'Sin definir';
        var accountLabel = selectedOptionText(accountSelect, accountFallback);
        var salaryAmount = salaryInput ? salaryInput.value : 0;
        var currency = currencyInput && currencyInput.value ? currencyInput.value : 'USD';
        var frequencyLabel = frequencyProjectionLabel(frequencySelect ? frequencySelect.value : 'monthly');
        var eligibilityText = contractStatus.eligible ? 'Si' : 'No';

        if (projection.querySelector('[data-employee-projection-frequency]')) {
            projection.querySelector('[data-employee-projection-frequency]').textContent = frequencyLabel;
        }

        if (projection.querySelector('[data-employee-projection-next]')) {
            projection.querySelector('[data-employee-projection-next]').textContent = nextPayment.label;
        }

        if (projection.querySelector('[data-employee-projection-account]')) {
            projection.querySelector('[data-employee-projection-account]').textContent = accountLabel || 'Sin definir';
        }

        if (projection.querySelector('[data-employee-projection-contract]')) {
            projection.querySelector('[data-employee-projection-contract]').textContent = contractStatus.label;
        }

        if (projection.querySelector('[data-employee-projection-eligibility]')) {
            if (employmentStatus && employmentStatus.value === 'paused') {
                eligibilityText = 'Revisar pausa';
            }
            projection.querySelector('[data-employee-projection-eligibility]').textContent = eligibilityText;
        }

        if (projection.querySelector('[data-employee-projection-salary]')) {
            projection.querySelector('[data-employee-projection-salary]').textContent = formatMoneyValue(salaryAmount, currency);
        }

        if (summary) {
            if (employmentStatus && employmentStatus.value === 'ended') {
                summary.textContent = 'Empleado finalizado. Solo conserva esta ficha como referencia historica y contractual.';
            } else if (employmentStatus && employmentStatus.value === 'paused') {
                summary.textContent = 'Empleado en pausa. La configuracion sigue guardada, pero conviene revisar antes de generar nuevas nominas.';
            } else if (!contractStatus.eligible) {
                summary.textContent = contractStatus.summary;
            } else if (nextPayment.manual) {
                summary.textContent = 'Proximo pago fijado manualmente para ' + nextPayment.label + '. Si eliminas el override, el sistema volvera a calcularlo segun la frecuencia.';
            } else {
                summary.textContent = contractStatus.summary + ' Proxima nomina estimada para ' + nextPayment.label + '.';
            }
        }
    }

    function setupSalaryAdvanceForms() {
        document.querySelectorAll('[data-salary-advance-form]').forEach(function (form) {
            if (form.dataset.advanceReady === '1') {
                return;
            }

            var amountInput = form.querySelector('[data-salary-advance-amount]');
            var currencyInput = form.querySelector('[data-salary-advance-currency]');
            var modeSelect = form.querySelector('[data-salary-advance-mode]');
            var recoveryDateInput = form.querySelector('[data-salary-advance-recovery-date]');
            var accountSelect = form.querySelector('[data-salary-advance-account]');
            var projection = form.querySelector('[data-salary-advance-projection]');
            var summary = form.querySelector('[data-salary-advance-summary]');
            var employeeNextPayment = form.getAttribute('data-employee-next-payment') || '';
            var defaultAccountLabel = form.getAttribute('data-employee-default-account') || 'Sin definir';

            if (!modeSelect || !recoveryDateInput || !projection) {
                return;
            }

            function updateAdvanceProjection() {
                var mode = modeSelect.value || 'next_payroll';
                var currency = currencyInput && currencyInput.value ? currencyInput.value : (form.getAttribute('data-employee-currency') || 'USD');
                var amount = amountInput ? amountInput.value : 0;
                var accountLabel = selectedOptionText(accountSelect, defaultAccountLabel);
                var effectiveDate = recoveryDateInput.value || '';

                if (mode === 'next_payroll' && !effectiveDate && employeeNextPayment) {
                    recoveryDateInput.value = employeeNextPayment;
                    effectiveDate = employeeNextPayment;
                }

                if (projection.querySelector('[data-advance-projection-mode]')) {
                    projection.querySelector('[data-advance-projection-mode]').textContent = recoveryModeLabel(mode);
                }

                if (projection.querySelector('[data-advance-projection-date]')) {
                    projection.querySelector('[data-advance-projection-date]').textContent = effectiveDate ? formatIsoDate(effectiveDate) : 'Sin definir';
                }

                if (projection.querySelector('[data-advance-projection-account]')) {
                    projection.querySelector('[data-advance-projection-account]').textContent = accountLabel || 'Sin definir';
                }

                if (projection.querySelector('[data-advance-projection-impact]')) {
                    projection.querySelector('[data-advance-projection-impact]').textContent = mode === 'next_payroll'
                        ? 'Se intentara descontar automaticamente en la proxima nomina elegible.'
                        : 'Queda bajo gestion manual hasta que registres el descuento o lo conviertas en otro acuerdo.';
                }

                if (summary) {
                    if (mode === 'next_payroll' && !effectiveDate) {
                        summary.textContent = 'Falta una proxima fecha de pago configurada. Puedes cargarla en la ficha laboral o dejar este adelanto en gestion manual.';
                    } else if (mode === 'next_payroll') {
                        summary.textContent = 'Este adelanto de ' + formatMoneyValue(amount, currency) + ' se programara para recuperarse a partir del ' + formatIsoDate(effectiveDate) + '.';
                    } else {
                        summary.textContent = 'Este adelanto de ' + formatMoneyValue(amount, currency) + ' quedara fuera del descuento automatico hasta que lo gestiones manualmente.';
                    }
                }
            }

            ['input', 'change'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    if (event.target && (
                        event.target.matches('[data-salary-advance-amount]') ||
                        event.target.matches('[data-salary-advance-currency]') ||
                        event.target.matches('[data-salary-advance-mode]') ||
                        event.target.matches('[data-salary-advance-recovery-date]') ||
                        event.target.matches('[data-salary-advance-account]')
                    )) {
                        updateAdvanceProjection();
                    }
                });
            });

            updateAdvanceProjection();
            form.dataset.advanceReady = '1';
        });
    }

    function setupPayrollPeriodForms() {
        document.querySelectorAll('[data-payroll-period-form]').forEach(function (form) {
            if (form.dataset.payrollReady === '1') {
                return;
            }

            var periodStart = form.querySelector('[data-payroll-period-start]');
            var periodEnd = form.querySelector('[data-payroll-period-end]');
            var scheduledDate = form.querySelector('[data-payroll-scheduled-date]');
            var grossAmount = form.querySelector('[data-payroll-gross-amount]');
            var otherDeduction = form.querySelector('[data-payroll-other-deduction]');
            var accountSelect = form.querySelector('[data-payroll-account]');
            var projection = form.querySelector('[data-payroll-projection]');
            var summary = form.querySelector('[data-payroll-projection-summary]');
            var defaultAccountLabel = form.getAttribute('data-payroll-default-account') || 'Sin definir';
            var currency = form.getAttribute('data-payroll-currency') || 'USD';

            if (!projection || !scheduledDate || !periodStart || !periodEnd) {
                return;
            }

            function updatePayrollProjection() {
                var frequencyLabel = frequencyProjectionLabel(form.getAttribute('data-payroll-frequency') || 'monthly');
                var windowLabel = (periodStart.value || '—') + ' al ' + (periodEnd.value || '—');
                var accountLabel = selectedOptionText(accountSelect, defaultAccountLabel);
                var grossValue = grossAmount ? grossAmount.value : 0;
                var otherDeductionValue = otherDeduction ? otherDeduction.value : 0;

                if (projection.querySelector('[data-payroll-projection-frequency]')) {
                    projection.querySelector('[data-payroll-projection-frequency]').textContent = frequencyLabel;
                }

                if (projection.querySelector('[data-payroll-projection-window]')) {
                    projection.querySelector('[data-payroll-projection-window]').textContent = windowLabel;
                }

                if (projection.querySelector('[data-payroll-projection-date]')) {
                    projection.querySelector('[data-payroll-projection-date]').textContent = scheduledDate.value ? formatIsoDate(scheduledDate.value) : 'Sin definir';
                }

                if (projection.querySelector('[data-payroll-projection-gross]')) {
                    projection.querySelector('[data-payroll-projection-gross]').textContent = formatMoneyValue(grossValue, currency);
                }

                if (projection.querySelector('[data-payroll-projection-deduction]')) {
                    projection.querySelector('[data-payroll-projection-deduction]').textContent = formatMoneyValue(otherDeductionValue, currency);
                }

                if (projection.querySelector('[data-payroll-projection-account]')) {
                    projection.querySelector('[data-payroll-projection-account]').textContent = accountLabel || 'Sin definir';
                }

                if (summary) {
                    summary.textContent = 'Base proyectada: ' + formatMoneyValue(grossValue, currency)
                        + ' con pago previsto para ' + (scheduledDate.value ? formatIsoDate(scheduledDate.value) : 'fecha sin definir')
                        + '. Los adelantos activos y los compromisos por nomina se sumaran automaticamente al procesar este periodo.';
                }
            }

            ['input', 'change'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    if (event.target && (
                        event.target.matches('[data-payroll-period-start]') ||
                        event.target.matches('[data-payroll-period-end]') ||
                        event.target.matches('[data-payroll-scheduled-date]') ||
                        event.target.matches('[data-payroll-gross-amount]') ||
                        event.target.matches('[data-payroll-other-deduction]') ||
                        event.target.matches('[data-payroll-account]')
                    )) {
                        updatePayrollProjection();
                    }
                });
            });

            updatePayrollProjection();
            form.dataset.payrollReady = '1';
        });
    }

    function parseIsoDate(value) {
        if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(String(value))) {
            return null;
        }

        var parts = String(value).split('-').map(function (chunk) {
            return parseInt(chunk, 10);
        });

        return new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    }

    function formatIsoDate(value) {
        var date = parseIsoDate(value);
        if (!date) {
            return 'Sin definir';
        }

        return String(date.getUTCDate()).padStart(2, '0') + '/'
            + String(date.getUTCMonth() + 1).padStart(2, '0') + '/'
            + date.getUTCFullYear();
    }

    function weekdayLabelFromIso(value) {
        var date = parseIsoDate(value);
        var labels = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];

        if (!date) {
            return '';
        }

        return labels[date.getUTCDay()] || '';
    }

    function ensureDateWeekdayOutput(input) {
        if (!input) {
            return null;
        }

        var field = input.closest('.asdl-fin-field');
        if (!field) {
            return null;
        }

        var helper = field.querySelector('[data-date-weekday-output]');
        if (helper) {
            return helper;
        }

        helper = document.createElement('small');
        helper.className = 'asdl-fin-date-weekday';
        helper.setAttribute('data-date-weekday-output', '1');
        helper.hidden = true;

        var firstHelp = field.querySelector('small');
        if (firstHelp) {
            field.insertBefore(helper, firstHelp);
        } else {
            field.appendChild(helper);
        }

        return helper;
    }

    function updateDateWeekdayOutput(input) {
        if (!input || input.type !== 'date') {
            return;
        }

        var helper = ensureDateWeekdayOutput(input);
        if (!helper) {
            return;
        }

        var label = weekdayLabelFromIso(input.value);
        helper.textContent = label;
        helper.hidden = !label;
    }

    function setupDateWeekdayHelpers() {
        refreshDateWeekdayOutputs(document);

        if (document.documentElement.dataset.asdlDateWeekdayReady === '1') {
            return;
        }

        document.documentElement.dataset.asdlDateWeekdayReady = '1';

        ['input', 'change'].forEach(function (eventName) {
            document.addEventListener(eventName, function (event) {
                if (event.target && event.target.matches('.asdl-fin-field input[type="date"]')) {
                    updateDateWeekdayOutput(event.target);
                }
            });
        });
    }

    function addPeriods(date, frequency, periods) {
        if (!date || !(date instanceof Date) || Number(periods || 0) <= 0) {
            return date;
        }

        var next = new Date(date.getTime());
        var count = Number(periods || 0);

        if (frequency === 'weekly') {
            next.setUTCDate(next.getUTCDate() + (count * 7));
            return next;
        }

        if (frequency === 'biweekly') {
            next.setUTCDate(next.getUTCDate() + (count * 14));
            return next;
        }

        if (frequency === 'quarterly') {
            next.setUTCMonth(next.getUTCMonth() + (count * 3));
            return next;
        }

        next.setUTCMonth(next.getUTCMonth() + count);
        return next;
    }

    function formatMoneyValue(amount, currency) {
        var numeric = Number(amount || 0);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return '—';
        }

        return numeric.toLocaleString('es-VE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' ' + (currency || 'USD');
    }

    function commitmentFrequencyLabel(value) {
        switch (value) {
            case 'weekly':
                return 'Semanal';
            case 'biweekly':
                return 'Quincenal';
            case 'quarterly':
                return 'Trimestral';
            case 'monthly':
            default:
                return 'Mensual';
        }
    }

    function commitmentModeLabel(value, direction) {
        switch (value) {
            case 'payroll_deduction':
                return 'Descuento automatico desde nomina';
            case 'payroll_disbursement':
                return 'Pago automatico dentro de nomina';
            case 'mixed':
                return direction === 'payable'
                    ? 'Pago mixto entre nomina y gestion manual'
                    : 'Cobro mixto entre nomina y gestion manual';
            case 'manual':
            default:
                return direction === 'payable'
                    ? 'Pago manual fuera de nomina'
                    : 'Cobro manual fuera de nomina';
        }
    }

    function commitmentPeriodUnitLabel(frequency, count) {
        var total = Number(count || 0);
        switch (frequency) {
            case 'weekly':
                return total === 1 ? 'semana' : 'semanas';
            case 'biweekly':
                return total === 1 ? 'quincena' : 'quincenas';
            case 'quarterly':
                return total === 1 ? 'trimestre' : 'trimestres';
            case 'monthly':
            default:
                return total === 1 ? 'mes' : 'meses';
        }
    }

    function normalizeCommitmentMode(requested, direction, isEmployee, allowUnknownPayroll, payrollReady) {
        if (allowUnknownPayroll) {
            return requested || 'manual';
        }

        if (!isEmployee) {
            return 'manual';
        }

        if (!payrollReady && ['payroll_deduction', 'payroll_disbursement', 'mixed'].indexOf(requested) >= 0) {
            return 'manual';
        }

        if (direction === 'payable') {
            if (requested === 'payroll_deduction') {
                return 'payroll_disbursement';
            }
            return ['manual', 'payroll_disbursement', 'mixed'].indexOf(requested) >= 0 ? requested : 'payroll_disbursement';
        }

        if (requested === 'payroll_disbursement') {
            return 'payroll_deduction';
        }

        return ['manual', 'payroll_deduction', 'mixed'].indexOf(requested) >= 0 ? requested : 'payroll_deduction';
    }

    function setupCommitmentForms() {
        document.querySelectorAll('[data-commitment-form]').forEach(function (form) {
            if (form.dataset.commitmentReady === '1') {
                return;
            }

            var principalInput = form.querySelector('[data-commitment-principal]');
            var totalInput = form.querySelector('[data-commitment-total]');
            var totalToggle = form.querySelector('[data-commitment-total-toggle]');
            var totalField = form.querySelector('[data-commitment-total-field]');
            var planningMode = form.querySelector('[data-commitment-planning-mode]');
            var planningValue = form.querySelector('[data-commitment-planning-value]');
            var planningLabel = form.querySelector('[data-commitment-planning-label]');
            var planningHelp = form.querySelector('[data-commitment-planning-help]');
            var planningField = form.querySelector('[data-commitment-planning-field]');
            var targetInput = form.querySelector('[data-commitment-target-installment]');
            var countInput = form.querySelector('[data-commitment-installment-count]');
            var directionSelect = form.querySelector('[data-commitment-direction]');
            var originSelect = form.querySelector('[data-commitment-origin]');
            var frequencySelect = form.querySelector('[data-commitment-frequency]');
            var frequencyField = form.querySelector('[data-commitment-frequency-field]');
            var startDateInput = form.querySelector('[data-commitment-start-date]');
            var startField = form.querySelector('[data-commitment-start-field]');
            var payrollStartField = form.querySelector('[data-commitment-payroll-start-field]');
            var payrollStartSelect = form.querySelector('[data-commitment-payroll-start-select]');
            var collectionMode = form.querySelector('[data-commitment-collection-mode]');
            var currencyInput = form.querySelector('[data-commitment-currency]');
            var projection = form.querySelector('[data-commitment-projection]');
            var projectionSummary = form.querySelector('[data-commitment-projection-summary]');
            var modeHelp = form.querySelector('[data-commitment-mode-help]');
            var frequencyHelp = form.querySelector('[data-commitment-frequency-help]');
            var originSummary = form.querySelector('[data-commitment-origin-summary]');
            var originSummaryText = form.querySelector('[data-commitment-origin-summary-text]');
            var employeeFrequency = form.getAttribute('data-employee-frequency') || '';
            var employeeNextPayment = form.getAttribute('data-employee-next-payment') || '';
            var employeeCurrency = form.getAttribute('data-employee-currency') || 'USD';
            var isEmployee = form.getAttribute('data-is-employee') === '1';
            var allowUnknownPayroll = form.getAttribute('data-allow-unknown-payroll') === '1';
            var payrollReady = form.getAttribute('data-employee-payroll-ready') === '1';
            var hasProfileContext = form.getAttribute('data-has-profile-context') === '1';
            var storeDebtTotal = Number(form.getAttribute('data-store-debt-total') || 0);
            var storeDebtCount = Number(form.getAttribute('data-store-debt-count') || 0);
            var companyDebtTotal = Number(form.getAttribute('data-company-debt-total') || 0);

            if (!principalInput || !planningMode || !planningValue || !targetInput || !countInput || !frequencySelect || !startDateInput || !projection) {
                return;
            }

            function updateOriginSummary(origin, currencyCode, forceAutofill) {
                var message = '';
                var recommended = 0;
                var currentAmount = Number(principalInput.value || 0);
                var previousAutoAmount = Number(form.dataset.commitmentAutoPrincipal || 0);

                if (origin === 'store_debt') {
                    recommended = storeDebtTotal;
                    if (recommended > 0) {
                        message = 'Deuda de tienda detectada: ' + formatMoneyValue(recommended, currencyCode) + (storeDebtCount > 0 ? ' en ' + storeDebtCount + ' pedido(s) abiertos. Se tomara como base del compromiso.' : '. Se tomara como base del compromiso.');
                    } else if (hasProfileContext) {
                        message = 'Este perfil no tiene deuda abierta de tienda registrada para tomarla como base automatica.';
                    } else {
                        message = 'En la gestion global, abre primero el perfil si quieres tomar automaticamente la deuda de tienda conocida.';
                    }
                } else if (origin === 'company_debt') {
                    recommended = companyDebtTotal;
                    if (recommended > 0) {
                        message = 'Deuda registrada de la empresa con este perfil: ' + formatMoneyValue(recommended, currencyCode) + '. Se tomara como base del compromiso por pagar.';
                    } else if (hasProfileContext) {
                        message = 'No hay deuda registrada de la empresa con este perfil para usarla como base automatica.';
                    } else {
                        message = 'En la gestion global, abre primero el perfil si quieres tomar automaticamente la deuda de la empresa registrada.';
                    }
                }

                if (originSummary) {
                    originSummary.hidden = message === '';
                    originSummary.classList.toggle('is-hidden', message === '');
                }
                if (originSummaryText) {
                    originSummaryText.textContent = message;
                }

                if ((origin === 'store_debt' || origin === 'company_debt') && recommended > 0) {
                    if (forceAutofill || !principalInput.value || Math.abs(currentAmount - previousAutoAmount) < 0.0001) {
                        principalInput.value = recommended.toFixed(2);
                        form.dataset.commitmentAutoPrincipal = String(recommended);
                        form.dataset.commitmentAutoOrigin = origin;
                    }
                }
            }

            function updateCommitmentPlanner() {
                var principal = Number(principalInput.value || 0);
                var useDistinctTotal = !!(totalToggle && totalToggle.checked);
                var total = Number(totalInput && totalInput.value ? totalInput.value : 0);
                var mode = planningMode.value || 'period_amount';
                var direction = directionSelect ? (directionSelect.value || 'receivable') : 'receivable';
                var origin = originSelect ? (originSelect.value || 'loan') : 'loan';
                var previousOrigin = form.dataset.commitmentLastOrigin || '';
                var originChanged = previousOrigin !== origin;
                var effectiveFrequency = frequencySelect.value || 'monthly';
                var startValue = startDateInput.value || employeeNextPayment || '';
                var effectiveStart = startValue;
                var planningAmount = Number(planningValue.value || 0);
                var currentCurrency = currencyInput && currencyInput.value ? currencyInput.value : employeeCurrency;
                var periods = 0;
                var amountPerPeriod = 0;
                var projectionItems = {
                    frequency: projection.querySelector('[data-projection-frequency]'),
                    amount: projection.querySelector('[data-projection-amount]'),
                    count: projection.querySelector('[data-projection-count]'),
                    start: projection.querySelector('[data-projection-start]'),
                    end: projection.querySelector('[data-projection-end]'),
                    mode: projection.querySelector('[data-projection-mode]')
                };
                var employeeFrequencyKnown = !!employeeFrequency;
                var employeeNextPaymentKnown = !!employeeNextPayment;

                form.dataset.commitmentLastOrigin = origin;

                if (origin === 'store_debt' && directionSelect && directionSelect.value !== 'receivable') {
                    directionSelect.value = 'receivable';
                    direction = 'receivable';
                }

                if (origin === 'company_debt' && directionSelect && directionSelect.value !== 'payable') {
                    directionSelect.value = 'payable';
                    direction = 'payable';
                }

                updateOriginSummary(origin, currentCurrency, originChanged);
                principal = Number(principalInput.value || 0);

                if (totalField) {
                    totalField.hidden = !useDistinctTotal;
                    totalField.classList.toggle('is-hidden', !useDistinctTotal);
                }
                if (!useDistinctTotal && totalInput) {
                    totalInput.value = '';
                }

                if (collectionMode) {
                    var normalizedMode = normalizeCommitmentMode(collectionMode.value || 'manual', direction, isEmployee, allowUnknownPayroll, payrollReady);
                    Array.prototype.slice.call(collectionMode.options).forEach(function (option) {
                        if (allowUnknownPayroll) {
                            option.disabled = false;
                            option.hidden = false;
                            return;
                        }

                        var allowed = normalizeCommitmentMode(option.value, direction, isEmployee, false, payrollReady) === option.value;
                        option.disabled = !allowed;
                        option.hidden = !allowed;
                    });

                    if (collectionMode.value !== normalizedMode) {
                        collectionMode.value = normalizedMode;
                    }
                }

                var collection = collectionMode ? collectionMode.value : 'manual';
                var payrollMode = collection === 'payroll_deduction' || collection === 'payroll_disbursement' || collection === 'mixed';
                var totalAmount = useDistinctTotal && total > 0 ? Math.max(total, principal) : principal;
                var payrollUsesEmployeeSchedule = payrollMode && isEmployee && payrollReady;

                if (payrollUsesEmployeeSchedule && employeeFrequencyKnown) {
                    effectiveFrequency = employeeFrequency;
                    frequencySelect.value = employeeFrequency;
                }

                if (frequencyField) {
                    frequencyField.hidden = payrollUsesEmployeeSchedule && employeeFrequencyKnown;
                    frequencyField.classList.toggle('is-hidden', payrollUsesEmployeeSchedule && employeeFrequencyKnown);
                }

                if (payrollUsesEmployeeSchedule && payrollStartSelect && payrollStartSelect.value) {
                    effectiveStart = payrollStartSelect.value;
                    if (startDateInput.value !== payrollStartSelect.value) {
                        startDateInput.value = payrollStartSelect.value;
                    }
                } else if (payrollMode && isEmployee && employeeNextPaymentKnown) {
                    effectiveStart = employeeNextPayment;
                    if (startDateInput.value !== employeeNextPayment) {
                        startDateInput.value = employeeNextPayment;
                    }
                }

                if (startField) {
                    startField.hidden = payrollUsesEmployeeSchedule;
                    startField.classList.toggle('is-hidden', payrollUsesEmployeeSchedule);
                }

                if (payrollStartField) {
                    payrollStartField.hidden = !payrollUsesEmployeeSchedule;
                    payrollStartField.classList.toggle('is-hidden', !payrollUsesEmployeeSchedule);
                }

                if (mode === 'single_period') {
                    if (planningField) {
                        planningField.hidden = true;
                    }
                    planningValue.required = false;
                    targetInput.value = totalAmount > 0 ? String(totalAmount.toFixed(2)) : '';
                    countInput.value = totalAmount > 0 ? '1' : '';
                    periods = totalAmount > 0 ? 1 : 0;
                    amountPerPeriod = totalAmount > 0 ? totalAmount : 0;
                } else if (mode === 'period_count') {
                    if (planningField) {
                        planningField.hidden = false;
                    }
                    if (planningLabel) {
                        planningLabel.textContent = 'Cantidad de periodos *';
                    }
                    if (planningHelp) {
                        planningHelp.textContent = 'Indica en cuantas semanas, quincenas o meses quieres resolver el compromiso y el sistema estimara el monto por periodo.';
                    }
                    planningValue.required = true;
                    planningValue.step = '1';
                    planningValue.min = '1';
                    periods = planningAmount > 0 ? Math.max(1, Math.ceil(planningAmount)) : 0;
                    amountPerPeriod = periods > 0 && totalAmount > 0 ? (totalAmount / periods) : 0;
                    targetInput.value = amountPerPeriod > 0 ? String(amountPerPeriod.toFixed(2)) : '';
                    countInput.value = periods > 0 ? String(periods) : '';
                } else {
                    if (planningField) {
                        planningField.hidden = false;
                    }
                    if (planningLabel) {
                        planningLabel.textContent = 'Monto por periodo *';
                    }
                    if (planningHelp) {
                        planningHelp.textContent = 'Indica cuanto quieres cobrar o pagar por cada periodo y el sistema estimara cuantas cuotas hacen falta.';
                    }
                    planningValue.required = true;
                    planningValue.step = '0.01';
                    planningValue.min = '0.01';
                    amountPerPeriod = planningAmount > 0 ? planningAmount : 0;
                    periods = amountPerPeriod > 0 && totalAmount > 0 ? Math.max(1, Math.ceil(totalAmount / amountPerPeriod)) : 0;
                    targetInput.value = amountPerPeriod > 0 ? String(amountPerPeriod.toFixed(2)) : '';
                    countInput.value = periods > 0 ? String(periods) : '';
                }

                if (modeHelp) {
                    if (!isEmployee && payrollMode && !allowUnknownPayroll) {
                        modeHelp.textContent = 'Este perfil no tiene ficha laboral. Si guardas asi, el backend degradara el modo a gestion manual.';
                    } else if (isEmployee && !payrollReady && !allowUnknownPayroll) {
                        modeHelp.textContent = 'Para usar descuento por sueldo o pago por nomina, primero define frecuencia de pago, contrato vigente y proxima fecha de nomina en la ficha laboral del empleado.';
                    } else if (allowUnknownPayroll && payrollMode) {
                        modeHelp.textContent = 'Si el perfil seleccionado es empleado, el backend alineara automaticamente este compromiso con su frecuencia laboral y su proxima nomina.';
                    } else if (payrollMode && isEmployee && !employeeFrequencyKnown && !employeeNextPaymentKnown) {
                        modeHelp.textContent = direction === 'payable'
                            ? 'El compromiso podra pagarse por nomina, pero este empleado aun no tiene frecuencia ni proxima fecha definidas. La proyeccion usara lo que indiques aqui hasta completar su ficha laboral.'
                            : 'El compromiso podra descontarse por nomina, pero este empleado aun no tiene frecuencia ni proxima fecha definidas. La proyeccion usara lo que indiques aqui hasta completar su ficha laboral.';
                    } else if (payrollMode && isEmployee && !employeeFrequencyKnown) {
                        modeHelp.textContent = direction === 'payable'
                            ? 'El compromiso se integrara con nomina, pero la ficha laboral aun no define frecuencia de pago. La proyeccion usa temporalmente la frecuencia seleccionada aqui.'
                            : 'El compromiso se integrara con nomina, pero la ficha laboral aun no define frecuencia de pago. La proyeccion usa temporalmente la frecuencia seleccionada aqui.';
                    } else if (payrollMode && isEmployee && !employeeNextPaymentKnown) {
                        modeHelp.textContent = direction === 'payable'
                            ? 'Este compromiso se pagara junto a nomina usando la frecuencia laboral actual (' + commitmentFrequencyLabel(employeeFrequency).toLowerCase() + '). Como la proxima fecha aun no esta definida, se tomara la fecha inicial que pongas aqui.'
                            : 'Este compromiso se descontara junto a nomina usando la frecuencia laboral actual (' + commitmentFrequencyLabel(employeeFrequency).toLowerCase() + '). Como la proxima fecha aun no esta definida, se tomara la fecha inicial que pongas aqui.';
                    } else if (payrollMode && isEmployee) {
                        modeHelp.textContent = direction === 'payable'
                            ? 'Este compromiso se pagara junto a la nomina del empleado y usara automaticamente su frecuencia laboral actual (' + commitmentFrequencyLabel(employeeFrequency).toLowerCase() + '). Si quieres otra cadencia, cambia la ficha laboral o usa gestion manual.'
                            : 'Este compromiso se descontara desde la nomina del empleado y usara automaticamente su frecuencia laboral actual (' + commitmentFrequencyLabel(employeeFrequency).toLowerCase() + '). Si quieres otra cadencia, cambia la ficha laboral o usa gestion manual.';
                    } else {
                        modeHelp.textContent = direction === 'payable'
                            ? 'Gestion directa fuera de nomina. Podras registrar pagos manuales o convertirlo luego a otro esquema.'
                            : 'Gestion directa fuera de nomina. Podras aplicar cobros o abonos manuales cuando haga falta.';
                    }
                }

                if (frequencyHelp) {
                    if (payrollUsesEmployeeSchedule && employeeFrequencyKnown) {
                        frequencyHelp.textContent = 'La frecuencia laboral del empleado manda en este compromiso: la proyeccion usa esa periodicidad y las fechas reales de nomina.';
                    } else if (isEmployee && !payrollReady && !allowUnknownPayroll) {
                        frequencyHelp.textContent = 'Sin ficha laboral completa no se habilita la gestion por nomina. Completa primero la configuracion del empleado.';
                    } else if (payrollMode && isEmployee) {
                        frequencyHelp.textContent = 'Si quieres, por ejemplo, descontar 10 USD semanales junto a nomina, primero define al empleado como semanal en su ficha laboral. Mientras tanto puedes estimarlo con esta frecuencia temporal.';
                    } else if (allowUnknownPayroll && payrollMode) {
                        frequencyHelp.textContent = 'En el modulo global, la frecuencia seleccionada sirve como referencia hasta que el backend pueda resolver la ficha del perfil elegido.';
                    } else {
                        frequencyHelp.textContent = 'Usa esta frecuencia para definir la cadencia del compromiso.';
                    }
                }

                if (projectionItems.frequency) {
                    projectionItems.frequency.textContent = commitmentFrequencyLabel(effectiveFrequency);
                }
                if (projectionItems.amount) {
                    projectionItems.amount.textContent = formatMoneyValue(amountPerPeriod, currentCurrency);
                }
                if (projectionItems.count) {
                    projectionItems.count.textContent = periods > 0 ? (String(periods) + ' ' + commitmentPeriodUnitLabel(effectiveFrequency, periods)) : '—';
                }
                if (projectionItems.start) {
                    projectionItems.start.textContent = effectiveStart ? formatIsoDate(effectiveStart) : 'Sin definir';
                }
                if (projectionItems.end) {
                    var endDate = effectiveStart && periods > 0 ? addPeriods(parseIsoDate(effectiveStart), effectiveFrequency, Math.max(periods - 1, 0)) : null;
                    projectionItems.end.textContent = endDate ? formatIsoDate(endDate.getUTCFullYear() + '-' + String(endDate.getUTCMonth() + 1).padStart(2, '0') + '-' + String(endDate.getUTCDate()).padStart(2, '0')) : 'Sin definir';
                }
                if (projectionItems.mode) {
                    projectionItems.mode.textContent = commitmentModeLabel(collection, direction);
                }

                if (projectionSummary) {
                    var periodUnit = commitmentPeriodUnitLabel(effectiveFrequency, periods);
                    if (totalAmount <= 0) {
                        projectionSummary.textContent = 'Indica el monto del compromiso para que el sistema calcule cuotas, calendario e impacto esperado.';
                    } else if (periods <= 0 || amountPerPeriod <= 0) {
                        projectionSummary.textContent = 'Selecciona la forma de planificar el compromiso y completa el valor base para estimar periodos y monto por cuota.';
                    } else if (collection === 'payroll_deduction' && direction === 'receivable') {
                        projectionSummary.textContent = 'Se descontaran ' + formatMoneyValue(amountPerPeriod, currentCurrency) + ' por ' + commitmentPeriodUnitLabel(effectiveFrequency, 1) + ' desde la nomina, para cerrar el compromiso en ' + periods + ' ' + periodUnit + '.';
                    } else if (collection === 'payroll_disbursement' && direction === 'payable') {
                        projectionSummary.textContent = 'Se pagaran ' + formatMoneyValue(amountPerPeriod, currentCurrency) + ' por ' + commitmentPeriodUnitLabel(effectiveFrequency, 1) + ' junto a nomina, hasta completar ' + periods + ' ' + periodUnit + '.';
                    } else if (collection === 'mixed') {
                        projectionSummary.textContent = 'El sistema proyecta ' + periods + ' ' + periodUnit + ' de ' + formatMoneyValue(amountPerPeriod, currentCurrency) + ' combinando nomina y gestion manual segun el sentido elegido.';
                    } else {
                        projectionSummary.textContent = 'Resultado estimado: ' + periods + ' ' + periodUnit + ' de ' + formatMoneyValue(amountPerPeriod, currentCurrency) + ' a partir del ' + formatIsoDate(effectiveStart) + '.';
                    }
                }
            }

            ['input', 'change'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    if (event.target && (
                        event.target.matches('[data-commitment-principal]') ||
                        event.target.matches('[data-commitment-total]') ||
                        event.target.matches('[data-commitment-total-toggle]') ||
                        event.target.matches('[data-commitment-planning-mode]') ||
                        event.target.matches('[data-commitment-planning-value]') ||
                        event.target.matches('[data-commitment-frequency]') ||
                        event.target.matches('[data-commitment-start-date]') ||
                        event.target.matches('[data-commitment-payroll-start-select]') ||
                        event.target.matches('[data-commitment-collection-mode]') ||
                        event.target.matches('[data-commitment-direction]') ||
                        event.target.matches('[data-commitment-origin]') ||
                        event.target.matches('[data-commitment-currency]')
                    )) {
                        updateCommitmentPlanner();
                    }
                });
            });

            updateCommitmentPlanner();
            form.dataset.commitmentReady = '1';
        });
    }

    function setupHistoricalTools() {
        var indexRoot = document.querySelector('[data-historical-index-root]');
        var resolutionRoot = document.querySelector('[data-historical-resolution-root]');
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var cachedYears = [];
        var indexTimer = 0;
        var resolutionTimer = 0;
        var resolutionPreviewState = null;

        if ((!indexRoot && !resolutionRoot) || !ASDLFinanceAdmin || !ASDLFinanceAdmin.ajaxUrl) {
            return;
        }

        function buildYearOptions(years, indexedOnly, closableOnly) {
            return (years || []).filter(function (row) {
                if (indexedOnly && String(row.status || '') !== 'indexed') {
                    return false;
                }

                if (closableOnly && !row.is_closable) {
                    return false;
                }

                return Number(row.fiscal_year || 0) > 0;
            }).map(function (row) {
                var label = row.label || ('FY ' + row.fiscal_year);

                if (indexedOnly) {
                    label += ' · indexado';
                }

                if (row.compacted_at) {
                    label += ' · compactado';
                }

                if (row.is_special_case) {
                    label += ' · caso especial admin';
                }

                return {
                    value: String(row.fiscal_year || ''),
                    label: label
                };
            });
        }

        function syncSelectOptions(select, items, placeholder) {
            var previous = select ? String(select.value || '') : '';
            var options = Array.isArray(items) ? items : [];

            if (!select) {
                return;
            }

            select.innerHTML = '';

            var emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = placeholder || 'Selecciona';
            select.appendChild(emptyOption);

            options.forEach(function (item) {
                var option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                select.appendChild(option);
            });

            if (previous && options.some(function (item) { return item.value === previous; })) {
                select.value = previous;
            }
        }

        function syncHistoricalYearSelectors() {
            var allYears = buildYearOptions(cachedYears, false, false);
            var indexedYears = buildYearOptions(cachedYears, true, false);
            var resolutionYears = buildYearOptions(cachedYears, true, false);

            syncSelectOptions(document.getElementById('historical_index_year'), allYears, 'Selecciona un ejercicio');
            syncSelectOptions(document.getElementById('historical_rollup_year'), indexedYears, 'Selecciona un ejercicio indexado');
            syncSelectOptions(document.getElementById('historical_resolution_year_from'), resolutionYears, 'Selecciona un ejercicio');
            syncSelectOptions(document.getElementById('historical_resolution_year_to'), resolutionYears, 'Selecciona un ejercicio');
        }

        function buildToolNotice(message, tone) {
            if (!message) {
                return '';
            }

            return '<div class="asdl-fin-tool-notice asdl-fin-tool-notice-' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(message) + '</div>';
        }

        function resolutionFilterSignature(filters) {
            var data = filters || {};
            var normalizeFlag = function (value) {
                if (
                    value === true ||
                    value === 1 ||
                    value === '1' ||
                    value === 'true' ||
                    value === 'yes' ||
                    value === 'on'
                ) {
                    return '1';
                }

                return '';
            };

            return JSON.stringify({
                fiscal_year_from: String(data.fiscal_year_from || ''),
                fiscal_year_to: String(data.fiscal_year_to || ''),
                contact_id: String(data.contact_id || ''),
                search: String(data.search || ''),
                provider: String(data.provider || ''),
                min_balance: String(data.min_balance || ''),
                max_balance: String(data.max_balance || ''),
                special_previous_year: normalizeFlag(data.special_previous_year),
                only_without_paid: normalizeFlag(data.only_without_paid)
            });
        }

        function getResolutionSelectedRows() {
            if (!resolutionPreviewState || !Array.isArray(resolutionPreviewState.items)) {
                return [];
            }

            var selectedMap = {};
            (resolutionPreviewState.selectedIds || []).forEach(function (id) {
                selectedMap[String(id)] = true;
            });

            return resolutionPreviewState.items.filter(function (item) {
                return !!selectedMap[String(item.id || 0)];
            });
        }

        function getResolutionSelectedIdsCsv() {
            return getResolutionSelectedRows().map(function (item) {
                return String(item.id || 0);
            }).filter(Boolean).join(',');
        }

        function getResolutionSelectionSummary() {
            var rows = getResolutionSelectedRows();

            return rows.reduce(function (carry, row) {
                carry.count += 1;
                carry.balance += Number(row.balance || 0);
                return carry;
            }, {
                count: 0,
                balance: 0
            });
        }

        function setResolutionPreviewState(preview) {
            var payload = preview || {};
            var items = Array.isArray(payload.items) ? payload.items : [];

            resolutionPreviewState = {
                filters: payload.filters || {},
                signature: resolutionFilterSignature(payload.filters || {}),
                summary: payload.summary || {},
                years: Array.isArray(payload.years) ? payload.years : [],
                items: items,
                previewLimit: Number(payload.preview_limit || items.length || 0),
                truncated: !!payload.items_truncated,
                selectedIds: items.map(function (item) {
                    return String(item.id || 0);
                }).filter(Boolean)
            };
        }

        function buildToolProgressBar(current, total) {
            var safeTotal = Math.max(0, Number(total || 0));
            var safeCurrent = Math.max(0, Number(current || 0));
            var percent = safeTotal > 0 ? Math.min(100, Math.round((safeCurrent / safeTotal) * 100)) : 0;

            return ''
                + '<div class="asdl-fin-tool-progress-bar" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100">'
                + '<span style="width:' + percent + '%"></span>'
                + '</div>';
        }

        function renderHistoricalIndexYears(root, years) {
            var target = root ? root.querySelector('[data-historical-index-years]') : null;
            var rows = Array.isArray(years) ? years : [];

            if (!target) {
                return;
            }

            if (!rows.length) {
                target.innerHTML = '<div class="asdl-fin-empty"><strong>Sin ejercicios historicos detectados.</strong><p>Cuando Woo/OpenPOS tenga ejercicios anteriores disponibles, apareceran aqui para indexarlos por lote.</p></div>';
                return;
            }

            target.innerHTML = ''
                + '<table class="widefat striped">'
                + '<thead><tr><th>Ejercicio</th><th>Estado</th><th>Pedidos</th><th>Cobrable</th><th>Rollups</th><th>Actualizado</th></tr></thead>'
                + '<tbody>'
                + rows.map(function (row) {
                    var updatedAt = row.indexed_at || row.compacted_at || '';
                    return ''
                        + '<tr>'
                        + '<td><strong>' + escapeHtml(row.label || ('FY ' + row.fiscal_year)) + '</strong></td>'
                        + '<td>' + renderPill(String(row.status || 'pending'), historicalStatusTone(row.status)) + (row.is_closable ? '<div><small>Cerrable</small></div>' : '') + (!row.is_closable && row.is_special_case ? '<div><small>Caso especial admin</small></div>' : '') + '</td>'
                        + '<td>' + escapeHtml(Number(row.order_count || 0).toLocaleString('es-VE')) + '</td>'
                        + '<td>' + escapeHtml(formatToolMoney(row.collectible_balance || 0, 'USD')) + '</td>'
                        + '<td>' + escapeHtml(Number(row.rollup_count || 0).toLocaleString('es-VE')) + '</td>'
                        + '<td><small>' + escapeHtml(formatToolTimestamp(updatedAt)) + '</small></td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table>';
        }

        function renderHistoricalIndexSummary(root, status) {
            var target = root ? root.querySelector('[data-historical-index-summary]') : null;
            var summary = status && status.global ? status.global : {};
            var job = status && status.job ? status.job : {};

            if (!target) {
                return;
            }

            target.innerHTML = ''
                + '<div><strong>Pedidos indexados</strong><span>' + escapeHtml(Number(summary.total_orders || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Anios cubiertos</strong><span>' + escapeHtml(Number(summary.indexed_years || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Historico cobrable</strong><span>' + escapeHtml(formatToolMoney(summary.collectible_balance_total || 0, 'USD')) + '</span></div>'
                + '<div><strong>Ultimo rebuild</strong><span>' + escapeHtml(formatToolTimestamp(job.updated_at || '')) + '</span></div>';
        }

        function renderHistoricalIndexProgress(root, status, message, tone) {
            var target = root ? root.querySelector('[data-historical-index-progress]') : null;
            var job = status && status.job ? status.job : {};
            var total = Number(job.total || 0);
            var processed = Number(job.processed || 0);

            if (!target) {
                return;
            }

            if (!job.status) {
                target.innerHTML = buildToolNotice(message || 'Sin reconstrucciones historicas ejecutandose.', tone || 'neutral');
                return;
            }

            if (job.status === 'running') {
                target.innerHTML = ''
                    + buildToolNotice(message || ('Leyendo ejercicio ' + (job.fiscal_year || '') + ' por lotes.'), tone || 'warning')
                    + '<div class="asdl-fin-tool-progress-card">'
                    + '<div class="asdl-fin-tool-progress-head"><strong>Reconstruyendo ' + escapeHtml(String(job.fiscal_year || '')) + '</strong><span>' + escapeHtml(processed.toLocaleString('es-VE')) + ' / ' + escapeHtml(total.toLocaleString('es-VE')) + '</span></div>'
                    + buildToolProgressBar(processed, total)
                    + '<div class="asdl-fin-tool-progress-meta">'
                    + '<span>Lote actual: ' + escapeHtml(String(job.last_batch || 0)) + ' pedido(s)</span>'
                    + '<span>Pagina: ' + escapeHtml(String(Math.max(1, Number(job.current_page || 1) - 1))) + ' de ' + escapeHtml(String(job.max_pages || 0)) + '</span>'
                    + '<span>Actualizado: ' + escapeHtml(formatToolTimestamp(job.updated_at || '')) + '</span>'
                    + '</div>'
                    + '</div>';
                return;
            }

            target.innerHTML = buildToolNotice(
                message || (
                    job.status === 'completed'
                        ? 'Reconstruccion historica completada.'
                        : job.status === 'error'
                            ? 'La reconstruccion historica termino con errores.'
                            : 'Estado actualizado.'
                ),
                tone || historicalStatusTone(job.status)
            );
        }

        function renderHistoricalResolutionPreview(root, preview, message, tone) {
            var summaryTarget = root ? root.querySelector('[data-historical-resolution-preview-summary]') : null;
            var itemsTarget = root ? root.querySelector('[data-historical-resolution-preview-items]') : null;
            var payload = preview || {};
            var state = resolutionPreviewState;
            var summary = state && state.summary ? state.summary : (payload.summary || {});
            var years = state && Array.isArray(state.years) ? state.years : (Array.isArray(payload.years) ? payload.years : []);
            var items = state && Array.isArray(state.items) ? state.items : (Array.isArray(payload.items) ? payload.items : []);
            var selection = getResolutionSelectionSummary();
            var selectAllChecked = items.length > 0 && selection.count === items.length;
            var statusLabel = message || (summary.item_count ? 'Vista previa lista' : 'Sin resultados');
            var truncationNotice = state && state.truncated
                ? 'Se muestran solo los primeros ' + escapeHtml(String(state.previewLimit || items.length)) + ' pedido(s). Refina el filtro para seleccionar manualmente con precision.'
                : '';
            var selectedMap = {};

            (state && Array.isArray(state.selectedIds) ? state.selectedIds : []).forEach(function (id) {
                selectedMap[String(id)] = true;
            });

            if (summaryTarget) {
                var filters = state && state.filters ? state.filters : (payload.filters || {});
                if (filters.special_previous_year) {
                    statusLabel += ' · Caso especial';
                }

                summaryTarget.innerHTML = ''
                    + '<div><strong>Pedidos elegibles</strong><span>' + escapeHtml(Number(summary.item_count || 0).toLocaleString('es-VE')) + '</span></div>'
                    + '<div><strong>Total a excluir</strong><span>' + escapeHtml(formatToolMoney(selection.balance || 0, 'USD')) + '</span></div>'
                    + '<div><strong>Ejercicios</strong><span>' + escapeHtml(years.length ? years.map(function (year) { return year.label || ('FY ' + year.fiscal_year); }).join(', ') : 'Sin seleccion') + '</span></div>'
                    + '<div><strong>Estado</strong><span>' + escapeHtml(statusLabel) + '</span></div>'
                    + '<div><strong>Seleccionados</strong><span>' + escapeHtml(Number(selection.count || 0).toLocaleString('es-VE')) + '</span></div>'
                    + '<div><strong>Modo</strong><span>' + escapeHtml(selectAllChecked ? 'Todos los mostrados' : 'Seleccion manual') + '</span></div>';
            }

            if (!itemsTarget) {
                return;
            }

            if (!items.length) {
                itemsTarget.innerHTML = buildToolNotice(message || 'No hay pedidos historicos elegibles con esos filtros.', tone || 'neutral');
                return;
            }

            itemsTarget.innerHTML = ''
                + buildToolNotice(message || 'Vista previa calculada correctamente.', tone || 'success')
                + (truncationNotice ? buildToolNotice(truncationNotice, 'warning') : '')
                + '<div class="asdl-fin-tool-selection-bar">'
                + '<label class="asdl-fin-checkbox-row"><input type="checkbox" data-historical-resolution-select-all ' + (selectAllChecked ? 'checked' : '') + ' /> <strong>Seleccionar todos los mostrados</strong></label>'
                + '<span class="asdl-fin-tool-selection-meta">Seleccionados: ' + escapeHtml(Number(selection.count || 0).toLocaleString('es-VE')) + ' de ' + escapeHtml(Number(items.length || 0).toLocaleString('es-VE')) + ' · ' + escapeHtml(formatToolMoney(selection.balance || 0, 'USD')) + '</span>'
                + '</div>'
                + '<table class="widefat striped">'
                + '<thead><tr><th></th><th>Pedido</th><th>Cliente</th><th>Ejercicio</th><th>Proveedor</th><th>Balance</th></tr></thead>'
                + '<tbody>'
                + items.map(function (item) {
                    var rowId = String(item.id || 0);
                    var checked = !!selectedMap[rowId];
                    return ''
                        + '<tr>'
                        + '<td><input type="checkbox" data-historical-resolution-item value="' + escapeHtml(rowId) + '" ' + (checked ? 'checked' : '') + ' /></td>'
                        + '<td><strong>' + escapeHtml(item.order_number || ('#' + item.external_order_id)) + '</strong><br /><small>#' + escapeHtml(String(item.external_order_id || 0)) + '</small></td>'
                        + '<td>' + escapeHtml(item.display_name || item.customer_email || 'Sin cliente') + '</td>'
                        + '<td>' + escapeHtml(String(item.fiscal_year || '')) + '</td>'
                        + '<td>' + escapeHtml(item.provider || '') + '</td>'
                        + '<td>' + escapeHtml(formatToolMoney(item.balance || 0, item.currency || 'USD')) + '</td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table>';
        }

        function renderHistoricalResolutionBatches(root, batches) {
            var target = root ? root.querySelector('[data-historical-resolution-batches]') : null;
            var rows = Array.isArray(batches) ? batches : [];

            if (!target) {
                return;
            }

            if (!rows.length) {
                target.innerHTML = '<div class="asdl-fin-empty"><strong>Sin lotes aplicados.</strong><p>Cuando ejecutes cierres administrativos historicos, apareceran aqui con su cantidad y monto total.</p></div>';
                return;
            }

            target.innerHTML = ''
                + '<table class="widefat striped">'
                + '<thead><tr><th>Lote</th><th>Rango fiscal</th><th>Estado</th><th>Pedidos</th><th>Total</th><th>Procesado</th><th>Accion</th></tr></thead>'
                + '<tbody>'
                + rows.map(function (batch) {
                    return ''
                        + '<tr>'
                        + '<td><strong>#' + escapeHtml(String(batch.id || 0)) + '</strong><br /><small>' + escapeHtml(batch.reason_key || '') + '</small></td>'
                        + '<td>' + escapeHtml('FY ' + String(batch.fiscal_year_from || 0) + ' a FY ' + String(batch.fiscal_year_to || 0)) + '</td>'
                        + '<td>' + renderPill(String(batch.status || 'pending'), historicalStatusTone(batch.status)) + '</td>'
                        + '<td>' + escapeHtml(Number(batch.item_count || 0).toLocaleString('es-VE')) + '</td>'
                        + '<td>' + escapeHtml(formatToolMoney(batch.balance_total || 0, 'USD')) + '</td>'
                        + '<td>' + escapeHtml(Number(batch.processed_count || 0).toLocaleString('es-VE')) + ' / ' + escapeHtml(Number(batch.item_count || 0).toLocaleString('es-VE')) + '</td>'
                        + '<td><button type="button" class="button button-secondary small" data-historical-batch-detail="' + escapeHtml(String(batch.id || 0)) + '">Ver detalle</button></td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table>';
        }

        function renderHistoricalDiagnostics(root, payload, message, tone) {
            var target = root ? root.querySelector('[data-historical-index-diagnostics]') : null;
            var data = payload && payload.diagnostics ? payload.diagnostics : {};
            var row = data.year || {};
            var rollups = data.rollups || {};
            var diagnostics = data.diagnostics || {};

            if (!target) {
                return;
            }

            if (!data.fiscal_year) {
                target.innerHTML = buildToolNotice(message || 'No se pudo cargar el diagnostico solicitado.', tone || 'danger');
                return;
            }

            target.innerHTML = ''
                + buildToolNotice(message || ('Diagnostico listo para ' + escapeHtml(data.label || ('FY ' + data.fiscal_year)) + '.'), tone || 'success')
                + '<div class="asdl-fin-tool-detail-grid">'
                + '<div class="asdl-fin-tool-card"><h3>Indice del ejercicio</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + '<div><strong>Pedidos indexados</strong><span>' + escapeHtml(Number(row.order_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Cobrable</strong><span>' + escapeHtml(formatToolMoney(row.collectible_balance_total || 0, 'USD')) + '</span></div>'
                + '<div><strong>Cerrado admin.</strong><span>' + escapeHtml(formatToolMoney(row.administratively_closed_balance || 0, 'USD')) + '</span></div>'
                + '<div><strong>Ultima actualizacion</strong><span>' + escapeHtml(formatToolTimestamp(row.updated_at || '')) + '</span></div>'
                + '</div></div>'
                + '<div class="asdl-fin-tool-card"><h3>Rollups del ejercicio</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + '<div><strong>Rollups</strong><span>' + escapeHtml(Number(rollups.rollup_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Pedidos</strong><span>' + escapeHtml(Number(rollups.order_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Cobrable</strong><span>' + escapeHtml(formatToolMoney(rollups.collectible_balance_total || 0, 'USD')) + '</span></div>'
                + '<div><strong>Ultima actualizacion</strong><span>' + escapeHtml(formatToolTimestamp(rollups.updated_at || '')) + '</span></div>'
                + '</div></div>'
                + '<div class="asdl-fin-tool-card"><h3>Hallazgos</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + '<div><strong>Sin contacto</strong><span>' + escapeHtml(Number(diagnostics.missing_contact_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Sin source link</strong><span>' + escapeHtml(Number(diagnostics.missing_source_link_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Sin documento</strong><span>' + escapeHtml(Number(diagnostics.missing_document_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Sin correo</strong><span>' + escapeHtml(Number(diagnostics.missing_email_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '</div></div>'
                + '</div>';
        }

        function renderHistoricalBatchDetail(root, payload, message, tone) {
            var target = root ? root.querySelector('[data-historical-resolution-batch-detail]') : null;
            var batch = payload && payload.batch ? payload.batch : null;
            var items = payload && Array.isArray(payload.items) ? payload.items : [];

            if (!target) {
                return;
            }

            if (!batch) {
                target.innerHTML = buildToolNotice(message || 'No se pudo cargar el detalle del lote.', tone || 'danger');
                return;
            }

            target.innerHTML = ''
                + buildToolNotice(message || ('Detalle cargado para el lote #' + escapeHtml(String(batch.id || 0)) + '.'), tone || 'success')
                + '<div class="asdl-fin-tool-detail-grid">'
                + '<div class="asdl-fin-tool-card"><h3>Lote #' + escapeHtml(String(batch.id || 0)) + '</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + '<div><strong>Rango fiscal</strong><span>' + escapeHtml('FY ' + String(batch.fiscal_year_from || 0) + ' a FY ' + String(batch.fiscal_year_to || 0)) + '</span></div>'
                + '<div><strong>Estado</strong><span>' + escapeHtml(String(batch.status || '')) + '</span></div>'
                + '<div><strong>Pedidos</strong><span>' + escapeHtml(Number(batch.item_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Total</strong><span>' + escapeHtml(formatToolMoney(batch.balance_total || 0, 'USD')) + '</span></div>'
                + '<div><strong>Procesado</strong><span>' + escapeHtml(Number(batch.processed_count || 0).toLocaleString('es-VE')) + ' / ' + escapeHtml(Number(batch.item_count || 0).toLocaleString('es-VE')) + '</span></div>'
                + '<div><strong>Actualizado</strong><span>' + escapeHtml(formatToolTimestamp(batch.updated_at || batch.created_at || '')) + '</span></div>'
                + '</div>'
                + (batch.note ? '<div class="asdl-fin-tool-note-box"><strong>Nota del lote</strong><p>' + escapeHtml(batch.note) + '</p></div>' : '')
                + '</div>'
                + '<div class="asdl-fin-tool-card"><h3>Pedidos afectados</h3>'
                + (
                    items.length
                        ? '<table class="widefat striped"><thead><tr><th>Pedido</th><th>Proveedor</th><th>Balance antes</th><th>Estado previo</th><th>Resultado</th></tr></thead><tbody>'
                            + items.map(function (item) {
                                var meta = {};
                                if (item.meta_json) {
                                    try {
                                        meta = JSON.parse(item.meta_json);
                                    } catch (error) {
                                        meta = {};
                                    }
                                }

                                return ''
                                    + '<tr>'
                                    + '<td><strong>' + escapeHtml((meta.order_number || ('#' + String(item.external_order_id || 0)))) + '</strong><br /><small>#' + escapeHtml(String(item.external_order_id || 0)) + '</small></td>'
                                    + '<td>' + escapeHtml(String(item.provider || '')) + '</td>'
                                    + '<td>' + escapeHtml(formatToolMoney(item.balance_before || 0, 'USD')) + '</td>'
                                    + '<td>' + escapeHtml(String(item.previous_status || '')) + '</td>'
                                    + '<td>' + escapeHtml(String(item.new_resolution_status || '')) + '</td>'
                                    + '</tr>';
                            }).join('')
                            + '</tbody></table>'
                        : '<div class="asdl-fin-empty"><strong>Sin items registrados.</strong><p>Este lote todavia no tiene pedidos asociados o el detalle aun no fue persistido.</p></div>'
                )
                + '</div>'
                + '</div>';
        }

        function renderHistoricalResolutionProgress(root, status, message, tone) {
            var target = root ? root.querySelector('[data-historical-resolution-progress]') : null;
            var job = status && status.job ? status.job : {};
            var total = Number(job.item_count || 0);
            var processed = Number(job.processed_count || 0);

            if (!target) {
                return;
            }

            if (!job.status) {
                target.innerHTML = buildToolNotice(message || 'Sin cierres administrativos historicos ejecutandose.', tone || 'neutral');
                return;
            }

            if (job.status === 'running') {
                target.innerHTML = ''
                    + buildToolNotice(message || 'Aplicando cierre administrativo historico por lotes.', tone || 'warning')
                    + '<div class="asdl-fin-tool-progress-card">'
                    + '<div class="asdl-fin-tool-progress-head"><strong>Lote #' + escapeHtml(String(job.batch_id || 0)) + '</strong><span>' + escapeHtml(processed.toLocaleString('es-VE')) + ' / ' + escapeHtml(total.toLocaleString('es-VE')) + '</span></div>'
                    + buildToolProgressBar(processed, total)
                    + '<div class="asdl-fin-tool-progress-meta">'
                    + '<span>Ultimo lote: ' + escapeHtml(String(job.last_batch || 0)) + ' pedido(s)</span>'
                    + '<span>Total procesado: ' + escapeHtml(formatToolMoney(job.processed_total || 0, 'USD')) + '</span>'
                    + '<span>Actualizado: ' + escapeHtml(formatToolTimestamp(job.updated_at || '')) + '</span>'
                    + '</div>'
                    + '</div>';
                return;
            }

            target.innerHTML = buildToolNotice(
                message || (
                    job.status === 'completed'
                        ? 'Cierre administrativo historico completado.'
                        : job.status === 'error'
                            ? 'El cierre administrativo historico termino con errores.'
                            : 'Estado actualizado.'
                ),
                tone || historicalStatusTone(job.status)
            );
        }

        function collectResolutionFilters(root, includeSelection) {
            var field = function (id) {
                var element = root ? root.querySelector('#' + id) : null;
                return element ? element.value : '';
            };
            var contactField = root ? root.querySelector('input[name="historical_resolution_contact_id"]') : null;

            var payload = {
                fiscal_year_from: field('historical_resolution_year_from'),
                fiscal_year_to: field('historical_resolution_year_to'),
                contact_id: contactField ? contactField.value : '',
                search: field('historical_resolution_search'),
                provider: field('historical_resolution_provider') || 'all',
                min_balance: field('historical_resolution_min_balance'),
                max_balance: field('historical_resolution_max_balance'),
                reason_key: field('historical_resolution_reason') || 'historical_cleanup',
                batch_size: field('historical_resolution_batch_size') || '200',
                note: field('historical_resolution_note'),
                special_previous_year: root && root.querySelector('[data-historical-resolution-special-previous-year]') && root.querySelector('[data-historical-resolution-special-previous-year]').checked ? '1' : '',
                only_without_paid: root && root.querySelector('[data-historical-resolution-only-without-paid]') && root.querySelector('[data-historical-resolution-only-without-paid]').checked ? '1' : ''
            };

            if (includeSelection) {
                payload.selected_row_ids = getResolutionSelectedIdsCsv();
            }

            return payload;
        }

        function scheduleIndexContinue() {
            var running = indexRoot && indexRoot.dataset.historicalIndexRunning === '1';

            window.clearTimeout(indexTimer);

            if (!running) {
                return;
            }

            indexTimer = window.setTimeout(function () {
                requestAdminAjax('asdl_fin_historical_index_continue', runtimeNonces.historicalIndexContinue, {}).then(function (payload) {
                    applyHistoricalIndexStatus(payload.status || {}, 'Lote historico procesado correctamente.', 'success');
                }).catch(function (error) {
                    if (indexRoot) {
                        indexRoot.dataset.historicalIndexRunning = '0';
                    }
                    renderHistoricalIndexProgress(indexRoot, { job: { status: 'error' } }, (error && error.message) || 'No se pudo continuar la reconstruccion historica.', 'danger');
                });
            }, 220);
        }

        function scheduleResolutionContinue() {
            var running = resolutionRoot && resolutionRoot.dataset.historicalResolutionRunning === '1';

            window.clearTimeout(resolutionTimer);

            if (!running) {
                return;
            }

            resolutionTimer = window.setTimeout(function () {
                requestAdminAjax('asdl_fin_historical_resolution_continue', runtimeNonces.historicalResolutionContinue, {}).then(function (payload) {
                    applyHistoricalResolutionStatus(payload.status || {}, 'Lote historico aplicado correctamente.', 'success');
                }).catch(function (error) {
                    if (resolutionRoot) {
                        resolutionRoot.dataset.historicalResolutionRunning = '0';
                    }
                    renderHistoricalResolutionProgress(resolutionRoot, { job: { status: 'error' } }, (error && error.message) || 'No se pudo continuar el cierre administrativo historico.', 'danger');
                });
            }, 220);
        }

        function applyHistoricalIndexStatus(status, message, tone) {
            if (!indexRoot) {
                return;
            }

            cachedYears = Array.isArray(status.years) ? status.years : cachedYears;
            syncHistoricalYearSelectors();
            renderHistoricalIndexSummary(indexRoot, status);
            renderHistoricalIndexYears(indexRoot, status.years || []);
            renderHistoricalIndexProgress(indexRoot, status, message, tone);

            if (status && status.job && status.job.status === 'running') {
                indexRoot.dataset.historicalIndexRunning = '1';
                scheduleIndexContinue();
            } else {
                indexRoot.dataset.historicalIndexRunning = '0';
                window.clearTimeout(indexTimer);
            }
        }

        function applyHistoricalResolutionStatus(status, message, tone) {
            if (!resolutionRoot) {
                return;
            }

            renderHistoricalResolutionBatches(resolutionRoot, status.batches || []);
            renderHistoricalResolutionProgress(resolutionRoot, status, message, tone);

            if (status && status.job && status.job.status === 'running') {
                resolutionRoot.dataset.historicalResolutionRunning = '1';
                scheduleResolutionContinue();
            } else {
                resolutionRoot.dataset.historicalResolutionRunning = '0';
                window.clearTimeout(resolutionTimer);
            }
        }

        function refreshHistoricalIndexStatus(message, tone) {
            if (!indexRoot || !runtimeNonces.historicalIndexStatus) {
                return Promise.resolve();
            }

            return requestAdminAjax('asdl_fin_historical_index_status', runtimeNonces.historicalIndexStatus, {}).then(function (payload) {
                applyHistoricalIndexStatus(payload.status || {}, message || '', tone || 'neutral');
                return payload;
            }).catch(function (error) {
                renderHistoricalIndexProgress(indexRoot, { job: {} }, (error && error.message) || 'No se pudo cargar el estado del indice historico.', 'danger');
            });
        }

        function refreshHistoricalResolutionStatus(message, tone) {
            if (!resolutionRoot || !runtimeNonces.historicalResolutionStatus) {
                return Promise.resolve();
            }

            return requestAdminAjax('asdl_fin_historical_resolution_status', runtimeNonces.historicalResolutionStatus, {}).then(function (payload) {
                applyHistoricalResolutionStatus(payload.status || {}, message || '', tone || 'neutral');
                return payload;
            }).catch(function (error) {
                renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, (error && error.message) || 'No se pudo cargar el estado del cierre historico.', 'danger');
            });
        }

        if (indexRoot && indexRoot.dataset.historicalReady !== '1') {
            indexRoot.dataset.historicalReady = '1';

            var startButton = indexRoot.querySelector('[data-historical-index-start]');
            var refreshButton = indexRoot.querySelector('[data-historical-index-refresh]');
            var rollupButton = indexRoot.querySelector('[data-historical-rollups]');
            var compactButton = indexRoot.querySelector('[data-historical-compact]');
            var diagnosticsButton = indexRoot.querySelector('[data-historical-diagnostics]');

            if (startButton) {
                startButton.addEventListener('click', function () {
                    requestAdminAjax('asdl_fin_historical_index_start', runtimeNonces.historicalIndexStart, {
                        fiscal_year: document.getElementById('historical_index_year') ? document.getElementById('historical_index_year').value : '',
                        batch_size: document.getElementById('historical_index_batch_size') ? document.getElementById('historical_index_batch_size').value : '250',
                        force: indexRoot.querySelector('[data-historical-index-force]') && indexRoot.querySelector('[data-historical-index-force]').checked ? '1' : ''
                    }).then(function (payload) {
                        applyHistoricalIndexStatus(payload.status || {}, 'Reconstruccion historica iniciada.', 'success');
                    }).catch(function (error) {
                        renderHistoricalIndexProgress(indexRoot, { job: {} }, (error && error.message) || 'No se pudo iniciar la reconstruccion historica.', 'danger');
                    });
                });
            }

            if (refreshButton) {
                refreshButton.addEventListener('click', function () {
                    refreshHistoricalIndexStatus('Estado del indice actualizado.', 'success');
                });
            }

            if (rollupButton) {
                rollupButton.addEventListener('click', function () {
                    requestAdminAjax('asdl_fin_historical_index_rollups', runtimeNonces.historicalRollups, {
                        fiscal_year: document.getElementById('historical_rollup_year') ? document.getElementById('historical_rollup_year').value : ''
                    }).then(function (payload) {
                        applyHistoricalIndexStatus(payload.status || {}, payload.message || 'Rollups recalculados correctamente.', 'success');
                    }).catch(function (error) {
                        renderHistoricalIndexProgress(indexRoot, { job: {} }, (error && error.message) || 'No se pudieron recalcular los rollups historicos.', 'danger');
                    });
                });
            }

            if (compactButton) {
                compactButton.addEventListener('click', function () {
                    requestAdminAjax('asdl_fin_historical_index_compact', runtimeNonces.historicalCompact, {
                        fiscal_year: document.getElementById('historical_rollup_year') ? document.getElementById('historical_rollup_year').value : ''
                    }).then(function (payload) {
                        applyHistoricalIndexStatus(payload.status || {}, payload.message || 'Ano historico compactado correctamente.', 'success');
                    }).catch(function (error) {
                        renderHistoricalIndexProgress(indexRoot, { job: {} }, (error && error.message) || 'No se pudo compactar el historico seleccionado.', 'danger');
                    });
                });
            }

            if (diagnosticsButton) {
                diagnosticsButton.addEventListener('click', function () {
                    requestAdminAjax('asdl_fin_historical_index_diagnostics', runtimeNonces.historicalIndexDiagnostics, {
                        fiscal_year: document.getElementById('historical_rollup_year') ? document.getElementById('historical_rollup_year').value : ''
                    }).then(function (payload) {
                        applyHistoricalIndexStatus(payload.status || {}, 'Estado del indice actualizado.', 'success');
                        renderHistoricalDiagnostics(indexRoot, payload, 'Diagnostico calculado correctamente.', 'success');
                    }).catch(function (error) {
                        renderHistoricalDiagnostics(indexRoot, {}, (error && error.message) || 'No se pudo cargar el diagnostico del ejercicio.', 'danger');
                    });
                });
            }

            refreshHistoricalIndexStatus();
        }

        if (resolutionRoot && resolutionRoot.dataset.historicalReady !== '1') {
            resolutionRoot.dataset.historicalReady = '1';

            var previewButton = resolutionRoot.querySelector('[data-historical-resolution-preview]');
            var startResolutionButton = resolutionRoot.querySelector('[data-historical-resolution-start]');

            if (previewButton) {
                previewButton.addEventListener('click', function () {
                    requestAdminAjax('asdl_fin_historical_resolution_preview', runtimeNonces.historicalResolutionPreview, collectResolutionFilters(resolutionRoot)).then(function (payload) {
                        setResolutionPreviewState(payload.preview || {});
                        renderHistoricalResolutionPreview(resolutionRoot, payload.preview || {}, 'Vista previa lista.', 'success');
                    }).catch(function (error) {
                        resolutionPreviewState = null;
                        renderHistoricalResolutionPreview(resolutionRoot, {}, (error && error.message) || 'No se pudo calcular la vista previa del cierre historico.', 'danger');
                    });
                });
            }

            if (startResolutionButton) {
                startResolutionButton.addEventListener('click', function () {
                    var filters = collectResolutionFilters(resolutionRoot, false);
                    var currentSignature = resolutionFilterSignature(filters);

                    if (!resolutionPreviewState || !resolutionPreviewState.items || !resolutionPreviewState.items.length) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, 'Calcula primero una vista previa para seleccionar los pedidos que vas a cerrar.', 'danger');
                        return;
                    }

                    if (currentSignature !== resolutionPreviewState.signature) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, 'La vista previa ya no coincide con los filtros actuales. Vuelve a calcularla antes de aplicar el cierre.', 'danger');
                        return;
                    }

                    filters.selected_row_ids = getResolutionSelectedIdsCsv();
                    if (!filters.selected_row_ids) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, 'Selecciona al menos un pedido en la vista previa antes de aplicar el cierre.', 'danger');
                        return;
                    }

                    requestAdminAjax('asdl_fin_historical_resolution_start', runtimeNonces.historicalResolutionStart, filters).then(function (payload) {
                        applyHistoricalResolutionStatus(payload.status || {}, 'Cierre administrativo historico iniciado.', 'success');
                    }).catch(function (error) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, (error && error.message) || 'No se pudo iniciar el cierre administrativo historico.', 'danger');
                    });
                });
            }

            resolutionRoot.addEventListener('change', function (event) {
                var itemCheckbox = event.target.closest('[data-historical-resolution-item]');
                var selectAllCheckbox = event.target.closest('[data-historical-resolution-select-all]');

                if (!resolutionPreviewState || !Array.isArray(resolutionPreviewState.items) || !resolutionPreviewState.items.length) {
                    return;
                }

                if (selectAllCheckbox) {
                    resolutionPreviewState.selectedIds = selectAllCheckbox.checked
                        ? resolutionPreviewState.items.map(function (item) { return String(item.id || 0); }).filter(Boolean)
                        : [];
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Seleccion actualizada.', 'success');
                    return;
                }

                if (itemCheckbox) {
                    var selectedMap = {};
                    (resolutionPreviewState.selectedIds || []).forEach(function (id) {
                        selectedMap[String(id)] = true;
                    });

                    if (itemCheckbox.checked) {
                        selectedMap[String(itemCheckbox.value || '')] = true;
                    } else {
                        delete selectedMap[String(itemCheckbox.value || '')];
                    }

                    resolutionPreviewState.selectedIds = Object.keys(selectedMap).filter(Boolean);
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Seleccion actualizada.', 'success');
                }
            });

            resolutionRoot.addEventListener('click', function (event) {
                var detailButton = event.target.closest('[data-historical-batch-detail]');
                if (!detailButton) {
                    return;
                }

                requestAdminAjax('asdl_fin_historical_resolution_batch_detail', runtimeNonces.historicalResolutionBatchDetail, {
                    batch_id: detailButton.getAttribute('data-historical-batch-detail') || ''
                }).then(function (payload) {
                    renderHistoricalBatchDetail(resolutionRoot, payload, 'Detalle del lote cargado correctamente.', 'success');
                }).catch(function (error) {
                    renderHistoricalBatchDetail(resolutionRoot, {}, (error && error.message) || 'No se pudo cargar el detalle del lote.', 'danger');
                });
            });

            refreshHistoricalResolutionStatus();
        }
    }

    function setupDashboardRuntimeLoader() {
        setupAdminRuntimeLoaders(document);
    }

    function setupContactDetailRuntimeLoader() {
        setupAdminRuntimeLoaders(document);
    }

    function toggleReceiptLogoFields(context) {
        var select = (context || document).querySelector('[name="logo_mode"]');
        var customField = (context || document).querySelector('[data-receipt-logo-custom-field]');

        if (!select || !customField) {
            return;
        }

        customField.style.display = select.value === 'custom_logo' ? '' : 'none';
    }

    function copyText(text) {
        if (!text) {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            return;
        }

        var input = document.createElement('input');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
    }

    document.addEventListener('click', function (event) {
        var orderListTrigger = event.target.closest('.asdl-fin-open-order-list');
        if (orderListTrigger) {
            event.preventDefault();
            populateOrderListModal(orderListTrigger).then(function (modal) {
                setModalState(modal, true);
            });
            return;
        }

        var openTrigger = event.target.closest('.asdl-fin-open-modal');
        if (openTrigger) {
            event.preventDefault();
            setModalState(document.querySelector('[data-modal="' + openTrigger.getAttribute('data-modal-target') + '"]'), true);
            return;
        }

        var closeTrigger = event.target.closest('[data-modal-close]');
        if (closeTrigger) {
            event.preventDefault();
            setModalState(closeTrigger.closest('.asdl-fin-modal'), false);
            return;
        }

        var target = event.target.closest('.asdl-fin-copy-route');
        if (target) {
            event.preventDefault();
            if (!target.dataset.originalLabel) {
                target.dataset.originalLabel = target.textContent;
            }
            copyText(target.getAttribute('data-copy'));
            target.textContent = 'Ruta copiada';

            window.setTimeout(function () {
                target.textContent = target.dataset.originalLabel || 'Copiar ruta';
            }, 1200);
            return;
        }

        var printTrigger = event.target.closest('.asdl-fin-print-trigger');
        if (printTrigger) {
            event.preventDefault();
            window.print();
            return;
        }

        var selectMedia = event.target.closest('.asdl-fin-select-media');
        if (selectMedia && window.wp && wp.media) {
            event.preventDefault();
            var targetInput = document.getElementById(selectMedia.getAttribute('data-target-input'));
            var targetPreview = document.getElementById(selectMedia.getAttribute('data-target-preview'));
            var targetLabel = document.getElementById(selectMedia.getAttribute('data-target-label'));
            var targetLink = document.getElementById(selectMedia.getAttribute('data-target-link'));
            var mediaType = selectMedia.getAttribute('data-media-type');
            var frameConfig = {
                title: selectMedia.getAttribute('data-frame-title') || 'Seleccionar archivo',
                button: {
                    text: selectMedia.getAttribute('data-button-text') || 'Usar archivo'
                },
                multiple: false
            };

            if (mediaType) {
                frameConfig.library = { type: mediaType };
            } else if (targetPreview) {
                frameConfig.library = { type: 'image' };
            }

            var frame = wp.media(frameConfig);

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                var attachmentUrl = attachment.url || '';
                var isImage = attachment.type === 'image' || /\.(png|jpe?g|gif|webp|svg)$/i.test(attachmentUrl);

                if (targetInput) {
                    targetInput.value = attachment.id || '';
                }
                if (targetPreview) {
                    targetPreview.src = isImage ? attachmentUrl : '';
                    targetPreview.hidden = !isImage;
                }
                if (targetLabel) {
                    targetLabel.textContent = attachment.filename || attachment.title || ('Archivo #' + (attachment.id || ''));
                }
                if (targetLink) {
                    if (attachmentUrl) {
                        targetLink.href = attachmentUrl;
                        targetLink.hidden = false;
                    } else {
                        targetLink.removeAttribute('href');
                        targetLink.hidden = true;
                    }
                }
            });

            frame.open();
            return;
        }

        var clearMedia = event.target.closest('.asdl-fin-clear-media');
        if (clearMedia) {
            event.preventDefault();
            var clearInput = document.getElementById(clearMedia.getAttribute('data-target-input'));
            var clearPreview = document.getElementById(clearMedia.getAttribute('data-target-preview'));
            var clearLabel = document.getElementById(clearMedia.getAttribute('data-target-label'));
            var clearLink = document.getElementById(clearMedia.getAttribute('data-target-link'));
            if (clearInput) {
                clearInput.value = '';
            }
            if (clearPreview) {
                clearPreview.src = '';
                clearPreview.hidden = true;
            }
            if (clearLabel) {
                clearLabel.textContent = clearMedia.getAttribute('data-empty-label') || 'Sin archivo cargado';
            }
            if (clearLink) {
                clearLink.removeAttribute('href');
                clearLink.hidden = true;
            }
        }
    });

    document.querySelectorAll('.asdl-fin-employee-profile-form').forEach(function (form) {
        toggleEmployeePayrollFields(form);

        ['input', 'change'].forEach(function (eventName) {
            form.addEventListener(eventName, function (event) {
                if (event.target && event.target.matches(
                    '[data-employee-frequency-select], ' +
                    '[data-employee-status-select], ' +
                    '[data-employee-contract-type-select], ' +
                    '[data-employee-payday-weekday], ' +
                    '[data-employee-payday-monthday], ' +
                    '[data-employee-cycle-anchor], ' +
                    '[data-employee-effective-from], ' +
                    '[data-employee-hire-date], ' +
                    '[data-employee-next-payment-input], ' +
                    '[data-employee-default-account-select], ' +
                    '[data-employee-salary-amount], ' +
                    '[data-employee-salary-currency], ' +
                    '[data-employee-contract-end-input]'
                )) {
                    toggleEmployeePayrollFields(form);
                }
            });
        });
    });

    toggleReceiptLogoFields(document);

    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('[name="logo_mode"]')) {
            toggleReceiptLogoFields(document);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.asdl-fin-modal:not([hidden])').forEach(function (modal) {
            setModalState(modal, false);
        });
    });

    document.addEventListener('click', function (event) {
        var retryButton = event.target.closest('[data-runtime-retry]');
        if (!retryButton) {
            return;
        }

        var container = retryButton.closest('[data-runtime-action]');
        if (!container) {
            return;
        }

        event.preventDefault();
        delete container.dataset.runtimeLoaded;
        loadRuntimeContainer(container);
    });

    setupContactSearch();
    setupContactPickers(document);
    setupWpUserPickers(document);
    setupSupplierKindToggles(document);
    setupProfileContextDisclosures(document);
    setupInlineTabs(document);
    setupConsumptionHistorySelectors(document);
    setupDateWeekdayHelpers();
    setupAdminRuntimeLoaders(document);
    setupDashboardQueueFilters();
    setupDashboardTables();
    setupSortableStaticTables();
    setupOrderSettlementPreview();
    setupOrderAssumptionModal();
    setupPayrollPaymentModal();
    setupHistoricalTools();
    setupPayrollManualSettlementModal();
    setupCommitmentForms();
    setupSalaryAdvanceForms();
    setupPayrollPeriodForms();
})();
