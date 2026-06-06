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

    function parseNumber(value) {
        var normalized = String(value || '').replace(',', '.');
        var numeric = Number(normalized);
        return Number.isFinite(numeric) ? numeric : 0;
    }

    function normalizeSearchText(value) {
        var normalized = String(value || '').toLowerCase().trim();

        if (!normalized) {
            return '';
        }

        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        return normalized
            .replace(/[^a-z0-9]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function tokenizeSearchQuery(query) {
        var tokens = normalizeSearchText(query).split(/\s+/).filter(Boolean);

        return tokens.filter(function (token, index) {
            return tokens.indexOf(token) === index;
        });
    }

    function matchesFlexibleSearch(query, haystack) {
        var tokens = tokenizeSearchQuery(query);
        var normalizedHaystack;

        if (!tokens.length) {
            return true;
        }

        normalizedHaystack = normalizeSearchText(haystack);
        if (!normalizedHaystack) {
            return false;
        }

        return tokens.every(function (token) {
            return normalizedHaystack.indexOf(token) !== -1;
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
        }).catch(function (error) {
            var message = (error && error.message) || '';

            if (!message || message === 'Failed to fetch') {
                message = 'Se interrumpio la conexion con el servidor. Revisa tu red y vuelve a intentarlo.';
            }

            throw new Error(message);
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

            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    if (item === undefined || item === null || item === '') {
                        return;
                    }

                    body.append(key, item);
                });
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
                    var error = new Error((json && json.data && json.data.message) || 'No se pudo completar esta accion.');
                    if (json && json.data && typeof json.data === 'object') {
                        Object.keys(json.data).forEach(function (key) {
                            error[key] = json.data[key];
                        });
                    }
                    throw error;
                }

                return json.data || {};
            });
        }).catch(function (error) {
            var wrapped;
            var message = (error && error.message) || '';

            if (!message || message === 'Failed to fetch') {
                message = 'Se interrumpio la conexion con el servidor. Revisa tu red y vuelve a intentarlo.';
            }

            wrapped = new Error(message);

            if (error && typeof error === 'object') {
                Object.keys(error).forEach(function (key) {
                    wrapped[key] = error[key];
                });
            }

            wrapped.message = message;
            throw wrapped;
        });
    }

    function buildAsyncProgressBar(current, total) {
        var safeTotal = Math.max(0, Number(total || 0));
        var safeCurrent = Math.max(0, Number(current || 0));
        var percent = safeTotal > 0 ? Math.min(100, Math.round((safeCurrent / safeTotal) * 100)) : 0;

        return ''
            + '<div class="asdl-fin-tool-progress-bar" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100">'
            + '<span style="width:' + percent + '%"></span>'
            + '</div>';
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

    function normalizeRuntimeRefreshPlan(plan) {
        function normalizeTokens(values) {
            if (!Array.isArray(values)) {
                values = values ? [values] : [];
            }

            return values.map(function (value) {
                return String(value || '').trim();
            }).filter(Boolean).filter(function (value, index, items) {
                return items.indexOf(value) === index;
            });
        }

        return {
            page_keys: normalizeTokens(plan && plan.page_keys),
            groups: normalizeTokens(plan && plan.groups),
            sections: normalizeTokens(plan && plan.sections),
            contact_id: Number((plan && plan.contact_id) || 0),
            fallback_reload: !!(plan && plan.fallback_reload)
        };
    }

    function matchesRuntimeRefreshTarget(container, plan) {
        var pageKey;
        var group;
        var section;
        var contactId;

        if (!(container instanceof Element)) {
            return false;
        }

        pageKey = String(container.getAttribute('data-runtime-param-page-key') || '');
        group = String(container.getAttribute('data-runtime-group') || '');
        section = String(container.getAttribute('data-runtime-param-section-key') || '');
        contactId = Number(container.getAttribute('data-runtime-param-contact-id') || 0);

        if (plan.page_keys.length && plan.page_keys.indexOf(pageKey) === -1) {
            return false;
        }

        if (plan.contact_id > 0 && pageKey === 'contacts' && contactId > 0 && contactId !== plan.contact_id) {
            return false;
        }

        if (plan.sections.length && (pageKey === 'contacts' || group === 'contacts-detail')) {
            if (!section || plan.sections.indexOf(section) === -1) {
                return false;
            }
        }

        if (plan.groups.length && (!group || plan.groups.indexOf(group) === -1)) {
            return false;
        }

        if (!plan.page_keys.length && !plan.groups.length && !plan.sections.length && plan.contact_id <= 0) {
            return false;
        }

        return true;
    }

    function refreshRuntimeTargets(plan) {
        var normalized = normalizeRuntimeRefreshPlan(plan);
        var containers = Array.prototype.slice.call(document.querySelectorAll('[data-runtime-action="asdl_fin_admin_runtime"]')).filter(function (container) {
            return matchesRuntimeRefreshTarget(container, normalized);
        });

        if (!containers.length) {
            if (normalized.fallback_reload) {
                window.location.reload();
            }

            return Promise.resolve();
        }

        return Promise.allSettled(containers.map(function (container) {
            delete container.dataset.runtimeLoaded;
            delete container.dataset.runtimeLoading;
            container.classList.add('is-runtime-loading');
            return loadRuntimeContainer(container);
        })).then(function () {
            return undefined;
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
        setupOrderSettlementPreviewForms();
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
                    search: searchInput ? searchInput.value.trim() : '',
                    origin: originSelect ? originSelect.value.trim() : '',
                    range: rangeSelect ? rangeSelect.value.trim() : ''
                };
            }

            function matchesRow(row, filters) {
                var searchText = String(row.dataset.searchText || '');
                var origins = String(row.dataset.originFlags || '').split(/\s+/).filter(Boolean);
                var ranges = String(row.dataset.rangeFlags || '').split(/\s+/).filter(Boolean);

                if (filters.search && !matchesFlexibleSearch(filters.search, searchText)) {
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

    function isModalInteractionLocked(modal) {
        return !!(modal && modal.dataset.modalLocked === '1');
    }

    function setModalInteractionLock(modal, locked, message, titleText) {
        var lockBox;
        var lockTitle;
        var lockMessage;

        if (!modal) {
            return;
        }

        lockBox = modal.querySelector('[data-modal-lock-box]');
        lockTitle = lockBox ? lockBox.querySelector('[data-modal-lock-title]') : null;
        lockMessage = lockBox ? lockBox.querySelector('[data-modal-lock-message]') : null;

        if (locked) {
            modal.dataset.modalLocked = '1';
        } else {
            delete modal.dataset.modalLocked;
        }

        if (lockBox) {
            lockBox.hidden = !locked;
        }

        if (lockTitle) {
            lockTitle.textContent = titleText || 'Procesando abono';
        }

        if (lockMessage) {
            lockMessage.textContent = message || 'Procesando abono, no cierres esta ventana.';
        }

        modal.querySelectorAll('[data-modal-close]').forEach(function (node) {
            if (node && typeof node.disabled !== 'undefined') {
                node.disabled = !!locked;
            }
        });
    }

    function setModalState(modal, isOpen, options) {
        options = options || {};

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

        if (!options.force && isModalInteractionLocked(modal)) {
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

    function settlementDiscountDetection(item) {
        if (item && item.discount_detection && typeof item.discount_detection === 'object') {
            return item.discount_detection;
        }

        if (item && item.meta && item.meta.discount_detection && typeof item.meta.discount_detection === 'object') {
            return item.meta.discount_detection;
        }

        return {};
    }

    function settlementDiscountStatus(item) {
        return String(settlementDiscountDetection(item).status || 'none');
    }

    function settlementDualStatus(preview) {
        if (preview && preview.dual_status && typeof preview.dual_status === 'object') {
            return preview.dual_status;
        }

        return { key: 'unknown', label: '' };
    }

    function settlementExecutionBlocked(preview) {
        return !!(preview && preview.execution_blocked);
    }

    function settlementExecutionBlockedMessage(preview) {
        return preview && preview.execution_blocked_message
            ? String(preview.execution_blocked_message)
            : 'La configuracion actual no permite confirmar este abono.';
    }

    function settlementExtraordinaryState(preview) {
        if (preview && preview.extraordinary_closure && typeof preview.extraordinary_closure === 'object') {
            return preview.extraordinary_closure;
        }

        return {
            allowed: false,
            enabled: false,
            available: false,
            selected_item_key: '',
            selected_order_id: 0,
            selected_order_label: '',
            selected_balance: 0,
            payment_total: 0,
            difference_total: 0,
            applied_total: 0,
            reason: '',
            reason_label: '',
            approval_reference: '',
            note: '',
            acknowledged: false,
            message: ''
        };
    }

    function settlementApprovalGate(preview) {
        return operationalApprovalGateState(preview && preview.approval_gate ? preview.approval_gate : {});
    }

    function settlementExtraordinaryReasonOptions() {
        return [
            { key: 'cierre_administrativo', label: 'Cierre administrativo' },
            { key: 'diferencia_aprobada', label: 'Diferencia aprobada' },
            { key: 'cortesia_comercial', label: 'Cortesia comercial' },
            { key: 'ajuste_operativo', label: 'Ajuste operativo' }
        ];
    }

    function renderSettlementExtraordinaryReasonOptions(selectedKey) {
        return settlementExtraordinaryReasonOptions().map(function (option) {
            var selected = String(option.key || '') === String(selectedKey || '') ? ' selected' : '';

            return '<option value="' + escapeHtml(option.key) + '"' + selected + '>' + escapeHtml(option.label) + '</option>';
        }).join('');
    }

    function buildSettlementExtraordinaryPanel(preview, options) {
        options = options || {};
        var extraordinary = settlementExtraordinaryState(preview);
        var currency = preview && preview.currency ? preview.currency : 'USD';
        var enabled = !!extraordinary.enabled;
        var available = !!extraordinary.available;
        var selectedLabel = extraordinary.selected_order_label || 'Pedido seleccionado';
        var toggleDisabled = !available && !enabled;
        var previewDirty = !!options.previewDirty;
        var selectedCount = Number(options.selectedCount || 0);
        var blockedMessage = extraordinary && extraordinary.execution_blocked_message
            ? String(extraordinary.execution_blocked_message)
            : '';
        var helperMessage = extraordinary.message
            ? '<small>' + escapeHtml(String(extraordinary.message)) + '</small>'
            : '<small>Este ajuste no representa dinero recibido. Se registra como cierre administrativo, deja una traza adicional en Movimientos y puede prepararse sin metodo cuando no hay abono real.</small>';
        var stateNote = available
            ? '<div class="asdl-fin-note-box"><strong>Pedido especifico.</strong><div>Este modo solo cierra la diferencia del pedido marcado y no toca otras facturas del perfil. Si no hubo entrada de caja, puedes dejar el metodo vacio y confirmar solo el ajuste administrativo.</div></div>'
            : '<div class="asdl-fin-note-box"><strong>No disponible.</strong><div>' + escapeHtml(String(extraordinary.message || 'Marca un solo pedido con diferencia pendiente para usar este cierre.')) + '</div></div>';
        var blockedNote = enabled && blockedMessage
            ? '<div class="asdl-fin-note-box asdl-fin-settlement-preview-error"><strong>Falta completar el cierre extraordinario.</strong><div>' + escapeHtml(blockedMessage) + '</div></div>'
            : '';

        if ((!extraordinary.allowed && !enabled) || (!enabled && !available && Number(extraordinary.selected_balance || 0) <= 0)) {
            return '';
        }

        if (previewDirty) {
            return ''
                + '<div class="asdl-fin-note-box asdl-fin-settlement-extraordinary-box" data-settlement-extraordinary-panel>'
                + '<strong>Cierre extraordinario del pedido</strong>'
                + '<div>La seleccion cambio y este panel todavia no puede activar el cierre porque los montos pertenecen a la vista previa anterior. La vista se recalculara automaticamente cuando quede un solo pedido marcado.</div>'
                + '<div class="asdl-fin-note-box">'
                + '<strong>' + escapeHtml(selectedCount === 1 ? 'Un pedido marcado.' : 'Seleccion no valida para cierre extraordinario.') + '</strong>'
                + '<div>' + escapeHtml(selectedCount === 1
                    ? 'Estamos recalculando este pedido; si no arranca la carga, pulsa Actualizar vista. Despues se habilitara la activacion del cierre extraordinario.'
                    : 'Marca exactamente un pedido y actualiza la vista para habilitar el cierre extraordinario.') + '</div>'
                + '</div>'
                + '</div>';
        }

        return ''
            + '<div class="asdl-fin-note-box asdl-fin-settlement-extraordinary-box" data-settlement-extraordinary-panel>'
            + '<strong>Cierre extraordinario del pedido</strong>'
            + '<div>Usalo cuando necesitas extinguir administrativamente el saldo restante del pedido seleccionado, con o sin un abono real en esta misma corrida.</div>'
            + '<div class="asdl-fin-settlement-preview-summary">'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedido</strong><span>' + escapeHtml(selectedLabel) + '</span><small>' + escapeHtml(Number(extraordinary.selected_order_id || 0) > 0 ? 'Orden #' + String(extraordinary.selected_order_id || 0) : 'Seleccion actual') + '</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Saldo del pedido</strong><span>' + escapeHtml(formatCurrencyAmount(extraordinary.selected_balance || 0, currency)) + '</span><small>Saldo abierto antes del cierre.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Abono real</strong><span>' + escapeHtml(formatCurrencyAmount(extraordinary.payment_total || 0, currency)) + '</span><small>Dinero recibido de verdad.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Diferencia extraordinaria</strong><span>' + escapeHtml(formatCurrencyAmount(extraordinary.difference_total || 0, currency)) + '</span><small>Tramo administrativo para dejar el pedido en cero.</small></div>'
            + '</div>'
            + stateNote
            + blockedNote
            + '<div class="asdl-fin-form-grid">'
            + '<div class="asdl-fin-field asdl-fin-field-wide">'
            + '<span>Activacion</span>'
            + '<label class="asdl-fin-inline-checkbox"><input type="checkbox" data-settlement-extraordinary-toggle ' + (enabled ? 'checked' : '') + (toggleDisabled ? ' disabled' : '') + ' /> <span>Cerrar diferencia extraordinariamente</span></label>'
            + helperMessage
            + '</div>'
            + (enabled
                ? ''
                    + '<label class="asdl-fin-field">'
                    + '<span>Motivo</span>'
                    + '<select data-settlement-extraordinary-reason-input>'
                    + '<option value="">Selecciona un motivo</option>'
                    + renderSettlementExtraordinaryReasonOptions(extraordinary.reason || '')
                    + '</select>'
                    + '</label>'
                    + '<label class="asdl-fin-field asdl-fin-field-wide">'
                    + '<span>Nota administrativa</span>'
                    + '<textarea rows="3" data-settlement-extraordinary-note-input placeholder="Explica por que la diferencia se cerrara manualmente.">' + escapeHtml(extraordinary.note || '') + '</textarea>'
                    + '<small>Esta nota se guardara en el ajuste, en el lote y como movimiento manual del perfil.</small>'
                    + '</label>'
                    + '<label class="asdl-fin-field asdl-fin-field-wide">'
                    + '<span>Confirmacion</span>'
                    + '<label class="asdl-fin-inline-checkbox"><input type="checkbox" data-settlement-extraordinary-ack-input ' + (extraordinary.acknowledged ? 'checked' : '') + ' /> <span>Confirmo que la diferencia no corresponde a dinero recibido.</span></label>'
                    + '</label>'
                : '')
            + '</div>'
            + '</div>';
    }

    function operationalApprovalGateState(gate) {
        var payload = gate && typeof gate === 'object' ? gate : {};

        return {
            action_key: String(payload.action_key || ''),
            plugin_available: !!payload.plugin_available,
            requires_approval: !!payload.requires_approval,
            can_bypass: !!payload.can_bypass,
            allow_self_approval: !!payload.allow_self_approval,
            actor_user_id: Number(payload.actor_user_id || 0),
            eligible_approvers: Array.isArray(payload.eligible_approvers) ? payload.eligible_approvers : [],
            token_ttl_seconds: Number(payload.token_ttl_seconds || 300),
            message: String(payload.message || ''),
            single_actor_approver: !!payload.single_actor_approver
        };
    }

    function operationalApprovalNeedsToken(gate) {
        var state = operationalApprovalGateState(gate);
        return state.requires_approval && !state.can_bypass;
    }

    function operationalApprovalState(state) {
        var payload = state && typeof state === 'object' ? state : {};

        return {
            token: String(payload.token || ''),
            expiresAt: String(payload.expiresAt || ''),
            approverUserId: String(payload.approverUserId || ''),
            approverLabel: String(payload.approverLabel || ''),
            verificationMethod: String(payload.verificationMethod || ''),
            pending: !!payload.pending,
            error: String(payload.error || ''),
            message: String(payload.message || '')
        };
    }

    function operationalApprovalHasToken(state) {
        return !!operationalApprovalState(state).token;
    }

    function operationalApprovalResolvedApproverId(gate, state) {
        var gateState = operationalApprovalGateState(gate);
        var approvalState = operationalApprovalState(state);

        if (approvalState.approverUserId) {
            return approvalState.approverUserId;
        }

        if (gateState.eligible_approvers.length === 1) {
            return String(gateState.eligible_approvers[0].id || '');
        }

        return '';
    }

    function operationalApprovalResolvedApproverLabel(gate, state) {
        var gateState = operationalApprovalGateState(gate);
        var approvalState = operationalApprovalState(state);
        var approverId = operationalApprovalResolvedApproverId(gateState, approvalState);
        var label = approvalState.approverLabel;

        if (label) {
            return label;
        }

        gateState.eligible_approvers.some(function (approver) {
            if (String(approver.id || '') === String(approverId)) {
                label = String(approver.display_name || approver.user_login || approver.user_email || '');
                return true;
            }
            return false;
        });

        return label;
    }

    function buildOperationalApprovalPanel(prefix, gate, state, options) {
        var gateState = operationalApprovalGateState(gate);
        var approvalState = operationalApprovalState(state);
        var optionsState = options && typeof options === 'object' ? options : {};
        var panelTitle = String(optionsState.title || 'Validacion operativa');
        var scopeLabel = String(optionsState.scopeLabel || 'esta accion');
        var defaultMessage = gateState.message || ('Debes validar ' + scopeLabel + ' con tu autenticador antes de ejecutarla.');
        var helpMessage = String(optionsState.helpMessage || 'Si cambias la seleccion, la firma o recalculas la vista previa, se pedira validar otra vez.');
        var approverId = operationalApprovalResolvedApproverId(gateState, approvalState);
        var approverLabel = operationalApprovalResolvedApproverLabel(gateState, approvalState);
        var needsSelector = gateState.eligible_approvers.length > 1 && !gateState.single_actor_approver;
        var approvedTone = approvalState.error ? 'danger' : (operationalApprovalHasToken(approvalState) ? 'success' : 'warning');
        var approvedMessage = operationalApprovalHasToken(approvalState)
            ? 'Validacion TOTP lista para ejecutar.'
            : defaultMessage;

        if (!operationalApprovalNeedsToken(gateState)) {
            return '';
        }

        return ''
            + '<div class="asdl-fin-note-box asdl-fin-note-box-' + escapeHtml(approvedTone) + '" data-' + escapeHtml(prefix) + '-approval-panel>'
            + '<strong>' + escapeHtml(panelTitle) + '</strong>'
            + '<div>' + escapeHtml(approvalState.error || approvalState.message || approvedMessage) + '</div>'
            + (operationalApprovalHasToken(approvalState)
                ? '<div class="asdl-fin-approval-inline-meta">'
                    + '<span>' + renderPill('Validado', 'success') + '</span>'
                    + (approverLabel ? '<span><strong>Aprobador:</strong> ' + escapeHtml(approverLabel) + '</span>' : '')
                    + (approvalState.expiresAt ? '<span><strong>Vence:</strong> ' + escapeHtml(formatPreviewDateLabel(approvalState.expiresAt)) + '</span>' : '')
                    + (approvalState.verificationMethod ? '<span><strong>Metodo:</strong> ' + escapeHtml(approvalState.verificationMethod) + '</span>' : '')
                    + '</div>'
                : (
                    gateState.plugin_available
                        ? '<div class="asdl-fin-form-grid asdl-fin-approval-inline-grid">'
                            + (needsSelector
                                ? '<label class="asdl-fin-field">'
                                    + '<span>Aprobador</span>'
                                    + '<select data-' + escapeHtml(prefix) + '-approval-approver>'
                                    + '<option value="">Selecciona un aprobador</option>'
                                    + gateState.eligible_approvers.map(function (approver) {
                                        var selected = String(approver.id || '') === String(approverId) ? ' selected' : '';
                                        var approverText = String(approver.display_name || approver.user_login || approver.user_email || '');
                                        return '<option value="' + escapeHtml(String(approver.id || '')) + '"' + selected + '>' + escapeHtml(approverText) + '</option>';
                                    }).join('')
                                    + '</select>'
                                    + '</label>'
                                : '<div class="asdl-fin-field"><span>Aprobador</span><div class="asdl-fin-note-box">' + escapeHtml(approverLabel || 'Aprobador listo') + '</div></div>')
                            + '<label class="asdl-fin-field">'
                                + '<span>Codigo del autenticador</span>'
                                + '<input type="text" inputmode="numeric" autocomplete="one-time-code" data-' + escapeHtml(prefix) + '-approval-code placeholder="123456 o codigo de respaldo" />'
                            + '</label>'
                            + '<div class="asdl-fin-field asdl-fin-field-wide">'
                                + '<div class="asdl-fin-inline-actions">'
                                    + '<button type="button" class="button button-secondary" data-' + escapeHtml(prefix) + '-approval-validate ' + (approvalState.pending ? 'disabled' : '') + '>' + escapeHtml(approvalState.pending ? 'Validando...' : 'Validar con autenticador') + '</button>'
                                + '</div>'
                                + '<small>' + escapeHtml(helpMessage) + '</small>'
                            + '</div>'
                        + '</div>'
                        : '<div class="asdl-fin-note-box asdl-fin-note-box-danger"><strong>Plugin de aprobaciones no disponible.</strong><div>' + escapeHtml(defaultMessage) + '</div></div>'
                ))
            + '</div>';
    }

    function requestOperationalApprovalInline(options) {
        var api = window.ASDLOperationalApprovals || {};
        var requestOptions = options && typeof options === 'object' ? options : {};
        var approverUserId = String(requestOptions.approverUserId || '').trim();
        var code = String(requestOptions.code || '').trim();

        if (!api || typeof api.requestChallenge !== 'function' || typeof api.verifyChallenge !== 'function') {
            return Promise.reject(new Error('La capa de aprobaciones operativas no esta disponible en esta pantalla.'));
        }

        if (!code) {
            return Promise.reject(new Error('Ingresa el codigo de tu autenticador antes de validar la accion.'));
        }

        return api.requestChallenge({
            actionKey: requestOptions.actionKey,
            payload: requestOptions.payload || {},
            reason: requestOptions.reason || '',
            targetPlugin: requestOptions.targetPlugin || '',
            targetEntityType: requestOptions.targetEntityType || '',
            targetEntityId: requestOptions.targetEntityId || ''
        }).then(function (challenge) {
            var resolvedApprover = approverUserId
                || (
                    Array.isArray(challenge && challenge.eligible_approvers)
                    && challenge.eligible_approvers.length === 1
                    ? String(challenge.eligible_approvers[0].id || '')
                    : ''
                );

            if (challenge && challenge.mode === 'bypass_available') {
                return challenge;
            }

            if (!resolvedApprover) {
                throw new Error('Selecciona un aprobador antes de validar esta accion.');
            }

            return api.verifyChallenge({
                challengeId: challenge.challenge_id,
                approverUserId: resolvedApprover,
                code: code
            });
        });
    }

    function settlementDualReason(preview) {
        var status = settlementDualStatus(preview);
        if (status && status.key) {
            return status;
        }

        var dualMode = preview && preview.dual_discount_mode ? String(preview.dual_discount_mode) : ((preview && preview.force_dual_discount) ? 'force' : 'off');
        var currency = String(preview && preview.currency ? preview.currency : '').trim().toUpperCase();
        var methodKey = preview && preview.payment_method ? String(preview.payment_method.key || '') : '';
        var config = getDualPricingConfig();

        if (dualMode === 'off') {
            return { key: 'off', label: 'Descuento automatico apagado' };
        }

        if (!config.active) {
            return { key: 'global_off', label: 'El descuento general esta apagado' };
        }

        if (currency !== 'USD') {
            return { key: 'currency', label: 'La moneda registrada no es USD' };
        }

        if (!methodKey) {
            return { key: 'method_missing', label: 'Falta confirmar el metodo final' };
        }

        if (!settlementMethodQualifiesForDual(methodKey, currency)) {
            return { key: 'method', label: 'El metodo no califica para precio dual' };
        }

        if (dualMode === 'force') {
            return { key: 'force', label: 'Precio dual forzado' };
        }

        return { key: 'active', label: 'Precio dual activo' };
    }

    function settlementDualNetNeeded(item, preview) {
        var fraction = Number(preview && preview.discount ? (preview.discount.fraction || 0) : 0);
        var balance = Number(item && (item.balance_before !== undefined ? item.balance_before : item.document_balance) || 0);
        var factor = 1 - Math.max(0, Math.min(0.95, fraction));

        if (factor <= 0 || balance <= 0) {
            return 0;
        }

        return Math.ceil((balance * factor) * 100) / 100;
    }

    function settlementDualShortfallHelp(item, preview) {
        if (!preview || !preview.uses_dual || !item) {
            return '';
        }

        if (String(item.status_key || '') === 'closed') {
            return '';
        }

        var needed = settlementDualNetNeeded(item, preview);
        var paid = Number(item.payment_applied_total || item.customer_paid_amount || 0);

        if (needed <= 0 || paid <= 0 || paid >= needed - 0.00001) {
            return '';
        }

        return 'Para cerrarlo con precio dual hacian falta aprox. ' + formatCurrencyAmount(needed, item.currency || (preview && preview.currency) || 'USD') + '.';
    }

    function settlementDiscountUi(item, preview) {
        var detection = settlementDiscountDetection(item);
        var status = String(detection.status || 'none');
        var discountAmount = Number(item && (item.discount_effective_amount || item.discount_applied_total || 0) || 0);
        var dualReason = settlementDualReason(preview);
        var shortfallHelp = settlementDualShortfallHelp(item, preview);

        if (status === 'same_dual') {
            return {
                label: detection.label || 'Descuento dual ya aplicado',
                tone: 'success',
                help: 'Ya estaba aplicado. No se descuenta otra vez.'
            };
        }

        if (status === 'different') {
            return {
                label: detection.label || 'Descuento previo distinto',
                tone: 'warning',
                help: 'Tiene otro descuento previo. Se conserva sin rebaja adicional.'
            };
        }

        if (discountAmount > 0.00001) {
            return {
                label: 'Precio dual aplicado ahora',
                tone: 'success',
                help: shortfallHelp || ('Rebaja aplicada en esta vista: ' + formatCurrencyAmount(discountAmount, item && item.currency ? item.currency : (preview && preview.currency) || 'USD') + '.')
            };
        }

        switch (String(dualReason.key || '')) {
            case 'off':
                return {
                    label: 'Precio dual apagado',
                    tone: 'neutral',
                    help: 'Esta vista se calculo sin rebaja automatica.'
                };
            case 'global_off':
                return {
                    label: dualReason.label,
                    tone: 'warning',
                    help: 'Aunque el abono este en USD, la rebaja general esta apagada.'
                };
            case 'currency':
                return {
                    label: dualReason.label,
                    tone: 'warning',
                    help: 'El precio dual solo aplica cuando el abono se registra en USD.'
                };
            case 'method':
            case 'method_missing':
                return {
                    label: dualReason.label,
                    tone: 'warning',
                    help: settlementExecutionBlocked(preview)
                        ? settlementExecutionBlockedMessage(preview)
                        : 'Prueba con un metodo elegible o deja el abono normal sin rebaja.'
                };
            case 'force':
            case 'active':
                return {
                    label: 'Sin descuento previo',
                    tone: 'neutral',
                    help: shortfallHelp
                };
            default:
                return {
                    label: detection.label || 'Sin descuento previo',
                    tone: 'neutral',
                    help: shortfallHelp
                };
        }
    }

    function settlementDiscountLabel(item, preview) {
        return settlementDiscountUi(item, preview).label;
    }

    function settlementDiscountTone(item, preview) {
        return settlementDiscountUi(item, preview).tone;
    }

    function settlementDiscountHelp(item, preview) {
        return settlementDiscountUi(item, preview).help;
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

    function setupInlinePaymentMethodModal() {
        var setupRoot = document.documentElement;
        var modal = document.querySelector('[data-modal="payment-method"]');
        var form = modal ? modal.querySelector('[data-payment-method-inline-form]') : null;
        var feedback = form ? form.querySelector('[data-payment-method-inline-feedback]') : null;
        var nameInput = form ? form.querySelector('[name="payment_method_name"]') : null;
        var keyInput = form ? form.querySelector('[data-payment-method-key]') : null;
        var dualCheckbox = form ? form.querySelector('[data-payment-method-dual-eligible]') : null;
        var titleNode = modal ? modal.querySelector('[data-payment-method-modal-title]') : null;
        var descriptionNode = modal ? modal.querySelector('[data-payment-method-modal-description]') : null;
        var canonicalBox = modal ? modal.querySelector('[data-payment-method-canonical-box]') : null;
        var canonicalKeyNode = canonicalBox ? canonicalBox.querySelector('[data-payment-method-canonical-key]') : null;
        var canonicalHelpNode = canonicalBox ? canonicalBox.querySelector('[data-payment-method-canonical-help]') : null;
        var catalogFeedback = document.querySelector('[data-payment-method-catalog-feedback]');
        var tableBody = document.querySelector('[data-payment-methods-table] tbody');
        var paymentConfig = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.paymentMethods) || {};
        var defaultLabels = paymentConfig && typeof paymentConfig.defaultLabels === 'object' ? paymentConfig.defaultLabels : {};
        var aliasMap = paymentConfig && typeof paymentConfig.aliasMap === 'object' ? paymentConfig.aliasMap : {};
        var actionNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.actionNonces) || {};
        var state = {
            activeSelect: null,
            activeRow: null,
            activeKind: 'custom'
        };
        var copy = {
            createTitle: 'Agregar metodo de pago',
            createDescription: 'Este metodo quedara disponible en cobros, pagos, abonos y nomina. Si escribes un alias claro como Efectivo, Transferencia o Pago movil, se fusionara con el metodo base del catalogo ASD.',
            updateTitle: 'Configurar metodo de pago',
            updateDescription: 'Aqui decides si el metodo queda elegible para precio dual cuando la moneda del cobro o abono sea USD.'
        };

        function refreshRefs() {
            modal = document.querySelector('[data-modal="payment-method"]');
            form = modal ? modal.querySelector('[data-payment-method-inline-form]') : null;
            feedback = form ? form.querySelector('[data-payment-method-inline-feedback]') : null;
            nameInput = form ? form.querySelector('[name="payment_method_name"]') : null;
            keyInput = form ? form.querySelector('[data-payment-method-key]') : null;
            dualCheckbox = form ? form.querySelector('[data-payment-method-dual-eligible]') : null;
            titleNode = modal ? modal.querySelector('[data-payment-method-modal-title]') : null;
            descriptionNode = modal ? modal.querySelector('[data-payment-method-modal-description]') : null;
            canonicalBox = modal ? modal.querySelector('[data-payment-method-canonical-box]') : null;
            canonicalKeyNode = canonicalBox ? canonicalBox.querySelector('[data-payment-method-canonical-key]') : null;
            canonicalHelpNode = canonicalBox ? canonicalBox.querySelector('[data-payment-method-canonical-help]') : null;
            catalogFeedback = document.querySelector('[data-payment-method-catalog-feedback]');
            tableBody = document.querySelector('[data-payment-methods-table] tbody');
        }

        if (setupRoot.dataset.asdlFinPaymentMethodSetup === '1') {
            return;
        }

        setupRoot.dataset.asdlFinPaymentMethodSetup = '1';
        refreshRefs();

        function setFeedback(message, tone) {
            refreshRefs();
            if (!feedback) {
                return;
            }

            feedback.textContent = message || '';
            feedback.classList.toggle('is-hidden', !message);
            feedback.classList.toggle('is-error', tone === 'error');
            feedback.classList.toggle('is-success', tone === 'success');
        }

        function setCatalogFeedback(message, tone) {
            refreshRefs();
            if (!catalogFeedback) {
                return;
            }

            catalogFeedback.textContent = message || '';
            catalogFeedback.classList.toggle('is-hidden', !message);
            catalogFeedback.classList.toggle('is-error', tone === 'error');
            catalogFeedback.classList.toggle('is-success', tone === 'success');
        }

        function normalizeMethodToken(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9_]+/g, '');
        }

        function resolveMethodPreview(value) {
            var token = normalizeMethodToken(value);
            var key = '';

            if (!token) {
                return null;
            }

            if (defaultLabels[token]) {
                key = token;
            } else if (aliasMap[token]) {
                key = String(aliasMap[token] || '');
            }

            if (key) {
                return {
                    key: key,
                    label: String(defaultLabels[key] || key),
                    isDefault: true,
                    isAliasFusion: key !== token
                };
            }

            return {
                key: token,
                label: String(value || '').trim(),
                isDefault: false,
                isAliasFusion: false
            };
        }

        function updateCanonicalHint() {
            var preview;

            refreshRefs();
            if (!canonicalBox || !canonicalKeyNode || !canonicalHelpNode) {
                return;
            }

            preview = resolveMethodPreview(nameInput ? nameInput.value : '');

            if (state.activeKind === 'default' && keyInput && keyInput.value) {
                canonicalBox.classList.remove('is-hidden');
                canonicalKeyNode.textContent = String(keyInput.value || '');
                canonicalHelpNode.textContent = 'Este es un metodo base del catalogo ASD. Aqui solo cambias su elegibilidad USD y no se crea un duplicado.';
                return;
            }

            if (state.activeRow && state.activeKind === 'custom' && preview && preview.isDefault) {
                canonicalBox.classList.remove('is-hidden');
                canonicalKeyNode.textContent = preview.key;
                canonicalHelpNode.textContent = 'Este cambio fusionara el metodo con el base ' + preview.label + ' y dejara de existir como fila separada.';
                return;
            }

            if (state.activeRow && state.activeKind === 'custom' && keyInput && keyInput.value) {
                canonicalBox.classList.remove('is-hidden');
                canonicalKeyNode.textContent = String(keyInput.value || '');
                canonicalHelpNode.textContent = 'Este metodo conservara su clave actual; aqui cambias la etiqueta visible y su elegibilidad USD.';
                return;
            }

            if (!preview) {
                canonicalBox.classList.add('is-hidden');
                canonicalKeyNode.textContent = '';
                canonicalHelpNode.textContent = '';
                return;
            }

            canonicalBox.classList.remove('is-hidden');
            canonicalKeyNode.textContent = preview.key;

            if (preview.isDefault && preview.isAliasFusion) {
                canonicalHelpNode.textContent = 'Este nombre se fusionara con el metodo base ' + preview.label + ' y no creara otra fila separada.';
                return;
            }

            if (preview.isDefault) {
                canonicalHelpNode.textContent = 'Este nombre corresponde al metodo base ' + preview.label + ' del catalogo ASD.';
                return;
            }

            canonicalHelpNode.textContent = 'Este nombre se guardara como metodo propio del catalogo ASD.';
        }

        function upsertMethodOption(select, key, label) {
            if (!select || !key) {
                return;
            }

            var existing = Array.prototype.find.call(select.options || [], function (option) {
                return String(option.value || '') === String(key);
            });

            if (!existing) {
                existing = document.createElement('option');
                existing.value = key;
                select.appendChild(existing);
            }

            existing.textContent = label || key;
        }

        function setModalCopy(isEdit) {
            if (titleNode) {
                titleNode.textContent = isEdit ? copy.updateTitle : copy.createTitle;
            }

            if (descriptionNode) {
                descriptionNode.textContent = isEdit ? copy.updateDescription : copy.createDescription;
            }
        }

        function resetModalContext() {
            refreshRefs();
            state.activeSelect = null;
            state.activeRow = null;
            state.activeKind = 'custom';
            setFeedback('', '');
            setCatalogFeedback('', '');
            setModalCopy(false);

            if (keyInput) {
                keyInput.value = '';
            }

            if (nameInput) {
                nameInput.value = '';
                nameInput.readOnly = false;
            }

            if (dualCheckbox) {
                dualCheckbox.checked = false;
            }

            updateCanonicalHint();
        }

        function syncDualPricingConfig(payload) {
            if (!window.ASDLFinanceAdmin || !ASDLFinanceAdmin.dualPricing) {
                return;
            }

            if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
                ASDLFinanceAdmin.dualPricing.active = !!payload.active;
                ASDLFinanceAdmin.dualPricing.percent = Number(payload.percent || 0);
                ASDLFinanceAdmin.dualPricing.fraction = Number(payload.fraction || 0);
                ASDLFinanceAdmin.dualPricing.divisaMethodKeys = Array.isArray(payload.divisaMethodKeys)
                    ? payload.divisaMethodKeys.map(function (item) {
                        return String(item || '');
                    })
                    : [];
                ASDLFinanceAdmin.dualPricing.eligibilityByKey = payload.eligibilityByKey && typeof payload.eligibilityByKey === 'object'
                    ? payload.eligibilityByKey
                    : {};
                return;
            }

            ASDLFinanceAdmin.dualPricing.divisaMethodKeys = Array.isArray(payload)
                ? payload.map(function (item) {
                    return String(item || '');
                })
                : [];
            ASDLFinanceAdmin.dualPricing.eligibilityByKey = {};
        }

        function buildEligibilityPill(eligible) {
            return eligible
                ? '<span class="asdl-fin-pill asdl-fin-pill-success">Elegible</span>'
                : '<span class="asdl-fin-pill asdl-fin-pill-neutral">No elegible</span>';
        }

        function buildEditButton(method) {
            return ''
                + '<button'
                + ' type="button"'
                + ' class="button button-secondary asdl-fin-open-modal asdl-fin-payment-method-edit"'
                + ' data-modal-target="payment-method"'
                + ' data-payment-method-open="1"'
                + ' data-payment-method-edit="1"'
                + ' data-payment-method-key="' + escapeHtml(String(method.key || '')) + '"'
                + ' data-payment-method-label="' + escapeHtml(String(method.label || '')) + '"'
                + ' data-payment-method-dual="' + (method.dualEligible ? '1' : '0') + '"'
                + ' data-payment-method-kind="' + escapeHtml(String(method.kind || 'default')) + '"'
                + '>'
                + 'Configurar'
                + '</button>';
        }

        function upsertMethodRow(method) {
            var row;
            var labelCell;
            var eligibilityCell;
            var sourceCell;
            var actionCell;
            var selector;

            if (!tableBody || !method || !method.key) {
                return;
            }

            selector = 'tr[data-payment-method-row="' + String(method.key || '').replace(/"/g, '&quot;') + '"]';
            row = tableBody.querySelector(selector);

            if (!row) {
                row = document.createElement('tr');
                row.setAttribute('data-payment-method-row', String(method.key));
                row.innerHTML = ''
                    + '<td data-payment-method-label></td>'
                    + '<td><code></code></td>'
                    + '<td data-payment-method-eligibility></td>'
                    + '<td data-payment-method-source></td>'
                    + '<td data-payment-method-action></td>';
                tableBody.appendChild(row);
            }

            labelCell = row.querySelector('[data-payment-method-label]');
            eligibilityCell = row.querySelector('[data-payment-method-eligibility]');
            sourceCell = row.querySelector('[data-payment-method-source]');
            actionCell = row.querySelector('[data-payment-method-action]');

            if (labelCell) {
                labelCell.textContent = method.label || method.key;
            }

            if (row.children[1] && row.children[1].querySelector('code')) {
                row.children[1].querySelector('code').textContent = method.key;
            }

            if (eligibilityCell) {
                eligibilityCell.innerHTML = buildEligibilityPill(!!method.dualEligible);
            }

            if (sourceCell) {
                sourceCell.textContent = method.dualSourceLabel || 'No elegible';
            }

            if (actionCell) {
                actionCell.innerHTML = buildEditButton(method);
            }
        }

        document.addEventListener('click', function (event) {
            var editTrigger;
            var trigger = event.target && event.target.closest
                ? event.target.closest('[data-payment-method-open="1"]')
                : null;

            if (!trigger) {
                return;
            }

            event.preventDefault();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            refreshRefs();

            if (!modal || !form) {
                setCatalogFeedback('No se pudo abrir el formulario de metodos. Recarga la pagina e intenta de nuevo.', 'error');
                return;
            }

            editTrigger = trigger.matches('[data-payment-method-edit="1"]') ? trigger : null;

            resetModalContext();

            if (editTrigger) {
                state.activeRow = editTrigger.closest('tr');
                state.activeKind = String(editTrigger.getAttribute('data-payment-method-kind') || 'default');

                if (keyInput) {
                    keyInput.value = String(editTrigger.getAttribute('data-payment-method-key') || '');
                }

                if (nameInput) {
                    nameInput.value = String(editTrigger.getAttribute('data-payment-method-label') || '');
                    nameInput.readOnly = state.activeKind === 'default';
                }

                if (dualCheckbox) {
                    dualCheckbox.checked = String(editTrigger.getAttribute('data-payment-method-dual') || '') === '1';
                }

                setModalCopy(true);
            } else {
                var methodRow = trigger.closest('.asdl-fin-method-row');
                state.activeSelect = methodRow ? methodRow.querySelector('[data-payment-method-select]') : null;
            }

            updateCanonicalHint();
            setModalState(modal, true);

            window.setTimeout(function () {
                var focusField = nameInput && !nameInput.readOnly ? nameInput : dualCheckbox;
                if (focusField && typeof focusField.focus === 'function') {
                    focusField.focus();
                }
            }, 0);
        });

        document.addEventListener('input', function (event) {
            if (!event.target || !event.target.matches('[data-payment-method-inline-form] [name="payment_method_name"]')) {
                return;
            }

            updateCanonicalHint();
        });

        document.addEventListener('submit', function (event) {
            var currentForm = event.target && event.target.matches && event.target.matches('[data-payment-method-inline-form]')
                ? event.target
                : null;
            var submitButton;
            var methodName;
            if (!currentForm) {
                return;
            }

            refreshRefs();
            form = currentForm;
            if (!actionNonces.savePaymentMethodInline) {
                return;
            }

            event.preventDefault();

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }

            methodName = nameInput ? String(nameInput.value || '').trim() : '';
            if (!methodName) {
                setFeedback('Debes indicar un nombre valido para el metodo.', 'error');
                return;
            }

            submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            setFeedback('', '');
            setAsyncButtonState(submitButton, true, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar metodo') : 'Guardar metodo', 'Guardando metodo...');

            requestAdminAjax('asdl_fin_save_payment_method_inline', actionNonces.savePaymentMethodInline, {
                payment_method_name: methodName,
                payment_method_key: keyInput ? String(keyInput.value || '') : '',
                payment_method_dual_eligible: dualCheckbox && dualCheckbox.checked ? 1 : 0
            }).then(function (payload) {
                var method = payload && payload.method ? payload.method : {};
                var key = method.key || '';
                var label = method.label || method.key || methodName;
                var activeSelect = state.activeSelect;

                if (!key) {
                    throw new Error('No se pudo registrar el metodo.');
                }

                document.querySelectorAll('[data-payment-method-select]').forEach(function (select) {
                    upsertMethodOption(select, key, label);
                });

                upsertMethodRow(method);
                syncDualPricingConfig(payload && payload.dualPricing ? payload.dualPricing : (payload && payload.dualPricingMethodKeys ? payload.dualPricingMethodKeys : []));
                setCatalogFeedback((payload && payload.message) || 'Metodo guardado correctamente.', 'success');

                if (activeSelect) {
                    activeSelect.value = key;
                    activeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }

                resetModalContext();
                setAsyncButtonState(submitButton, false, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar metodo') : 'Guardar metodo');
                setModalState(modal, false);
            }).catch(function (error) {
                setAsyncButtonState(submitButton, false, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar metodo') : 'Guardar metodo');
                setFeedback((error && error.message) || 'No se pudo guardar el metodo.', 'error');
                setCatalogFeedback((error && error.message) || 'No se pudo guardar el metodo.', 'error');
            });
        });
    }

    function setupInlineCurrencyModal() {
        var setupRoot = document.documentElement;
        var modal = document.querySelector('[data-modal="currency"]');
        var form = modal ? modal.querySelector('[data-currency-inline-form]') : null;
        var feedback = form ? form.querySelector('[data-currency-inline-feedback]') : null;
        var codeInput = form ? form.querySelector('[name="currency_code"]') : null;
        var labelInput = form ? form.querySelector('[name="currency_label"]') : null;
        var catalogFeedback = document.querySelector('[data-currency-catalog-feedback]');
        var table = document.querySelector('[data-currencies-table]');
        var actionNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.actionNonces) || {};
        var activeSelect = null;

        function refreshRefs() {
            modal = document.querySelector('[data-modal="currency"]');
            form = modal ? modal.querySelector('[data-currency-inline-form]') : null;
            feedback = form ? form.querySelector('[data-currency-inline-feedback]') : null;
            codeInput = form ? form.querySelector('[name="currency_code"]') : null;
            labelInput = form ? form.querySelector('[name="currency_label"]') : null;
            catalogFeedback = document.querySelector('[data-currency-catalog-feedback]');
            table = document.querySelector('[data-currencies-table]');
        }

        if (setupRoot.dataset.asdlFinCurrencySetup === '1') {
            return;
        }

        setupRoot.dataset.asdlFinCurrencySetup = '1';
        refreshRefs();

        function setFeedback(message, tone) {
            refreshRefs();
            if (!feedback) {
                return;
            }

            feedback.textContent = message || '';
            feedback.classList.toggle('is-hidden', !message);
            feedback.classList.toggle('is-error', tone === 'error');
            feedback.classList.toggle('is-success', tone === 'success');
        }

        function setCatalogFeedback(message, tone) {
            refreshRefs();
            if (!catalogFeedback) {
                return;
            }

            catalogFeedback.textContent = message || '';
            catalogFeedback.classList.toggle('is-hidden', !message);
            catalogFeedback.classList.toggle('is-error', tone === 'error');
            catalogFeedback.classList.toggle('is-success', tone === 'success');
        }

        function normalizeCurrencyCode(value) {
            return String(value || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
        }

        function upsertCurrencyOption(select, code, label) {
            if (!select || !code) {
                return;
            }

            var existing = Array.prototype.find.call(select.options || [], function (option) {
                return String(option.value || '') === String(code);
            });

            if (!existing) {
                existing = document.createElement('option');
                existing.value = code;
                select.appendChild(existing);
            }

            existing.textContent = label || code;
        }

        function upsertCurrencyRow(currency) {
            if (!table || !currency || !currency.code) {
                return;
            }

            var tbody = table.querySelector('tbody');
            var selector = 'tr[data-currency-row="' + String(currency.code || '').replace(/"/g, '&quot;') + '"]';
            var row = tbody ? tbody.querySelector(selector) : null;

            if (!tbody) {
                return;
            }

            if (!row) {
                row = document.createElement('tr');
                row.setAttribute('data-currency-row', String(currency.code));
                row.innerHTML = '<td><code></code></td><td data-currency-label></td><td data-currency-kind></td>';
                tbody.appendChild(row);
            }

            if (row.children[0] && row.children[0].querySelector('code')) {
                row.children[0].querySelector('code').textContent = currency.code;
            }

            if (row.querySelector('[data-currency-label]')) {
                row.querySelector('[data-currency-label]').textContent = currency.label || currency.code;
            }

            if (row.querySelector('[data-currency-kind]')) {
                row.querySelector('[data-currency-kind]').textContent = currency.kind === 'custom' ? 'Personalizada' : 'Base del sistema';
            }
        }

        function resetModalContext() {
            refreshRefs();
            activeSelect = null;
            setFeedback('', '');
            if (codeInput) {
                codeInput.value = '';
            }
            if (labelInput) {
                labelInput.value = '';
            }
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest
                ? event.target.closest('[data-currency-open="1"]')
                : null;

            if (!trigger) {
                return;
            }

            event.preventDefault();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            refreshRefs();

            if (!modal || !form) {
                setCatalogFeedback('No se pudo abrir el formulario de monedas. Recarga la pagina e intenta de nuevo.', 'error');
                return;
            }

            resetModalContext();
            activeSelect = trigger.closest('.asdl-fin-method-row')
                ? trigger.closest('.asdl-fin-method-row').querySelector('[data-currency-select]')
                : null;

            setModalState(modal, true);

            window.setTimeout(function () {
                if (codeInput && typeof codeInput.focus === 'function') {
                    codeInput.focus();
                }
            }, 0);
        });

        document.addEventListener('submit', function (event) {
            var currentForm = event.target && event.target.matches && event.target.matches('[data-currency-inline-form]')
                ? event.target
                : null;
            var submitButton;
            var code;
            var label;
            if (!currentForm) {
                return;
            }

            refreshRefs();
            form = currentForm;
            if (!actionNonces.saveCurrencyInline) {
                return;
            }

            event.preventDefault();

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }

            code = normalizeCurrencyCode(codeInput ? codeInput.value : '');
            label = String(labelInput && labelInput.value ? labelInput.value : '').trim();

            if (!code) {
                setFeedback('Debes indicar un codigo de moneda valido.', 'error');
                return;
            }

            submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            setFeedback('', '');
            setAsyncButtonState(submitButton, true, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar moneda') : 'Guardar moneda', 'Guardando moneda...');

            requestAdminAjax('asdl_fin_save_currency_inline', actionNonces.saveCurrencyInline, {
                currency_code: code,
                currency_label: label
            }).then(function (payload) {
                var currency = payload && payload.currency ? payload.currency : {};
                var value = currency.code || code;
                var display = currency.label || label || value;

                document.querySelectorAll('[data-currency-select]').forEach(function (select) {
                    upsertCurrencyOption(select, value, display);
                });

                upsertCurrencyRow(currency);
                setCatalogFeedback((payload && payload.message) || 'Moneda guardada correctamente.', 'success');

                if (activeSelect) {
                    activeSelect.value = value;
                    activeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }

                resetModalContext();
                setAsyncButtonState(submitButton, false, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar moneda') : 'Guardar moneda');
                setModalState(modal, false);
            }).catch(function (error) {
                setAsyncButtonState(submitButton, false, submitButton ? (submitButton.dataset.idleLabel || submitButton.textContent || submitButton.value || 'Guardar moneda') : 'Guardar moneda');
                setFeedback((error && error.message) || 'No se pudo guardar la moneda.', 'error');
                setCatalogFeedback((error && error.message) || 'No se pudo guardar la moneda.', 'error');
            });
        });
    }

    function getDualPricingConfig() {
        var config = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.dualPricing && typeof ASDLFinanceAdmin.dualPricing === 'object')
            ? ASDLFinanceAdmin.dualPricing
            : {};

        return {
            active: !!config.active,
            percent: Number(config.percent || 0),
            fraction: Number(config.fraction || 0),
            eligibilityByKey: config.eligibilityByKey && typeof config.eligibilityByKey === 'object'
                ? config.eligibilityByKey
                : {},
            divisaMethodKeys: Array.isArray(config.divisaMethodKeys) ? config.divisaMethodKeys.map(function (item) {
                return String(item || '');
            }) : []
        };
    }

    function normalizePaymentMethodKeyClient(methodKey) {
        var paymentConfig = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.paymentMethods) || {};
        var defaultLabels = paymentConfig && typeof paymentConfig.defaultLabels === 'object' ? paymentConfig.defaultLabels : {};
        var aliasMap = paymentConfig && typeof paymentConfig.aliasMap === 'object' ? paymentConfig.aliasMap : {};
        var token = String(methodKey || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9_]+/g, '');

        if (!token) {
            return '';
        }

        if (defaultLabels[token]) {
            return token;
        }

        if (aliasMap[token]) {
            return String(aliasMap[token] || '');
        }

        return token;
    }

    function settlementMethodQualifiesForDual(methodKey, currency) {
        var config = getDualPricingConfig();
        var normalizedMethod = normalizePaymentMethodKeyClient(methodKey);
        var normalizedCurrency = String(currency || '').trim().toUpperCase();
        var eligibility = normalizedMethod && config.eligibilityByKey && typeof config.eligibilityByKey[normalizedMethod] === 'object'
            ? config.eligibilityByKey[normalizedMethod]
            : null;

        if (!config.active || !normalizedMethod || normalizedCurrency !== 'USD') {
            return false;
        }

        if (eligibility) {
            return !!eligibility.eligible;
        }

        return config.divisaMethodKeys.indexOf(normalizedMethod) !== -1;
    }

    function getSettlementDualFormContext(form) {
        var methodInput = form ? form.querySelector('[data-payment-method-select]') : null;
        var currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
        var rawMethod = String(methodInput && methodInput.value ? methodInput.value : '').trim();

        return {
            rawMethod: rawMethod,
            method: normalizePaymentMethodKeyClient(rawMethod),
            currency: String(currencyInput && currencyInput.value ? currencyInput.value : '').trim().toUpperCase()
        };
    }

    function getSettlementDualStrictState(form) {
        var context;
        var strictMethod;
        var strictCurrency;

        if (!form || !form.hasAttribute('data-settlement-dual-strict-qualifies')) {
            return null;
        }

        context = getSettlementDualFormContext(form);
        strictMethod = normalizePaymentMethodKeyClient(form.getAttribute('data-settlement-dual-strict-method') || '');
        strictCurrency = String(form.getAttribute('data-settlement-dual-strict-currency') || '').trim().toUpperCase();

        if (strictMethod !== context.method || strictCurrency !== context.currency) {
            return null;
        }

        return {
            qualifies: form.getAttribute('data-settlement-dual-strict-qualifies') === '1',
            reason: String(form.getAttribute('data-settlement-dual-strict-reason') || ''),
            statusKey: String(form.getAttribute('data-settlement-dual-strict-status-key') || ''),
            statusLabel: String(form.getAttribute('data-settlement-dual-strict-status-label') || '')
        };
    }

    function getSettlementDualReferenceState(form) {
        var context;
        var referenceMethod;
        var referenceCurrency;

        if (!form || !form.hasAttribute('data-settlement-dual-reference-active')) {
            return null;
        }

        context = getSettlementDualFormContext(form);
        referenceMethod = normalizePaymentMethodKeyClient(form.getAttribute('data-settlement-dual-reference-method') || '');
        referenceCurrency = String(form.getAttribute('data-settlement-dual-reference-currency') || '').trim().toUpperCase();

        if ((referenceMethod || referenceCurrency) && (referenceMethod !== context.method || referenceCurrency !== context.currency)) {
            return null;
        }

        return {
            active: form.getAttribute('data-settlement-dual-reference-active') === '1',
            percent: parseNumber(form.getAttribute('data-settlement-dual-reference-percent') || '0'),
            total: parseNumber(form.getAttribute('data-settlement-dual-reference-total') || '0')
        };
    }

    function refreshSettlementDualSuggestionFromServer(form) {
        var actionNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.actionNonces) || {};
        var nonce = actionNonces.dualPricingSnapshot || '';
        var methodInput = form ? form.querySelector('[data-payment-method-select]') : null;
        var currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
        var pendingTotal = parseNumber(form ? form.getAttribute('data-settlement-open-total') || '0' : '0');
        var requestContext = getSettlementDualFormContext(form);

        if (!form || !nonce || pendingTotal <= 0) {
            return Promise.resolve(null);
        }

        if (form.dataset.settlementDualSyncing === '1') {
            form.dataset.settlementDualRefreshQueued = '1';
            return Promise.resolve(null);
        }

        form.dataset.settlementDualSyncing = '1';

        return requestAdminAjax('asdl_fin_dual_pricing_snapshot', nonce, {
            pending_total: pendingTotal,
            currency: currencyInput ? String(currencyInput.value || '') : '',
            method_key: methodInput ? String(methodInput.value || '') : ''
        }).then(function (payload) {
            var currentContext = getSettlementDualFormContext(form);
            var staleResponse = currentContext.method !== requestContext.method || currentContext.currency !== requestContext.currency;

            if (staleResponse) {
                form.dataset.settlementDualRefreshQueued = '1';
                return payload || null;
            }

            if (payload && payload.dualPricing) {
                syncDualPricingConfig(payload.dualPricing);
            }

            if (payload && payload.percent !== undefined) {
                form.setAttribute('data-settlement-dual-percent', String(payload.percent || 0));
            }

            if (payload && payload.suggested_total !== undefined) {
                form.setAttribute('data-settlement-dual-total', String(payload.suggested_total || 0));
            }

            if (payload && payload.reference) {
                form.setAttribute('data-settlement-dual-reference-method', requestContext.method);
                form.setAttribute('data-settlement-dual-reference-currency', requestContext.currency);
                form.setAttribute('data-settlement-dual-reference-active', payload.reference.active ? '1' : '0');
                form.setAttribute('data-settlement-dual-reference-percent', String(payload.reference.percent || 0));
                form.setAttribute('data-settlement-dual-reference-total', String(payload.reference.suggested_total || 0));
            }

            if (payload && payload.strict) {
                form.setAttribute('data-settlement-dual-strict-method', requestContext.method);
                form.setAttribute('data-settlement-dual-strict-currency', requestContext.currency);
                form.setAttribute('data-settlement-dual-strict-qualifies', payload.strict.qualifies ? '1' : '0');
                form.setAttribute('data-settlement-dual-strict-reason', String(payload.strict.reason || ''));
                form.setAttribute('data-settlement-dual-strict-status-key', String(payload.strict.status_key || ''));
                form.setAttribute('data-settlement-dual-strict-status-label', String(payload.strict.status_label || ''));
            }

            return payload || null;
        }).catch(function () {
            return null;
        }).finally(function () {
            delete form.dataset.settlementDualSyncing;
            if (form.dataset.settlementDualRefreshQueued === '1') {
                delete form.dataset.settlementDualRefreshQueued;
                return refreshSettlementDualSuggestionFromServer(form).finally(function () {
                    updateSettlementDualToggle(form);
                });
            }
        });
    }

    function updateSettlementDualSuggestion(form) {
        var container = form && form.closest ? form.closest('[data-profile-context-disclosure], .asdl-fin-profile-context-panel, .asdl-fin-contact-settlement-panel') : null;
        var wrapper = container ? container.querySelector('[data-settlement-summary-chip]') : null;
        var percentTarget = wrapper ? wrapper.querySelector('[data-settlement-summary-percent]') : null;
        var totalTarget = wrapper ? wrapper.querySelector('[data-settlement-summary-total]') : null;
        var checkbox = form ? form.querySelector('[data-settlement-force-dual]') : null;
        var methodInput = form ? form.querySelector('[data-payment-method-select]') : null;
        var currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
        var strictState = getSettlementDualStrictState(form);
        var config = getDualPricingConfig();
        var pendingTotal = parseNumber(form ? form.getAttribute('data-settlement-open-total') || '0' : '0');
        var referenceState = getSettlementDualReferenceState(form);
        var attrDualTotal = referenceState
            ? referenceState.total
            : parseNumber(form ? (form.getAttribute('data-settlement-dual-total') || '0') : '0');
        var attrDualPercent = referenceState
            ? referenceState.percent
            : parseNumber(form ? (form.getAttribute('data-settlement-dual-percent') || '0') : '0');
        var referenceActive = referenceState ? referenceState.active : null;
        var dualTotal = 0;
        var dualPercent = 0;
        var methodValue = String(methodInput && methodInput.value ? methodInput.value : '').trim();
        var currencyValue = String(currencyInput && currencyInput.value ? currencyInput.value : '').trim().toUpperCase();
        var dualActive = referenceActive !== null
            ? referenceActive
            : ((config.active && config.percent > 0 && config.fraction > 0) || (attrDualPercent > 0 && attrDualTotal > 0));
        var qualifies = methodValue
            ? (strictState !== null ? strictState.qualifies : settlementMethodQualifiesForDual(methodValue, currencyValue))
            : false;
        var show;

        if (!wrapper) {
            return;
        }

        if (config.active && config.percent > 0 && config.fraction > 0) {
            dualPercent = Number(config.percent || 0);
            dualTotal = pendingTotal * (1 - config.fraction);
        } else {
            dualPercent = attrDualPercent > 0 ? attrDualPercent : Number(config.percent || 0);
            dualTotal = attrDualTotal;
        }

        show = !!checkbox
            && !!checkbox.checked
            && dualActive
            && pendingTotal > 0
            && currencyValue === 'USD'
            && dualPercent > 0
            && dualTotal > 0
            && qualifies;

        if (!show) {
            wrapper.hidden = true;
            return;
        }

        if (percentTarget) {
            percentTarget.textContent = 'Precio dual ' + dualPercent.toLocaleString('es-VE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + '%';
        }

        if (totalTarget) {
            totalTarget.textContent = formatCurrencyAmount(dualTotal, 'USD');
        }

        wrapper.hidden = false;
    }

    function shouldAutoEnableSettlementDual(form, state) {
        var payload = state && typeof state === 'object' ? state : {};
        var referenceState = getSettlementDualReferenceState(form);
        var referenceActive = referenceState ? referenceState.active : null;
        var config = payload.config || getDualPricingConfig();
        var attrDualPercent = Number(payload.attrDualPercent || 0);
        var dualAvailable = payload.dualAvailable !== undefined
            ? !!payload.dualAvailable
            : ((!!config.active && Number(config.percent || 0) > 0) || attrDualPercent > 0);

        return !!form
            && form.dataset.settlementDualManual !== '1'
            && referenceActive !== false
            && dualAvailable
            && String(payload.currencyValue || '').toUpperCase() === 'USD'
            && !!String(payload.methodValue || '').trim()
            && !!payload.qualifies;
    }

    function updateSettlementDualToggle(form, options) {
        var checkbox = form ? form.querySelector('[data-settlement-force-dual]') : null;
        var help = form ? form.querySelector('[data-settlement-force-dual-help]') : null;
        var modeField = form ? form.querySelector('[data-settlement-dual-mode]') : null;
        var methodInput = form ? form.querySelector('[data-payment-method-select]') : null;
        var currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
        var strictState = getSettlementDualStrictState(form);
        var strictReason = strictState ? strictState.reason : '';
        var strictStatusKey = strictState ? strictState.statusKey : '';
        var strictStatusLabel = strictState ? strictState.statusLabel : '';
        var qualifies = methodInput && String(methodInput.value || '').trim()
            ? (strictState !== null ? strictState.qualifies : settlementMethodQualifiesForDual(methodInput ? methodInput.value : '', currencyInput ? currencyInput.value : ''))
            : false;
        var config = getDualPricingConfig();
        var attrDualPercent = parseNumber(form ? (form.getAttribute('data-settlement-dual-reference-percent') || form.getAttribute('data-settlement-dual-percent') || '0') : '0');
        var dualAvailable = (!!config.active && Number(config.percent || 0) > 0) || attrDualPercent > 0;
        var methodValue = methodInput ? String(methodInput.value || '').trim() : '';
        var currencyValue = String(currencyInput && currencyInput.value ? currencyInput.value : '').trim().toUpperCase();

        if (!checkbox || !help) {
            return;
        }

        if (!checkbox.checked && shouldAutoEnableSettlementDual(form, {
            qualifies: qualifies,
            methodValue: methodValue,
            currencyValue: currencyValue,
            config: config,
            attrDualPercent: attrDualPercent,
            dualAvailable: dualAvailable
        })) {
            checkbox.checked = true;
        }

        if (modeField) {
            modeField.value = checkbox.checked ? 'auto' : 'off';
        }

        if (!checkbox.checked) {
            help.textContent = form && form.dataset.settlementDualManual === '1'
                ? 'Desactivado manualmente: este abono se registrara normal, sin precio dual, aunque el metodo y la moneda califiquen.'
                : 'Desactivado: selecciona un metodo elegible en USD para activar automaticamente el precio dual, o marca esta opcion manualmente.';
            updateSettlementDualSuggestion(form);
            return;
        }

        if (qualifies) {
            help.textContent = 'Activo: este abono aplicara el descuento dual vigente para la configuracion seleccionada.';
            updateSettlementDualSuggestion(form);
            return;
        }

        if (currencyValue !== 'USD') {
            help.textContent = 'Activo, pero la moneda registrada no es USD. El precio dual no aplica en esta corrida.';
            updateSettlementDualSuggestion(form);
            return;
        }

        if (strictStatusKey === 'global_off' || (!dualAvailable && strictStatusKey !== 'method' && strictStatusKey !== 'method_missing')) {
            help.textContent = 'Activo, pero el descuento dual general esta apagado. El abono seguira normal.';
            updateSettlementDualSuggestion(form);
            return;
        }

        if (!methodValue || strictStatusKey === 'method_missing') {
            help.textContent = 'Activo, pero falta confirmar el metodo final. Selecciona un metodo elegible o apaga el descuento antes de confirmar.';
            updateSettlementDualSuggestion(form);
            return;
        }

        if (qualifies) {
            help.textContent = 'Activo. Si mantienes este metodo, el descuento dual se aplicara al procesar.';
            updateSettlementDualSuggestion(form);
            return;
        }

        help.textContent = (strictReason || strictStatusLabel || 'Activo, pero el metodo actual no califica para precio dual.') + ' Corrige el metodo o apaga el descuento antes de confirmar.';
        updateSettlementDualSuggestion(form);
    }

    function updateSettlementCreditBreakdown(form) {
        var wrapper = form ? form.querySelector('[data-settlement-credit-breakdown]') : null;
        var creditAvailableBadge = form ? form.querySelector('[data-settlement-credit-available-badge]') : null;
        var help = form ? form.querySelector('[data-settlement-credit-help]') : null;
        var totalInput = form ? form.querySelector('[data-settlement-total]') : null;
        var currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
        var hiddenInput = form ? form.querySelector('[data-settlement-include-credit]') : null;
        var toggleInput = form ? form.querySelector('[data-settlement-include-credit-toggle]') : null;
        var newTarget = wrapper ? wrapper.querySelector('[data-settlement-credit-new-total]') : null;
        var usedTarget = wrapper ? wrapper.querySelector('[data-settlement-credit-used-total]') : null;
        var combinedTarget = wrapper ? wrapper.querySelector('[data-settlement-credit-combined-total]') : null;
        var copyTarget = wrapper ? wrapper.querySelector('[data-settlement-credit-breakdown-copy]') : null;
        var creditAvailable = parseNumber(form ? form.getAttribute('data-settlement-credit-total') || '0' : '0');
        var creditCurrency = String(form ? form.getAttribute('data-settlement-credit-currency') || 'USD' : 'USD').toUpperCase();
        var currency = String(currencyInput && currencyInput.value ? currencyInput.value : creditCurrency).toUpperCase();
        var enteredAmount = Math.max(0, parseNumber(totalInput ? totalInput.value : 0));
        var includeCredit = !!(toggleInput && toggleInput.checked) || !!(hiddenInput && hiddenInput.value === '1');
        var currencyMatches = currency === creditCurrency;
        var includedCredit = includeCredit && currencyMatches ? creditAvailable : 0;
        var combinedTotal = enteredAmount + includedCredit;

        if (creditAvailableBadge) {
            creditAvailableBadge.textContent = formatCurrencyAmount(creditAvailable, creditCurrency);
        }

        if (help) {
            if (includeCredit && !currencyMatches) {
                help.textContent = 'Saldo disponible en ' + creditCurrency + ': ' + formatCurrencyAmount(creditAvailable, creditCurrency) + '. No se sumara mientras la moneda del abono sea ' + currency + '.';
            } else if (includeCredit) {
                help.textContent = 'Activo: el preview sumara ' + formatCurrencyAmount(creditAvailable, creditCurrency) + ' de saldo a favor al dinero nuevo recibido.';
            } else {
                help.textContent = 'Disponible hoy: ' + formatCurrencyAmount(creditAvailable, creditCurrency) + '. Activalo para sumarlo al dinero nuevo recibido en este abono.';
            }
        }

        if (!wrapper) {
            return;
        }

        wrapper.classList.toggle('is-active', includedCredit > 0);
        wrapper.classList.toggle('is-currency-mismatch', includeCredit && !currencyMatches);
        wrapper.setAttribute('aria-live', 'polite');

        if (newTarget) {
            newTarget.textContent = formatCurrencyAmount(enteredAmount, currency);
        }

        if (usedTarget) {
            usedTarget.textContent = includeCredit && !currencyMatches
                ? formatCurrencyAmount(0, currency)
                : formatCurrencyAmount(includedCredit, creditCurrency);
        }

        if (combinedTarget) {
            combinedTarget.textContent = formatCurrencyAmount(combinedTotal, currency);
        }

        if (copyTarget) {
            if (includeCredit && !currencyMatches) {
                copyTarget.textContent = 'La moneda del abono no coincide con el saldo a favor disponible; el preview no lo sumara en esta corrida.';
            } else if (includedCredit > 0) {
                copyTarget.textContent = 'Base antes de descuento dual o cierre: dinero nuevo + saldo a favor incluido.';
            } else {
                copyTarget.textContent = 'Activa el saldo a favor si quieres sumarlo a este abono.';
            }
        }
    }

    function setupOrderSettlementPreviewForms() {
        document.querySelectorAll('[data-order-settlement-preview-form]').forEach(function (form) {
            if (form.dataset.previewSetup === '1') {
                return;
            }

            var includeCreditToggle = form.querySelector('[data-settlement-include-credit-toggle]');
            var dualToggle = form.querySelector('[data-settlement-force-dual]');
            var currencyField = form.querySelector('[data-settlement-currency]');
            var methodField = form.querySelector('[data-payment-method-select]');
            var syncDualUi = function () {
                updateSettlementDualToggle(form);
                return refreshSettlementDualSuggestionFromServer(form).finally(function () {
                    updateSettlementDualToggle(form);
                });
            };

            updateSettlementDualToggle(form);
            refreshSettlementDualSuggestionFromServer(form).finally(function () {
                updateSettlementDualToggle(form);
            });
            setSettlementIncludeCreditBalance(form, includeCreditToggle && includeCreditToggle.checked);
            updateSettlementCreditBreakdown(form);

            if (dualToggle) {
                dualToggle.addEventListener('change', function () {
                    form.dataset.settlementDualManual = '1';
                    updateSettlementDualToggle(form);
                    syncDualUi();
                });
            }

            if (currencyField) {
                currencyField.addEventListener('change', function () {
                    updateSettlementDualToggle(form);
                    syncDualUi();
                });
            }

            if (methodField) {
                methodField.addEventListener('change', function () {
                    updateSettlementDualToggle(form);
                    syncDualUi();
                });
            }

            ['input', 'change'].forEach(function (eventName) {
                form.addEventListener(eventName, function (event) {
                    var needsDualRefresh = false;
                    if (!event.target || !event.target.matches(
                        '[name="account_id"], [data-settlement-payment-date], [data-settlement-total], [data-settlement-currency], [data-payment-method-select], [data-settlement-force-dual], [data-settlement-include-credit-toggle]'
                    )) {
                        return;
                    }

                    if (event.target.matches('[data-settlement-force-dual]')) {
                        needsDualRefresh = true;
                    }

                    if (event.target.matches('[data-payment-method-select], [data-settlement-currency]')) {
                        needsDualRefresh = true;
                    }

                    if (event.target.matches('[data-settlement-include-credit-toggle]')) {
                        setSettlementIncludeCreditBalance(form, !!event.target.checked);
                    }

                    updateSettlementCreditBreakdown(form);
                    resetPreviewConfirmation(form);
                    resetSettlementFormLoading(form);

                    if (needsDualRefresh) {
                        updateSettlementDualToggle(form);
                        syncDualUi();
                    } else {
                        updateSettlementDualSuggestion(form);
                    }
                });
            });

            var submitButton = findSettlementSubmitButton(form);
            if (submitButton && !submitButton.dataset.idleLabel) {
                submitButton.dataset.idleLabel = submitButton.textContent || submitButton.value || 'Aplicar abono a pedidos';
            }

            form.dataset.previewSetup = '1';
        });
    }

    function setSettlementIncludeCreditBalance(form, enabled) {
        var hiddenInput = form ? form.querySelector('[data-settlement-include-credit]') : null;
        var toggleInput = form ? form.querySelector('[data-settlement-include-credit-toggle]') : null;

        if (hiddenInput) {
            hiddenInput.value = enabled ? '1' : '0';
        }

        if (toggleInput) {
            toggleInput.checked = !!enabled;
        }

        updateSettlementCreditBreakdown(form);
    }

    function settlementPreviewItemKey(item) {
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

    function getSettlementEligibleItems(preview) {
        if (preview && Array.isArray(preview.eligible_items) && preview.eligible_items.length) {
            return preview.eligible_items;
        }

        return Array.isArray(preview && preview.items) ? preview.items : [];
    }

    function normalizeSettlementSelectedItemKeys(preview, selectedItemKeys) {
        var items = getSettlementEligibleItems(preview);
        var eligibleMap = {};
        var normalized = [];
        var seen = {};
        var sourceKeys;
        var isSpecificMode = String(preview && preview.selection_mode || '') === 'specific';

        items.forEach(function (item) {
            var itemKey = settlementPreviewItemKey(item);
            if (itemKey) {
                eligibleMap[itemKey] = true;
            }
        });

        if (Array.isArray(selectedItemKeys)) {
            sourceKeys = selectedItemKeys;
        } else if (Array.isArray(preview && preview.selected_item_keys) && preview.selected_item_keys.length) {
            sourceKeys = preview.selected_item_keys;
        } else if (isSpecificMode) {
            sourceKeys = [];
        } else {
            sourceKeys = items.map(function (item) {
                return settlementPreviewItemKey(item);
            });
        }

        sourceKeys.forEach(function (key) {
            var normalizedKey = String(key || '');
            if (!normalizedKey || !eligibleMap[normalizedKey] || seen[normalizedKey]) {
                return;
            }
            seen[normalizedKey] = true;
            normalized.push(normalizedKey);
        });

        return normalized;
    }

    function buildSettlementSelectionSummary(preview, selectedItemKeys) {
        var items = getSettlementEligibleItems(preview);
        var selectedMap = {};
        var keys = normalizeSettlementSelectedItemKeys(preview, selectedItemKeys);
        var totals = {
            selectedCount: 0,
            selectedTotal: 0,
            currentTotal: 0,
            historicalTotal: 0
        };

        keys.forEach(function (key) {
            if (key) {
                selectedMap[String(key)] = true;
            }
        });

        items.forEach(function (item) {
            var itemKey = settlementPreviewItemKey(item);
            var amount = Number(item && item.balance_before ? item.balance_before : 0);

            if (!selectedMap[itemKey]) {
                return;
            }

            totals.selectedCount += 1;
            totals.selectedTotal += amount;

            if (String(item && item.source_kind || '') === 'historical_index') {
                totals.historicalTotal += amount;
            } else {
                totals.currentTotal += amount;
            }
        });

        return totals;
    }

    function buildSettlementSpecificSelectionNote(preview, previewDirty, selectedCount) {
        var validationMessage = preview && preview.validation_message ? String(preview.validation_message) : '';

        if (previewDirty) {
            return ''
                + '<strong>Seleccion actualizada.</strong>'
                + '<div>La seleccion cambio. Puedes marcar o desmarcar libremente. Si queda un solo pedido marcado, la vista se recalculara automaticamente para habilitar el cierre extraordinario; si no arranca la carga, pulsa <em>Actualizar vista</em>.</div>'
                + (selectedCount === 1
                    ? '<div class="asdl-fin-inline-actions"><button type="button" class="button button-secondary" data-order-settlement-refresh-preview="1">Actualizar vista</button></div>'
                    : '');
        }

        if ((selectedCount || 0) <= 0) {
            return ''
                + '<strong>' + escapeHtml(validationMessage || 'Debes marcar al menos un pedido.') + '</strong>'
                + '<div>En modo especifico ASD Finanzas solo cubrira las facturas que selecciones manualmente. Si no hay una seleccion valida, no se repartira el abono ni se tocaran otros pedidos. Para usar cierre extraordinario, marca un solo pedido y recalcula la vista; si no entrara dinero real, luego podras seguir sin metodo.</div>';
        }

        return ''
            + '<strong>Seleccion manual.</strong>'
            + '<div>Marca solo las facturas que quieras cubrir en esta corrida. Si sobra dinero, la diferencia se tratara segun la politica de remanente y no se aplicara a otros pedidos sin una seleccion explicita. Si este caso es una exoneracion o ajuste administrativo, deja un solo pedido marcado para habilitar el cierre extraordinario aunque no haya metodo.</div>';
    }

    function updateSettlementSpecificSelectionUi(body, preview, options) {
        var selectedKeys;
        var selectedMap = {};
        var selection;
        var eligibleItems;
        var currency;
        var planMap = {};
        var previewDirty;
        var allChecked;
        var selectAllCheckbox;
        var summaryNode;
        var countNode;
        var totalNode;
        var noteNode;

        if (!body || !preview || String(preview.selection_mode || '') !== 'specific') {
            return;
        }

        options = options || {};
        previewDirty = !!options.previewDirty;
        selectedKeys = normalizeSettlementSelectedItemKeys(preview, options.selectedItemKeys);
        selection = buildSettlementSelectionSummary(preview, selectedKeys);
        eligibleItems = getSettlementEligibleItems(preview);
        currency = preview && preview.currency ? preview.currency : 'USD';
        allChecked = eligibleItems.length > 0 && selection.selectedCount === eligibleItems.length;

        selectedKeys.forEach(function (key) {
            if (key) {
                selectedMap[String(key)] = true;
            }
        });

        if (Array.isArray(preview.items)) {
            preview.items.forEach(function (item) {
                planMap[settlementPreviewItemKey(item)] = item;
            });
        }

        selectAllCheckbox = body.querySelector('[data-order-settlement-select-all]');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = !allChecked && selection.selectedCount > 0;
        }

        summaryNode = body.querySelector('[data-settlement-selection-summary]');
        if (summaryNode) {
            summaryNode.textContent = 'Seleccionados: ' + String(selection.selectedCount || 0) + ' de ' + String(eligibleItems.length || 0) + ' · ' + formatCurrencyAmount(selection.selectedTotal || 0, currency);
        }

        countNode = body.querySelector('[data-settlement-selected-count]');
        if (countNode) {
            countNode.textContent = String(selection.selectedCount || 0);
        }

        totalNode = body.querySelector('[data-settlement-selected-total]');
        if (totalNode) {
            totalNode.textContent = formatCurrencyAmount(selection.selectedTotal || 0, currency) + ' marcados manualmente.';
        }

        noteNode = body.querySelector('[data-settlement-selection-note]');
        if (noteNode) {
            noteNode.innerHTML = buildSettlementSpecificSelectionNote(preview, previewDirty, selection.selectedCount || 0);
        }

        body.querySelectorAll('[data-settlement-item-row]').forEach(function (row) {
            var key = String(row.getAttribute('data-settlement-item-row') || '');
            var checkbox = row.querySelector('[data-order-settlement-item]');
            var stateNode = row.querySelector('[data-settlement-selection-state]');
            var checked = !!selectedMap[key];
            var planItem = planMap[key] || null;
            var tone = 'neutral';
            var label = checked ? 'Marcado' : 'Sin marcar';

            if (checkbox) {
                checkbox.checked = checked;
            }

            if (!previewDirty) {
                if (planItem && String(planItem.selection_origin || '') === 'selected') {
                    tone = 'success';
                    label = 'Seleccionado';
                }
            }

            if (stateNode) {
                stateNode.innerHTML = renderPill(label, tone);
            }
        });
    }

    function buildSettlementPreviewHtml(preview, options) {
        options = options || {};
        var summary = preview && preview.summary ? preview.summary : {};
        var items = Array.isArray(preview && preview.items) ? preview.items : [];
        var eligibleItems = getSettlementEligibleItems(preview);
        var currency = preview && preview.currency ? preview.currency : 'USD';
        var paymentMethod = preview && preview.payment_method ? preview.payment_method : {};
        var discount = preview && preview.discount ? preview.discount : {};
        var executionMode = preview && preview.execution_mode ? preview.execution_mode : 'runner';
        var dualMode = preview && preview.dual_discount_mode ? String(preview.dual_discount_mode) : ((preview && preview.force_dual_discount) ? 'force' : 'off');
        var dualReason = settlementDualReason(preview);
        var selectionModeKey = preview && preview.selection_mode === 'specific' ? 'specific' : 'oldest_first';
        var selectionMode = selectionModeKey === 'specific' ? 'Pedidos especificos' : 'Antiguedad';
        var selectedKeys = normalizeSettlementSelectedItemKeys(
            preview,
            Object.prototype.hasOwnProperty.call(options, 'selectedItemKeys') ? options.selectedItemKeys : null
        );
        var selection = buildSettlementSelectionSummary(preview, selectedKeys);
        var previewDirty = !!options.previewDirty;
        var cashTotal = Number(summary.cash_total !== undefined ? summary.cash_total : (summary.requested_total || 0));
        var creditAppliedTotal = Number(summary.credit_applied_total || 0);
        var creditAvailableTotal = Number(summary.credit_available_total || 0);
        var creditPreviewTotal = cashTotal + creditAvailableTotal;
        var totalAvailable = Number(summary.total_available !== undefined ? summary.total_available : (cashTotal + creditAppliedTotal));
        var remainderTotal = Number(summary.remainder_total !== undefined ? summary.remainder_total : (summary.unapplied_total || 0));
        var paymentRecordedTotal = Number(summary.payment_recorded_total !== undefined ? summary.payment_recorded_total : cashTotal);
        var rateSnapshot = preview && preview.rate_snapshot && typeof preview.rate_snapshot === 'object'
            ? preview.rate_snapshot
            : null;
        var rateValue = rateSnapshot
            ? (rateSnapshot.rate || rateSnapshot.value || rateSnapshot.amount || rateSnapshot.bs_per_usd || '')
            : '';
        var rateDate = rateSnapshot
            ? (rateSnapshot.date || rateSnapshot.updated_at || rateSnapshot.updatedAt || '')
            : '';
        var extraordinary = settlementExtraordinaryState(preview);
        var approvalGate = settlementApprovalGate(preview);
        var approvalPanel = buildOperationalApprovalPanel('settlement', approvalGate, options.approvalState || {}, {
            title: 'Validacion operativa',
            scopeLabel: 'el cierre extraordinario de este pedido',
            helpMessage: 'Valida esta accion con tu autenticador. Si cambias la seleccion, la firma o recalculas la vista, se pedira aprobar otra vez.'
        });
        var extraordinaryAppliedTotal = Number(summary.extraordinary_closure_total || extraordinary.applied_total || 0);
        var blockedNote = settlementExecutionBlocked(preview)
            ? '<div class="asdl-fin-note-box asdl-fin-settlement-preview-error"><strong>Abono bloqueado.</strong><div>' + escapeHtml(settlementExecutionBlockedMessage(preview)) + '</div></div>'
            : '';
        var meta = [
            '<span><strong>Metodo:</strong> ' + escapeHtml(paymentMethod.label || paymentMethod.key || 'Sin definir') + '</span>',
            '<span><strong>Moneda:</strong> ' + escapeHtml(currency) + '</span>',
            '<span><strong>Descuento automatico:</strong> ' + (dualMode === 'off' ? 'Desactivado' : (dualMode === 'force' ? 'Forzado' : 'Activo')) + '</span>',
            '<span><strong>Precio dual:</strong> ' + (preview && preview.uses_dual && dualMode !== 'off' ? escapeHtml(Number(discount.percent || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) + '%' : escapeHtml(dualReason.label || 'No aplica')) + '</span>',
            '<span><strong>Cierre extraordinario:</strong> ' + escapeHtml(extraordinaryAppliedTotal > 0 ? 'Activo' : (extraordinary.available ? 'Disponible' : 'No aplica')) + '</span>',
            '<span><strong>Seleccion:</strong> ' + escapeHtml(selectionMode) + '</span>',
            '<span><strong>Ejecucion:</strong> ' + escapeHtml(executionMode === 'fast_path' ? 'Aplicacion inmediata' : 'Runner por lotes') + '</span>'
        ];

        if (rateValue !== '') {
            meta.push('<span><strong>Tasa de referencia:</strong> ' + escapeHtml(String(rateValue)) + '</span>');
        }

        if (creditAvailableTotal > 0) {
            meta.push('<span><strong>Saldo a favor:</strong> Incluido ' + escapeHtml(formatCurrencyAmount(creditAvailableTotal, currency)) + '</span>');
        }

        if (rateDate) {
            meta.push('<span><strong>Corte:</strong> ' + escapeHtml(formatPreviewDateLabel(rateDate)) + '</span>');
        }

        if (!eligibleItems.length && !items.length) {
            return ''
                + '<div class="asdl-fin-empty">'
                + '<strong>Sin pedidos simulados.</strong>'
                + '<p>No encontramos pedidos cobrables para construir la vista previa del abono.</p>'
                + '</div>';
        }

        if (selectionModeKey === 'specific') {
            var planMap = {};
            var selectAllChecked = eligibleItems.length > 0 && selection.selectedCount === eligibleItems.length;
            var remainderPolicy = preview && preview.remainder_policy ? String(preview.remainder_policy) : 'create_credit';
            var remainderAppliedOldestFirstTotal = Number(summary.remainder_applied_oldest_first_total || 0);
            var remainderAdditionalItemCount = Number(summary.remainder_additional_item_count || 0);
            var decisionRemainderTotal = remainderTotal > 0 ? remainderTotal : remainderAppliedOldestFirstTotal;
            var exactPaymentTotal = 0;
            var remainderPanel = '';
            var specificCreditHelp = creditAppliedTotal > 0
                ? 'Aplicado en la seleccion actual: ' + formatCurrencyAmount(creditAppliedTotal, currency) + '.'
                : 'Disponible para aplicar cuando marques pedidos y recalcules.';
            if (remainderPolicy === 'discard') {
                remainderPolicy = 'adjust_payment_total';
            }

            items.forEach(function (item) {
                planMap[settlementPreviewItemKey(item)] = item;
                if (String(item.selection_origin || '') === 'selected') {
                    exactPaymentTotal += Number(item.customer_paid_amount || item.payment_applied_total || 0);
                }
            });
            if (exactPaymentTotal <= 0) {
                exactPaymentTotal = Number(summary.payment_applied_total || 0);
            }

            if ((remainderTotal > 0 || remainderAppliedOldestFirstTotal > 0) && !(extraordinary.enabled && extraordinaryAppliedTotal > 0)) {
                remainderPanel = ''
                    + '<div class="asdl-fin-note-box"><strong>Excedente en pedidos especificos.</strong><div>El monto recibido supera lo que consume la seleccion actual. Decide aqui que hacer con la diferencia antes de confirmar; el sistema no la repartira en silencio.</div>'
                    + '<div class="asdl-fin-inline-actions asdl-fin-settlement-remainder-actions">'
                    + '<label class="asdl-fin-inline-checkbox"><input type="radio" name="settlement_remainder_policy" value="adjust_payment_total" data-settlement-remainder-policy-choice ' + (remainderPolicy === 'adjust_payment_total' ? 'checked' : '') + ' /> <span>Ajustar abono al monto exacto</span><small>Se registrara ' + escapeHtml(formatCurrencyAmount(exactPaymentTotal, currency)) + '; no crea saldo a favor ni toca otros pedidos.</small></label>'
                    + '<label class="asdl-fin-inline-checkbox"><input type="radio" name="settlement_remainder_policy" value="apply_oldest_first" data-settlement-remainder-policy-choice ' + (remainderPolicy === 'apply_oldest_first' ? 'checked' : '') + ' /> <span>Aplicar excedente a otras facturas</span><small>' + (remainderAppliedOldestFirstTotal > 0 ? 'Esta vista agrega ' + escapeHtml(String(remainderAdditionalItemCount || 0)) + ' pedido(s) por ' + escapeHtml(formatCurrencyAmount(remainderAppliedOldestFirstTotal, currency)) + ' siguiendo antiguedad.' : 'Al elegirlo, recalcula para mostrar las facturas adicionales antes de confirmar.') + '</small></label>'
                    + '<label class="asdl-fin-inline-checkbox"><input type="radio" name="settlement_remainder_policy" value="create_credit" data-settlement-remainder-policy-choice ' + (remainderPolicy === 'create_credit' ? 'checked' : '') + ' /> <span>Guardar excedente como saldo a favor</span><small>Se registra el monto recibido completo y quedaran ' + escapeHtml(formatCurrencyAmount(decisionRemainderTotal, currency)) + ' disponibles como credito del perfil.</small></label>'
                    + '</div>'
                    + (remainderPolicy !== 'create_credit' && paymentRecordedTotal < cashTotal
                        ? '<div class="asdl-fin-table-note">Monto que se registrara como pago real: ' + escapeHtml(formatCurrencyAmount(paymentRecordedTotal, currency)) + ' de ' + escapeHtml(formatCurrencyAmount(cashTotal, currency)) + ' capturados.</div>'
                        : '')
                    + '</div>';
            }

            return ''
                + '<div class="asdl-fin-settlement-preview-meta">' + meta.join('') + '</div>'
                + blockedNote
                + '<div class="asdl-fin-settlement-preview-summary">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Dinero nuevo recibido</strong><span>' + escapeHtml(formatCurrencyAmount(cashTotal, currency)) + '</span><small>Efectivo/divisa cargado en este abono.</small></div>'
                + (creditAvailableTotal > 0 ? '<div class="asdl-fin-settlement-preview-card"><strong>Saldo a favor incluido</strong><span>' + escapeHtml(formatCurrencyAmount(creditAvailableTotal, currency)) + '</span><small>' + escapeHtml(specificCreditHelp) + '</small></div>' : '')
                + (creditAvailableTotal > 0 ? '<div class="asdl-fin-settlement-preview-card"><strong>Total para vista previa</strong><span>' + escapeHtml(formatCurrencyAmount(creditPreviewTotal, currency)) + '</span><small>Dinero nuevo + saldo a favor disponible.</small></div>' : '')
                + '<div class="asdl-fin-settlement-preview-card"><strong>Seleccionados</strong><span data-settlement-selected-count>' + escapeHtml(String(selection.selectedCount || 0)) + '</span><small data-settlement-selected-total>' + escapeHtml(formatCurrencyAmount(selection.selectedTotal || 0, currency)) + ' marcados manualmente.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Cierre extraordinario</strong><span>' + escapeHtml(formatCurrencyAmount(extraordinaryAppliedTotal, currency)) + '</span><small>' + (extraordinaryAppliedTotal > 0 ? 'Diferencia administrativa que dejara el pedido en cero.' : 'Sin ajuste extraordinario en esta vista.') + '</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(summary.covered_total || 0, currency)) + '</span><small>Deuda que se cubriria con la seleccion actual.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Remanente</strong><span>' + escapeHtml(formatCurrencyAmount(remainderTotal, currency)) + '</span><small>' + (remainderTotal > 0 ? 'Diferencia que no se aplicara a otros pedidos sin seleccion manual.' : 'Sin diferencia pendiente por resolver.') + '</small></div>'
                + (remainderAppliedOldestFirstTotal > 0 ? '<div class="asdl-fin-settlement-preview-card"><strong>Excedente aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(remainderAppliedOldestFirstTotal, currency)) + '</span><small>' + escapeHtml(String(remainderAdditionalItemCount || 0)) + ' pedido(s) adicional(es) por antiguedad.</small></div>' : '')
                + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos cerrados</strong><span>' + escapeHtml(String(summary.closed_count || 0)) + '</span><small>Pedidos que quedarian liquidados.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos parciales</strong><span>' + escapeHtml(String(summary.partial_count || 0)) + '</span><small>Pedidos que seguirian abiertos.</small></div>'
                + '</div>'
                + '<div class="asdl-fin-note-box" data-settlement-selection-note>' + buildSettlementSpecificSelectionNote(preview, previewDirty, selection.selectedCount || 0) + '</div>'
                + buildSettlementExtraordinaryPanel(preview, {
                    previewDirty: previewDirty,
                    selectedCount: selection.selectedCount || 0
                })
                + approvalPanel
                + remainderPanel
                + '<div class="asdl-fin-table-wrap">'
                + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-settlement-preview-table">'
                + '<thead><tr><th colspan="9"><label class="asdl-fin-checkbox-row"><input type="checkbox" data-order-settlement-select-all ' + (selectAllChecked ? 'checked' : '') + ' /> <strong>Seleccionar / deseleccionar todos</strong></label><span class="asdl-fin-table-note" data-settlement-selection-summary>Seleccionados: ' + escapeHtml(String(selection.selectedCount || 0)) + ' de ' + escapeHtml(String(eligibleItems.length || 0)) + ' · ' + escapeHtml(formatCurrencyAmount(selection.selectedTotal || 0, currency)) + '</span></th></tr><tr><th></th><th>Pedido</th><th>Fecha</th><th>Origen</th><th>Deuda</th><th>Cubrir ahora</th><th>Estado descuento</th><th>Estado en esta vista</th><th>Acceso</th></tr></thead>'
                + '<tbody>'
                + eligibleItems.map(function (item) {
                    var itemKey = settlementPreviewItemKey(item);
                    var checked = selectedKeys.indexOf(itemKey) !== -1;
                    var planItem = planMap[itemKey] || null;
                    var discountItem = planItem || item;
                    var tone = 'neutral';
                    var label = checked ? 'Marcado' : 'Sin marcar';

                    if (previewDirty) {
                        tone = checked ? 'neutral' : 'neutral';
	                    } else if (planItem && String(planItem.selection_origin || '') === 'selected') {
	                        tone = 'success';
	                        label = 'Seleccionado';
	                    } else if (planItem && String(planItem.selection_origin || '') === 'specific_remainder_oldest_first') {
	                        tone = 'warning';
	                        label = 'Por excedente';
	                    } else {
                        tone = checked ? 'neutral' : 'neutral';
                    }

                    return ''
                        + '<tr data-settlement-item-row="' + escapeHtml(itemKey) + '">'
                        + '<td><input type="checkbox" data-order-settlement-item value="' + escapeHtml(itemKey) + '" ' + (checked ? 'checked' : '') + ' /></td>'
                        + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(item.order_label || item.order_number || 'Pedido') + '</strong><small>' + escapeHtml(item.display_name || '') + '</small></div></td>'
                        + '<td>' + escapeHtml(formatPreviewDateLabel(item.issue_date || item.date_created || '')) + '</td>'
                        + '<td>' + renderPill(item.source_kind === 'historical_index' ? 'Historico' : 'Actual', item.source_kind === 'historical_index' ? 'warning' : 'neutral') + '</td>'
                        + '<td>' + escapeHtml(formatCurrencyAmount(item.balance_before || 0, item.currency || currency)) + '</td>'
                        + '<td>' + escapeHtml(formatCurrencyAmount(planItem ? (planItem.covered_total || 0) : 0, item.currency || currency)) + '</td>'
                        + '<td><div class="asdl-fin-stack">' + renderPill(settlementDiscountLabel(discountItem, preview), settlementDiscountTone(discountItem, preview)) + (settlementDiscountHelp(discountItem, preview) ? '<small>' + escapeHtml(settlementDiscountHelp(discountItem, preview)) + '</small>' : '') + '</div></td>'
                        + '<td data-settlement-selection-state>' + renderPill(label, tone) + '</td>'
                        + '<td>' + (item.edit_url ? '<a class="button button-secondary button-small" href="' + escapeHtml(item.edit_url) + '" target="_blank" rel="noopener">Abrir pedido</a>' : '<span class="asdl-fin-label">Sin enlace</span>') + '</td>'
                        + '</tr>';
                }).join('')
                + '</tbody></table></div>';
        }

        return ''
            + '<div class="asdl-fin-settlement-preview-meta">' + meta.join('') + '</div>'
            + blockedNote
            + '<div class="asdl-fin-settlement-preview-summary">'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Dinero nuevo recibido</strong><span>' + escapeHtml(formatCurrencyAmount(cashTotal, currency)) + '</span><small>Efectivo/divisa disponible para repartir.</small></div>'
            + (creditAvailableTotal > 0 ? '<div class="asdl-fin-settlement-preview-card"><strong>Saldo a favor incluido</strong><span>' + escapeHtml(formatCurrencyAmount(creditAvailableTotal, currency)) + '</span><small>Aplicado sobre pedidos: ' + escapeHtml(formatCurrencyAmount(creditAppliedTotal, currency)) + '.</small></div>' : '')
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total disponible</strong><span>' + escapeHtml(formatCurrencyAmount(totalAvailable, currency)) + '</span><small>Suma util para cubrir pedidos en esta corrida.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Descuento aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(dualMode === 'off' ? 0 : (summary.discount_applied_total || 0), currency)) + '</span><small>Rebaja total concedida por precio dual.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(summary.covered_total || 0, currency)) + '</span><small>Deuda real que quedara gestionada en pedidos.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Remanente</strong><span>' + escapeHtml(formatCurrencyAmount(remainderTotal, currency)) + '</span><small>Saldo que no logra aplicarse sobre pedidos abiertos.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos cerrados</strong><span>' + escapeHtml(String(summary.closed_count || 0)) + '</span><small>Pedidos que quedaran liquidados.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos parciales</strong><span>' + escapeHtml(String(summary.partial_count || 0)) + '</span><small>Pedidos que seguiran abiertos despues del abono.</small></div>'
            + '</div>'
            + '<div class="asdl-fin-settlement-preview-note">' + escapeHtml(
                preview && preview.uses_dual
                    ? 'Esta simulacion sigue el orden por antiguedad: procesa primero los pedidos mas viejos y aplica el descuento dual solo sobre la porcion realmente cubierta en cada pedido.'
                    : (dualMode === 'off'
                        ? 'Esta simulacion sigue el orden por antiguedad: procesa primero los pedidos mas viejos y deja parcial el siguiente si el monto no alcanza. El precio dual quedo realmente apagado para este abono.'
                        : 'El descuento automatico esta activo, pero con la configuracion actual no genero rebaja. Motivo: ' + (dualReason.label || 'No aplica') + '. El abono seguira el orden por antiguedad.')
            ) + '</div>'
            + '<div class="asdl-fin-table-wrap">'
            + '<table class="widefat striped asdl-fin-table asdl-fin-table-compact asdl-fin-settlement-preview-table">'
            + '<thead><tr>'
            + '<th>Pedido</th>'
            + '<th>Fecha</th>'
            + '<th>Deuda original</th>'
            + '<th>Descuento</th>'
            + '<th>Estado descuento</th>'
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
                    + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(formatCurrencyAmount(item.discount_effective_amount || item.discount_applied_total || 0, item.currency || currency)) + '</strong>' + (settlementDiscountHelp(item, preview) ? '<small>' + escapeHtml(settlementDiscountHelp(item, preview)) + '</small>' : '') + '</div></td>'
                    + '<td>' + renderPill(settlementDiscountLabel(item, preview), settlementDiscountTone(item, preview)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.payment_applied_total || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.covered_total || 0, item.currency || currency)) + '</td>'
                    + '<td>' + escapeHtml(formatCurrencyAmount(item.remaining_document_balance || 0, item.currency || currency)) + '</td>'
                    + '<td>' + renderPill(item.status_label || 'Pendiente', settlementPreviewStatusTone(item.status_key)) + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table></div>';
    }

    function renderSettlementPreview(body, preview, options) {
        if (!body) {
            return;
        }

        body.innerHTML = buildSettlementPreviewHtml(preview, options);
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
            + '<div class="asdl-fin-settlement-preview-card"><strong>Saldo a favor aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(job.credit_applied_total || 0, currency)) + '</span><small>Credito del perfil ya compensado dentro del lote.</small></div>'
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
            + '<div class="asdl-fin-settlement-preview-card"><strong>Saldo a favor aplicado</strong><span>' + escapeHtml(formatCurrencyAmount(result.credit_applied_total || job.credit_applied_total || 0, currency)) + '</span><small>Credito del perfil compensado dentro del lote.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Cierre extraordinario</strong><span>' + escapeHtml(formatCurrencyAmount(result.extraordinary_closure_total || 0, currency)) + '</span><small>' + (Number(result.extraordinary_closure_total || 0) > 0 ? 'Diferencia administrativa registrada para cerrar el pedido.' : 'Sin cierre extraordinario en esta corrida.') + '</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Total cubierto</strong><span>' + escapeHtml(formatCurrencyAmount(result.covered_total || job.processed_total || 0, currency)) + '</span><small>Deuda finalmente gestionada.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Descuento total</strong><span>' + escapeHtml(formatCurrencyAmount(result.dual_discount_total || job.discount_total || 0, currency)) + '</span><small>Descuento dual aplicado por el runner.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Pedidos cerrados</strong><span>' + escapeHtml(String((result.closed_order_ids || []).length || 0)) + '</span><small>Pedidos liquidados por completo.</small></div>'
            + '<div class="asdl-fin-settlement-preview-card"><strong>Parciales / errores</strong><span>' + escapeHtml(String((result.partial_order_ids || []).length || 0)) + ' / ' + escapeHtml(String(job.errors_count || 0)) + '</span><small>Incluye parciales, omitidos y errores.</small></div>'
            + '</div>';

        var note = '<div class="asdl-fin-note-box"><strong>Resultado listo.</strong><div>' + escapeHtml('Estado: ' + status + '.') + ' ' + escapeHtml('Puedes actualizar el perfil para ver los saldos y pedidos recalculados.')
            + (Number(result.extraordinary_closure_total || 0) > 0
                ? ' ' + escapeHtml('La diferencia extraordinaria quedo registrada como ajuste manual y como movimiento del perfil.')
                : '')
            + '</div></div>';

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
        var contactId;
        var sections;

        if (!containers.length && legacyContainer) {
            containers = [legacyContainer];
        }

        if (!containers.length) {
            return Promise.resolve();
        }

        contactId = Number((containers[0] && containers[0].getAttribute('data-runtime-param-contact-id')) || 0);
        sections = containers.map(function (container) {
            return String(container.getAttribute('data-runtime-param-section-key') || '');
        }).filter(Boolean);

        if (!sections.length && legacyContainer) {
            delete legacyContainer.dataset.runtimeLoaded;
            delete legacyContainer.dataset.runtimeLoading;
            return loadRuntimeContainer(legacyContainer);
        }

        return refreshRuntimeTargets({
            page_keys: ['contacts'],
            groups: ['contacts-detail'],
            sections: sections,
            contact_id: contactId
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

    function isSettlementFormBusy(form) {
        return !!(form && form.dataset.settlementBusy === '1');
    }

    function setSettlementFormBusy(form, busy) {
        var container;

        if (!form) {
            return;
        }

        if (busy) {
            form.dataset.settlementBusy = '1';
        } else {
            delete form.dataset.settlementBusy;
        }

        form.querySelectorAll('[data-order-settlement-specific-open]').forEach(function (button) {
            button.disabled = !!busy;
        });

        container = form.closest('[data-profile-context-disclosure], .asdl-fin-profile-context-panel, .asdl-fin-contact-settlement-panel');
        if (!container) {
            return;
        }

        container.querySelectorAll('[data-settlement-dual-apply]').forEach(function (button) {
            button.disabled = !!busy;
        });
    }

    function setupOrderSettlementPreview() {
        var modal = document.querySelector('[data-modal="order-settlement-preview"]');
        var body = modal ? modal.querySelector('[data-settlement-preview-body]') : null;
        var confirmButton = modal ? modal.querySelector('[data-settlement-preview-confirm]') : null;
        var secondaryButton = modal ? modal.querySelector('[data-settlement-preview-secondary]') : null;
        var title = modal ? modal.querySelector('[data-settlement-preview-title]') : null;
        var description = modal ? modal.querySelector('[data-settlement-preview-description]') : null;
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var activeState = {
            form: null,
            preview: null,
            formSignature: '',
            batchId: 0,
            clientOperationId: '',
            controller: null,
            timer: 0,
            autoPreviewTimer: 0,
            stage: 'idle',
            selectedItemKeys: [],
            hasManualSelection: false,
            previewDirty: false,
            selectionDirty: false,
            runtimeRefresh: null,
            approval: operationalApprovalState({})
        };

        function setSettlementApprovalToken(form, token) {
            var field = form ? form.querySelector('[data-settlement-approval-token]') : null;
            var normalizedToken = String(token || '').trim();

            if (field) {
                field.value = normalizedToken;
            }

            activeState.approval.token = normalizedToken;
            return normalizedToken;
        }

        function clearSettlementApproval(message) {
            setSettlementApprovalToken(activeState.form, '');
            activeState.approval = operationalApprovalState({
                message: message || '',
                approverUserId: activeState.approval && activeState.approval.approverUserId ? activeState.approval.approverUserId : '',
                approverLabel: activeState.approval && activeState.approval.approverLabel ? activeState.approval.approverLabel : ''
            });
        }

        function refreshActiveSettlementPreview(previewDirty) {
            if (!activeState.preview) {
                return;
            }

            renderSettlementPreview(body, activeState.preview || {}, {
                selectedItemKeys: activeState.selectedItemKeys,
                previewDirty: !!previewDirty,
                approvalState: activeState.approval
            });
        }

        function settlementApprovalMissing(preview) {
            return operationalApprovalNeedsToken(settlementApprovalGate(preview)) && !operationalApprovalHasToken(activeState.approval);
        }

        function buildSettlementApprovalRequest() {
            var preview = activeState.preview || {};
            var gate = settlementApprovalGate(preview);
            var extraordinary = settlementExtraordinaryState(preview);
            var batchPayload = preview && preview.batch_payload && typeof preview.batch_payload === 'object' ? preview.batch_payload : {};
            var summary = preview && preview.summary && typeof preview.summary === 'object' ? preview.summary : {};
            var selectedKeys = Array.isArray(activeState.selectedItemKeys) && activeState.selectedItemKeys.length
                ? activeState.selectedItemKeys.slice()
                : (Array.isArray(preview.selected_item_keys) ? preview.selected_item_keys.slice() : []);

            return {
                actionKey: gate.action_key || '',
                payload: {
                    contact_id: Number(preview.contact_id || batchPayload.contact_id || 0),
                    origin: String(batchPayload.origin || preview.origin || 'profile_settlement'),
                    preview_signature: String(preview.preview_signature || ''),
                    selection_mode: String(batchPayload.selection_mode || preview.selection_mode || ''),
                    selected_item_keys: selectedKeys.filter(Boolean),
                    selected_order_id: Number(extraordinary.selected_order_id || 0),
                    selected_order_label: String(extraordinary.selected_order_label || ''),
                    currency: String(preview.currency || batchPayload.currency || 'USD'),
                    method_key: String(batchPayload.method_key || (preview.payment_method && preview.payment_method.key) || ''),
                    payment_recorded_total: Number(summary.payment_recorded_total || 0),
                    extraordinary_closure_total: Number(summary.extraordinary_closure_total || 0),
                    extraordinary_reason: String(batchPayload.extraordinary_closure_reason || extraordinary.reason || ''),
                    extraordinary_reason_label: String(batchPayload.extraordinary_closure_reason_label || extraordinary.reason_label || ''),
                    approval_reference: String(batchPayload.extraordinary_closure_approval_reference || extraordinary.approval_reference || ''),
                    note: String(batchPayload.extraordinary_closure_note || extraordinary.note || '')
                },
                reason: String(batchPayload.extraordinary_closure_note || extraordinary.note || ''),
                targetPlugin: 'analysis-financiero-plugin',
                targetEntityType: Number(extraordinary.selected_order_id || 0) > 0 ? 'order' : 'contact',
                targetEntityId: Number(extraordinary.selected_order_id || 0) > 0
                    ? String(extraordinary.selected_order_id || 0)
                    : String(preview.contact_id || batchPayload.contact_id || 0)
            };
        }

        function markSettlementRuntimeUnavailable(message) {
            document.querySelectorAll('[data-order-settlement-preview-form]').forEach(function (form) {
                var warning;
                var submitButton;
                var specificButton;

                if (!form || form.dataset.settlementRuntimeUnavailable === '1') {
                    return;
                }

                warning = document.createElement('div');
                warning.className = 'asdl-fin-note-box asdl-fin-note-box-danger asdl-fin-field-wide';
                warning.innerHTML = '<strong>Flujo de abonos no disponible.</strong><div>' + escapeHtml(message || 'No se pudo iniciar el modal de preview.') + '</div>';
                form.appendChild(warning);
                form.dataset.settlementRuntimeUnavailable = '1';

                submitButton = findSettlementSubmitButton(form);
                specificButton = form.querySelector('[data-order-settlement-specific-open]');

                if (submitButton) {
                    submitButton.disabled = true;
                }

                if (specificButton) {
                    specificButton.disabled = true;
                }
            });

            if (window.console && typeof window.console.error === 'function') {
                window.console.error('[ASDL Finanzas] ' + String(message || 'No se pudo iniciar el flujo de abonos.'));
            }
        }

        if (!window.ASDLFinanceAdmin) {
            markSettlementRuntimeUnavailable('No se cargo el runtime admin necesario para abrir el modal de abonos.');
            return;
        }

        setupOrderSettlementPreviewForms();

        if (!modal || !body || !confirmButton || !secondaryButton) {
            markSettlementRuntimeUnavailable('Faltan elementos del modal de abonos en esta vista. Recarga la pagina antes de volver a intentarlo.');
            return;
        }

        if (modal.dataset.previewReady === '1') {
            return;
        }

        if (!runtimeNonces.orderSettlementPreview || !runtimeNonces.orderSettlementStart || !runtimeNonces.orderSettlementContinue || !runtimeNonces.orderSettlementStatus || !runtimeNonces.orderSettlementResult) {
            markSettlementRuntimeUnavailable('No se cargaron los nonces del flujo de abonos. Recarga el perfil antes de continuar.');
            return;
        }

        function getRelevantSignature(form) {
            var accountInput = form.querySelector('[name="account_id"]');
            var methodInput = form.querySelector('[data-payment-method-select]');
            var totalInput = form.querySelector('[data-settlement-total]');
            var currencyInput = form.querySelector('[data-settlement-currency]');
            var dateInput = form.querySelector('[data-settlement-payment-date]');
            var forceDualInput = form.querySelector('[data-settlement-force-dual]');
            var dualModeInput = form.querySelector('[data-settlement-dual-mode]');
            var selectionModeInput = form.querySelector('[data-settlement-selection-mode]');
            var includeCreditInput = form.querySelector('[data-settlement-include-credit]');
            var remainderPolicyInput = form.querySelector('[data-settlement-remainder-policy]');
            var extraordinaryEnabledInput = form.querySelector('[data-settlement-extraordinary-enabled]');
            var extraordinaryReasonInput = form.querySelector('[data-settlement-extraordinary-reason]');
            var extraordinaryApprovalInput = form.querySelector('[data-settlement-extraordinary-approval-reference]');
            var extraordinaryNoteInput = form.querySelector('[data-settlement-extraordinary-note]');
            var extraordinaryAckInput = form.querySelector('[data-settlement-extraordinary-acknowledged]');
            return [
                (accountInput && accountInput.value) || '',
                (methodInput && methodInput.value) || '',
                (totalInput && totalInput.value) || '',
                (currencyInput && currencyInput.value) || '',
                (dateInput && dateInput.value) || '',
                (dualModeInput && dualModeInput.value) || (forceDualInput && forceDualInput.checked ? 'force' : 'off'),
                forceDualInput && forceDualInput.checked ? '1' : '0',
                (selectionModeInput && selectionModeInput.value) || 'oldest_first',
                (includeCreditInput && includeCreditInput.value) || '0',
	                (remainderPolicyInput && remainderPolicyInput.value) || (((selectionModeInput && selectionModeInput.value) || 'oldest_first') === 'specific' ? 'adjust_payment_total' : 'create_credit'),
                (extraordinaryEnabledInput && extraordinaryEnabledInput.value) || '0',
                (extraordinaryReasonInput && extraordinaryReasonInput.value) || '',
                (extraordinaryApprovalInput && extraordinaryApprovalInput.value) || '',
                (extraordinaryNoteInput && extraordinaryNoteInput.value) || '',
                (extraordinaryAckInput && extraordinaryAckInput.value) || '0',
                form.getAttribute('data-order-settlement-origin') || 'profile_settlement'
            ].join('|');
        }

        function buildSettlementClientOperationId() {
            return [
                'settlement',
                Date.now().toString(36),
                Math.random().toString(36).slice(2, 10)
            ].join('_');
        }

        function syncSettlementClientOperationId(form, operationId) {
            var field = form ? form.querySelector('[data-settlement-client-operation-id]') : null;
            var nextOperationId = String(operationId || '').trim();

            if (field) {
                field.value = nextOperationId;
            }

            if (form) {
                if (nextOperationId) {
                    form.dataset.settlementClientOperationId = nextOperationId;
                } else {
                    delete form.dataset.settlementClientOperationId;
                }
            }

            activeState.clientOperationId = nextOperationId;
            return nextOperationId;
        }

        function ensureSettlementClientOperationId(form, forceNew) {
            var currentOperationId = forceNew ? '' : String(activeState.clientOperationId || '').trim();
            var field;

            if (!currentOperationId && form) {
                field = form.querySelector('[data-settlement-client-operation-id]');
                currentOperationId = String(
                    (field && field.value)
                    || form.getAttribute('data-settlement-client-operation-id')
                    || ''
                ).trim();
            }

            if (!currentOperationId) {
                currentOperationId = buildSettlementClientOperationId();
            }

            return syncSettlementClientOperationId(form, currentOperationId);
        }

        function getPreviewPayload(form) {
            var formData = new FormData(form);
            var forceDualField = form.querySelector('[data-settlement-force-dual]');
            var dualModeField = form.querySelector('[data-settlement-dual-mode]');
            var selectionModeField = form.querySelector('[data-settlement-selection-mode]');
            var includeCreditField = form.querySelector('[data-settlement-include-credit]');
            var remainderPolicyField = form.querySelector('[data-settlement-remainder-policy]');
            var extraordinaryEnabledField = form.querySelector('[data-settlement-extraordinary-enabled]');
            var extraordinaryReasonField = form.querySelector('[data-settlement-extraordinary-reason]');
            var extraordinaryApprovalField = form.querySelector('[data-settlement-extraordinary-approval-reference]');
            var extraordinaryNoteField = form.querySelector('[data-settlement-extraordinary-note]');
            var extraordinaryAckField = form.querySelector('[data-settlement-extraordinary-acknowledged]');
            var selectionMode = (selectionModeField && selectionModeField.value) || 'oldest_first';
            var dualMode = (dualModeField && dualModeField.value) || (forceDualField && forceDualField.checked ? 'auto' : 'off');
            var clientOperationId = ensureSettlementClientOperationId(form, false);

            updateSettlementDualToggle(form);
            dualMode = (dualModeField && dualModeField.value) || (forceDualField && forceDualField.checked ? 'auto' : 'off');

            return {
                origin: form.getAttribute('data-order-settlement-origin') || 'profile_settlement',
                contact_id: Number(formData.get('contact_id') || 0),
                account_id: formData.get('account_id') || '',
                payment_date: formData.get('payment_date') || '',
                total: formData.get('total') || '',
                currency: formData.get('currency') || '',
                method_key: formData.get('method_key') || '',
                reference: formData.get('reference') || '',
                notes: formData.get('notes') || '',
                dual_discount_mode: dualMode,
                force_dual_discount: dualMode === 'force' ? '1' : '0',
                selection_mode: selectionMode,
                include_credit_balance: (includeCreditField && includeCreditField.value) || '0',
	                remainder_policy: (remainderPolicyField && remainderPolicyField.value) || (selectionMode === 'specific' ? 'adjust_payment_total' : 'create_credit'),
                extraordinary_closure_enabled: (extraordinaryEnabledField && extraordinaryEnabledField.value) || '0',
                extraordinary_closure_reason: (extraordinaryReasonField && extraordinaryReasonField.value) || '',
                extraordinary_closure_approval_reference: (extraordinaryApprovalField && extraordinaryApprovalField.value) || '',
                extraordinary_closure_note: (extraordinaryNoteField && extraordinaryNoteField.value) || '',
                extraordinary_closure_acknowledged: (extraordinaryAckField && extraordinaryAckField.value) || '0',
                approval_token: (form.querySelector('[data-settlement-approval-token]') || {}).value || '',
                selected_item_keys: selectionMode === 'specific' ? getSettlementSelectedKeysCsv() : '',
                client_operation_id: clientOperationId
            };
        }

        function trackSettlementEvent(eventType, form, message, extra) {
            var payload;

            if (!runtimeNonces.orderSettlementTrace || !form) {
                return Promise.resolve(null);
            }

            payload = Object.assign({}, getPreviewPayload(form), extra || {}, {
                event_type: eventType,
                message: message || ''
            });

            return requestAdminAjax('asdl_fin_order_settlement_trace', runtimeNonces.orderSettlementTrace, payload).catch(function () {
                return null;
            });
        }

        function reportSettlementFormValidity(form, options) {
            var methodField;
            var originalMethodRequired;
            var ignorePaymentMethod = !!(options && options.ignorePaymentMethod);

            if (!form || typeof form.reportValidity !== 'function') {
                return true;
            }

            if (ignorePaymentMethod) {
                methodField = form.querySelector('[data-payment-method-select]');
                if (methodField) {
                    originalMethodRequired = !!methodField.required;
                    methodField.required = false;
                }
            }

            try {
                return form.reportValidity();
            } finally {
                if (methodField) {
                    methodField.required = originalMethodRequired;
                }
            }
        }

        function showSettlementGuardMessage(titleText, message, actionLabel) {
            renderSettlementPreviewEmpty(
                body,
                titleText || 'No se pudo continuar con el abono.',
                message || 'Revisa la configuracion del abono e intenta otra vez.',
                'asdl-fin-settlement-preview-error'
            );
            unlockSettlementModal();
            setModalState(modal, true);

            if (activeState.form) {
                setSettlementFormBusy(activeState.form, false);
                resetSettlementFormLoading(activeState.form);
            }

            setActionState(actionLabel || 'Confirmar y aplicar', true, 'Cancelar');
        }

        function blockSettlementAction(form, reason, titleText, message, options) {
            options = options || {};

            if (form) {
                ensureSettlementClientOperationId(form, false);
            }

            if (!options.skipTrace) {
                trackSettlementEvent('order_settlement_ui_blocked', form, message, Object.assign({}, options.trace || {}, {
                    reason: reason || ''
                }));
            }

            if (!options.skipMessage) {
                showSettlementGuardMessage(titleText, message, options.actionLabel);
            }
        }

        function setActionState(primaryLabel, primaryDisabled, secondaryLabel, primaryLoading) {
            setAsyncButtonState(confirmButton, !!primaryLoading, 'Confirmar y aplicar', primaryLabel || 'Confirmar y aplicar');
            if (!primaryLoading) {
                confirmButton.textContent = primaryLabel || 'Confirmar y aplicar';
                confirmButton.disabled = !!primaryDisabled;
            }
            secondaryButton.textContent = secondaryLabel || 'Cancelar';
        }

        function isSettlementWorkflowLocked() {
            return ['preview_loading', 'starting', 'processing', 'result_loading'].indexOf(String(activeState.stage || '')) !== -1;
        }

        function lockSettlementModal(titleText, message) {
            setModalInteractionLock(
                modal,
                true,
                message || 'Procesando abono, no cierres esta ventana.',
                titleText || 'Procesando abono'
            );
        }

        function unlockSettlementModal() {
            setModalInteractionLock(modal, false);
        }

        function clearTimer() {
            window.clearTimeout(activeState.timer);
            activeState.timer = 0;
        }

        function clearAutoPreviewTimer() {
            window.clearTimeout(activeState.autoPreviewTimer);
            activeState.autoPreviewTimer = 0;
        }

        function resetActiveState(form) {
            clearTimer();
            clearAutoPreviewTimer();
            if (activeState.form) {
                setSettlementFormBusy(activeState.form, false);
            }
            activeState.form = form || null;
            activeState.preview = null;
            activeState.formSignature = '';
            activeState.batchId = 0;
            activeState.clientOperationId = '';
            activeState.stage = 'idle';
            activeState.selectedItemKeys = [];
            activeState.hasManualSelection = false;
            activeState.previewDirty = false;
            activeState.selectionDirty = false;
            activeState.runtimeRefresh = null;
            activeState.approval = operationalApprovalState({});
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

        function setSettlementExtraordinaryField(form, selector, value) {
            var field = form ? form.querySelector(selector) : null;

            if (field) {
                field.value = value;
            }
        }

        function updateActiveSettlementExtraordinaryDraft(values) {
            var current;

            if (!activeState.preview || String(activeState.preview.selection_mode || '') !== 'specific') {
                return;
            }

            current = activeState.preview.extraordinary_closure && typeof activeState.preview.extraordinary_closure === 'object'
                ? activeState.preview.extraordinary_closure
                : settlementExtraordinaryState(activeState.preview);
            activeState.preview.extraordinary_closure = Object.assign({}, current, values || {});
        }

        function clearExtraordinaryClosureDraftValidation() {
            updateActiveSettlementExtraordinaryDraft({
                execution_blocked: false,
                execution_blocked_message: ''
            });
        }

        function getExtraordinaryClosureDraftMissing(form) {
            var enabledField = form ? form.querySelector('[data-settlement-extraordinary-enabled]') : null;
            var reasonField = form ? form.querySelector('[data-settlement-extraordinary-reason]') : null;
            var noteField = form ? form.querySelector('[data-settlement-extraordinary-note]') : null;
            var ackField = form ? form.querySelector('[data-settlement-extraordinary-acknowledged]') : null;
            var missing = [];

            if (!enabledField || enabledField.value !== '1') {
                return missing;
            }

            if (!reasonField || !String(reasonField.value || '').trim()) {
                missing.push('motivo');
            }

            if (!noteField || !String(noteField.value || '').trim()) {
                missing.push('nota administrativa');
            }

            if (!ackField || ackField.value !== '1') {
                missing.push('confirmacion de que no corresponde a dinero recibido');
            }

            return missing;
        }

        function focusFirstExtraordinaryClosureMissingField(form) {
            var missing = getExtraordinaryClosureDraftMissing(form);
            var selector = '';
            var field;

            if (!missing.length) {
                return;
            }

            if (missing[0] === 'motivo') {
                selector = '[data-settlement-extraordinary-reason-input]';
            } else if (missing[0] === 'nota administrativa') {
                selector = '[data-settlement-extraordinary-note-input]';
            } else {
                selector = '[data-settlement-extraordinary-ack-input]';
            }

            field = body ? body.querySelector(selector) : null;
            if (field && typeof field.focus === 'function') {
                field.focus();
            }
        }

        function validateExtraordinaryClosureDraftForButton(form) {
            var missing = getExtraordinaryClosureDraftMissing(form);
            var message;

            if (!missing.length) {
                return true;
            }

            message = 'Para cerrar extraordinariamente este pedido debes completar: ' + missing.join(', ') + '.';
            updateActiveSettlementExtraordinaryDraft({
                enabled: true,
                message: message,
                execution_blocked: true,
                execution_blocked_message: message
            });
            refreshActiveSettlementPreview(false);
            setActionState('Completa el cierre extraordinario', true, 'Cancelar');
            focusFirstExtraordinaryClosureMissingField(form);

            trackSettlementEvent(
                'order_settlement_ui_blocked',
                form,
                message,
                {
                    reason: 'extraordinary_closure_incomplete',
                    stage: activeState.stage || 'preview',
                    missing_fields: missing
                }
            );

            return false;
        }

        function resetSettlementExtraordinaryDraft(form) {
            setSettlementExtraordinaryField(form, '[data-settlement-extraordinary-enabled]', '0');
            setSettlementExtraordinaryField(form, '[data-settlement-extraordinary-reason]', '');
            setSettlementExtraordinaryField(form, '[data-settlement-extraordinary-approval-reference]', '');
            setSettlementExtraordinaryField(form, '[data-settlement-extraordinary-note]', '');
            setSettlementExtraordinaryField(form, '[data-settlement-extraordinary-acknowledged]', '0');
        }

        function markSettlementPreviewDirty(options) {
            options = options || {};
            if (!activeState.preview || String(activeState.preview.selection_mode || '') !== 'specific') {
                return;
            }

            activeState.previewDirty = true;
            if (options.selectionChanged) {
                activeState.selectionDirty = true;
            }
            clearSettlementApproval('La seleccion o los datos del cierre cambiaron. Valida otra vez despues de recalcular la vista previa.');
            if (!options.skipRefresh) {
                refreshActiveSettlementPreview(activeState.selectionDirty);
            }
            setActionState('Actualizar vista', activeState.selectedItemKeys.length === 0, 'Cancelar');
        }

        function scheduleSpecificSelectionPreviewRefresh() {
            clearAutoPreviewTimer();

            if (
                !activeState.form
                || !activeState.preview
                || String(activeState.preview.selection_mode || '') !== 'specific'
                || activeState.selectedItemKeys.length !== 1
                || isSettlementWorkflowLocked()
                || isSettlementFormBusy(activeState.form)
            ) {
                return;
            }

            activeState.autoPreviewTimer = window.setTimeout(function () {
                activeState.autoPreviewTimer = 0;

                if (
                    !activeState.form
                    || !activeState.preview
                    || String(activeState.preview.selection_mode || '') !== 'specific'
                    || activeState.selectedItemKeys.length !== 1
                    || isSettlementWorkflowLocked()
                    || isSettlementFormBusy(activeState.form)
                ) {
                    return;
                }

                callPreview(activeState.form, getRelevantSignature(activeState.form));
            }, 350);
        }

        function extraordinaryClosureDraftReadyForPreview() {
            var form = activeState.form;
            var enabledField = form ? form.querySelector('[data-settlement-extraordinary-enabled]') : null;
            var reasonField = form ? form.querySelector('[data-settlement-extraordinary-reason]') : null;
            var noteField = form ? form.querySelector('[data-settlement-extraordinary-note]') : null;
            var ackField = form ? form.querySelector('[data-settlement-extraordinary-acknowledged]') : null;

            return !!(
                form
                && activeState.preview
                && String(activeState.preview.selection_mode || '') === 'specific'
                && !activeState.selectionDirty
                && activeState.selectedItemKeys.length === 1
                && enabledField
                && enabledField.value === '1'
                && reasonField
                && String(reasonField.value || '').trim()
                && noteField
                && String(noteField.value || '').trim()
                && ackField
                && ackField.value === '1'
                && !isSettlementWorkflowLocked()
                && !isSettlementFormBusy(form)
            );
        }

        function scheduleExtraordinaryClosurePreviewRefresh() {
            clearAutoPreviewTimer();

            if (!extraordinaryClosureDraftReadyForPreview()) {
                return;
            }

            activeState.autoPreviewTimer = window.setTimeout(function () {
                activeState.autoPreviewTimer = 0;

                if (!extraordinaryClosureDraftReadyForPreview()) {
                    return;
                }

                callPreview(activeState.form, getRelevantSignature(activeState.form));
            }, 650);
        }

        function setSettlementSelectionMode(form, mode) {
            var field = form ? form.querySelector('[data-settlement-selection-mode]') : null;

            if (field) {
                field.value = mode === 'specific' ? 'specific' : 'oldest_first';
            }
        }

        function setSettlementRemainderPolicy(form, policy) {
            var field = form ? form.querySelector('[data-settlement-remainder-policy]') : null;
            var normalizedPolicy = String(policy || '');

            if (normalizedPolicy === 'discard') {
                normalizedPolicy = 'adjust_payment_total';
            }

            if (['create_credit', 'adjust_payment_total', 'apply_oldest_first'].indexOf(normalizedPolicy) === -1) {
                normalizedPolicy = 'adjust_payment_total';
            }

            if (field) {
                field.value = normalizedPolicy;
            }
        }

        function getCurrentSelectedItemKeys(preview) {
            if (activeState.hasManualSelection) {
                return normalizeSettlementSelectedItemKeys(preview, activeState.selectedItemKeys);
            }

            if (Array.isArray(preview && preview.selected_item_keys) && preview.selected_item_keys.length) {
                return normalizeSettlementSelectedItemKeys(preview, preview.selected_item_keys);
            }

            return normalizeSettlementSelectedItemKeys(preview, null);
        }

        function getSettlementSelectedKeysCsv() {
            return (activeState.selectedItemKeys || []).filter(Boolean).join(',');
        }

        function updateSettlementPreviewHeader(mode) {
            if (!title || !description) {
                return;
            }

            if (mode === 'specific') {
                title.textContent = 'Abono a pedidos especificos';
                description.textContent = 'Marca solo las facturas que quieres cubrir en esta corrida. Si vas a exonerar o ajustar un pedido puntual, deja un solo pedido marcado: en este modo puedes continuar sin metodo cuando no hay un abono real nuevo.';
                return;
            }

            title.textContent = 'Vista previa del abono';
            description.textContent = 'Revisa como quedaran los pedidos antes de aplicar el abono. Si el metodo usa precio dual, el descuento se mostrara aqui.';
        }

        function renderPreviewState(preview) {
            var requiresSelection;
            var blocked;
            var primaryLabel;
            var approvalMissing;
            var extraordinary;

            activeState.preview = preview || null;
            activeState.stage = 'preview';
            activeState.selectedItemKeys = getCurrentSelectedItemKeys(preview);
            activeState.hasManualSelection = false;
            activeState.previewDirty = false;
            activeState.selectionDirty = false;
            requiresSelection = preview && preview.selection_mode === 'specific' && !activeState.selectedItemKeys.length;
            blocked = settlementExecutionBlocked(preview);
            approvalMissing = settlementApprovalMissing(preview);
            extraordinary = settlementExtraordinaryState(preview);
            primaryLabel = blocked
                ? ((preview
                    && preview.selection_mode === 'specific'
                    && extraordinary.available
                    && !extraordinary.enabled)
                    ? 'Activa el cierre extraordinario'
                    : ((preview
                    && preview.extraordinary_closure
                    && preview.extraordinary_closure.enabled)
                    ? 'Completa el cierre extraordinario'
                    : 'Revisa la configuracion del abono'))
                : (approvalMissing
                    ? 'Valida con autenticador'
                    : (requiresSelection ? 'Debes marcar al menos un pedido' : 'Confirmar y aplicar'));
            updateSettlementPreviewHeader(preview && preview.selection_mode === 'specific' ? 'specific' : 'oldest_first');
            renderSettlementPreview(body, preview || {}, {
                selectedItemKeys: activeState.selectedItemKeys,
                previewDirty: false,
                approvalState: activeState.approval
            });
            setActionState(
                primaryLabel,
                !preview || !preview.preview_signature || blocked || requiresSelection || approvalMissing,
                'Cancelar'
            );
            if (activeState.form) {
                setSettlementFormBusy(activeState.form, false);
                resetSettlementFormLoading(activeState.form);
            }
            unlockSettlementModal();
            setModalState(modal, true);
        }

        function renderProcessingState(snapshot) {
            var job = snapshot && snapshot.job ? snapshot.job : {};
            activeState.batchId = Number(job.batch_id || 0);
            activeState.stage = 'processing';
            renderSettlementProcessing(body, snapshot || {});
            setActionState('Procesando...', true, 'Procesando...', true);
            if (activeState.form) {
                setSettlementFormBusy(activeState.form, true);
                setSettlementFormLoading(activeState.form, true, 'Procesando abono...');
            }
            lockSettlementModal('Procesando abono', 'Procesando abono, no cierres esta ventana.');
            setModalState(modal, true);
        }

        function renderResultState(snapshot) {
            activeState.stage = 'result';
            activeState.runtimeRefresh = (snapshot && snapshot.runtime_refresh) || activeState.runtimeRefresh || null;
            renderSettlementResult(body, snapshot || {});
            setActionState('Actualizar perfil', false, 'Cerrar');
            if (activeState.form) {
                setSettlementFormBusy(activeState.form, false);
                resetSettlementFormLoading(activeState.form);
            }
            unlockSettlementModal();
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
                batch_id: batchId,
                client_operation_id: activeState.clientOperationId || ''
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
                    setSettlementFormBusy(activeState.form, false);
                    resetSettlementFormLoading(activeState.form);
                }
                unlockSettlementModal();
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
                activeState.stage = 'result_loading';
                renderSettlementProcessing(body, snapshot || {});
                setActionState('Finalizando...', true, 'Procesando...', true);
                if (activeState.form) {
                    setSettlementFormBusy(activeState.form, true);
                    setSettlementFormLoading(activeState.form, true, 'Aplicando descuentos...');
                }
                lockSettlementModal('Aplicando descuentos', 'Aplicando descuentos y consolidando el resultado final, no cierres esta ventana.');
                requestAdminAjax('asdl_fin_order_settlement_result', runtimeNonces.orderSettlementResult, {
                    batch_id: Number(job.batch_id || activeState.batchId || 0),
                    client_operation_id: activeState.clientOperationId || ''
                }).then(function (payload) {
                    var resultSnapshot = payload.snapshot || snapshot || {};
                    resultSnapshot.runtime_refresh = payload.runtime_refresh || resultSnapshot.runtime_refresh || null;
                    renderResultState(resultSnapshot);
                    return refreshRuntimeTargets(payload.runtime_refresh || resultSnapshot.runtime_refresh || null);
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
                setSettlementFormBusy(activeState.form, false);
                resetSettlementFormLoading(activeState.form);
            }
            unlockSettlementModal();
            setActionState('Actualizar perfil', false, 'Cerrar');
        }

        function callPreview(form, signature) {
            var payload;

            updateSettlementDualToggle(form);
            signature = getRelevantSignature(form);
            ensureSettlementClientOperationId(form, true);
            payload = getPreviewPayload(form);

            if (!payload.contact_id) {
                blockSettlementAction(
                    form,
                    'missing_contact_id',
                    'Perfil no valido.',
                    'No encontramos el perfil asociado al abono.',
                    {
                        trace: {
                            stage: 'preview'
                        }
                    }
                );
                return;
            }

            setSettlementApprovalToken(form, '');
            resetActiveState(form);
            activeState.form = form;
            activeState.formSignature = signature;
            activeState.clientOperationId = syncSettlementClientOperationId(form, payload.client_operation_id || '');
            activeState.stage = 'preview_loading';
            renderSettlementPreviewLoading(body);
            setSettlementFormBusy(form, true);
            setSettlementFormLoading(form, true, 'Calculando vista previa...');
            setActionState('Calculando...', true, 'Procesando...', true);
            lockSettlementModal('Calculando vista previa', 'Calculando vista previa del abono, no cierres esta ventana.');
            setModalState(modal, true);
            trackSettlementEvent(
                'order_settlement_preview_requested',
                form,
                'Se solicito la vista previa del abono.',
                {
                    stage: 'preview'
                }
            );

            requestAdminAjax('asdl_fin_order_settlement_status', runtimeNonces.orderSettlementStatus, {
                contact_id: payload.contact_id,
                origin: payload.origin,
                client_operation_id: activeState.clientOperationId || payload.client_operation_id || ''
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
                    activeState.clientOperationId = payload.client_operation_id || activeState.clientOperationId || '';
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
                setSettlementFormBusy(form, false);
                resetSettlementFormLoading(form);
                unlockSettlementModal();
                setActionState('Confirmar y aplicar', true, 'Cerrar');
                setModalState(modal, true);
            });
        }

        body.addEventListener('change', function (event) {
            var preview = activeState.preview;
            var eligibleItems = getSettlementEligibleItems(preview);
            var target = event.target;
            var itemCheckbox;
            var selectAllCheckbox;
            var remainderChoice;
            var extraordinaryToggle;
            var extraordinaryReasonInput;
            var extraordinaryAckInput;
            var approvalApproverInput;

            if (!(target instanceof Element)) {
                return;
            }

            itemCheckbox = target.matches('[data-order-settlement-item]')
                ? target
                : target.closest('[data-order-settlement-item]');
            selectAllCheckbox = target.matches('[data-order-settlement-select-all]')
                ? target
                : target.closest('[data-order-settlement-select-all]');
            remainderChoice = target.matches('[data-settlement-remainder-policy-choice]')
                ? target
                : target.closest('[data-settlement-remainder-policy-choice]');
            extraordinaryToggle = target.matches('[data-settlement-extraordinary-toggle]')
                ? target
                : target.closest('[data-settlement-extraordinary-toggle]');
            extraordinaryReasonInput = target.matches('[data-settlement-extraordinary-reason-input]')
                ? target
                : target.closest('[data-settlement-extraordinary-reason-input]');
            extraordinaryAckInput = target.matches('[data-settlement-extraordinary-ack-input]')
                ? target
                : target.closest('[data-settlement-extraordinary-ack-input]');
            approvalApproverInput = target.matches('[data-settlement-approval-approver]')
                ? target
                : target.closest('[data-settlement-approval-approver]');

            if (!preview || String(preview.selection_mode || '') !== 'specific') {
                return;
            }

            if (approvalApproverInput) {
                activeState.approval.approverUserId = String(approvalApproverInput.value || '');
                activeState.approval.approverLabel = approvalApproverInput.options && approvalApproverInput.selectedIndex >= 0
                    ? String(approvalApproverInput.options[approvalApproverInput.selectedIndex].text || '')
                    : '';
                refreshActiveSettlementPreview(activeState.selectionDirty);
                return;
            }

            if (extraordinaryToggle) {
                setSettlementExtraordinaryField(activeState.form, '[data-settlement-extraordinary-enabled]', extraordinaryToggle.checked ? '1' : '0');
                updateActiveSettlementExtraordinaryDraft({
                    enabled: extraordinaryToggle.checked
                });
                clearExtraordinaryClosureDraftValidation();
                markSettlementPreviewDirty();
                scheduleExtraordinaryClosurePreviewRefresh();
                return;
            }

            if (extraordinaryReasonInput) {
                setSettlementExtraordinaryField(activeState.form, '[data-settlement-extraordinary-reason]', extraordinaryReasonInput.value || '');
                updateActiveSettlementExtraordinaryDraft({
                    reason: extraordinaryReasonInput.value || '',
                    reason_label: extraordinaryReasonInput.options && extraordinaryReasonInput.selectedIndex >= 0
                        ? String(extraordinaryReasonInput.options[extraordinaryReasonInput.selectedIndex].text || '')
                        : ''
                });
                clearExtraordinaryClosureDraftValidation();
                markSettlementPreviewDirty({ skipRefresh: true });
                scheduleExtraordinaryClosurePreviewRefresh();
                return;
            }

            if (extraordinaryAckInput) {
                setSettlementExtraordinaryField(activeState.form, '[data-settlement-extraordinary-acknowledged]', extraordinaryAckInput.checked ? '1' : '0');
                updateActiveSettlementExtraordinaryDraft({
                    acknowledged: extraordinaryAckInput.checked
                });
                clearExtraordinaryClosureDraftValidation();
                markSettlementPreviewDirty({ skipRefresh: true });
                scheduleExtraordinaryClosurePreviewRefresh();
                return;
            }

            if (itemCheckbox) {
                var key = String(itemCheckbox.value || '');
                var selected = (activeState.selectedItemKeys || []).slice();

                if (itemCheckbox.checked) {
                    if (selected.indexOf(key) === -1) {
                        selected.push(key);
                    }
                } else {
                    selected = selected.filter(function (currentKey) {
                        return currentKey !== key;
                    });
                }

                activeState.selectedItemKeys = normalizeSettlementSelectedItemKeys(preview, selected);
                activeState.hasManualSelection = true;
                updateSettlementSpecificSelectionUi(body, preview, {
                    selectedItemKeys: activeState.selectedItemKeys,
                    previewDirty: true
                });
                markSettlementPreviewDirty({ selectionChanged: true });
                scheduleSpecificSelectionPreviewRefresh();
                return;
            }

            if (selectAllCheckbox) {
                activeState.selectedItemKeys = normalizeSettlementSelectedItemKeys(preview, selectAllCheckbox.checked
                    ? eligibleItems.map(function (item) { return settlementPreviewItemKey(item); }).filter(Boolean)
                    : []);
                activeState.hasManualSelection = true;
                updateSettlementSpecificSelectionUi(body, preview, {
                    selectedItemKeys: activeState.selectedItemKeys,
                    previewDirty: true
                });
                markSettlementPreviewDirty({ selectionChanged: true });
                scheduleSpecificSelectionPreviewRefresh();
                return;
            }

            if (remainderChoice) {
                setSettlementRemainderPolicy(activeState.form, remainderChoice.value || 'adjust_payment_total');
                activeState.hasManualSelection = true;
                updateSettlementSpecificSelectionUi(body, preview, {
                    selectedItemKeys: activeState.selectedItemKeys,
                    previewDirty: true
                });
                markSettlementPreviewDirty({ skipRefresh: true });
                if (activeState.form && activeState.selectedItemKeys.length && !isSettlementWorkflowLocked() && !isSettlementFormBusy(activeState.form)) {
                    callPreview(activeState.form, getRelevantSignature(activeState.form));
                }
            }
        });

        body.addEventListener('input', function (event) {
            var preview = activeState.preview;
            var target = event.target;
            var extraordinaryNoteInput;

            if (!(target instanceof Element) || !preview || String(preview.selection_mode || '') !== 'specific') {
                return;
            }

            extraordinaryNoteInput = target.matches('[data-settlement-extraordinary-note-input]')
                ? target
                : target.closest('[data-settlement-extraordinary-note-input]');

            if (extraordinaryNoteInput) {
                setSettlementExtraordinaryField(activeState.form, '[data-settlement-extraordinary-note]', extraordinaryNoteInput.value || '');
                updateActiveSettlementExtraordinaryDraft({
                    note: extraordinaryNoteInput.value || ''
                });
                clearExtraordinaryClosureDraftValidation();
                markSettlementPreviewDirty({ skipRefresh: true });
                scheduleExtraordinaryClosurePreviewRefresh();
            }
        });

        body.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-settlement-approval-validate]') : null;
            var refreshButton = event.target && event.target.closest ? event.target.closest('[data-order-settlement-refresh-preview]') : null;
            var gate;
            var requestOptions;
            var codeInput;
            var approverInput;
            var resolvedApproverId;

            if (refreshButton) {
                event.preventDefault();
                if (!activeState.form || !activeState.preview || String(activeState.preview.selection_mode || '') !== 'specific') {
                    return;
                }
                if (activeState.selectedItemKeys.length !== 1) {
                    blockSettlementAction(
                        activeState.form,
                        'invalid_extraordinary_selection',
                        'Debes marcar un solo pedido.',
                        'Marca exactamente un pedido y actualiza la vista para habilitar el cierre extraordinario.',
                        {
                            actionLabel: 'Debes marcar un solo pedido',
                            trace: {
                                stage: 'preview'
                            }
                        }
                    );
                    return;
                }
                clearAutoPreviewTimer();
                callPreview(activeState.form, getRelevantSignature(activeState.form));
                return;
            }

            if (!button) {
                return;
            }

            event.preventDefault();

            if (!validateExtraordinaryClosureDraftForButton(activeState.form)) {
                return;
            }

            if (!activeState.preview || activeState.previewDirty) {
                blockSettlementAction(
                    activeState.form,
                    activeState.previewDirty ? 'approval_preview_dirty' : 'approval_missing_preview',
                    'Recalcula la vista antes de validar.',
                    activeState.previewDirty
                        ? 'La seleccion o el formulario cambiaron. Actualiza la vista previa y luego valida la accion sensible.'
                        : 'Necesitas una vista previa vigente antes de validar este cierre extraordinario.',
                    {
                        actionLabel: 'Actualizar vista',
                        trace: {
                            stage: 'approval'
                        }
                    }
                );
                return;
            }

            gate = settlementApprovalGate(activeState.preview);
            if (!operationalApprovalNeedsToken(gate)) {
                return;
            }

            codeInput = body.querySelector('[data-settlement-approval-code]');
            approverInput = body.querySelector('[data-settlement-approval-approver]');
            requestOptions = buildSettlementApprovalRequest();
            resolvedApproverId = operationalApprovalResolvedApproverId(gate, activeState.approval);
            activeState.approval.pending = true;
            activeState.approval.error = '';
            activeState.approval.message = 'Validando codigo TOTP...';
            if (approverInput) {
                activeState.approval.approverUserId = String(approverInput.value || '');
                activeState.approval.approverLabel = approverInput.options && approverInput.selectedIndex >= 0
                    ? String(approverInput.options[approverInput.selectedIndex].text || '')
                    : '';
            } else if (resolvedApproverId) {
                activeState.approval.approverUserId = String(resolvedApproverId || '');
                activeState.approval.approverLabel = operationalApprovalResolvedApproverLabel(gate, activeState.approval);
            }
            refreshActiveSettlementPreview(false);

            requestOperationalApprovalInline({
                actionKey: requestOptions.actionKey,
                payload: requestOptions.payload,
                reason: requestOptions.reason,
                targetPlugin: requestOptions.targetPlugin,
                targetEntityType: requestOptions.targetEntityType,
                targetEntityId: requestOptions.targetEntityId,
                approverUserId: activeState.approval.approverUserId || '',
                code: codeInput ? codeInput.value : ''
            }).then(function (result) {
                activeState.approval = operationalApprovalState({
                    token: String(result.approval_token || ''),
                    expiresAt: String(result.expires_at || ''),
                    approverUserId: String(result.approver_user_id || activeState.approval.approverUserId || ''),
                    approverLabel: operationalApprovalResolvedApproverLabel(
                        gate,
                        { approverUserId: String(result.approver_user_id || activeState.approval.approverUserId || '') }
                    ),
                    verificationMethod: String(result.verification_method || ''),
                    message: 'Validacion TOTP lista.',
                    pending: false
                });
                setSettlementApprovalToken(activeState.form, activeState.approval.token);
                renderPreviewState(activeState.preview);
            }).catch(function (error) {
                activeState.approval.pending = false;
                activeState.approval.error = (error && error.message) || 'No se pudo validar la accion sensible.';
                setSettlementApprovalToken(activeState.form, '');
                renderPreviewState(activeState.preview);
            });
        });

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

            if (isSettlementFormBusy(form) || isSettlementWorkflowLocked()) {
                blockSettlementAction(
                    form,
                    isSettlementWorkflowLocked() ? 'workflow_locked' : 'form_busy',
                    'El abono sigue en proceso.',
                    'Ya hay una simulacion o un abono corriendo para este perfil. Espera a que termine antes de volver a intentarlo.',
                    {
                        skipMessage: isModalInteractionLocked(modal),
                        trace: {
                            stage: 'preview'
                        }
                    }
                );
                return;
            }

            if (!reportSettlementFormValidity(form)) {
                return;
            }

            setSettlementSelectionMode(form, 'oldest_first');
            setSettlementRemainderPolicy(form, 'create_credit');
            resetSettlementExtraordinaryDraft(form);
            activeState.selectedItemKeys = [];
            activeState.hasManualSelection = false;
            activeState.previewDirty = false;
            updateSettlementPreviewHeader('oldest_first');
            callPreview(form, getRelevantSignature(form));
        }, true);

        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-order-settlement-specific-open]') : null;
            var form;

            if (!button) {
                return;
            }

            form = button.closest('[data-order-settlement-preview-form]');
            if (!form) {
                return;
            }

            event.preventDefault();

            if (isSettlementFormBusy(form) || isSettlementWorkflowLocked()) {
                blockSettlementAction(
                    form,
                    isSettlementWorkflowLocked() ? 'workflow_locked' : 'form_busy',
                    'El abono sigue en proceso.',
                    'No puedes abrir Pedidos especificos mientras el modal o el formulario siguen ocupados.',
                    {
                        skipMessage: isModalInteractionLocked(modal),
                        trace: {
                            stage: 'preview'
                        }
                    }
                );
                return;
            }

            if (!reportSettlementFormValidity(form, { ignorePaymentMethod: true })) {
                return;
            }

	            setSettlementSelectionMode(form, 'specific');
	            setSettlementRemainderPolicy(form, 'adjust_payment_total');
	            activeState.selectedItemKeys = [];
            activeState.hasManualSelection = false;
            activeState.previewDirty = false;
            updateSettlementPreviewHeader('specific');
            callPreview(form, getRelevantSignature(form));
        });

        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('[data-settlement-dual-apply]') : null;
            var form;
            var totalInput;
            var dualTotal;
            var container;
            var checkbox;
            var methodInput;
            var currencyInput;
            var methodValue;
            var currencyValue;
            var strictState;
            var referenceState;
            var qualifies;
            var referenceActive;

            if (!button) {
                return;
            }

            form = button.closest('[data-order-settlement-preview-form]');
            if (!form) {
                container = button.closest('[data-profile-context-disclosure], .asdl-fin-profile-context-panel, .asdl-fin-contact-settlement-panel');
                form = container ? container.querySelector('[data-order-settlement-preview-form]') : null;
            }
            totalInput = form ? form.querySelector('[data-settlement-total]') : null;
            checkbox = form ? form.querySelector('[data-settlement-force-dual]') : null;
            methodInput = form ? form.querySelector('[data-payment-method-select]') : null;
            currencyInput = form ? form.querySelector('[data-settlement-currency]') : null;
            methodValue = String(methodInput && methodInput.value ? methodInput.value : '').trim();
            currencyValue = String(currencyInput && currencyInput.value ? currencyInput.value : '').trim().toUpperCase();
            strictState = getSettlementDualStrictState(form);
            referenceState = getSettlementDualReferenceState(form);
            referenceActive = referenceState ? referenceState.active : null;
            qualifies = methodValue
                ? (strictState !== null ? strictState.qualifies : settlementMethodQualifiesForDual(methodValue, currencyValue))
                : false;
	            dualTotal = 0;

	            if (form) {
	                dualTotal = referenceState
	                    ? referenceState.total
	                    : parseNumber(form.getAttribute('data-settlement-dual-total') || '0');
	            }

            if (
                !form
                || !totalInput
                || isSettlementFormBusy(form)
                || form.dataset.settlementDualSyncing === '1'
                || !checkbox
                || !checkbox.checked
                || currencyValue !== 'USD'
                || !qualifies
                || referenceActive === false
                || dualTotal <= 0
            ) {
                if (form) {
                    updateSettlementDualToggle(form);
                }
                return;
            }

            event.preventDefault();
            totalInput.value = dualTotal.toFixed(2);
            totalInput.dispatchEvent(new Event('input', { bubbles: true }));
            totalInput.dispatchEvent(new Event('change', { bubbles: true }));
            totalInput.focus();
            totalInput.select();
        });

        confirmButton.addEventListener('click', function () {
            var form = activeState.form;
            var isSpecificMode;
            if (!form) {
                blockSettlementAction(
                    activeState.form,
                    'missing_form_state',
                    'No encontramos el formulario del abono.',
                    'Recarga el perfil y vuelve a calcular la vista previa antes de confirmar.',
                    {
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            if (activeState.stage === 'result') {
                (activeState.runtimeRefresh
                    ? refreshRuntimeTargets(activeState.runtimeRefresh)
                    : refreshCurrentContactDetailRuntime()
                ).finally(function () {
                    resetActiveState();
                    setModalState(modal, false);
                });
                return;
            }

            if (isSettlementWorkflowLocked() || isSettlementFormBusy(form)) {
                blockSettlementAction(
                    form,
                    isSettlementWorkflowLocked() ? 'workflow_locked' : 'form_busy',
                    'El abono sigue en proceso.',
                    'Todavia estamos procesando esta operacion. Espera a que termine antes de confirmar otra vez.',
                    {
                        skipMessage: isModalInteractionLocked(modal),
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            if (activeState.stage !== 'preview' || !activeState.preview) {
                blockSettlementAction(
                    form,
                    'missing_preview_state',
                    'Falta una vista previa valida.',
                    'No encontramos una simulacion vigente del abono. Calcula la vista previa otra vez antes de confirmar.',
                    {
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            isSpecificMode = String(activeState.preview.selection_mode || '') === 'specific';

            if (isSpecificMode && !validateExtraordinaryClosureDraftForButton(form)) {
                return;
            }

            if (isSpecificMode && activeState.previewDirty) {
                trackSettlementEvent(
                    'order_settlement_ui_blocked',
                    form,
                    'La seleccion cambio y obliga a recalcular la vista previa antes de confirmar.',
                    {
                        reason: 'preview_dirty',
                        stage: 'start'
                    }
                );
                callPreview(form, getRelevantSignature(form));
                return;
            }

            if (isSpecificMode && (!activeState.selectedItemKeys.length || !activeState.preview.preview_signature)) {
                blockSettlementAction(
                    form,
                    !activeState.selectedItemKeys.length ? 'missing_specific_selection' : 'missing_preview_signature',
                    'Debes marcar al menos un pedido.',
                    'En Pedidos especificos solo se pueden confirmar las facturas seleccionadas manualmente. Marca al menos una y actualiza la vista antes de aplicar.',
                    {
                        actionLabel: 'Debes marcar al menos un pedido',
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            if (settlementExecutionBlocked(activeState.preview)) {
                blockSettlementAction(
                    form,
                    'execution_blocked',
                    'El abono quedo bloqueado.',
                    settlementExecutionBlockedMessage(activeState.preview),
                    {
                        actionLabel: 'Revisa la configuracion del abono',
                        trace: {
                            stage: 'start',
                            execution_blocked: true
                        }
                    }
                );
                return;
            }

            if (settlementApprovalMissing(activeState.preview)) {
                blockSettlementAction(
                    form,
                    'approval_token_missing',
                    'Falta la validacion operativa.',
                    settlementApprovalGate(activeState.preview).message || 'Valida primero esta accion sensible con tu autenticador antes de confirmar el cierre extraordinario.',
                    {
                        actionLabel: 'Valida con autenticador',
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            if (getRelevantSignature(form) !== activeState.formSignature) {
                blockSettlementAction(
                    form,
                    'form_signature_changed',
                    'Falta recalcular la vista previa.',
                    'Los datos del formulario cambiaron despues de la simulacion. Calcula la vista previa otra vez antes de confirmar.',
                    {
                        trace: {
                            stage: 'start'
                        }
                    }
                );
                return;
            }

            syncHiddenSignature(form, activeState.preview.preview_signature || '');
            activeState.stage = 'starting';
            setSettlementFormBusy(form, true);
            setSettlementFormLoading(form, true, 'Procesando abono...');
            setActionState('Iniciando...', true, 'Procesando...', true);
            renderSettlementPreviewLoading(body);
            lockSettlementModal('Procesando abono', 'Procesando abono, no cierres esta ventana.');
            trackSettlementEvent(
                'order_settlement_start_requested',
                form,
                'Se solicito iniciar el abono.',
                {
                    stage: 'start',
                    preview_signature: activeState.preview.preview_signature || ''
                }
            );

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
                setSettlementFormBusy(form, false);
                resetSettlementFormLoading(form);
                unlockSettlementModal();
                setActionState('Confirmar y aplicar', true, 'Cerrar');
            });
        });

        secondaryButton.addEventListener('click', function () {
            if (isSettlementWorkflowLocked()) {
                trackSettlementEvent(
                    'order_settlement_ui_blocked',
                    activeState.form,
                    'No puedes cerrar el modal mientras el abono sigue en proceso.',
                    {
                        reason: 'modal_locked_secondary',
                        stage: activeState.stage || 'processing'
                    }
                );
                return;
            }

            if (activeState.form && activeState.stage !== 'processing') {
                setSettlementFormBusy(activeState.form, false);
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
            selectedItemKeys: [],
            runtimeRefresh: null
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
            state.runtimeRefresh = null;
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
            state.runtimeRefresh = (snapshot && snapshot.runtime_refresh) || state.runtimeRefresh || null;
            body.innerHTML = buildAssumptionResultHtml(snapshot || {});
            setActionState('Nueva vista previa', false, 'Actualizar vista', false, false, false, 'Cerrar');
            setModalState(modal, true);
        }

        function syncAffectedViews(plan) {
            if (plan) {
                return refreshRuntimeTargets(plan);
            }

            if (String(state.origin || '').indexOf('profile_') === 0) {
                return refreshCurrentContactDetailRuntime();
            }

            return Promise.resolve();
        }

        function refreshAfterAssumption(plan) {
            if (plan) {
                return refreshRuntimeTargets(plan);
            }

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
                    var resultSnapshot = payload.snapshot || snapshot || {};
                    resultSnapshot.runtime_refresh = payload.runtime_refresh || resultSnapshot.runtime_refresh || null;
                    renderResultState(resultSnapshot);
                    return syncAffectedViews(payload.runtime_refresh || resultSnapshot.runtime_refresh || null);
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
                var resultSnapshot = payload.snapshot || {};
                resultSnapshot.runtime_refresh = payload.runtime_refresh || resultSnapshot.runtime_refresh || null;
                renderResultState(resultSnapshot);
                return syncAffectedViews(payload.runtime_refresh || resultSnapshot.runtime_refresh || null);
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
                var resultSnapshot = payload.snapshot || {};
                resultSnapshot.runtime_refresh = payload.runtime_refresh || resultSnapshot.runtime_refresh || null;
                renderResultState(resultSnapshot);
                return syncAffectedViews(payload.runtime_refresh || resultSnapshot.runtime_refresh || null);
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
                refreshAfterAssumption(state.runtimeRefresh).finally(function () {
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
            return !!(
                context
                && target
                && target.target_type === 'store_orders'
                && settlementMethodQualifiesForDual('payroll_deduction', context.currency)
            );
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

        function getPayrollCommitmentConfig(form) {
            var cached = form && form.__asdlPayrollCommitmentConfig;
            var source;
            var parsed;

            if (!form) {
                return {
                    items: [],
                    next_cycle_date: '',
                    currency: 'USD',
                    payroll_id: 0,
                    contact_id: 0,
                    paid_at: '',
                    approval_gate: {}
                };
            }

            if (cached && typeof cached === 'object') {
                return cached;
            }

            source = form.querySelector('[data-payroll-commitment-config]');
            if (!source) {
                parsed = {};
            } else {
                try {
                    parsed = JSON.parse(source.textContent || '{}');
                } catch (error) {
                    parsed = {};
                }
            }

            if (!parsed || typeof parsed !== 'object') {
                parsed = {};
            }

            parsed.items = Array.isArray(parsed.items) ? parsed.items : [];
            form.__asdlPayrollCommitmentConfig = parsed;

            return parsed;
        }

        function payrollCommitmentState(form) {
            var existing = form && form.__asdlPayrollCommitmentState;
            var config;

            if (!form) {
                return {
                    actions: {},
                    reason: '',
                    approval: operationalApprovalState({})
                };
            }

            if (existing && typeof existing === 'object') {
                return existing;
            }

            config = getPayrollCommitmentConfig(form);
            existing = {
                actions: {},
                reason: '',
                approval: operationalApprovalState({}),
                gate: operationalApprovalGateState(config.approval_gate || {})
            };

            form.__asdlPayrollCommitmentState = existing;
            return existing;
        }

        function payrollCommitmentItems(form) {
            var config = getPayrollCommitmentConfig(form);
            return Array.isArray(config.items) ? config.items : [];
        }

        function payrollCommitmentSelectedOverrides(form) {
            var state = payrollCommitmentState(form);

            return payrollCommitmentItems(form).map(function (item) {
                var key = String(item && item.item_key ? item.item_key : '');
                var action = key && state.actions[key] ? String(state.actions[key]) : 'apply';

                if (action !== 'skip_once' && action !== 'defer_next_cycle') {
                    return null;
                }

                return {
                    item_key: key,
                    plan_id: Number(item.plan_id || 0),
                    installment_id: Number(item.installment_id || 0),
                    settlement_direction: String(item.settlement_direction || 'receivable'),
                    action: action
                };
            }).filter(function (item) {
                return item && item.plan_id > 0 && item.installment_id > 0;
            });
        }

        function payrollCommitmentHasOverrides(form) {
            return payrollCommitmentSelectedOverrides(form).length > 0;
        }

        function setPayrollCommitmentApprovalToken(form, token) {
            var field = form ? form.querySelector('[data-payroll-commitment-approval-token]') : null;

            if (field) {
                field.value = String(token || '');
            }
        }

        function syncPayrollCommitmentHiddenFields(form) {
            var actionsField = form ? form.querySelector('[data-payroll-commitment-actions-json]') : null;
            var state = payrollCommitmentState(form);

            if (actionsField) {
                actionsField.value = JSON.stringify(payrollCommitmentSelectedOverrides(form));
            }

            setPayrollCommitmentApprovalToken(form, operationalApprovalState(state.approval).token);
        }

        function clearPayrollCommitmentApproval(form, message) {
            var state = payrollCommitmentState(form);
            var approval = operationalApprovalState(state.approval);

            state.approval = operationalApprovalState({
                message: String(message || ''),
                approverUserId: approval.approverUserId || '',
                approverLabel: approval.approverLabel || ''
            });

            syncPayrollCommitmentHiddenFields(form);
        }

        function payrollCommitmentApprovalMissing(form) {
            var state = payrollCommitmentState(form);

            return payrollCommitmentHasOverrides(form)
                && operationalApprovalNeedsToken(state.gate)
                && !operationalApprovalHasToken(state.approval);
        }

        function payrollCommitmentBlockingItem(form) {
            var state = payrollCommitmentState(form);

            return payrollCommitmentItems(form).find(function (item) {
                var key = String(item && item.item_key ? item.item_key : '');
                var action = key && state.actions[key] ? String(state.actions[key]) : 'apply';

                return !!(item && item.blocked) && action === 'apply';
            }) || null;
        }

        function payrollCommitmentActionLabel(item, action, nextCycleDate) {
            var direction = String(item && item.settlement_direction ? item.settlement_direction : 'receivable');

            if (item && item.blocked && action === 'apply') {
                return String(item.blocked_reason_message || 'La deuda base ya no tiene saldo abierto suficiente para cobrar esta cuota tal como está.');
            }

            if (action === 'skip_once') {
                return direction === 'payable'
                    ? 'Este pago extra no se aplicara en esta nomina. Quedara pendiente para la siguiente corrida elegible.'
                    : 'Esta cuota no se cobrara en esta nomina. Quedara pendiente para la siguiente corrida elegible.';
            }

            if (action === 'defer_next_cycle') {
                return direction === 'payable'
                    ? 'Esta cuota se movera a la proxima nomina (' + formatPreviewDateLabel(nextCycleDate || '') + ') y no se pagara en esta corrida.'
                    : 'Esta cuota se movera a la proxima nomina (' + formatPreviewDateLabel(nextCycleDate || '') + ') y no se cobrara en esta corrida.';
            }

            if (item && item.is_recovery_plan) {
                return String(item.recovery_helper || 'Este cobro liquida primero la deuda base y luego descuenta la cuota del compromiso por el mismo monto.');
            }

            return '';
        }

        function payrollCommitmentActionOptions(item, nextCycleDate, selectedAction) {
            var direction = String(item && item.settlement_direction ? item.settlement_direction : 'receivable');
            var isPayable = direction === 'payable';
            var options = [
                {
                    value: 'apply',
                    label: isPayable ? 'Pagar normal' : 'Cobrar normal',
                    disabled: false
                },
                {
                    value: 'skip_once',
                    label: isPayable ? 'No pagar en esta nomina' : 'No cobrar en esta nomina',
                    disabled: false
                },
                {
                    value: 'defer_next_cycle',
                    label: 'Rodar a proxima nomina',
                    disabled: !nextCycleDate
                }
            ];

            return options.map(function (option) {
                var selected = String(selectedAction || 'apply') === String(option.value) ? ' selected' : '';
                var disabled = option.disabled ? ' disabled' : '';

                return '<option value="' + escapeHtml(option.value) + '"' + selected + disabled + '>' + escapeHtml(option.label) + '</option>';
            }).join('');
        }

        function buildPayrollCommitmentApprovalRequest(form) {
            var config = getPayrollCommitmentConfig(form);
            var state = payrollCommitmentState(form);
            var normalizedReason = String(state.reason || '').replace(/<[^>]*>/g, '').trim();
            var actions = payrollCommitmentSelectedOverrides(form).map(function (item) {
                return {
                    plan_id: Number(item.plan_id || 0),
                    installment_id: Number(item.installment_id || 0),
                    settlement_direction: String(item.settlement_direction || 'receivable'),
                    action: String(item.action || '')
                };
            });

            actions.sort(function (left, right) {
                if (Number(left.installment_id || 0) !== Number(right.installment_id || 0)) {
                    return Number(left.installment_id || 0) - Number(right.installment_id || 0);
                }

                if (Number(left.plan_id || 0) !== Number(right.plan_id || 0)) {
                    return Number(left.plan_id || 0) - Number(right.plan_id || 0);
                }

                return String(left.settlement_direction || '').localeCompare(String(right.settlement_direction || ''));
            });

            return {
                actionKey: (state.gate && state.gate.action_key) || '',
                payload: {
                    contact_id: Number(config.contact_id || 0),
                    payroll_id: Number(config.payroll_id || 0),
                    scheduled_payment_date: String(config.paid_at || ''),
                    currency: String(config.currency || 'USD'),
                    override_reason: normalizedReason,
                    actions: actions
                },
                reason: normalizedReason,
                targetPlugin: 'analysis-financiero-plugin',
                targetEntityType: 'payroll_period',
                targetEntityId: String(config.payroll_id || 0)
            };
        }

        function refreshPayrollCommitmentSubmitState(form) {
            var submitButton = findSettlementSubmitButton(form);
            var state = payrollCommitmentState(form);
            var reasonMissing;
            var approvalMissing;
            var blockingItem;

            if (!submitButton || form.dataset.payrollSubmitting === '1') {
                return;
            }

            blockingItem = payrollCommitmentBlockingItem(form);
            reasonMissing = payrollCommitmentHasOverrides(form) && !String(state.reason || '').trim();
            approvalMissing = payrollCommitmentApprovalMissing(form);

            if (blockingItem) {
                submitButton.disabled = true;
                submitButton.textContent = 'Revisa la deuda base bloqueada';
                return;
            }

            if (reasonMissing) {
                submitButton.disabled = true;
                submitButton.textContent = 'Indica el motivo operativo';
                return;
            }

            if (approvalMissing) {
                submitButton.disabled = true;
                submitButton.textContent = 'Valida con autenticador';
                return;
            }

            submitButton.disabled = false;
            submitButton.textContent = 'Procesar pago';
        }

        function renderPayrollCommitmentSection(form) {
            var container = form ? form.querySelector('[data-payroll-commitment-section]') : null;
            var config = getPayrollCommitmentConfig(form);
            var state = payrollCommitmentState(form);
            var items = payrollCommitmentItems(form);
            var nextCycleDate = String(config.next_cycle_date || '');
            var reason = String(state.reason || '');
            var gate = operationalApprovalGateState(state.gate || {});
            var overrides = payrollCommitmentSelectedOverrides(form);
            var deductionTotal = 0;
            var payoutTotal = 0;

            if (!container) {
                return;
            }

            items.forEach(function (item) {
                var amount = Number(item && item.planned_amount ? item.planned_amount : 0);
                if (String(item && item.settlement_direction ? item.settlement_direction : 'receivable') === 'payable') {
                    payoutTotal += amount;
                } else {
                    deductionTotal += amount;
                }
            });

            if (!items.length) {
                container.innerHTML = ''
                    + '<span>Compromisos previstos en esta nomina</span>'
                    + '<div class="asdl-fin-note-box"><strong>Sin compromisos previstos.</strong><div>Esta corrida no trae cuotas automaticas para cobrar o pagar desde nomina.</div></div>';
                syncPayrollCommitmentHiddenFields(form);
                refreshPayrollCommitmentSubmitState(form);
                return;
            }

            container.innerHTML = ''
                + '<span>Compromisos previstos en esta nomina</span>'
                + '<div class="asdl-fin-note-box asdl-fin-payroll-commitment-box">'
                + '<strong>Compromisos previstos en esta nomina</strong>'
                + '<div>Revisa individualmente cada cuota proyectada. Puedes dejarla normal, no aplicarla solo en esta corrida o rodarla a la proxima nomina.</div>'
                + '<div class="asdl-fin-settlement-preview-summary">'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Cuotas previstas</strong><span>' + escapeHtml(String(items.length)) + '</span><small>Total de compromisos detectados para este pago.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Descuentos</strong><span>' + escapeHtml(formatCurrencyAmount(deductionTotal, config.currency || 'USD')) + '</span><small>Cuotas que reducen el neto de esta nomina.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Pagos extra</strong><span>' + escapeHtml(formatCurrencyAmount(payoutTotal, config.currency || 'USD')) + '</span><small>Compromisos a favor del empleado incluidos en esta corrida.</small></div>'
                + '<div class="asdl-fin-settlement-preview-card"><strong>Proxima nomina</strong><span>' + escapeHtml(nextCycleDate ? formatPreviewDateLabel(nextCycleDate) : 'Sin fecha') + '</span><small>Fecha usada si decides rodar una cuota.</small></div>'
                + '</div>'
                + '<div class="asdl-fin-payroll-commitment-list">'
                + items.map(function (item) {
                    var key = String(item.item_key || '');
                    var action = state.actions[key] ? String(state.actions[key]) : 'apply';
                    var helper = payrollCommitmentActionLabel(item, action, nextCycleDate);
                    var directionLabel = String(item.settlement_direction || 'receivable') === 'payable' ? 'Pago extra' : 'Descuento';
                    var directionTone = item && item.blocked && action === 'apply'
                        ? 'danger'
                        : (String(item.settlement_direction || 'receivable') === 'payable' ? 'info' : 'warning');
                    var installmentLabel = item.installment_title
                        ? String(item.installment_title)
                        : ('Cuota #' + String(item.sequence_no || item.installment_id || ''));

                    return ''
                        + '<div class="asdl-fin-payroll-commitment-row">'
                        + '<div class="asdl-fin-payroll-commitment-main">'
                        + '<strong>' + escapeHtml(item.title || ('Compromiso #' + String(item.plan_id || 0))) + '</strong>'
                        + '<small>' + escapeHtml(installmentLabel) + '</small>'
                        + '<div class="asdl-fin-approval-inline-meta">'
                        + '<span>' + renderPill(directionLabel, directionTone) + '</span>'
                        + '<span><strong>Vence:</strong> ' + escapeHtml(formatPreviewDateLabel(item.due_date || '')) + '</span>'
                        + '<span><strong>Monto:</strong> ' + escapeHtml(formatCurrencyAmount(item.planned_amount || 0, config.currency || 'USD')) + '</span>'
                        + '</div>'
                        + (helper ? '<small class="asdl-fin-payroll-commitment-helper">' + escapeHtml(helper) + '</small>' : '')
                        + '</div>'
                        + '<label class="asdl-fin-field asdl-fin-payroll-commitment-action">'
                        + '<span>Accion</span>'
                        + '<select data-payroll-commitment-action-select data-item-key="' + escapeHtml(key) + '">'
                        + payrollCommitmentActionOptions(item, nextCycleDate, action)
                        + '</select>'
                        + '</label>'
                        + '</div>';
                }).join('')
                + '</div>'
                + (
                    overrides.length
                        ? '<label class="asdl-fin-field asdl-fin-field-wide">'
                            + '<span>Motivo operativo *</span>'
                            + '<textarea rows="3" name="payroll_commitment_override_reason" data-payroll-commitment-override-reason placeholder="Explica por que esta cuota no se cobrara ahora o por que se rodara a la proxima nomina.">' + escapeHtml(reason) + '</textarea>'
                            + '<small>Este motivo quedara guardado en la nomina y en la auditoria del ajuste operativo.</small>'
                        + '</label>'
                        + buildOperationalApprovalPanel('payroll', gate, state.approval, {
                            title: 'Validacion operativa',
                            scopeLabel: 'este ajuste de compromisos en nomina',
                            helpMessage: 'Si cambias una accion individual o el motivo, se pedira validar otra vez.'
                        })
                        : '<small class="asdl-fin-payroll-commitment-helper">Si una cuota no debe cobrarse esta semana, cambiala individualmente a "No cobrar en esta nomina" o "Rodar a proxima nomina".</small>'
                )
                + '</div>';

            syncPayrollCommitmentHiddenFields(form);
            refreshPayrollCommitmentSubmitState(form);
        }

        function validatePayrollCommitmentOverrides(form) {
            var state = payrollCommitmentState(form);
            var blockingItem = payrollCommitmentBlockingItem(form);

            if (blockingItem) {
                return String(blockingItem.blocked_reason_message || 'Hay un compromiso recovery sin deuda base abierta suficiente. Corrige el respaldo o rueda la cuota antes de procesar la nomina.');
            }

            if (!payrollCommitmentHasOverrides(form)) {
                return '';
            }

            if (!String(state.reason || '').trim()) {
                return 'Indica el motivo operativo antes de ajustar compromisos dentro de esta nomina.';
            }

            if (payrollCommitmentApprovalMissing(form)) {
                return 'Valida con autenticador los ajustes operativos antes de procesar el pago.';
            }

            return '';
        }

        function refreshPayrollContext(plan) {
            var contactRuntime = document.querySelector('[data-runtime-action="asdl_fin_admin_runtime"][data-runtime-param-page-key="contacts"]');

            if (plan) {
                return refreshRuntimeTargets(plan);
            }

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
            if (injectedForm) {
                renderPayrollCommitmentSection(injectedForm);
            }
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

            var overrideError = validatePayrollCommitmentOverrides(form);
            if (overrideError) {
                buildFeedbackBox(form, overrideError);
                refreshPayrollCommitmentSubmitState(form);
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
                return refreshPayrollContext(result && result.runtime_refresh ? result.runtime_refresh : null);
            }).catch(function (error) {
                buildFeedbackBox(form, (error && error.message) || 'No se pudo completar esta accion.');
                setAsyncButtonState(submitButton, false, 'Procesar pago');
                delete form.dataset.payrollSubmitting;
            });
        });

        body.addEventListener('change', function (event) {
            var actionSelect = event.target.closest('[data-payroll-commitment-action-select]');
            var approverSelect = event.target.closest('[data-payroll-approval-approver]');
            var form;
            var state;
            var itemKey;

            if (actionSelect) {
                form = actionSelect.closest('[data-payroll-process-modal-form]');
                if (!form) {
                    return;
                }

                state = payrollCommitmentState(form);
                itemKey = String(actionSelect.getAttribute('data-item-key') || '');

                if (itemKey) {
                    state.actions[itemKey] = String(actionSelect.value || 'apply');
                    if (state.actions[itemKey] === 'apply') {
                        delete state.actions[itemKey];
                    }
                }

                clearPayrollCommitmentApproval(form, 'Los ajustes de compromisos cambiaron. Vuelve a validar con autenticador antes de procesar la nomina.');
                renderPayrollCommitmentSection(form);
                return;
            }

            if (approverSelect) {
                form = approverSelect.closest('[data-payroll-process-modal-form]');
                if (!form) {
                    return;
                }

                state = payrollCommitmentState(form);
                state.approval.approverUserId = String(approverSelect.value || '');
                state.approval.approverLabel = approverSelect.options && approverSelect.selectedIndex >= 0
                    ? String(approverSelect.options[approverSelect.selectedIndex].text || '')
                    : '';
            }
        });

        body.addEventListener('input', function (event) {
            var reasonInput = event.target.closest('[data-payroll-commitment-override-reason]');
            var form;
            var state;

            if (!reasonInput) {
                return;
            }

            form = reasonInput.closest('[data-payroll-process-modal-form]');
            if (!form) {
                return;
            }

            state = payrollCommitmentState(form);
            state.reason = String(reasonInput.value || '');

            if (operationalApprovalHasToken(state.approval)) {
                clearPayrollCommitmentApproval(form, 'El motivo operativo cambio. Vuelve a validar con autenticador antes de procesar la nomina.');
            }

            syncPayrollCommitmentHiddenFields(form);
            refreshPayrollCommitmentSubmitState(form);
        });

        body.addEventListener('click', function (event) {
            var approvalButton = event.target.closest('[data-payroll-approval-validate]');
            var form;
            var state;
            var codeInput;
            var approverInput;
            var requestOptions;

            if (!approvalButton) {
                return;
            }

            event.preventDefault();
            form = approvalButton.closest('[data-payroll-process-modal-form]');
            if (!form) {
                return;
            }

            state = payrollCommitmentState(form);
            if (!payrollCommitmentHasOverrides(form)) {
                renderPayrollCommitmentSection(form);
                return;
            }

            if (!String(state.reason || '').trim()) {
                buildFeedbackBox(form, 'Indica el motivo operativo antes de validar los ajustes de compromisos.');
                renderPayrollCommitmentSection(form);
                return;
            }

            if (!operationalApprovalNeedsToken(state.gate)) {
                renderPayrollCommitmentSection(form);
                return;
            }

            codeInput = form.querySelector('[data-payroll-approval-code]');
            approverInput = form.querySelector('[data-payroll-approval-approver]');
            requestOptions = buildPayrollCommitmentApprovalRequest(form);
            state.approval.pending = true;
            state.approval.error = '';
            state.approval.message = 'Validando codigo TOTP...';
            if (approverInput) {
                state.approval.approverUserId = String(approverInput.value || '');
                state.approval.approverLabel = approverInput.options && approverInput.selectedIndex >= 0
                    ? String(approverInput.options[approverInput.selectedIndex].text || '')
                    : '';
            }
            renderPayrollCommitmentSection(form);

            requestOperationalApprovalInline({
                actionKey: requestOptions.actionKey,
                payload: requestOptions.payload,
                reason: requestOptions.reason,
                targetPlugin: requestOptions.targetPlugin,
                targetEntityType: requestOptions.targetEntityType,
                targetEntityId: requestOptions.targetEntityId,
                approverUserId: state.approval.approverUserId || '',
                code: codeInput ? codeInput.value : ''
            }).then(function (result) {
                state.approval = operationalApprovalState({
                    token: String(result.approval_token || ''),
                    expiresAt: String(result.expires_at || ''),
                    approverUserId: String(result.approver_user_id || state.approval.approverUserId || ''),
                    approverLabel: operationalApprovalResolvedApproverLabel(
                        state.gate,
                        { approverUserId: String(result.approver_user_id || state.approval.approverUserId || '') }
                    ),
                    verificationMethod: String(result.verification_method || ''),
                    message: 'Validacion TOTP lista.',
                    pending: false
                });
                syncPayrollCommitmentHiddenFields(form);
                renderPayrollCommitmentSection(form);
            }).catch(function (error) {
                state.approval.pending = false;
                state.approval.error = (error && error.message) || 'No se pudo validar el ajuste operativo de nomina.';
                state.approval.token = '';
                syncPayrollCommitmentHiddenFields(form);
                renderPayrollCommitmentSection(form);
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
            var capacityConfirm = form.querySelector('[data-salary-advance-capacity-confirm]');
            var employeeNextPayment = form.getAttribute('data-employee-next-payment') || '';
            var defaultAccountLabel = form.getAttribute('data-employee-default-account') || 'Sin definir';
            var employeeSalaryAmount = Number(form.getAttribute('data-employee-salary-amount') || 0);
            var employeeCommitmentPreview = Number(form.getAttribute('data-employee-commitment-preview-total') || 0);

            if (!modeSelect || !recoveryDateInput || !projection) {
                return;
            }

            function updateAdvanceProjection() {
                var mode = modeSelect.value || 'next_payroll';
                var currency = currencyInput && currencyInput.value ? currencyInput.value : (form.getAttribute('data-employee-currency') || 'USD');
                var amount = amountInput ? amountInput.value : 0;
                var amountNumeric = Number(amount || 0);
                var accountLabel = selectedOptionText(accountSelect, defaultAccountLabel);
                var effectiveDate = recoveryDateInput.value || '';
                var availableForRecovery = Math.max(0, employeeSalaryAmount - employeeCommitmentPreview);
                var projectedRecoveryNow = mode === 'next_payroll' ? Math.min(amountNumeric, availableForRecovery) : 0;
                var projectedCarry = mode === 'next_payroll' ? Math.max(0, amountNumeric - projectedRecoveryNow) : 0;

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

                if (projection.querySelector('[data-advance-projection-available]')) {
                    projection.querySelector('[data-advance-projection-available]').textContent = formatMoneyValueOrZero(availableForRecovery, currency);
                }

                if (projection.querySelector('[data-advance-projection-now]')) {
                    projection.querySelector('[data-advance-projection-now]').textContent = formatMoneyValueOrZero(projectedRecoveryNow, currency);
                }

                if (projection.querySelector('[data-advance-projection-carry]')) {
                    projection.querySelector('[data-advance-projection-carry]').textContent = formatMoneyValueOrZero(projectedCarry, currency);
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
                    } else if (mode === 'next_payroll' && projectedCarry > 0) {
                        summary.textContent = 'En la proxima nomina caben ' + formatMoneyValueOrZero(projectedRecoveryNow, currency) + ' y ' + formatMoneyValueOrZero(projectedCarry, currency) + ' se moveran al siguiente pago si confirmas este adelanto.';
                    } else if (mode === 'next_payroll') {
                        summary.textContent = 'Este adelanto de ' + formatMoneyValue(amount, currency) + ' se programara para recuperarse a partir del ' + formatIsoDate(effectiveDate) + ' sin exceder la capacidad actual del sueldo.';
                    } else {
                        summary.textContent = 'Este adelanto de ' + formatMoneyValue(amount, currency) + ' quedara fuera del descuento automatico hasta que lo gestiones manualmente.';
                    }
                }

                form.dataset.advanceProjectedCarry = String(projectedCarry);
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
                        if (capacityConfirm) {
                            capacityConfirm.value = '0';
                        }
                        updateAdvanceProjection();
                    }
                });
            });

            form.addEventListener('submit', function (event) {
                var mode = modeSelect.value || 'next_payroll';
                var projectedCarry = Number(form.dataset.advanceProjectedCarry || 0);

                if (mode !== 'next_payroll' || projectedCarry <= 0 || (capacityConfirm && capacityConfirm.value === '1')) {
                    return;
                }

                if (!window.confirm('Este adelanto excede lo que cabe en la proxima nomina. El resto se movera al siguiente pago. ¿Quieres continuar?')) {
                    event.preventDefault();
                    return;
                }

                if (capacityConfirm) {
                    capacityConfirm.value = '1';
                }
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
            var commitmentPreview = Number(form.getAttribute('data-payroll-commitment-preview') || 0);
            var activeAdvanceBalance = Number(form.getAttribute('data-payroll-active-advance-balance') || 0);

            if (!projection || !scheduledDate || !periodStart || !periodEnd) {
                return;
            }

            function updatePayrollProjection() {
                var frequencyLabel = frequencyProjectionLabel(form.getAttribute('data-payroll-frequency') || 'monthly');
                var windowLabel = (periodStart.value || '—') + ' al ' + (periodEnd.value || '—');
                var accountLabel = selectedOptionText(accountSelect, defaultAccountLabel);
                var grossValue = grossAmount ? grossAmount.value : 0;
                var otherDeductionValue = otherDeduction ? otherDeduction.value : 0;
                var grossNumeric = Number(grossValue || 0);
                var otherDeductionNumeric = Number(otherDeductionValue || 0);
                var availableAfterManual = Math.max(0, grossNumeric - otherDeductionNumeric);
                var commitmentsNow = Math.min(commitmentPreview, availableAfterManual);
                var availableForAdvances = Math.max(0, availableAfterManual - commitmentsNow);
                var advancesNow = Math.min(activeAdvanceBalance, availableForAdvances);
                var advancesCarry = Math.max(0, activeAdvanceBalance - advancesNow);

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

                if (projection.querySelector('[data-payroll-projection-commitments]')) {
                    projection.querySelector('[data-payroll-projection-commitments]').textContent = formatMoneyValueOrZero(commitmentsNow, currency);
                }

                if (projection.querySelector('[data-payroll-projection-advances]')) {
                    projection.querySelector('[data-payroll-projection-advances]').textContent = formatMoneyValueOrZero(advancesNow, currency);
                }

                if (projection.querySelector('[data-payroll-projection-advance-carry]')) {
                    projection.querySelector('[data-payroll-projection-advance-carry]').textContent = formatMoneyValueOrZero(advancesCarry, currency);
                }

                if (projection.querySelector('[data-payroll-projection-account]')) {
                    projection.querySelector('[data-payroll-projection-account]').textContent = accountLabel || 'Sin definir';
                }

                if (summary) {
                    summary.textContent = 'Base proyectada: ' + formatMoneyValue(grossValue, currency)
                        + ' con pago previsto para ' + (scheduledDate.value ? formatIsoDate(scheduledDate.value) : 'fecha sin definir')
                        + '. Primero se descuentan ' + formatMoneyValueOrZero(commitmentsNow, currency)
                        + ' de compromisos y luego entran ' + formatMoneyValueOrZero(advancesNow, currency)
                        + ' de adelantos. Si faltan ' + formatMoneyValueOrZero(advancesCarry, currency) + ', pasaran al siguiente pago.';
                }

                form.dataset.payrollAdvanceCarry = String(advancesCarry);
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

            form.addEventListener('submit', function (event) {
                var advanceCarry = Number(form.dataset.payrollAdvanceCarry || 0);

                if (advanceCarry <= 0) {
                    return;
                }

                if (!window.confirm('No todo el adelanto pendiente cabe en este periodo. El resto se movera al siguiente pago. ¿Quieres generar la nomina asi?')) {
                    event.preventDefault();
                }
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

    function formatMoneyValueOrZero(amount, currency) {
        var numeric = Number(amount || 0);
        if (!Number.isFinite(numeric)) {
            numeric = 0;
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
            var employeeSalaryAmount = Number(form.getAttribute('data-employee-salary-amount') || 0);
            var employeeCommitmentPreview = Number(form.getAttribute('data-employee-commitment-preview-total') || 0);
            var isEmployee = form.getAttribute('data-is-employee') === '1';
            var allowUnknownPayroll = form.getAttribute('data-allow-unknown-payroll') === '1';
            var payrollReady = form.getAttribute('data-employee-payroll-ready') === '1';
            var hasProfileContext = form.getAttribute('data-has-profile-context') === '1';
            var storeDebtTotal = Number(form.getAttribute('data-store-debt-total') || 0);
            var storeDebtCount = Number(form.getAttribute('data-store-debt-count') || 0);
            var companyDebtTotal = Number(form.getAttribute('data-company-debt-total') || 0);
            var capacityConfirm = form.querySelector('[data-commitment-capacity-confirm]');

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
                var firstPeriodAmount = 0;
                var regularPeriodAmount = 0;
                var lastPeriodAmount = 0;
                var projectionItems = {
                    frequency: projection.querySelector('[data-projection-frequency]'),
                    amount: projection.querySelector('[data-projection-amount]'),
                    first: projection.querySelector('[data-projection-first]'),
                    regular: projection.querySelector('[data-projection-regular]'),
                    last: projection.querySelector('[data-projection-last]'),
                    count: projection.querySelector('[data-projection-count]'),
                    start: projection.querySelector('[data-projection-start]'),
                    end: projection.querySelector('[data-projection-end]'),
                    mode: projection.querySelector('[data-projection-mode]'),
                    capacity: projection.querySelector('[data-projection-capacity]'),
                    shortfall: projection.querySelector('[data-projection-shortfall]')
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
                    firstPeriodAmount = amountPerPeriod;
                    regularPeriodAmount = amountPerPeriod;
                    lastPeriodAmount = amountPerPeriod;
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
                    firstPeriodAmount = periods > 0 ? Number((totalAmount / periods).toFixed(2)) : 0;
                    regularPeriodAmount = firstPeriodAmount;
                    if (periods > 1) {
                        lastPeriodAmount = Math.max(0, Number((totalAmount - (firstPeriodAmount * (periods - 1))).toFixed(2)));
                    } else {
                        lastPeriodAmount = firstPeriodAmount;
                    }
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
                    firstPeriodAmount = amountPerPeriod;
                    regularPeriodAmount = amountPerPeriod;
                    if (periods > 1) {
                        lastPeriodAmount = Math.max(0, Number((totalAmount - (amountPerPeriod * (periods - 1))).toFixed(2)));
                    } else {
                        lastPeriodAmount = amountPerPeriod;
                    }
                }

                if (periods <= 0 || totalAmount <= 0) {
                    firstPeriodAmount = 0;
                    regularPeriodAmount = 0;
                    lastPeriodAmount = 0;
                }

                var payrollCollection = (collection === 'payroll_deduction' || collection === 'mixed') && direction === 'receivable';
                var capacityAvailable = payrollCollection ? Math.max(0, employeeSalaryAmount - employeeCommitmentPreview) : 0;
                var capacityShortfall = payrollCollection ? Math.max(0, firstPeriodAmount - capacityAvailable) : 0;

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
                if (projectionItems.first) {
                    projectionItems.first.textContent = formatMoneyValue(firstPeriodAmount, currentCurrency);
                }
                if (projectionItems.regular) {
                    projectionItems.regular.textContent = formatMoneyValue(regularPeriodAmount, currentCurrency);
                }
                if (projectionItems.last) {
                    projectionItems.last.textContent = formatMoneyValue(lastPeriodAmount, currentCurrency);
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
                if (projectionItems.capacity) {
                    projectionItems.capacity.textContent = payrollCollection ? formatMoneyValueOrZero(capacityAvailable, currentCurrency) : 'No aplica';
                }
                if (projectionItems.shortfall) {
                    projectionItems.shortfall.textContent = payrollCollection ? formatMoneyValueOrZero(capacityShortfall, currentCurrency) : 'No aplica';
                }

                if (projectionSummary) {
                    var periodUnit = commitmentPeriodUnitLabel(effectiveFrequency, periods);
                    if (totalAmount <= 0) {
                        projectionSummary.textContent = 'Indica el monto del compromiso para que el sistema calcule cuotas, calendario e impacto esperado.';
                    } else if (periods <= 0 || amountPerPeriod <= 0) {
                        projectionSummary.textContent = 'Selecciona la forma de planificar el compromiso y completa el valor base para estimar periodos y monto por cuota.';
                    } else if (payrollCollection && capacityShortfall > 0) {
                        projectionSummary.textContent = 'La primera cuota seria de ' + formatMoneyValueOrZero(firstPeriodAmount, currentCurrency) + ', pero en el proximo pago solo caben ' + formatMoneyValueOrZero(capacityAvailable, currentCurrency) + '. Haran falta ' + formatMoneyValueOrZero(capacityShortfall, currentCurrency) + ' si decides continuar.';
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

                form.dataset.commitmentCapacityShortfall = String(capacityShortfall);
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
                        if (capacityConfirm) {
                            capacityConfirm.value = '0';
                        }
                        updateCommitmentPlanner();
                    }
                });
            });

            form.addEventListener('submit', function (event) {
                var shortfall = Number(form.dataset.commitmentCapacityShortfall || 0);
                var direction = directionSelect ? (directionSelect.value || 'receivable') : 'receivable';
                var collection = collectionMode ? (collectionMode.value || 'manual') : 'manual';
                var usesPayroll = (collection === 'payroll_deduction' || collection === 'mixed') && direction === 'receivable';

                if (!usesPayroll || shortfall <= 0 || (capacityConfirm && capacityConfirm.value === '1')) {
                    return;
                }

                if (!window.confirm('La primera cuota supera lo que cabe limpio en el proximo pago. Si continuas, quedara faltante desde el primer periodo. ¿Quieres guardarlo asi?')) {
                    event.preventDefault();
                    return;
                }

                if (capacityConfirm) {
                    capacityConfirm.value = '1';
                }
            });

            updateCommitmentPlanner();
            form.dataset.commitmentReady = '1';
        });
    }

    function setupHistoricalTools() {
        var indexRoot = document.querySelector('[data-historical-index-root]');
        var resolutionRoot = document.querySelector('[data-historical-resolution-root]');
        var auditRoot = document.querySelector('[data-balance-audit-root]');
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var cachedYears = [];
        var indexTimer = 0;
        var resolutionTimer = 0;
        var resolutionPreviewState = null;

        if ((!indexRoot && !resolutionRoot && !auditRoot) || !ASDLFinanceAdmin || !ASDLFinanceAdmin.ajaxUrl) {
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
                    label += ' · caso especial';
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

        function balanceAuditTone(status) {
            var value = String(status || '').toLowerCase();
            if (value === 'ok' || value === 'success') {
                return 'success';
            }
            if (value === 'danger' || value === 'error') {
                return 'danger';
            }
            return 'neutral';
        }

        function balanceAuditStatusLabel(status) {
            var value = String(status || '').toLowerCase();
            if (value === 'ok' || value === 'success') {
                return 'OK';
            }
            if (value === 'danger' || value === 'error') {
                return 'Revisar';
            }
            if (value === 'warning') {
                return 'Advertencia';
            }
            return 'Neutral';
        }

        function formatAuditMetric(metric) {
            var item = metric || {};
            if (String(item.type || 'money') === 'number') {
                return Number(item.value || 0).toLocaleString('es-VE');
            }

            return formatToolMoney(item.value || 0, 'USD');
        }

        function formatAuditCheckValue(value, type) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return '—';
            }

            if (String(type || 'money') === 'number') {
                return Number(value || 0).toLocaleString('es-VE');
            }

            return formatToolMoney(value || 0, 'USD');
        }

        function renderBalanceAuditResults(root, audit, message, tone) {
            var target = root ? root.querySelector('[data-balance-audit-results]') : null;
            var payload = audit || {};
            var metrics = Array.isArray(payload.metrics) ? payload.metrics : [];
            var checks = Array.isArray(payload.checks) ? payload.checks : [];
            var subject = payload.subject || {};
            var range = payload.range || {};

            if (!target) {
                return;
            }

            if (!payload.kind) {
                target.innerHTML = buildToolNotice(message || 'No se pudo ejecutar la auditoria solicitada.', tone || 'danger');
                return;
            }

            target.innerHTML = ''
                + buildToolNotice(message || payload.label || 'Auditoria ejecutada.', tone || balanceAuditTone(payload.status))
                + '<div class="asdl-fin-tool-detail-grid">'
                + '<div class="asdl-fin-tool-card"><h3>Contexto</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + '<div><strong>Tipo</strong><span>' + escapeHtml(String(payload.kind || '')) + '</span></div>'
                + '<div><strong>Estado</strong><span>' + renderPill(balanceAuditStatusLabel(payload.status), balanceAuditTone(payload.status)) + '</span></div>'
                + '<div><strong>Rango</strong><span>' + escapeHtml((range.range_from || '—') + ' al ' + (range.range_to || '—')) + '</span></div>'
                + (subject.contact_id ? '<div><strong>Perfil</strong><span>#' + escapeHtml(String(subject.contact_id || '')) + ' · ' + escapeHtml(subject.display_name || '') + '</span></div>' : '')
                + (subject.email ? '<div><strong>Correo</strong><span>' + escapeHtml(subject.email || '') + '</span></div>' : '')
                + '<div><strong>Tolerancia</strong><span>' + escapeHtml(String(payload.tolerance || 0)) + '</span></div>'
                + '</div></div>'
                + '<div class="asdl-fin-tool-card"><h3>Metricas</h3><div class="asdl-fin-data-grid asdl-fin-data-grid-tight">'
                + (metrics.length ? metrics.map(function (metric) {
                    return '<div><strong>' + escapeHtml(metric.label || '') + '</strong><span>' + escapeHtml(formatAuditMetric(metric)) + '</span></div>';
                }).join('') : '<div><strong>Sin metricas</strong><span>—</span></div>')
                + '</div></div>'
                + '</div>';

            target.innerHTML += (
                checks.length
                    ? '<table class="widefat striped"><thead><tr><th>Check</th><th>Estado</th><th>Actual</th><th>Esperado</th><th>Diferencia</th></tr></thead><tbody>'
                        + checks.map(function (check) {
                            return ''
                                + '<tr>'
                                + '<td><strong>' + escapeHtml(check.label || '') + '</strong></td>'
                                + '<td>' + renderPill(balanceAuditStatusLabel(check.status), balanceAuditTone(check.status)) + '</td>'
                                + '<td>' + escapeHtml(formatAuditCheckValue(check.actual, check.type)) + '</td>'
                                + '<td>' + escapeHtml(formatAuditCheckValue(check.expected, check.type)) + '</td>'
                                + '<td>' + escapeHtml(formatAuditCheckValue(check.difference, check.type)) + '</td>'
                                + '</tr>';
                        }).join('')
                        + '</tbody></table>'
                    : '<div class="asdl-fin-empty"><strong>Sin checks.</strong><p>La auditoria no devolvio comparaciones para este contexto.</p></div>'
            );
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

        function resolutionApprovalGate(preview) {
            return operationalApprovalGateState(preview && preview.approval_gate ? preview.approval_gate : {});
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
                approvalGate: resolutionApprovalGate(payload),
                approval: operationalApprovalState({}),
                selectedIds: items.map(function (item) {
                    return String(item.id || 0);
                }).filter(Boolean)
            };
        }

        function resolutionApprovalMissing() {
            return !!(
                resolutionPreviewState
                && operationalApprovalNeedsToken(resolutionPreviewState.approvalGate)
                && !operationalApprovalHasToken(resolutionPreviewState.approval)
            );
        }

        function clearResolutionApproval(message) {
            if (!resolutionPreviewState) {
                return;
            }

            resolutionPreviewState.approval = operationalApprovalState({
                message: message || '',
                approverUserId: resolutionPreviewState.approval && resolutionPreviewState.approval.approverUserId
                    ? resolutionPreviewState.approval.approverUserId
                    : '',
                approverLabel: resolutionPreviewState.approval && resolutionPreviewState.approval.approverLabel
                    ? resolutionPreviewState.approval.approverLabel
                    : ''
            });
        }

        function buildResolutionApprovalRequest() {
            var state = resolutionPreviewState || {};
            var filters = state.filters || {};

            return {
                actionKey: (state.approvalGate && state.approvalGate.action_key) || '',
                payload: {
                    fiscal_year_from: Number(filters.fiscal_year_from || 0),
                    fiscal_year_to: Number(filters.fiscal_year_to || 0),
                    contact_id: Number(filters.contact_id || 0),
                    provider: String(filters.provider || 'all'),
                    search: String(filters.search || ''),
                    reason_key: String(filters.reason_key || ''),
                    note: String(filters.note || ''),
                    special_previous_year: !!filters.special_previous_year,
                    only_without_paid: !!filters.only_without_paid,
                    min_balance: filters.min_balance === null || filters.min_balance === undefined || filters.min_balance === '' ? null : Number(filters.min_balance),
                    max_balance: filters.max_balance === null || filters.max_balance === undefined || filters.max_balance === '' ? null : Number(filters.max_balance),
                    selected_row_ids: getResolutionSelectedRows().map(function (item) {
                        return Number(item.id || 0);
                    }).filter(function (id) {
                        return id > 0;
                    })
                },
                reason: String(filters.note || ''),
                targetPlugin: 'analysis-financiero-plugin',
                targetEntityType: Number(filters.contact_id || 0) > 0 ? 'contact' : 'historical_range',
                targetEntityId: Number(filters.contact_id || 0) > 0
                    ? String(filters.contact_id || 0)
                    : String(filters.fiscal_year_from || 0) + '-' + String(filters.fiscal_year_to || 0)
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
                        + '<td>' + renderPill(String(row.status || 'pending'), historicalStatusTone(row.status)) + (row.is_closable ? '<div><small>Cerrable</small></div>' : '') + (!row.is_closable && row.is_special_case ? '<div><small>Caso especial</small></div>' : '') + '</td>'
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
            var startButton = root ? root.querySelector('[data-historical-resolution-start]') : null;
            var payload = preview || {};
            var state = resolutionPreviewState;
            var summary = state && state.summary ? state.summary : (payload.summary || {});
            var years = state && Array.isArray(state.years) ? state.years : (Array.isArray(payload.years) ? payload.years : []);
            var items = state && Array.isArray(state.items) ? state.items : (Array.isArray(payload.items) ? payload.items : []);
            var selection = getResolutionSelectionSummary();
            var selectAllChecked = items.length > 0 && selection.count === items.length;
            var statusLabel = message || (summary.item_count ? 'Vista previa lista' : 'Sin resultados');
            var approvalGate = state && state.approvalGate ? state.approvalGate : resolutionApprovalGate(payload);
            var approvalState = state && state.approval ? state.approval : operationalApprovalState({});
            var approvalPanel = buildOperationalApprovalPanel('resolution', approvalGate, approvalState, {
                title: 'Validacion operativa',
                scopeLabel: 'este caso especial historico',
                helpMessage: 'Si cambias filtros o seleccion, se pedira validar otra vez antes de aplicar el cierre.'
            });
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

            if (startButton) {
                startButton.textContent = resolutionApprovalMissing() ? 'Valida con autenticador' : 'Aplicar cierre historico';
                startButton.disabled = !items.length || !selection.count || resolutionApprovalMissing();
            }

            if (!itemsTarget) {
                return;
            }

            if (!items.length) {
                itemsTarget.innerHTML = buildToolNotice(message || 'No hay pedidos historicos elegibles con esos filtros.', tone || 'neutral')
                    + approvalPanel;
                return;
            }

            itemsTarget.innerHTML = ''
                + buildToolNotice(message || 'Vista previa calculada correctamente.', tone || 'success')
                + approvalPanel
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
            var identities = [];
            var batchIdentityLabel = '';

            if (!target) {
                return;
            }

            if (!batch) {
                target.innerHTML = buildToolNotice(message || 'No se pudo cargar el detalle del lote.', tone || 'danger');
                return;
            }

            identities = items.map(function (item) {
                var meta = {};
                var displayName = '';
                var email = '';
                var parts = [];

                if (item.meta_json) {
                    try {
                        meta = JSON.parse(item.meta_json);
                    } catch (error) {
                        meta = {};
                    }
                }

                displayName = String(
                    meta.display_name
                    || item.resolved_display_name
                    || ''
                ).trim();
                email = String(
                    meta.customer_email
                    || item.resolved_customer_email
                    || ''
                ).trim();

                if (displayName) {
                    parts.push(displayName);
                }

                if (email && email !== displayName) {
                    parts.push(email);
                }

                return parts.join(' · ');
            }).filter(Boolean).filter(function (value, index, array) {
                return array.indexOf(value) === index;
            });

            if (identities.length === 1) {
                batchIdentityLabel = identities[0];
            } else if (identities.length > 1) {
                batchIdentityLabel = identities.length.toLocaleString('es-VE') + ' perfiles';
            } else {
                batchIdentityLabel = 'Sin perfil resuelto';
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
                + '<div><strong>Perfil afectado</strong><span>' + escapeHtml(batchIdentityLabel) + '</span></div>'
                + '</div>'
                + (batch.note ? '<div class="asdl-fin-tool-note-box"><strong>Nota del lote</strong><p>' + escapeHtml(batch.note) + '</p></div>' : '')
                + '</div>'
                + '<div class="asdl-fin-tool-card"><h3>Pedidos afectados</h3>'
                + (
                    items.length
                        ? '<table class="widefat striped"><thead><tr><th>Pedido</th><th>Perfil</th><th>Proveedor</th><th>Balance antes</th><th>Estado previo</th><th>Resultado</th></tr></thead><tbody>'
                            + items.map(function (item) {
                                var meta = {};
                                var displayName = '';
                                var email = '';
                                var profileLabel = '';
                                if (item.meta_json) {
                                    try {
                                        meta = JSON.parse(item.meta_json);
                                    } catch (error) {
                                        meta = {};
                                    }
                                }

                                displayName = String(meta.display_name || item.resolved_display_name || '').trim();
                                email = String(meta.customer_email || item.resolved_customer_email || '').trim();
                                profileLabel = displayName || email || 'Sin perfil';

                                return ''
                                    + '<tr>'
                                    + '<td><strong>' + escapeHtml((meta.order_number || item.resolved_order_number || ('#' + String(item.external_order_id || 0)))) + '</strong><br /><small>#' + escapeHtml(String(item.external_order_id || 0)) + '</small></td>'
                                    + '<td><div class="asdl-fin-stack"><strong>' + escapeHtml(profileLabel) + '</strong>' + (email && email !== profileLabel ? '<small>' + escapeHtml(email) + '</small>' : '') + '</div></td>'
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

        function runBalanceAudit(kind) {
            var contactField = auditRoot ? auditRoot.querySelector('input[name="balance_audit_contact_id"]') : null;
            var payload = {
                kind: kind
            };

            if (!auditRoot || !runtimeNonces.balanceAudit) {
                return Promise.resolve();
            }

            if (kind === 'contact') {
                payload.contact_id = contactField ? String(contactField.value || '') : '';
                if (!payload.contact_id) {
                    renderBalanceAuditResults(auditRoot, {}, 'Selecciona primero un perfil para auditarlo.', 'danger');
                    return Promise.resolve();
                }
            }

            renderBalanceAuditResults(auditRoot, {
                kind: kind,
                status: 'neutral',
                label: 'Ejecutando auditoria...'
            }, 'Ejecutando auditoria de saldos y paridad...', 'warning');

            return requestAdminAjax('asdl_fin_balance_audit', runtimeNonces.balanceAudit, payload).then(function (response) {
                renderBalanceAuditResults(auditRoot, response.audit || {}, 'Auditoria ejecutada correctamente.', 'success');
                return response;
            }).catch(function (error) {
                renderBalanceAuditResults(auditRoot, {}, (error && error.message) || 'No se pudo ejecutar la auditoria solicitada.', 'danger');
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
	                    startButton.disabled = true;
	                    renderHistoricalIndexProgress(indexRoot, {
	                        job: {
	                            status: 'running',
	                            fiscal_year: document.getElementById('historical_index_year') ? document.getElementById('historical_index_year').value : '',
	                            current_page: 1,
	                            max_pages: 0,
	                            total: 0,
	                            processed: 0,
	                            last_batch: 0,
	                            updated_at: ''
	                        }
	                    }, 'Inicializando reconstruccion historica...', 'warning');
	                    requestAdminAjax('asdl_fin_historical_index_start', runtimeNonces.historicalIndexStart, {
	                        fiscal_year: document.getElementById('historical_index_year') ? document.getElementById('historical_index_year').value : '',
	                        batch_size: document.getElementById('historical_index_batch_size') ? document.getElementById('historical_index_batch_size').value : '250',
	                        force: indexRoot.querySelector('[data-historical-index-force]') && indexRoot.querySelector('[data-historical-index-force]').checked ? '1' : ''
	                    }).then(function (payload) {
	                        startButton.disabled = false;
	                        applyHistoricalIndexStatus(payload.status || {}, 'Reconstruccion historica iniciada.', 'success');
	                    }).catch(function (error) {
	                        startButton.disabled = false;
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

                    if (resolutionApprovalMissing()) {
                        renderHistoricalResolutionProgress(
                            resolutionRoot,
                            { job: {} },
                            (resolutionPreviewState && resolutionPreviewState.approvalGate && resolutionPreviewState.approvalGate.message)
                                || 'Valida primero esta accion sensible con tu autenticador antes de aplicar el cierre.',
                            'danger'
                        );
                        return;
                    }

                    filters.approval_token = resolutionPreviewState && resolutionPreviewState.approval
                        ? String(resolutionPreviewState.approval.token || '')
                        : '';

                    requestAdminAjax('asdl_fin_historical_resolution_start', runtimeNonces.historicalResolutionStart, filters).then(function (payload) {
                        applyHistoricalResolutionStatus(payload.status || {}, 'Cierre administrativo historico iniciado.', 'success');
                    }).catch(function (error) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, (error && error.message) || 'No se pudo iniciar el cierre administrativo historico.', 'danger');
                    });
                });
            }

            resolutionRoot.addEventListener('change', function (event) {
                var target = event.target;
                var itemCheckbox = target.closest('[data-historical-resolution-item]');
                var selectAllCheckbox = target.closest('[data-historical-resolution-select-all]');
                var approvalApprover = target.closest('[data-resolution-approval-approver]');
                var isFilterField = target.matches('#historical_resolution_year_from, #historical_resolution_year_to, #historical_resolution_provider, #historical_resolution_reason, #historical_resolution_batch_size, [data-historical-resolution-special-previous-year], [data-historical-resolution-only-without-paid], input[name="historical_resolution_contact_id"]');

                if (approvalApprover && resolutionPreviewState) {
                    resolutionPreviewState.approval.approverUserId = String(approvalApprover.value || '');
                    resolutionPreviewState.approval.approverLabel = approvalApprover.options && approvalApprover.selectedIndex >= 0
                        ? String(approvalApprover.options[approvalApprover.selectedIndex].text || '')
                        : '';
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Configuracion de validacion actualizada.', 'success');
                    return;
                }

                if (!resolutionPreviewState || !Array.isArray(resolutionPreviewState.items) || !resolutionPreviewState.items.length) {
                    return;
                }

                if (selectAllCheckbox) {
                    resolutionPreviewState.selectedIds = selectAllCheckbox.checked
                        ? resolutionPreviewState.items.map(function (item) { return String(item.id || 0); }).filter(Boolean)
                        : [];
                    clearResolutionApproval('La seleccion del lote cambio. Valida otra vez antes de aplicar el cierre.');
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
                    clearResolutionApproval('La seleccion del lote cambio. Valida otra vez antes de aplicar el cierre.');
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Seleccion actualizada.', 'success');
                    return;
                }

                if (isFilterField) {
                    clearResolutionApproval('Los filtros del caso especial cambiaron. Vuelve a validar despues de recalcular la vista previa.');
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Filtros actualizados.', 'warning');
                }
            });

            resolutionRoot.addEventListener('input', function (event) {
                var target = event.target;
                var isFilterInput;

                if (!resolutionPreviewState || !target || target.closest('[data-resolution-approval-code]')) {
                    return;
                }

                isFilterInput = target.matches('#historical_resolution_search, #historical_resolution_min_balance, #historical_resolution_max_balance, #historical_resolution_note');
                if (!isFilterInput) {
                    return;
                }

                clearResolutionApproval('Los filtros del caso especial cambiaron. Vuelve a validar despues de recalcular la vista previa.');
                renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Filtros actualizados.', 'warning');
            });

            resolutionRoot.addEventListener('click', function (event) {
                var approvalButton = event.target.closest('[data-resolution-approval-validate]');
                var detailButton = event.target.closest('[data-historical-batch-detail]');

                if (approvalButton) {
                    var currentFilters = collectResolutionFilters(resolutionRoot, false);
                    var currentSignature = resolutionFilterSignature(currentFilters);
                    var requestOptions;
                    var approverInput;
                    var codeInput;

                    event.preventDefault();

                    if (!resolutionPreviewState || !resolutionPreviewState.items || !resolutionPreviewState.items.length) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, 'Calcula primero la vista previa del cierre historico antes de validar la accion sensible.', 'danger');
                        return;
                    }

                    if (currentSignature !== resolutionPreviewState.signature) {
                        renderHistoricalResolutionProgress(resolutionRoot, { job: {} }, 'La vista previa ya no coincide con los filtros actuales. Vuelve a calcularla antes de validar el caso especial.', 'danger');
                        return;
                    }

                    requestOptions = buildResolutionApprovalRequest();
                    approverInput = resolutionRoot.querySelector('[data-resolution-approval-approver]');
                    codeInput = resolutionRoot.querySelector('[data-resolution-approval-code]');
                    resolutionPreviewState.approval.pending = true;
                    resolutionPreviewState.approval.error = '';
                    resolutionPreviewState.approval.message = 'Validando codigo TOTP...';
                    if (approverInput) {
                        resolutionPreviewState.approval.approverUserId = String(approverInput.value || '');
                        resolutionPreviewState.approval.approverLabel = approverInput.options && approverInput.selectedIndex >= 0
                            ? String(approverInput.options[approverInput.selectedIndex].text || '')
                            : '';
                    }
                    renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Vista previa lista.', 'success');

                    requestOperationalApprovalInline({
                        actionKey: requestOptions.actionKey,
                        payload: requestOptions.payload,
                        reason: requestOptions.reason,
                        targetPlugin: requestOptions.targetPlugin,
                        targetEntityType: requestOptions.targetEntityType,
                        targetEntityId: requestOptions.targetEntityId,
                        approverUserId: resolutionPreviewState.approval.approverUserId || '',
                        code: codeInput ? codeInput.value : ''
                    }).then(function (result) {
                        resolutionPreviewState.approval = operationalApprovalState({
                            token: String(result.approval_token || ''),
                            expiresAt: String(result.expires_at || ''),
                            approverUserId: String(result.approver_user_id || resolutionPreviewState.approval.approverUserId || ''),
                            approverLabel: operationalApprovalResolvedApproverLabel(
                                resolutionPreviewState.approvalGate,
                                { approverUserId: String(result.approver_user_id || resolutionPreviewState.approval.approverUserId || '') }
                            ),
                            verificationMethod: String(result.verification_method || ''),
                            message: 'Validacion TOTP lista.',
                            pending: false
                        });
                        renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Vista previa lista.', 'success');
                    }).catch(function (error) {
                        resolutionPreviewState.approval.pending = false;
                        resolutionPreviewState.approval.error = (error && error.message) || 'No se pudo validar el caso especial.';
                        resolutionPreviewState.approval.token = '';
                        renderHistoricalResolutionPreview(resolutionRoot, resolutionPreviewState, 'Vista previa lista.', 'success');
                    });
                    return;
                }

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

        if (auditRoot && auditRoot.dataset.balanceAuditReady !== '1') {
            auditRoot.dataset.balanceAuditReady = '1';

            var auditDashboardButton = auditRoot.querySelector('[data-balance-audit-dashboard]');
            var auditMobileButton = auditRoot.querySelector('[data-balance-audit-mobile]');
            var auditContactButton = auditRoot.querySelector('[data-balance-audit-contact]');

            if (auditDashboardButton) {
                auditDashboardButton.addEventListener('click', function () {
                    runBalanceAudit('dashboard');
                });
            }

            if (auditMobileButton) {
                auditMobileButton.addEventListener('click', function () {
                    runBalanceAudit('mobile');
                });
            }

            if (auditContactButton) {
                auditContactButton.addEventListener('click', function () {
                    runBalanceAudit('contact');
                });
            }
        }
    }

    function setupMasterReportRunner() {
        var root = document.querySelector('[data-master-report-root="1"]');
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var timer = 0;
        var state = {
            running: false,
            jobId: ''
        };

        if (!root || root.dataset.masterReportReady === '1') {
            return;
        }

        if (
            !runtimeNonces.masterReportStart ||
            !runtimeNonces.masterReportContinue ||
            !runtimeNonces.masterReportResult
        ) {
            return;
        }

        root.dataset.masterReportReady = '1';

        var form = root.querySelector('[data-master-report-form="1"]');
        var progressTarget = root.querySelector('[data-master-report-progress]');
        var actionsTarget = root.querySelector('[data-master-report-actions]');
        var versionsTarget = root.querySelector('[data-master-report-versions]');
        var sectionsTarget = root.querySelector('[data-master-report-sections]');
        var submitButton = root.querySelector('[data-master-report-submit]');
        var totalShortcut = root.querySelector('[data-master-report-total-shortcut]');
        var modeSelect = form ? form.querySelector('[name="report_mode"]') : null;

        function buildNotice(message, tone) {
            if (!message) {
                return '';
            }

            return '<div class="asdl-fin-tool-notice asdl-fin-tool-notice-' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(message) + '</div>';
        }

        function renderWorkspaceState(title, description, extraClass) {
            if (!resultsTarget) {
                return;
            }

            resultsTarget.innerHTML = ''
                + '<div class="asdl-fin-empty' + (extraClass ? ' ' + escapeHtml(extraClass) : '') + '">'
                + '<strong>' + escapeHtml(title || 'Sin datos.') + '</strong>'
                + '<p>' + escapeHtml(description || '') + '</p>'
                + '</div>';
        }

        function stopProductMarginRunner(message) {
            window.clearTimeout(timer);
            state.running = false;

            if (startButton) {
                startButton.disabled = false;
            }
            if (discardVisibleButton) {
                discardVisibleButton.disabled = false;
            }

            renderProgress({ status: 'error' }, message || 'La revision del catalogo se interrumpio.', 'danger');
            renderWorkspaceState(
                'No se pudo actualizar la vista.',
                (message || 'La revision del catalogo se interrumpio.') + ' Puedes pulsar "Actualizar vista" otra vez cuando la conexion se estabilice.',
                'asdl-fin-runtime-error'
            );
        }

        function serializeFormData() {
            var payload = {};
            var formData;
            var checkboxArrayNames = {};

            if (!form) {
                return payload;
            }

            formData = new FormData(form);
            formData.forEach(function (value, key) {
                if (payload[key] !== undefined) {
                    if (!Array.isArray(payload[key])) {
                        payload[key] = [payload[key]];
                    }
                    payload[key].push(value);
                    return;
                }

                payload[key] = value;
            });

            Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name$="[]"]')).forEach(function (input) {
                checkboxArrayNames[input.name] = true;
            });

            Object.keys(checkboxArrayNames).forEach(function (name) {
                var markerKey = String(name || '').replace(/\[\]$/, '') + '_present';
                payload[markerKey] = '1';

                if (payload[name] === undefined) {
                    payload[name] = [];
                }
            });

            return payload;
        }

        function buildReportUrl(payload) {
            var params = new URLSearchParams();
            var base = payload || {};

            Object.keys(base).forEach(function (key) {
                var value = base[key];
                if (value === undefined || value === null || value === '') {
                    return;
                }

                if (/_present$/.test(String(key || ''))) {
                    return;
                }

                if (Array.isArray(value)) {
                    value.forEach(function (item) {
                        if (item === undefined || item === null || item === '') {
                            return;
                        }
                        params.append(key, item);
                    });
                    return;
                }

                params.set(key, value);
            });

            params.set('page', 'asdl-fin-reports');
            params.set('report_run', '1');

            return window.location.pathname + '?' + params.toString();
        }

        function setRunningState(isRunning) {
            state.running = !!isRunning;

            if (submitButton) {
                submitButton.disabled = !!isRunning;
            }

            if (totalShortcut) {
                totalShortcut.classList.toggle('disabled', !!isRunning);
                totalShortcut.setAttribute('aria-disabled', isRunning ? 'true' : 'false');
            }
        }

        function renderProgress(job, message, tone) {
            var snapshot = job || {};
            var percent = Math.max(0, Math.min(100, Number(snapshot.progress_percent || 0)));
            var subprogress = snapshot.subprogress || {};
            var meta = [];

            if (!progressTarget) {
                return;
            }

            if (!snapshot.status) {
                progressTarget.innerHTML = buildNotice(message || 'Todavia no hay un runner activo para este reporte.', tone || 'neutral');
                return;
            }

            if (snapshot.status === 'running') {
                if (subprogress.total > 0) {
                    meta.push('Productos revisados: ' + Number(subprogress.processed || 0).toLocaleString('es-VE') + ' / ' + Number(subprogress.total || 0).toLocaleString('es-VE'));
                }

                if (subprogress.last_batch > 0) {
                    meta.push('Ultimo lote: ' + escapeHtml(String(subprogress.last_batch)) + ' producto(s)');
                }

                if (subprogress.issue_count > 0) {
                    meta.push('Hallazgos: ' + escapeHtml(String(subprogress.issue_count)));
                }

                if (snapshot.updated_at) {
                    meta.push('Actualizado: ' + escapeHtml(formatToolTimestamp(snapshot.updated_at)));
                }

                progressTarget.innerHTML = ''
                    + buildNotice(message || snapshot.message || 'Calculando reporte maestro por etapas.', tone || 'warning')
                    + '<div class="asdl-fin-tool-progress-card">'
                    + '<div class="asdl-fin-tool-progress-head"><strong>' + escapeHtml(String(snapshot.stage_label || 'Procesando')) + '</strong><span>' + escapeHtml(String(percent)) + '%</span></div>'
                    + buildAsyncProgressBar(percent, 100)
                    + '<div class="asdl-fin-tool-progress-meta">'
                    + '<span>Etapa actual: ' + escapeHtml(String(snapshot.stage_label || 'Procesando')) + '</span>'
                    + (meta.length ? meta.map(function (item) { return '<span>' + item + '</span>'; }).join('') : '<span>Preparando la siguiente etapa del reporte.</span>')
                    + '</div>'
                    + '</div>';
                return;
            }

            progressTarget.innerHTML = buildNotice(
                message || snapshot.message || (snapshot.status === 'completed' ? 'Reporte maestro listo.' : 'El runner del reporte termino con error.'),
                tone || (snapshot.status === 'completed' ? 'success' : 'danger')
            );
        }

        function consumeResult(response) {
            if (actionsTarget) {
                actionsTarget.innerHTML = response.actions_html || '';
                initializeDynamicAdminContent(actionsTarget);
            }

            if (versionsTarget) {
                versionsTarget.innerHTML = response.versions_html || '';
                initializeDynamicAdminContent(versionsTarget);
            }

            if (sectionsTarget) {
                sectionsTarget.innerHTML = response.sections_html || '';
                initializeDynamicAdminContent(sectionsTarget);
            }

            renderProgress(response.job || {}, response.range_label || 'Reporte maestro generado correctamente.', 'success');
            root.dataset.masterReportAutoload = '0';

            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, buildReportUrl(serializeFormData()));
            }
        }

        function requestResult(jobId) {
            requestAdminAjax('asdl_fin_master_report_result', runtimeNonces.masterReportResult, {
                job_id: jobId
            }).then(function (response) {
                setRunningState(false);
                consumeResult(response || {});
            }).catch(function (error) {
                setRunningState(false);
                renderProgress({ status: 'error' }, (error && error.message) || 'No se pudo recuperar el resultado final del reporte.', 'danger');
            });
        }

        function scheduleContinue(jobId) {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                requestAdminAjax('asdl_fin_master_report_continue', runtimeNonces.masterReportContinue, {
                    job_id: jobId
                }).then(function (payload) {
                    var job = payload && payload.job ? payload.job : {};
                    state.jobId = String(job.job_id || jobId || '');
                    renderProgress(job, job.message || 'Etapa procesada correctamente.', job.status === 'running' ? 'warning' : 'success');

                    if (job.status === 'running') {
                        scheduleContinue(state.jobId);
                        return;
                    }

                    if (job.status === 'completed') {
                        requestResult(state.jobId);
                        return;
                    }

                    setRunningState(false);
                    renderProgress(job, job.error_message || job.message || 'El reporte termino con error.', 'danger');
                }).catch(function (error) {
                    setRunningState(false);
                    renderProgress({ status: 'error' }, (error && error.message) || 'No se pudo continuar el reporte maestro.', 'danger');
                });
            }, 180);
        }

        function startRunner(overrides) {
            var payload = Object.assign({}, serializeFormData(), overrides || {});

            if (state.running) {
                return;
            }

            setRunningState(true);
            state.jobId = '';

            if (actionsTarget) {
                actionsTarget.innerHTML = '';
            }
            if (versionsTarget) {
                versionsTarget.innerHTML = '';
            }
            if (sectionsTarget) {
                sectionsTarget.innerHTML = '<div class="asdl-fin-empty"><strong>Preparando el reporte...</strong><p>El runner esta resolviendo primero la verificacion de productos y luego el resto de bloques financieros.</p></div>';
            }

            renderProgress({
                status: 'running',
                stage_label: 'Inicializando',
                progress_percent: 0
            }, 'Inicializando el runner del reporte maestro...', 'warning');

            requestAdminAjax('asdl_fin_master_report_start', runtimeNonces.masterReportStart, payload).then(function (response) {
                var job = response && response.job ? response.job : {};
                state.jobId = String(job.job_id || '');
                renderProgress(job, job.message || 'Runner iniciado.', 'warning');

                if (job.status === 'completed') {
                    requestResult(state.jobId);
                    return;
                }

                scheduleContinue(state.jobId);
            }).catch(function (error) {
                setRunningState(false);
                renderProgress({ status: 'error' }, (error && error.message) || 'No se pudo iniciar el reporte maestro.', 'danger');
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                startRunner();
            });
        }

        if (totalShortcut) {
            totalShortcut.addEventListener('click', function (event) {
                event.preventDefault();
                if (state.running) {
                    return;
                }

                if (modeSelect) {
                    modeSelect.value = 'total';
                }

                startRunner({
                    report_mode: 'total'
                });
            });
        }

        if (root.dataset.masterReportAutoload === '1' && root.dataset.masterReportSnapshot !== '1') {
            startRunner();
        }
    }

    function setupProductMarginRunner() {
        var root = document.querySelector('[data-product-margin-root="1"]');
        var runtimeNonces = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.runtimeNonces) || {};
        var dualPricing = (window.ASDLFinanceAdmin && ASDLFinanceAdmin.dualPricing) || {};
        var timer = 0;
        var state = {
            running: false,
            jobId: '',
            activeTab: 'issues',
            workspaceStale: false
        };

        if (!root || root.dataset.productMarginReady === '1') {
            return;
        }

        if (
            !runtimeNonces.productMarginStart ||
            !runtimeNonces.productMarginContinue ||
            !runtimeNonces.productMarginResult ||
            !runtimeNonces.productMarginUpdateCost ||
            !runtimeNonces.productMarginDiscardRow ||
            !runtimeNonces.productMarginReinstateRow ||
            !runtimeNonces.productMarginDiscardNoStock
        ) {
            return;
        }

        root.dataset.productMarginReady = '1';

        var startButton = root.querySelector('[data-product-margin-start]');
        var progressTarget = root.querySelector('[data-product-margin-progress]');
        var resultsTarget = root.querySelector('[data-product-margin-results]');
        var excludeField = root.querySelector('[name="product_margin_exclude_categories"]');
        var searchField = root.querySelector('[name="product_margin_search"]');
        var categoryField = root.querySelector('[name="product_margin_category"]');
        var statusField = root.querySelector('[name="product_margin_status_filter"]');
        var modeField = root.querySelector('[name="product_margin_mode_filter"]');
        var discardVisibleButton = root.querySelector('[data-product-margin-discard-no-stock]');
        var modal = root.querySelector('[data-modal="product-margin-editor"]');
        var modalForm = modal ? modal.querySelector('[data-product-margin-editor-form="1"]') : null;
        var modalNotice = modal ? modal.querySelector('[data-product-margin-modal-notice]') : null;
        var modalSubtitle = modal ? modal.querySelector('[data-product-margin-modal-subtitle]') : null;
        var modalProduct = modal ? modal.querySelector('[data-product-margin-modal-product]') : null;
        var modalStock = modal ? modal.querySelector('[data-product-margin-modal-stock]') : null;
        var modalGlobalHelp = modal ? modal.querySelector('[data-product-margin-modal-global-help]') : null;
        var modalCategoryBadges = modal ? modal.querySelector('[data-product-margin-category-badges]') : null;
        var previewReal = modal ? modal.querySelector('[data-product-margin-preview-real]') : null;
        var previewGap = modal ? modal.querySelector('[data-product-margin-preview-gap]') : null;
        var previewMargin = modal ? modal.querySelector('[data-product-margin-preview-margin]') : null;
        var previewStatus = modal ? modal.querySelector('[data-product-margin-preview-status]') : null;
        var previewHelp = modal ? modal.querySelector('[data-product-margin-preview-help]') : null;
        var saveButton = modal ? modal.querySelector('[data-product-margin-save="1"]') : null;

        function buildNotice(message, tone) {
            if (!message) {
                return '';
            }

            return '<div class="asdl-fin-tool-notice asdl-fin-tool-notice-' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(message) + '</div>';
        }

        function money(value) {
            return formatToolMoney(Number(value || 0), 'USD');
        }

        function selectedOptionValues(select) {
            if (!select) {
                return [];
            }

            return Array.prototype.slice.call(select.options || []).filter(function (option) {
                return !!option.selected;
            }).map(function (option) {
                return String(option.value || '');
            }).filter(function (value) {
                return value !== '';
            });
        }

        function selectedOptionLabels(select) {
            if (!select) {
                return [];
            }

            return Array.prototype.slice.call(select.options || []).filter(function (option) {
                return !!option.selected;
            }).map(function (option) {
                return String(option.textContent || option.label || '').trim();
            }).filter(function (value) {
                return value !== '';
            });
        }

        function formatFixedNumber(value, decimals) {
            var number = Number(value || 0);

            if (!isFinite(number)) {
                number = 0;
            }

            return number.toFixed(decimals);
        }

        function syncCategoryBadges(select) {
            var labels;

            if (!modalCategoryBadges) {
                return;
            }

            labels = selectedOptionLabels(select);

            if (!labels.length) {
                modalCategoryBadges.innerHTML = renderPill('Sin categoria', 'neutral');
                return;
            }

            modalCategoryBadges.innerHTML = labels.map(function (label) {
                return renderPill(label, 'neutral');
            }).join('');
        }

        function renderInventoryTonePill(label, tone) {
            return '<span class="asdl-fin-product-margin-stock-pill asdl-fin-product-margin-stock-pill-' + escapeHtml(String(tone || 'neutral')) + '">' + escapeHtml(String(label || 'Sin control')) + '</span>';
        }

        function renderModalInventory(rowData) {
            var inventoryLabel;
            var lastInventoryLabel;

            if (!modalStock) {
                return;
            }

            inventoryLabel = String(rowData && rowData.inventory_label || 'Sin control');
            lastInventoryLabel = String(rowData && rowData.last_inventory_label || 'Sin historial');

            modalStock.innerHTML = ''
                + '<strong>' + renderInventoryTonePill(inventoryLabel, rowData && rowData.inventory_tone || 'neutral') + '</strong>'
                + '<span>' + escapeHtml(lastInventoryLabel) + '</span>';
        }

        function normalizePercent(value) {
            var number = Number(value || 0);
            if (!isFinite(number) || number < 0) {
                number = 0;
            }
            if (number > 95) {
                number = 95;
            }
            return Math.round(number * 100) / 100;
        }

        function normalizeStrategyMode(value) {
            return value === 'manual' ? 'manual' : 'formula';
        }

        function computeEstimatedRealPrice(regularPrice, targetPercent) {
            var regular = Math.max(0, Number(regularPrice || 0));
            var fraction = normalizePercent(targetPercent) / 100;

            if (fraction >= 0.995) {
                fraction = 0.995;
            }

            return Math.max(0, regular * (1 - fraction));
        }

        function computePriceGapPercent(regularPrice, estimatedRealPrice) {
            var regular = Math.max(0, Number(regularPrice || 0));
            var real = Math.max(0, Number(estimatedRealPrice || 0));

            if (real <= 0 || regular <= real) {
                return 0;
            }

            return ((regular - real) / real) * 100;
        }

        function computeRealMarginPercent(estimatedRealPrice, cost) {
            var real = Math.max(0, Number(estimatedRealPrice || 0));
            var currentCost = Math.max(0, Number(cost || 0));

            if (real <= 0) {
                return 0;
            }

            return ((real - currentCost) / real) * 100;
        }

        function productMarginTone(status) {
            if (status === 'critical') {
                return 'danger';
            }
            if (status === 'review') {
                return 'warning';
            }
            if (status === 'manual') {
                return 'info';
            }
            return 'success';
        }

        function evaluatePreviewState(cost, regularPrice, targetPercent, inheritTarget, strategyMode, globalPercent) {
            var regular = Math.max(0, Number(regularPrice || 0));
            var currentCost = Math.max(0, Number(cost || 0));
            var target = normalizePercent(targetPercent);
            var mode = normalizeStrategyMode(strategyMode);
            var effectiveTarget = inheritTarget ? normalizePercent(globalPercent) : target;
            var estimatedReal = computeEstimatedRealPrice(regular, effectiveTarget);
            var gapPercent = computePriceGapPercent(regular, estimatedReal);
            var marginPercent = computeRealMarginPercent(estimatedReal, currentCost);
            var deviation = Math.abs(effectiveTarget - normalizePercent(globalPercent));
            var status = 'ok';
            var label = 'OK';
            var help = 'Pricing consistente y margen sano.';

            if (regular <= 0) {
                status = 'critical';
                label = 'Critico';
                help = 'No tiene precio publicado.';
            } else if (estimatedReal <= 0) {
                status = 'critical';
                label = 'Critico';
                help = 'El precio real estimado quedo invalido.';
            } else if (currentCost <= 0) {
                status = 'critical';
                label = 'Critico';
                help = 'Falta el costo base del producto.';
            } else if (currentCost >= estimatedReal) {
                status = 'critical';
                label = 'Critico';
                help = 'El costo es mayor o igual al precio real estimado.';
            } else if (mode === 'manual') {
                status = 'manual';
                label = 'Manual';
                help = 'El producto usa estrategia manual y no se compara contra la regla general.';
            } else if (marginPercent <= 12) {
                status = 'review';
                label = 'Revisar';
                help = 'El margen sobre el precio real estimado quedo bajo.';
            } else if (!inheritTarget && deviation >= 5) {
                status = 'review';
                label = 'Revisar';
                help = 'El porcentaje objetivo se desvia bastante de la referencia global.';
            }

            return {
                effectiveTarget: effectiveTarget,
                estimatedReal: estimatedReal,
                gapPercent: gapPercent,
                marginPercent: marginPercent,
                status: status,
                label: label,
                help: help
            };
        }

        function renderProgress(job, message, tone) {
            var snapshot = job || {};
            var total = Number(snapshot.total_products || 0);
            var processed = Number(snapshot.processed_products || 0);

            if (!progressTarget) {
                return;
            }

            if (!snapshot.status) {
                progressTarget.innerHTML = buildNotice(message || 'Todavia no hay una revision activa.', tone || 'neutral');
                return;
            }

            if (snapshot.status === 'running') {
                progressTarget.innerHTML = ''
                    + buildNotice(message || snapshot.message || 'Actualizando snapshot de productos y precios.', tone || 'warning')
                    + '<div class="asdl-fin-tool-progress-card">'
                    + '<div class="asdl-fin-tool-progress-head"><strong>Revision del catalogo</strong><span>' + escapeHtml(processed.toLocaleString('es-VE')) + ' / ' + escapeHtml(total.toLocaleString('es-VE')) + '</span></div>'
                    + buildAsyncProgressBar(processed, total)
                    + '<div class="asdl-fin-tool-progress-meta">'
                    + '<span>Ultimo lote: ' + escapeHtml(String(snapshot.last_batch || 0)) + ' producto(s)</span>'
                    + '<span>Criticos + revisar: ' + escapeHtml(String(snapshot.issue_count || 0)) + '</span>'
                    + '<span>Actualizado: ' + escapeHtml(formatToolTimestamp(snapshot.updated_at || '')) + '</span>'
                    + '</div>'
                    + '</div>';
                return;
            }

            progressTarget.innerHTML = buildNotice(
                message || snapshot.message || (snapshot.status === 'completed' ? 'Revision lista.' : 'La revision termino con error.'),
                tone || (snapshot.status === 'completed' ? 'success' : 'danger')
            );
        }

        function decodeRowData(row) {
            if (!row) {
                return null;
            }

            try {
                return JSON.parse(decodeURIComponent(String(row.getAttribute('data-row-json') || '')));
            } catch (error) {
                return null;
            }
        }

        function buildRowNode(html) {
            var wrapper = document.createElement('tbody');
            wrapper.innerHTML = String(html || '').trim();
            return wrapper.firstElementChild;
        }

        function refreshTabCounters() {
            root.querySelectorAll('[data-product-margin-tab]').forEach(function (button) {
                var tabKey = String(button.getAttribute('data-product-margin-tab') || '');
                var tbody = resultsTarget ? resultsTarget.querySelector('[data-product-margin-tbody="' + tabKey + '"]') : null;
                var count = tbody ? tbody.querySelectorAll('[data-product-margin-row]').length : 0;
                button.textContent = (tabKey === 'verified' ? 'Productos en orden' : 'Hallazgos') + ' (' + count.toLocaleString('es-VE') + ')';
            });
        }

        function refreshTabPanels() {
            if (!resultsTarget) {
                return;
            }

            ['issues', 'verified'].forEach(function (tabKey) {
                var panel = resultsTarget.querySelector('[data-product-margin-panel="' + tabKey + '"]');
                var tbody = resultsTarget.querySelector('[data-product-margin-tbody="' + tabKey + '"]');
                var wrap = resultsTarget.querySelector('[data-product-margin-table-wrap="' + tabKey + '"]');
                var empty = resultsTarget.querySelector('[data-product-margin-tab-empty="' + tabKey + '"]');
                var visibleCount = 0;

                if (!panel) {
                    return;
                }

                if (tbody) {
                    tbody.querySelectorAll('[data-product-margin-row]').forEach(function (row) {
                        if (!row.hidden) {
                            visibleCount += 1;
                        }
                    });
                }

                if (wrap) {
                    wrap.hidden = visibleCount === 0;
                }
                if (empty) {
                    empty.hidden = visibleCount !== 0;
                }
            });
        }

        function applyFilters() {
            var term = String(searchField && searchField.value || '').trim();
            var categoryTerm = String(categoryField && categoryField.value || '').trim();
            var statusValue = String(statusField && statusField.value || 'all');
            var modeValue = String(modeField && modeField.value || 'all');

            if (!resultsTarget) {
                return;
            }

            resultsTarget.querySelectorAll('[data-product-margin-row]').forEach(function (row) {
                var haystack = String(row.getAttribute('data-margin-search') || '');
                var category = String(row.getAttribute('data-margin-category') || '');
                var status = String(row.getAttribute('data-margin-status') || '').toLowerCase();
                var mode = String(row.getAttribute('data-margin-mode') || '').toLowerCase();
                var match = true;

                if (term && !matchesFlexibleSearch(term, haystack)) {
                    match = false;
                }

                if (match && categoryTerm && !matchesFlexibleSearch(categoryTerm, category)) {
                    match = false;
                }

                if (match && statusValue !== 'all' && status !== statusValue) {
                    match = false;
                }

                if (match && modeValue !== 'all' && mode !== modeValue) {
                    match = false;
                }

                row.hidden = !match;
            });

            refreshTabPanels();
        }

        function setActiveTab(tabKey) {
            state.activeTab = tabKey === 'verified' ? 'verified' : 'issues';

            if (!resultsTarget) {
                return;
            }

            resultsTarget.querySelectorAll('[data-product-margin-tab]').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-product-margin-tab') === state.activeTab);
            });

            resultsTarget.querySelectorAll('[data-product-margin-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-product-margin-panel') !== state.activeTab;
            });

            refreshTabPanels();
        }

        function syncModalGlobalHelp(rowData) {
            var globalPercent = rowData ? normalizePercent(rowData.global_target_percent) : normalizePercent(dualPricing.percent);

            if (modalGlobalHelp) {
                modalGlobalHelp.textContent = 'Referencia global actual: ' + globalPercent.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%.';
            }
        }

        function renderModalPreview() {
            if (!modalForm) {
                return;
            }

            var costInput = modalForm.querySelector('[name="cost"]');
            var regularInput = modalForm.querySelector('[name="regular_price"]');
            var targetInput = modalForm.querySelector('[name="target_percent"]');
            var slider = modalForm.querySelector('[name="target_percent_slider"]');
            var inheritInput = modalForm.querySelector('[name="inherit_target"]');
            var modeInput = modalForm.querySelector('[name="strategy_mode"]');
            var globalPercent = normalizePercent(modalForm.getAttribute('data-global-target-percent') || dualPricing.percent || 0);
            var preview = evaluatePreviewState(
                Number(costInput && costInput.value || 0),
                Number(regularInput && regularInput.value || 0),
                Number(targetInput && targetInput.value || 0),
                !!(inheritInput && inheritInput.checked),
                String(modeInput && modeInput.value || 'formula'),
                globalPercent
            );

            if (slider && document.activeElement !== slider) {
                slider.value = String(preview.effectiveTarget);
            }

            if (previewReal) {
                previewReal.textContent = money(preview.estimatedReal);
            }
            if (previewGap) {
                previewGap.textContent = preview.gapPercent.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
            }
            if (previewMargin) {
                previewMargin.textContent = preview.marginPercent.toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '%';
            }
            if (previewStatus) {
                previewStatus.innerHTML = renderPill(preview.label, productMarginTone(preview.status));
            }
            if (previewHelp) {
                previewHelp.textContent = preview.help;
            }

            if (targetInput) {
                targetInput.disabled = !!(inheritInput && inheritInput.checked);
            }
            if (slider) {
                slider.disabled = !!(inheritInput && inheritInput.checked);
            }
        }

        function fillModal(rowData) {
            var costInput;
            var regularInput;
            var targetInput;
            var slider;
            var inheritInput;
            var modeInput;
            var categorySelect;
            var categoryLabels;
            var productMetaHtml;

            if (!modal || !modalForm || !rowData) {
                return;
            }

            modalForm.reset();
            modalForm.querySelector('[name="product_id"]').value = String(rowData.product_id || '');
            modalForm.querySelector('[name="row_signature"]').value = String(rowData.signature || '');
            modalForm.querySelector('[name="cost_target_product_id"]').value = String(rowData.cost_target_product_id || '');
            modalForm.querySelector('[name="category_target_product_id"]').value = String(rowData.category_target_product_id || '');
            modalForm.querySelector('[name="cost_meta_key"]').value = String(rowData.cost_meta_key || '');
            modalForm.querySelector('[name="scope_kind"]').value = 'catalog';
            modalForm.querySelector('[name="exclude_categories_raw"]').value = String(excludeField && excludeField.value || '');
            modalForm.setAttribute('data-global-target-percent', String(normalizePercent(rowData.global_target_percent || dualPricing.percent || 0)));

            costInput = modalForm.querySelector('[name="cost"]');
            regularInput = modalForm.querySelector('[name="regular_price"]');
            targetInput = modalForm.querySelector('[name="target_percent"]');
            slider = modalForm.querySelector('[name="target_percent_slider"]');
            inheritInput = modalForm.querySelector('[name="inherit_target"]');
            modeInput = modalForm.querySelector('[name="strategy_mode"]');
            categorySelect = modalForm.querySelector('[name="category_ids"]');
            categoryLabels = Array.isArray(rowData.category_labels) ? rowData.category_labels : [];

            if (costInput) {
                costInput.value = formatFixedNumber(rowData.cost || 0, 2);
            }
            if (regularInput) {
                regularInput.value = formatFixedNumber(rowData.regular_price || 0, 2);
            }
            if (targetInput) {
                targetInput.value = formatFixedNumber(normalizePercent(rowData.target_percent || 0), 2);
            }
            if (slider) {
                slider.value = formatFixedNumber(normalizePercent(rowData.target_percent || 0), 2);
            }
            if (inheritInput) {
                inheritInput.checked = !!rowData.target_inherited;
            }
            if (modeInput) {
                modeInput.value = normalizeStrategyMode(rowData.strategy_mode || 'formula');
            }
            if (categorySelect) {
                Array.prototype.slice.call(categorySelect.options || []).forEach(function (option) {
                    option.selected = Array.isArray(rowData.category_ids) && rowData.category_ids.map(String).indexOf(String(option.value || '')) !== -1;
                });
                syncCategoryBadges(categorySelect);
            }
            if (modalProduct) {
                productMetaHtml = '<strong>' + escapeHtml(String(rowData.product_name || 'Producto')) + '</strong>'
                    + '<span>ID interno: ' + escapeHtml(Number(rowData.product_internal_id || rowData.product_id || 0).toLocaleString('es-VE')) + '</span>'
                    + '<span>SKU: ' + escapeHtml(String(rowData.sku || 'Sin SKU')) + '</span>';

                modalProduct.innerHTML = productMetaHtml;
            }
            renderModalInventory(rowData);
            if (modalSubtitle) {
                modalSubtitle.textContent = 'Edita costo, precio publicado, categorias y la referencia usada para el precio real estimado.';
            }
            if (modalNotice) {
                modalNotice.innerHTML = '';
            }

            syncModalGlobalHelp(rowData);
            renderModalPreview();
        }

        function openEditor(row) {
            var rowData = decodeRowData(row);

            if (!rowData || !modal) {
                return;
            }

            fillModal(rowData);
            setModalState(modal, true);
        }

        function replaceRow(rowHtml, tabKey, productId) {
            var newRow = buildRowNode(rowHtml);
            var currentRow = resultsTarget ? resultsTarget.querySelector('[data-product-margin-id="' + String(productId) + '"]') : null;
            var targetTbody = resultsTarget ? resultsTarget.querySelector('[data-product-margin-tbody="' + String(tabKey || 'issues') + '"]') : null;

            if (!newRow || !resultsTarget || !targetTbody) {
                return;
            }

            if (currentRow && currentRow.parentNode) {
                currentRow.parentNode.removeChild(currentRow);
            }

            targetTbody.insertBefore(newRow, targetTbody.firstChild || null);
            refreshTabCounters();
            applyFilters();
        }

        function requestResult(jobId) {
            requestAdminAjax('asdl_fin_product_margin_check_result', runtimeNonces.productMarginResult, {
                job_id: jobId
            }).then(function (response) {
                state.running = false;
                window.clearTimeout(timer);
                if (startButton) {
                    startButton.disabled = false;
                }
                if (discardVisibleButton) {
                    discardVisibleButton.disabled = false;
                }
                if (resultsTarget) {
                    resultsTarget.innerHTML = response.html || '';
                }
                state.workspaceStale = false;
                refreshTabCounters();
                setActiveTab(state.activeTab);
                applyFilters();
                renderProgress((response.result || response.job || {}), 'Revision completada correctamente.', 'success');
            }).catch(function (error) {
                stopProductMarginRunner((error && error.message) || 'No se pudo recuperar el snapshot final del catalogo.');
            });
        }

        function refreshWorkspaceHtml(response, fallbackMessage, tone) {
            state.running = false;
            state.workspaceStale = false;
            window.clearTimeout(timer);

            if (startButton) {
                startButton.disabled = false;
            }

            if (discardVisibleButton) {
                discardVisibleButton.disabled = false;
            }

            if (resultsTarget) {
                resultsTarget.innerHTML = response && response.html ? response.html : '';
            }

            refreshTabCounters();
            setActiveTab(state.activeTab);
            applyFilters();
            renderProgress((response && response.result) || { status: 'completed' }, (response && response.message) || fallbackMessage || 'Vista actualizada.', tone || 'success');
        }

        function collectVisibleNoStockIssueIds() {
            if (!resultsTarget) {
                return [];
            }

            return Array.prototype.slice.call(resultsTarget.querySelectorAll('[data-product-margin-tbody="issues"] [data-product-margin-row]')).filter(function (row) {
                var data = decodeRowData(row);

                if (row.hidden || !data || data.snapshot_discarded) {
                    return false;
                }

                if (String(data.inventory_tone || '') !== 'danger') {
                    return false;
                }

                if (data.inventory_managed && Number(data.inventory_current || 0) > 0) {
                    return false;
                }

                return true;
            }).map(function (row) {
                return String(row.getAttribute('data-product-margin-id') || '');
            }).filter(function (value) {
                return value !== '';
            });
        }

        function runSnapshotDiscard(action, nonce, payload, fallbackMessage) {
            if (state.running) {
                return;
            }

            requestAdminAjax(action, nonce, payload).then(function (response) {
                refreshWorkspaceHtml(response || {}, fallbackMessage || 'Vista actualizada.', 'success');
            }).catch(function (error) {
                state.running = false;
                if (startButton) {
                    startButton.disabled = false;
                }
                if (discardVisibleButton) {
                    discardVisibleButton.disabled = false;
                }
                renderProgress({ status: 'error' }, (error && error.message) || fallbackMessage || 'No se pudo actualizar la vista.', 'danger');
            });
        }

        function triggerDiscardVisibleNoStock() {
            var visibleIds = collectVisibleNoStockIssueIds();

            if (!visibleIds.length) {
                renderProgress({ status: 'error' }, 'No hay hallazgos visibles sin inventario para descartar en esta vista.', 'danger');
                return;
            }

            if (discardVisibleButton) {
                discardVisibleButton.disabled = true;
            }

            runSnapshotDiscard('asdl_fin_product_margin_discard_no_stock_visible', runtimeNonces.productMarginDiscardNoStock, {
                scope_kind: 'catalog',
                exclude_categories_raw: excludeField ? String(excludeField.value || '') : '',
                visible_ids_csv: visibleIds.join(',')
            }, 'No se pudieron descartar los hallazgos visibles sin inventario.');
        }

        function scheduleContinue(jobId) {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                requestAdminAjax('asdl_fin_product_margin_check_continue', runtimeNonces.productMarginContinue, {
                    job_id: jobId
                }).then(function (payload) {
                    var job = payload && payload.job ? payload.job : {};
                    state.jobId = String(job.job_id || jobId || '');
                    renderProgress(job, job.message || 'Lote procesado correctamente.', job.status === 'running' ? 'warning' : 'success');

                    if (job.status === 'running') {
                        scheduleContinue(state.jobId);
                        return;
                    }

                    if (job.status === 'completed') {
                        requestResult(state.jobId);
                        return;
                    }

                    state.running = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    renderProgress(job, job.message || 'La revision termino con error.', 'danger');
                }).catch(function (error) {
                    stopProductMarginRunner((error && error.message) || 'No se pudo continuar la revision.');
                });
            }, 180);
        }

        function startCheck() {
            if (state.running) {
                return;
            }

            state.running = true;
            state.workspaceStale = false;

            if (startButton) {
                startButton.disabled = true;
            }
            if (discardVisibleButton) {
                discardVisibleButton.disabled = true;
            }

            if (resultsTarget) {
                resultsTarget.innerHTML = '<div class="asdl-fin-empty"><strong>Preparando la vista rapida...</strong><p>El sistema recorrera el catalogo por lotes para revisar costo, precio publicado, inventario, referencia real y estados de margen.</p></div>';
            }

            renderProgress({
                status: 'running',
                total_products: 0,
                processed_products: 0,
                last_batch: 0
            }, 'Inicializando la revision diaria de productos y precios...', 'warning');

            requestAdminAjax('asdl_fin_product_margin_check_start', runtimeNonces.productMarginStart, {
                scope_kind: 'catalog',
                exclude_categories_raw: excludeField ? String(excludeField.value || '') : ''
            }).then(function (response) {
                var job = response && response.job ? response.job : {};
                state.jobId = String(job.job_id || '');
                renderProgress(job, job.message || 'Revision iniciada.', job.status === 'completed' ? 'success' : 'warning');

                if (job.status === 'completed') {
                    requestResult(state.jobId);
                    return;
                }

                scheduleContinue(state.jobId);
            }).catch(function (error) {
                stopProductMarginRunner((error && error.message) || 'No se pudo iniciar la revision.');
            });
        }

        if (startButton) {
            startButton.addEventListener('click', function () {
                startCheck();
            });
        }

        if (discardVisibleButton) {
            discardVisibleButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                triggerDiscardVisibleNoStock();
            });
        }

        [searchField, categoryField, statusField, modeField].forEach(function (field) {
            if (!field) {
                return;
            }

            ['input', 'change'].forEach(function (eventName) {
                field.addEventListener(eventName, applyFilters);
            });
        });

        if (modalForm) {
            modalForm.addEventListener('input', function (event) {
                var target = event.target;
                var targetInput = modalForm.querySelector('[name="target_percent"]');
                var slider = modalForm.querySelector('[name="target_percent_slider"]');
                var categorySelect = modalForm.querySelector('[name="category_ids"]');

                if (target && target.name === 'target_percent_slider' && targetInput) {
                    targetInput.value = normalizePercent(target.value).toFixed(2);
                }

                if (target && target.name === 'target_percent' && slider) {
                    slider.value = normalizePercent(target.value).toFixed(2);
                }

                if (target && categorySelect && target.name === 'category_ids') {
                    syncCategoryBadges(categorySelect);
                }

                renderModalPreview();
            });

            modalForm.addEventListener('change', function (event) {
                var target = event.target;
                var categorySelect = modalForm.querySelector('[name="category_ids"]');

                if (target && target.name === 'category_ids' && categorySelect) {
                    syncCategoryBadges(categorySelect);
                }

                renderModalPreview();
            });

            modalForm.addEventListener('blur', function (event) {
                var target = event.target;

                if (!target || !target.name) {
                    return;
                }

                if (target.name === 'cost' || target.name === 'regular_price') {
                    target.value = formatFixedNumber(target.value, 2);
                    renderModalPreview();
                    return;
                }

                if (target.name === 'target_percent') {
                    target.value = formatFixedNumber(normalizePercent(target.value), 2);
                    renderModalPreview();
                }
            }, true);

            modalForm.addEventListener('submit', function (event) {
                var payload;

                event.preventDefault();

                if (!saveButton) {
                    return;
                }

                saveButton.disabled = true;

                payload = {
                    product_id: modalForm.querySelector('[name="product_id"]').value,
                    row_signature: modalForm.querySelector('[name="row_signature"]').value,
                    cost_target_product_id: modalForm.querySelector('[name="cost_target_product_id"]').value,
                    category_target_product_id: modalForm.querySelector('[name="category_target_product_id"]').value,
                    cost_meta_key: modalForm.querySelector('[name="cost_meta_key"]').value,
                    cost: modalForm.querySelector('[name="cost"]').value,
                    regular_price: modalForm.querySelector('[name="regular_price"]').value,
                    target_percent: modalForm.querySelector('[name="target_percent"]').value,
                    inherit_target: modalForm.querySelector('[name="inherit_target"]').checked ? '1' : '',
                    strategy_mode: modalForm.querySelector('[name="strategy_mode"]').value,
                    category_ids_csv: selectedOptionValues(modalForm.querySelector('[name="category_ids"]')).join(','),
                    scope_kind: modalForm.querySelector('[name="scope_kind"]').value,
                    exclude_categories_raw: modalForm.querySelector('[name="exclude_categories_raw"]').value
                };

                if (modalNotice) {
                    modalNotice.innerHTML = buildNotice('Guardando cambios y validando la fila real del producto...', 'warning');
                }

                requestAdminAjax('asdl_fin_product_margin_update_cost', runtimeNonces.productMarginUpdateCost, payload).then(function (response) {
                    saveButton.disabled = false;
                    replaceRow(response.row_html || '', response.tab_key || 'issues', Number(response.row && response.row.product_id || payload.product_id || 0));
                    state.workspaceStale = !!response.workspace_stale;
                    setModalState(modal, false);
                    renderProgress(
                        { status: 'completed' },
                        response.message || 'Producto actualizado. La vista actual quedo pendiente de recalculo.',
                        response.workspace_stale ? 'warning' : 'success'
                    );
                }).catch(function (error) {
                    var currentRow = error && error.current_row ? error.current_row : null;

                    saveButton.disabled = false;

                    if (modalNotice) {
                        modalNotice.innerHTML = buildNotice((error && error.message) || 'No se pudo guardar el producto.', 'danger');
                    }

                    if (currentRow && error.row_html) {
                        replaceRow(error.row_html, error.tab_key || 'issues', Number(currentRow.product_id || 0));
                        fillModal(currentRow);
                    }

                    renderProgress({ status: 'error' }, (error && error.message) || 'No se pudo actualizar el producto.', 'danger');
                });
            });
        }

        root.addEventListener('click', function (event) {
            var tabTrigger = event.target.closest('[data-product-margin-tab]');
            var rowEditorTrigger = event.target.closest('[data-product-margin-open-editor]');
            var rowDiscardTrigger = event.target.closest('[data-product-margin-discard-row]');
            var discardVisibleTrigger = event.target.closest('[data-product-margin-discard-no-stock]');
            var row;
            var rowData;

            if (tabTrigger) {
                event.preventDefault();
                setActiveTab(String(tabTrigger.getAttribute('data-product-margin-tab') || 'issues'));
                return;
            }

            if (discardVisibleTrigger) {
                event.preventDefault();
                triggerDiscardVisibleNoStock();
                return;
            }

            if (rowDiscardTrigger) {
                event.preventDefault();
                row = rowDiscardTrigger.closest('[data-product-margin-row]');
                rowData = decodeRowData(row);

                if (!rowData) {
                    return;
                }

                runSnapshotDiscard(
                    rowData.snapshot_discarded ? 'asdl_fin_product_margin_reinstate_row' : 'asdl_fin_product_margin_discard_row',
                    rowData.snapshot_discarded ? runtimeNonces.productMarginReinstateRow : runtimeNonces.productMarginDiscardRow,
                    {
                        product_id: rowData.product_id,
                        scope_kind: 'catalog',
                        exclude_categories_raw: excludeField ? String(excludeField.value || '') : ''
                    },
                    rowData.snapshot_discarded ? 'No se pudo reincluir el producto en esta vista.' : 'No se pudo descartar el hallazgo en esta vista.'
                );
                return;
            }

            if (rowEditorTrigger) {
                event.preventDefault();
                openEditor(rowEditorTrigger.closest('[data-product-margin-row]'));
            }
        });

        refreshTabCounters();
        setActiveTab(state.activeTab);
        applyFilters();
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
            if (openTrigger.matches('[data-payment-method-open="1"], [data-currency-open="1"]')) {
                return;
            }
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

    function setupExpensePaymentMethodFields(root) {
        (root || document).querySelectorAll('[data-expense-payment-form]').forEach(function (form) {
            if (form.dataset.expensePaymentSetup === '1') {
                return;
            }

            form.dataset.expensePaymentSetup = '1';

            var paidInput = form.querySelector('[data-expense-paid-total]');
            var statusSelect = form.querySelector('[data-expense-payment-status]');
            var methodField = form.querySelector('[data-expense-payment-method-field]');
            var methodSelect = form.querySelector('[data-expense-payment-method-select]');
            var helper = form.querySelector('[data-expense-payment-method-helper]');

            function refreshExpensePaymentState() {
                var paid = paidInput ? parseFloat(paidInput.value || '0') : 0;
                var status = statusSelect ? String(statusSelect.value || '') : '';
                var hasPayment = false;

                if (Number.isNaN(paid)) {
                    paid = 0;
                }

                hasPayment = paid > 0 || status === 'partial' || status === 'paid';

                if (methodField) {
                    methodField.hidden = !hasPayment;
                }

                if (helper) {
                    helper.hidden = !hasPayment;
                }

                if (methodSelect) {
                    methodSelect.disabled = !hasPayment;
                    methodSelect.required = hasPayment;

                    if (!hasPayment) {
                        methodSelect.value = '';
                    }
                }
            }

            if (paidInput) {
                paidInput.addEventListener('input', refreshExpensePaymentState);
                paidInput.addEventListener('change', refreshExpensePaymentState);
            }

            if (statusSelect) {
                statusSelect.addEventListener('change', refreshExpensePaymentState);
            }

            refreshExpensePaymentState();
        });
    }

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
    setupInlinePaymentMethodModal();
    setupInlineCurrencyModal();
    setupExpensePaymentMethodFields(document);
    setupSupplierKindToggles(document);
    setupProfileContextDisclosures(document);
    setupInlineTabs(document);
    setupConsumptionHistorySelectors(document);
    setupDateWeekdayHelpers();
    setupAdminRuntimeLoaders(document);
    setupDashboardQueueFilters();
    setupDashboardTables();
    setupSortableStaticTables();
    setupOrderSettlementPreviewForms();
    setupOrderSettlementPreview();
    setupOrderAssumptionModal();
    setupPayrollPaymentModal();
    setupHistoricalTools();
    setupPayrollManualSettlementModal();
    setupCommitmentForms();
    setupSalaryAdvanceForms();
    setupPayrollPeriodForms();
    setupMasterReportRunner();
    setupProductMarginRunner();
})();
