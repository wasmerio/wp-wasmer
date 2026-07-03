(function () {
    const page = document.querySelector('.wasmer-migrate-page');
    if (!page || !window.wasmerMigrate) {
        return;
    }

    const panels = Array.from(page.querySelectorAll('[data-panel]'));
    const steps = Array.from(page.querySelectorAll('[data-step]'));
    const message = document.getElementById('wasmer-migrate-message');
    const codeField = document.getElementById('wasmer-migrate-import-code');
    const autoButton = document.getElementById('wasmer-migrate-auto-start');
    const useCodeButton = document.getElementById('wasmer-migrate-use-code');
    const useAutoButton = document.getElementById('wasmer-migrate-use-auto');
    const autoGraphqlUrl = document.getElementById('wasmer-migrate-auto-graphql-url');
    const autoToken = document.getElementById('wasmer-migrate-auto-token');
    const autoOwner = document.getElementById('wasmer-migrate-auto-owner');
    const autoRegion = document.getElementById('wasmer-migrate-auto-region');
    const autoPerishAt = document.getElementById('wasmer-migrate-auto-perish-at');
    const autoAppName = document.getElementById('wasmer-migrate-auto-app-name');
    const autoAppText = document.getElementById('wasmer-migrate-auto-app');
    const autoBuildText = document.getElementById('wasmer-migrate-auto-build');
    const autoWpText = document.getElementById('wasmer-migrate-auto-wp');
    const connectButton = document.getElementById('wasmer-migrate-connect');
    const startButton = document.getElementById('wasmer-migrate-start');
    const clearButton = document.getElementById('wasmer-migrate-clear');
    const startOverButton = document.getElementById('wasmer-migrate-start-over');
    const statusText = document.getElementById('wasmer-migrate-status');
    const destinationText = document.getElementById('wasmer-migrate-destination');
    const fileCountText = document.getElementById('wasmer-migrate-file-count');
    const databaseText = document.getElementById('wasmer-migrate-database');
    const filesText = document.getElementById('wasmer-migrate-files');
    const bytesText = document.getElementById('wasmer-migrate-bytes');
    const progressBar = document.getElementById('wasmer-migrate-progress-bar');
    const logsText = document.getElementById('wasmer-migrate-logs');
    const doneMessage = document.getElementById('wasmer-migrate-done-message');
    let pollTimer = null;

    function initialState() {
        try {
            return JSON.parse(page.getAttribute('data-initial-state') || '{}');
        } catch (error) {
            return {};
        }
    }

    function request(action, data) {
        const form = new FormData();
        form.append('action', action);
        form.append('nonce', window.wasmerMigrate.nonce);
        Object.keys(data || {}).forEach(function (key) {
            form.append(key, data[key]);
        });

        return fetch(window.wasmerMigrate.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
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
        });
    }

    function showPanel(name) {
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== name;
        });
        steps.forEach(function (step) {
            const order = ['code', 'review', 'transfer', 'done'];
            const activeName = name === 'auto' ? 'code' : name;
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
        if (status === 'failed') {
            return state.auto_app ? 'auto' : 'transfer';
        }
        if (status === 'transfer_complete' || status === 'auto_complete') {
            return 'done';
        }
        if (status.indexOf('auto_') === 0) {
            if (status === 'auto_transferring' || status === 'auto_importing') {
                return 'transfer';
            }
            return 'auto';
        }
        if (status === 'transferring' || status === 'exported') {
            return 'transfer';
        }
        if (state.destination) {
            return 'review';
        }
        return 'auto';
    }

    function render(state) {
        state = state || {};
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

        statusText.textContent = state.status || 'idle';
        destinationText.textContent = (state.destination && (state.destination.target || state.destination.rest)) || '-';
        fileCountText.textContent = String(fileCount);
        databaseText.textContent = databaseSent + ' bytes';
        filesText.textContent = filesSent + ' / ' + fileCount;
        bytesText.textContent = bytesSent + ' bytes';
        progressBar.style.width = percent + '%';
        logsText.textContent = (state.logs || []).join('\n');
        autoAppText.textContent = autoApp.url || autoApp.name || '-';
        autoBuildText.textContent = auto.build_status || auto.status || 'idle';
        autoWpText.textContent = [
            targetWp.version || '',
            liveConfig.phpVersion ? 'PHP ' + liveConfig.phpVersion : ''
        ].filter(Boolean).join(' / ') || '-';
        if (doneMessage) {
            doneMessage.textContent = state.status === 'auto_complete' && autoApp.url
                ? 'The migrated site is ready at ' + autoApp.url + '.'
                : 'The target app has received and verified the transfer. Finish the import in the Wasmer app.';
        }

        showPanel(migrationStep(state));
        if (state.error) {
            showMessage(state.error, 'error');
        } else if (state.status === 'auto_complete') {
            showMessage('Automatic Wasmer import completed.', 'success');
        } else if (state.status === 'transfer_complete') {
            showMessage('Transfer completed. Finish the import in the target Wasmer app.', 'success');
        } else if ((state.status || '').indexOf('auto_') === 0) {
            showMessage('Automatic Wasmer app migration is running: ' + state.status.replace('auto_', '').replace('_', ' ') + '.', 'info');
        } else {
            showMessage('', 'info');
        }
    }

    function poll() {
        request('wasmer_migrate_status').then(render).catch(function () {});
    }

    function startPolling() {
        if (!pollTimer) {
            pollTimer = window.setInterval(poll, 3000);
        }
    }

    connectButton.addEventListener('click', function () {
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

    if (useCodeButton) {
        useCodeButton.addEventListener('click', function () {
            showPanel('code');
            showMessage('', 'info');
        });
    }

    if (useAutoButton) {
        useAutoButton.addEventListener('click', function () {
            showPanel('auto');
            showMessage('', 'info');
        });
    }

    if (autoButton) {
        autoButton.addEventListener('click', function () {
            autoButton.disabled = true;
            showPanel('auto');
            showMessage('Creating a Wasmer app and preparing this site for transfer...', 'info');
            startPolling();
            request('wasmer_migrate_auto', {
                graphql_url: autoGraphqlUrl ? autoGraphqlUrl.value : '',
                token: autoToken ? autoToken.value : '',
                owner: autoOwner ? autoOwner.value : '',
                region: autoRegion ? autoRegion.value : '',
                perish_at: autoPerishAt ? autoPerishAt.value : '',
                app_name: autoAppName ? autoAppName.value : ''
            }).then(function (state) {
                render(state);
            }).catch(function (error) {
                render(error.state);
                showMessage(error.message, 'error');
            }).finally(function () {
                autoButton.disabled = false;
            });
        });
    }

    startButton.addEventListener('click', function () {
        startButton.disabled = true;
        showPanel('transfer');
        showMessage('Preparing export and transferring content...', 'info');
        startPolling();
        request('wasmer_migrate_start').then(function (state) {
            render(state);
        }).catch(function (error) {
            render(error.state);
            showMessage(error.message, 'error');
        }).finally(function () {
            startButton.disabled = false;
        });
    });

    function clearState() {
        request('wasmer_migrate_cancel').then(function (state) {
            codeField.value = '';
            render(state);
        });
    }

    clearButton.addEventListener('click', clearState);
    startOverButton.addEventListener('click', clearState);

    render(initialState());
    startPolling();
})();
