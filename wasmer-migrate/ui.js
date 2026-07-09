(function () {
    const page = document.querySelector('.wasmer-migrate-page');
    if (!page || !window.wasmerMigrate) {
        return;
    }

    const panels = Array.from(page.querySelectorAll('[data-panel]'));
    const steps = Array.from(page.querySelectorAll('[data-step]'));
    const hasLegacyWizard = !!page.querySelector('[data-panel="code"]');
    const message = document.getElementById('wasmer-migrate-message');
    const codeField = document.getElementById('wasmer-migrate-import-code');
    const autoButton = document.getElementById('wasmer-migrate-auto-start');
    const advancedToggle = document.getElementById('wasmer-migrate-advanced-toggle');
    const advancedPanel = document.getElementById('wasmer-migrate-advanced');
    const useCodeButton = document.getElementById('wasmer-migrate-use-code');
    const useAutoButton = document.getElementById('wasmer-migrate-use-auto');
    const autoGraphqlUrl = document.getElementById('wasmer-migrate-auto-graphql-url');
    const autoAppText = document.getElementById('wasmer-migrate-auto-app');
    const autoBuildText = document.getElementById('wasmer-migrate-auto-build');
    const autoWpText = document.getElementById('wasmer-migrate-auto-wp');
    const connectButton = document.getElementById('wasmer-migrate-connect');
    const startButton = document.getElementById('wasmer-migrate-start');
    const clearButton = document.getElementById('wasmer-migrate-clear');
    const startOverButton = document.getElementById('wasmer-migrate-start-over');
    const footerStartOverButton = document.getElementById('wasmer-migrate-footer-start-over');
    const statusText = document.getElementById('wasmer-migrate-status');
    const destinationText = document.getElementById('wasmer-migrate-destination');
    const fileCountText = document.getElementById('wasmer-migrate-file-count');
    const databaseText = document.getElementById('wasmer-migrate-database');
    const filesText = document.getElementById('wasmer-migrate-files');
    const bytesText = document.getElementById('wasmer-migrate-bytes');
    const progressBar = document.getElementById('wasmer-migrate-progress-bar');
    const progressSteps = Array.from(page.querySelectorAll('[data-progress-step]'));
    const progressDetail = document.getElementById('wasmer-migrate-progress-detail');
    const logsText = document.getElementById('wasmer-migrate-logs');
    const doneMessage = document.getElementById('wasmer-migrate-done-message');
    const appLink = document.getElementById('wasmer-migrate-app-link');
    const perishAtText = document.getElementById('wasmer-migrate-perish-at');
    let pollTimer = null;
    let autoRequestRunning = false;
    let renderGeneration = 0;
    let currentState = {};
    let isResetting = false;
    let activeMigrationId = '';
    const activeRequests = [];

    function initialState() {
        try {
            return JSON.parse(page.getAttribute('data-initial-state') || '{}');
        } catch (error) {
            return {};
        }
    }

    function request(action, data, options) {
        const form = new FormData();
        form.append('action', action);
        form.append('nonce', window.wasmerMigrate.nonce);
        Object.keys(data || {}).forEach(function (key) {
            form.append(key, data[key]);
        });

        const controller = window.AbortController ? new AbortController() : null;
        if (controller && !(options && options.keepDuringReset)) {
            activeRequests.push(controller);
        }

        return fetch(window.wasmerMigrate.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined,
            body: form
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok || !body.success) {
                    const error = new Error((body.data && body.data.message) || 'Request failed');
                    error.state = body.data && body.data.state;
                    throw error;
                }
                return body.data;
            });
        }).finally(function () {
            if (!controller) {
                return;
            }
            const index = activeRequests.indexOf(controller);
            if (index !== -1) {
                activeRequests.splice(index, 1);
            }
        });
    }

    function abortActiveRequests() {
        while (activeRequests.length) {
            const controller = activeRequests.pop();
            controller.abort();
        }
    }

    function showPanel(name) {
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== name;
        });
        steps.forEach(function (step) {
            const order = hasLegacyWizard ? ['code', 'review', 'transfer', 'done'] : ['auto', 'transfer', 'done'];
            const activeName = order.indexOf(name) === -1 ? order[0] : name;
            const stepName = step.getAttribute('data-step');
            step.classList.toggle('is-active', stepName === activeName);
            step.classList.toggle('is-complete', order.indexOf(stepName) < order.indexOf(activeName));
        });
    }

    function showMessage(text, type) {
        if (!message) {
            return;
        }
        if (!text) {
            message.hidden = true;
            message.textContent = '';
            return;
        }
        message.hidden = false;
        message.className = 'notice inline notice-' + (type || 'info');
        message.innerHTML = '<p></p>';
        message.querySelector('p').textContent = text;
    }

    function migrationStep(state) {
        const status = state.status || 'idle';
        if (hasLegacyWizard) {
            if (status === 'failed') {
                return 'transfer';
            }
            if (status === 'transfer_complete' || status === 'auto_complete') {
                return 'done';
            }
            if (status === 'transferring' || status === 'exported' || status.indexOf('auto_') === 0) {
                return 'transfer';
            }
            if (state.destination) {
                return 'review';
            }
            return 'code';
        }
        if (status === 'failed') {
            return state.auto_app ? 'transfer' : 'auto';
        }
        if (status === 'transfer_complete' || status === 'auto_complete') {
            return 'done';
        }
        if (status.indexOf('auto_') === 0) {
            if (status !== 'auto_complete') {
                return 'transfer';
            }
            return 'auto';
        }
        if (status === 'transferring' || status === 'exported') {
            return 'transfer';
        }
        return 'auto';
    }

    function progressPhase(state) {
        const status = state.status || 'idle';
        if (status === 'auto_transferring' || status === 'auto_importing' || status === 'transferring' || status === 'transfer_complete' || status === 'auto_complete') {
            return 'transferring';
        }
        if (status === 'auto_exporting' || status === 'auto_session' || status === 'exported' || state.destination) {
            return 'preparing';
        }
        return 'creating';
    }

    function renderProgressSteps(state) {
        const currentState = state || {};
        const order = ['creating', 'preparing', 'transferring'];
        const phase = progressPhase(currentState);
        progressSteps.forEach(function (step) {
            const stepName = step.getAttribute('data-progress-step');
            step.classList.toggle('is-active', stepName === phase);
            step.classList.toggle('is-complete', order.indexOf(stepName) < order.indexOf(phase) || (currentState.status === 'auto_complete' && stepName === 'transferring'));
        });
    }

    function formatBytes(bytes) {
        const value = Number(bytes) || 0;
        if (value < 1024) {
            return value + ' bytes';
        }
        const units = ['KB', 'MB', 'GB'];
        let amount = value / 1024;
        let unitIndex = 0;
        while (amount >= 1024 && unitIndex < units.length - 1) {
            amount = amount / 1024;
            unitIndex += 1;
        }
        return amount.toFixed(amount >= 10 ? 0 : 1) + ' ' + units[unitIndex];
    }

    function formatDate(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short'
        });
    }

    function setText(element, value) {
        if (element) {
            element.textContent = value;
        }
    }

    function progressDetailText(state) {
        const status = state.status || 'idle';
        const auto = state.auto_app || {};
        if (status === 'auto_waiting') {
            return auto.build_status
                ? 'Wasmer is setting up the new app. Current build status: ' + auto.build_status + '.'
                : 'Wasmer is setting up the new app. This can take a few minutes.';
        }
        if (status === 'auto_exporting' || status === 'exported') {
            return 'Preparing a copy of this WordPress site. Your current site stays online.';
        }
        if (status === 'auto_session') {
            return 'The new app is ready. Preparing a secure transfer session.';
        }
        if (status === 'auto_transferring' || status === 'transferring') {
            return 'Copying your site data to Wasmer. Larger sites can take longer.';
        }
        if (status === 'auto_importing' || status === 'transfer_complete') {
            return 'Wasmer has received the site data and is finishing the import.';
        }
        return 'Starting migration...';
    }

    function render(state) {
        if (isResetting) {
            return;
        }
        state = state || {};
        currentState = state;
        const progress = state.progress || {};
        const fileCount = state.file_count || (state.files ? state.files.length : 0) || 0;
        const filesSent = progress.files_sent || 0;
        const bytesSent = progress.bytes_sent || 0;
        const databaseSent = progress.database_sent || 0;
        const percent = fileCount ? Math.min(100, Math.round((filesSent / fileCount) * 100)) : (databaseSent ? 5 : 0);
        const auto = state.auto_app || {};
        const autoApp = auto.app || {};
        const targetWp = auto.target_wp_version || {};
        const liveConfig = auto.live_config || {};

        const appUrl = autoApp.url || '';

        setText(statusText, state.status || 'idle');
        setText(destinationText, (state.destination && (state.destination.target || state.destination.rest)) || '-');
        setText(fileCountText, String(fileCount));
        setText(databaseText, databaseSent ? formatBytes(databaseSent) : 'Not started');
        setText(filesText, filesSent + ' / ' + fileCount);
        setText(bytesText, formatBytes(bytesSent));
        if (progressBar) {
            progressBar.style.width = percent + '%';
        }
        setText(progressDetail, progressDetailText(state));
        setText(logsText, (state.logs || []).join('\n'));
        renderProgressSteps(state);
        if (autoAppText) {
            autoAppText.textContent = autoApp.url || autoApp.name || '-';
        }
        if (autoBuildText) {
            autoBuildText.textContent = auto.build_status || auto.status || 'idle';
        }
        if (autoWpText) {
            autoWpText.textContent = [
                targetWp.version || '',
                liveConfig.phpVersion ? 'PHP ' + liveConfig.phpVersion : ''
            ].filter(Boolean).join(' / ') || '-';
        }
        if (doneMessage) {
            doneMessage.textContent = appUrl
                ? 'Your WordPress site has been copied to a new Wasmer app.'
                : 'Your WordPress site has been copied to a new Wasmer app. Open it from your Wasmer dashboard.';
        }
        if (appLink) {
            appLink.hidden = !appUrl;
            if (appUrl) {
                appLink.href = appUrl;
            }
        }
        if (perishAtText) {
            const expires = formatDate(autoApp.willPerishAt);
            perishAtText.hidden = !expires;
            perishAtText.textContent = expires ? ' It is currently scheduled to disappear on ' + expires + '.' : '';
        }

        showPanel(migrationStep(state));
        if (state.error) {
            showMessage(state.error, 'error');
        } else if (state.status === 'auto_complete') {
            showMessage('Your Wasmer app is ready.', 'success');
        } else if (state.status === 'transfer_complete') {
            showMessage('Your site data has been copied.', 'success');
        } else if ((state.status || '').indexOf('auto_') === 0) {
            showMessage('Wasmer is setting up your new app. You can leave this page open while it works.', 'info');
        } else {
            showMessage('', 'info');
        }
    }

    function shouldAcceptState(state) {
        if (isResetting) {
            return false;
        }
        if (activeMigrationId === 'pending') {
            return false;
        }
        if (!activeMigrationId) {
            return true;
        }
        return !!state && migrationIdOf(state) === activeMigrationId;
    }

    function migrationIdOf(state) {
        return state ? ((state.migration_id || state.id || '') + '') : '';
    }

    function poll() {
        if (isResetting) {
            return;
        }
        const generation = renderGeneration;
        request('wasmer_migrate_status').then(function (state) {
            if (generation === renderGeneration && shouldAcceptState(state)) {
                render(state);
            }
        }).catch(function () {});
    }

    function startPolling() {
        if (!pollTimer) {
            pollTimer = window.setInterval(poll, 3000);
        }
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function isAutoMigrationActive(state) {
        const status = (state && state.status) || '';
        return status.indexOf('auto_') === 0
            && status !== 'auto_complete'
            && status !== 'failed';
    }

    function runAutoMigration(messageText, resume) {
        if (!autoButton || autoRequestRunning) {
            return;
        }
        renderGeneration += 1;
        const generation = renderGeneration;
        activeMigrationId = resume && migrationIdOf(currentState) ? migrationIdOf(currentState) : 'pending';
        if (logsText) {
            logsText.textContent = '';
        }
        autoRequestRunning = true;
        autoButton.disabled = true;
        showPanel('transfer');
        renderProgressSteps({ status: 'auto_creating' });
        showMessage(messageText || 'Wasmer is creating your new app and copying this site into it.', 'info');
        startPolling();
        request('wasmer_migrate_auto', {
            graphql_url: autoGraphqlUrl ? autoGraphqlUrl.value : '',
            resume: resume ? '1' : '0'
        }).then(function (state) {
            if (!isResetting && generation === renderGeneration) {
                activeMigrationId = migrationIdOf(state);
                render(state);
            }
        }).catch(function (error) {
            if (!isResetting && generation === renderGeneration) {
                activeMigrationId = migrationIdOf(error.state) || activeMigrationId;
                render(error.state);
                showMessage(error.message, 'error');
            }
        }).finally(function () {
            if (!isResetting && generation === renderGeneration) {
                autoRequestRunning = false;
                autoButton.disabled = false;
            }
        });
    }

    if (advancedToggle && advancedPanel) {
        advancedToggle.addEventListener('click', function (event) {
            event.preventDefault();
            const isOpen = advancedPanel.classList.toggle('is-open');
            advancedToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            advancedPanel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        });
    }

    if (connectButton && codeField) {
        connectButton.addEventListener('click', function (event) {
            event.preventDefault();
            connectButton.disabled = true;
            showMessage('Validating import code...', 'info');
            request('wasmer_migrate_connect', {
                import_code: codeField.value
            }).then(function (state) {
                render(state);
            }).catch(function (error) {
                render(error.state);
                showMessage(error.message, 'error');
            }).finally(function () {
                connectButton.disabled = false;
            });
        });
    }

    if (useCodeButton) {
        useCodeButton.addEventListener('click', function (event) {
            event.preventDefault();
            showPanel('code');
            showMessage('', 'info');
        });
    }

    if (useAutoButton) {
        useAutoButton.addEventListener('click', function (event) {
            event.preventDefault();
            showPanel('auto');
            showMessage('', 'info');
        });
    }

    if (autoButton) {
        autoButton.addEventListener('click', function (event) {
            event.preventDefault();
            runAutoMigration('Wasmer is creating your new app and copying this site into it.', false);
        });
    }

    if (startButton) {
        startButton.addEventListener('click', function (event) {
            event.preventDefault();
            renderGeneration += 1;
            const generation = renderGeneration;
            activeMigrationId = migrationIdOf(currentState) || 'pending';
            startButton.disabled = true;
            showPanel('transfer');
            if (logsText) {
                logsText.textContent = '';
            }
            showMessage('Preparing and copying your site data...', 'info');
            startPolling();
            request('wasmer_migrate_start', {
                resume: '0'
            }).then(function (state) {
                if (!isResetting && generation === renderGeneration) {
                    activeMigrationId = migrationIdOf(state);
                    render(state);
                }
            }).catch(function (error) {
                if (!isResetting && generation === renderGeneration) {
                    activeMigrationId = migrationIdOf(error.state) || activeMigrationId;
                    render(error.state);
                    showMessage(error.message, 'error');
                }
            }).finally(function () {
                if (!isResetting && generation === renderGeneration) {
                    startButton.disabled = false;
                }
            });
        });
    }

    function clearState(event) {
        if (event) {
            event.preventDefault();
        }
        if (isResetting) {
            return;
        }
        isResetting = true;
        renderGeneration += 1;
        activeMigrationId = 'resetting';
        autoRequestRunning = false;
        stopPolling();
        abortActiveRequests();
        if (autoButton) {
            autoButton.disabled = true;
        }
        if (startButton) {
            startButton.disabled = true;
        }
        if (startOverButton) {
            startOverButton.disabled = true;
        }
        if (footerStartOverButton) {
            footerStartOverButton.disabled = true;
        }
        if (clearButton) {
            clearButton.disabled = true;
        }
        showPanel(hasLegacyWizard ? 'code' : 'auto');
        showMessage('Starting over...', 'info');
        if (logsText) {
            logsText.textContent = '';
        }
        request('wasmer_migrate_cancel', {}, { keepDuringReset: true }).then(function () {
            window.location.reload();
        }).catch(function (error) {
            isResetting = false;
            if (autoButton) {
                autoButton.disabled = false;
            }
            if (startButton) {
                startButton.disabled = false;
            }
            if (startOverButton) {
                startOverButton.disabled = false;
            }
            if (footerStartOverButton) {
                footerStartOverButton.disabled = false;
            }
            if (clearButton) {
                clearButton.disabled = false;
            }
            showMessage(error.message || 'Could not start over. Please refresh the page and try again.', 'error');
            startPolling();
        });
    }

    if (clearButton) {
        clearButton.addEventListener('click', clearState);
    }
    if (startOverButton) {
        startOverButton.addEventListener('click', clearState);
    }
    if (footerStartOverButton) {
        footerStartOverButton.addEventListener('click', clearState);
    }

    const state = initialState();
    activeMigrationId = migrationIdOf(state);
    render(state);
    startPolling();
    if (isAutoMigrationActive(state)) {
        runAutoMigration('Resuming your Wasmer migration from the last saved step.', true);
    }
})();
