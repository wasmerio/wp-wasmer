(function () {
    const createButton = document.getElementById('wasmer-import-create-session');
    if (!createButton || !window.wasmerImport) {
        return;
    }

    let sessionId = null;
    let pollTimer = null;
    const panels = Array.from(document.querySelectorAll('.wasmer-import-page [data-panel]'));
    const steps = Array.from(document.querySelectorAll('.wasmer-import-page [data-step]'));
    const message = document.getElementById('wasmer-import-message');
    const sessionBox = document.getElementById('wasmer-import-session');
    const codeField = document.getElementById('wasmer-import-code');
    const statusText = document.getElementById('wasmer-import-status');
    const databaseText = document.getElementById('wasmer-import-database');
    const filesText = document.getElementById('wasmer-import-files');
    const expiresText = document.getElementById('wasmer-import-expires');
    const logsText = document.getElementById('wasmer-import-logs');
    const dependencyReport = document.getElementById('wasmer-import-dependency-report');
    const progressBar = document.getElementById('wasmer-import-progress-bar');
    const startImportButton = document.getElementById('wasmer-import-start-import');
    let dependencyBlocked = false;

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.wasmerImport.nonce
        }, options.headers || {});
        return fetch(window.wasmerImport.restUrl + path, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    throw new Error(body.message || 'Request failed');
                }
                return body;
            });
        });
    }

    function showPanel(name) {
        const order = ['install', 'code', 'transfer', 'finish', 'complete'];
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== name;
        });
        steps.forEach(function (step) {
            const stepName = step.getAttribute('data-step');
            step.classList.toggle('is-active', stepName === name || (name === 'complete' && stepName === 'finish'));
            step.classList.toggle('is-complete', order.indexOf(stepName) < order.indexOf(name));
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

    function sessionPanel(session) {
        const status = session.status || 'created';
        if (status === 'complete') {
            return 'complete';
        }
        if (status === 'verified') {
            return 'finish';
        }
        if (['connected', 'manifest_received', 'transferring', 'transfer_complete', 'failed'].indexOf(status) !== -1) {
            return 'transfer';
        }
        return 'code';
    }

    function render(session) {
        sessionBox.hidden = false;
        statusText.textContent = session.status || 'created';
        databaseText.textContent = ((session.database && session.database.received) || 0) + ' bytes';
        const fileTotal = session.manifest && session.manifest.file_count ? session.manifest.file_count : Object.keys(session.files || {}).length;
        const fileReceived = Object.keys(session.files || {}).length;
        filesText.textContent = fileReceived + (fileTotal ? ' / ' + fileTotal : '');
        expiresText.textContent = session.expires ? new Date(session.expires * 1000).toLocaleString() : '';
        logsText.textContent = (session.logs || []).join('\n');
        const databaseComplete = session.database && session.database.complete ? 1 : 0;
        const percent = fileTotal ? Math.min(100, Math.round(((fileReceived + databaseComplete) / (fileTotal + 1)) * 100)) : (databaseComplete ? 100 : 0);
        progressBar.style.width = percent + '%';
        showPanel(sessionPanel(session));
        updateDependencyReport();

        if (session.status === 'failed') {
            showMessage(session.error || 'Import failed.', 'error');
        } else if (session.status === 'verified') {
            showMessage('Transfer completed. Review warnings, then import into this site.', 'success');
        } else if (session.status === 'complete') {
            showMessage('Import completed.', 'success');
        } else {
            showMessage('', 'info');
        }
    }

    function renderDependencyReport(report) {
        if (!dependencyReport) {
            return;
        }

        const messages = [].concat(report.errors || [], report.warnings || []);
        dependencyBlocked = !!report.blocked;
        if (startImportButton) {
            startImportButton.disabled = dependencyBlocked;
        }
        if (!messages.length) {
            dependencyReport.hidden = true;
            dependencyReport.textContent = '';
            return;
        }

        dependencyReport.hidden = false;
        dependencyReport.className = 'notice inline ' + (report.blocked ? 'notice-error' : 'notice-warning');
        dependencyReport.innerHTML = '';
        messages.forEach(function (reportMessage) {
            const paragraph = document.createElement('p');
            paragraph.textContent = reportMessage;
            dependencyReport.appendChild(paragraph);
        });
    }

    function updateDependencyReport() {
        if (!sessionId || !window.wasmerImport.ajaxUrl || !window.wasmerImport.dependencyNonce) {
            return;
        }

        const params = new URLSearchParams({
            action: 'wasmer_import_dependency_report',
            nonce: window.wasmerImport.dependencyNonce,
            session: sessionId
        });

        fetch(window.wasmerImport.ajaxUrl + '?' + params.toString(), {
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (body) {
            if (body && body.success && body.data) {
                renderDependencyReport(body.data);
            }
        }).catch(function () {});
    }

    function poll() {
        if (!sessionId) {
            return;
        }
        api('/session/' + sessionId, { method: 'GET' }).then(render).catch(function () {});
    }

    function startPolling() {
        if (!pollTimer) {
            pollTimer = window.setInterval(poll, 3000);
        }
    }

    document.getElementById('wasmer-import-next-install').addEventListener('click', function () {
        showPanel('code');
    });

    createButton.addEventListener('click', function () {
        createButton.disabled = true;
        api('/session', {
            method: 'POST',
            body: JSON.stringify({ ttl: 28800 })
        }).then(function (body) {
            sessionId = body.session.id;
            codeField.value = body.code;
            render(body.session);
            startPolling();
        }).catch(function (error) {
            showMessage(error.message, 'error');
        }).finally(function () {
            createButton.disabled = false;
        });
    });

    document.getElementById('wasmer-import-copy-code').addEventListener('click', function () {
        codeField.select();
        navigator.clipboard.writeText(codeField.value);
    });

    document.getElementById('wasmer-import-cancel-session').addEventListener('click', function () {
        if (sessionId) {
            api('/session/' + sessionId + '/cancel', { method: 'POST', body: '{}' }).then(render);
        }
    });

    startImportButton.addEventListener('click', function () {
        if (!sessionId) {
            return;
        }
        if (dependencyBlocked) {
            showMessage('Resolve the blocking dependency report before importing.', 'error');
            return;
        }
        startImportButton.disabled = true;
        showMessage('Importing into this site...', 'info');
        api('/session/' + sessionId + '/start-import', { method: 'POST', body: '{}' }).then(render).catch(function (error) {
            showMessage(error.message, 'error');
        }).finally(function () {
            startImportButton.disabled = dependencyBlocked;
        });
    });

    document.getElementById('wasmer-import-new').addEventListener('click', function () {
        window.location.reload();
    });

    showPanel('install');
})();
