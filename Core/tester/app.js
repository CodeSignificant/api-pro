let currentRoute = null;
let bearerToken = localStorage.getItem('apipro_bearer_token') || '';

// Init token UI on load
function toggleTokenPanel() {
    const widget = document.getElementById('tokenWidget');
    if (!widget) return;
    const isHidden = widget.style.display === 'none';
    if (isHidden) {
        widget.style.display = 'flex';
        const input = document.getElementById('bearerTokenInput');
        if (input) input.focus();
    } else {
        widget.style.display = 'none';
    }
}

function updateTokenIcon() {
    const btn = document.getElementById('tokenBtn');
    if (!btn) return;
    if (bearerToken) {
        btn.style.borderColor = '#10b981';
        btn.style.color = '#10b981';
        btn.style.background = 'rgba(16, 185, 129, 0.1)';
    } else {
        btn.style.borderColor = 'rgba(59, 130, 246, 0.3)';
        btn.style.color = 'var(--accent)';
        btn.style.background = 'rgba(59, 130, 246, 0.1)';
    }
}

function initToken() {
    const input = document.getElementById('bearerTokenInput');
    if (input && bearerToken) {
        input.value = bearerToken;
    }
    updateTokenIcon();
}

function saveToken() {
    const input = document.getElementById('bearerTokenInput');
    if (input) {
        bearerToken = input.value.trim();
        localStorage.setItem('apipro_bearer_token', bearerToken);
    }
    updateTokenIcon();
}

function clearToken() {
    bearerToken = '';
    const input = document.getElementById('bearerTokenInput');
    if (input) {
        input.value = '';
    }
    localStorage.removeItem('apipro_bearer_token');
    updateTokenIcon();
}

// Fetch Routes on Load
async function loadRoutes() {
    try {
        const res = await fetch('/apipro/tester/routes');
        const json = await res.json();

        if (json.success && json.data && json.data.tree) {
            renderSidebar(json.data.tree);
        } else {
            document.getElementById('routesList').innerHTML = `<div style="color:#ef4444; padding:20px;">Failed to load routes</div>`;
        }
    } catch (err) {
        document.getElementById('routesList').innerHTML = `<div style="color:#ef4444; padding:20px;">Network error loading routes: ${err.message}<br><br>Make sure you are accessing this via http://127.0.0.1:8000/test.html and not directly opening the file.</div>`;
    }
}

function renderSidebar(tree) {
    const list = document.getElementById('routesList');
    list.innerHTML = '';

    for (const [controller, routes] of Object.entries(tree)) {
        // Derive the display name and base path from the controller name
        const displayName = controller.replace('Controller', '');
        const basePath = commonPrefix(routes.map(r => r.path));

        const group = document.createElement('div');
        group.className = 'controller-group';

        // Accordion header
        const header = document.createElement('div');
        header.className = 'accordion-header';
        header.innerHTML = `
            <div class="accordion-label">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#60a5fa">
                    <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
                </svg>
                ${displayName}
                <span class="accordion-base">${basePath}</span>
            </div>
            <svg class="accordion-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="9 18 15 12 9 6"/>
            </svg>
        `;

        // Accordion body
        const body = document.createElement('div');
        body.className = 'accordion-body';

        routes.forEach(route => {
            const suffix = route.path.slice(basePath.length) || '/';
            const item = document.createElement('div');
            item.className = 'route-item';
            item.innerHTML = `
                <span class="method-badge method-${route.method}">${route.method}</span>
                <span class="route-path" title="${route.path}">${suffix}</span>
            `;
            item.onclick = () => selectRoute(route, item);
            body.appendChild(item);
        });

        // Toggle logic
        header.onclick = () => {
            const isOpen = body.classList.contains('open');
            header.classList.toggle('open', !isOpen);
            body.classList.toggle('open', !isOpen);
            header.querySelector('.accordion-chevron').classList.toggle('open', !isOpen);

            const anyOpen = Array.from(document.querySelectorAll('.accordion-body'))
                .some(b => b.classList.contains('open'));
            updateToggleAllIcon(anyOpen);
        };

        // Default open first group
        if (list.children.length === 0) {
            header.classList.add('open');
            body.classList.add('open');
            header.querySelector('.accordion-chevron').classList.add('open');
        }

        group.appendChild(header);
        group.appendChild(body);
        list.appendChild(group);
    }
}

function commonPrefix(paths) {
    if (!paths.length) return '';
    const segments = paths[0].split('/');
    let prefix = '';
    for (let i = 0; i < segments.length; i++) {
        const seg = segments[i];
        if (paths.every(p => p.split('/')[i] === seg)) {
            prefix += (i === 0 ? '' : '/') + seg;
        } else break;
    }
    return prefix;
}

function selectRoute(route, element) {
    // Update UI active state
    document.querySelectorAll('.route-item').forEach(el => el.classList.remove('active'));
    element.classList.add('active');

    currentRoute = route;

    // Switch workspace
    document.getElementById('emptyState').style.display = 'none';
    document.getElementById('activeState').style.display = 'flex';

    // Reset Response
    document.getElementById('responseBody').innerHTML = 'Awaiting request...';
    document.getElementById('responseStatus').style.display = 'none';
    document.getElementById('responseTime').innerText = '';
    
    const tabsEl = document.getElementById('responseTabs');
    if (tabsEl) tabsEl.style.display = 'none';
    const headersEl = document.getElementById('responseHeaders');
    if (headersEl) {
        headersEl.style.display = 'none';
        headersEl.innerText = '';
    }
    switchResponseTab('body');

    const copyBtn = document.getElementById('copyResponseBtn');
    if (copyBtn) copyBtn.style.display = 'none';

    closeMobileSidebar();

    // Update Header
    const headerMethod = document.getElementById('headerMethod');
    headerMethod.innerText = route.method;
    headerMethod.className = `method-badge method-${route.method}`;
    document.getElementById('headerPath').innerText = route.path;

    // Build Forms
    buildFormSection('querySection', 'queryFields', route.params || [], route.required_params || []);
    buildBodySection(route);
    buildFileSection('fileSection', 'fileFields', route.files || [], route.required_files || []);
    restoreEndpointInputs();
}

function buildBodySection(route) {
    const section = document.getElementById('bodySection');
    const container = document.getElementById('bodyFields');
    container.innerHTML = '';

    if (route.raw_body) {
        section.style.display = 'block';
        const group = document.createElement('div');
        group.className = 'form-group';
        group.innerHTML = `
            <div class="param-label-row">
                <label class="form-label" style="margin:0;">JSON Payload</label>
                <div style="display: flex; gap: 8px;">
                    <button type="button" onclick="formatRawJson()" style="background: transparent; border: 1px solid var(--border-color); color: var(--text-muted); padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; cursor: pointer; font-weight: 600;">Format JSON</button>
                    <span class="badge-required">Raw JSON</span>
                </div>
            </div>
            <textarea id="rawBodyInput" class="form-input" style="height: 150px; font-family: 'Fira Code', monospace; resize: vertical; margin-top: 5px;" placeholder="{\n  &quot;key&quot;: &quot;value&quot;\n}" oninput="saveEndpointInputs()">{\n  "key": "value"\n}</textarea>
        `;
        container.appendChild(group);
    } else if (route.body && route.body.length > 0) {
        section.style.display = 'block';
        route.body.forEach(param => {
            const isRequired = (route.required_body || []).includes(param);
            const group = document.createElement('div');
            group.className = 'form-group';
            group.innerHTML = `
                <div class="param-label-row">
                    <label class="form-label" style="margin:0;">${param}</label>
                    <span class="${isRequired ? 'badge-required' : 'badge-optional'}">${isRequired ? 'Required' : 'Optional'}</span>
                </div>
                <input type="text" class="form-input" name="param_${param}" data-key="${param}" placeholder="Enter ${param}..." ${isRequired ? 'required' : ''} oninput="saveEndpointInputs()" />
            `;
            container.appendChild(group);
        });
    } else {
        section.style.display = 'none';
    }
}

function buildFormSection(sectionId, containerId, params, required = []) {
    const section = document.getElementById(sectionId);
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    if (params.length === 0) {
        section.style.display = 'none';
        return;
    }

    section.style.display = 'block';
    params.forEach(param => {
        const isRequired = required.includes(param);
        const group = document.createElement('div');
        group.className = 'form-group';
        group.innerHTML = `
            <div class="param-label-row">
                <label class="form-label" style="margin:0;">${param}</label>
                <span class="${isRequired ? 'badge-required' : 'badge-optional'}">${isRequired ? 'Required' : 'Optional'}</span>
            </div>
            <input type="text" class="form-input" name="param_${param}" data-key="${param}" placeholder="Enter ${param}..." ${isRequired ? 'required' : ''} oninput="saveEndpointInputs()" />
        `;
        container.appendChild(group);
    });
}

function buildFileSection(sectionId, containerId, files, required = []) {
    const section = document.getElementById(sectionId);
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    if (files.length === 0) {
        section.style.display = 'none';
        return;
    }

    section.style.display = 'block';
    files.forEach(file => {
        const isRequired = required.includes(file);
        const group = document.createElement('div');
        group.className = 'form-group';
        group.innerHTML = `
            <div class="param-label-row">
                <label class="form-label" style="margin:0;">${file}</label>
                <span class="${isRequired ? 'badge-required' : 'badge-optional'}">${isRequired ? 'Required' : 'Optional'}</span>
            </div>
            <input type="file" class="form-input" name="file_${file}" data-key="${file}" style="padding: 7px;" />
        `;
        container.appendChild(group);
    });
}

async function sendRequest() {
    if (!currentRoute) return;

    const btn = document.getElementById('sendBtn');
    const loader = document.getElementById('loader');
    const span = btn.querySelector('span');

    span.style.display = 'none';
    loader.style.display = 'block';
    btn.disabled = true;

    const startTime = performance.now();

    try {
        // 1. Build Query String
        let url = currentRoute.path;
        const queryInputs = document.querySelectorAll('#queryFields input');
        const queryParams = new URLSearchParams();
        queryInputs.forEach(input => {
            if (input.value) queryParams.append(input.dataset.key, input.value);
        });

        const qs = queryParams.toString();
        if (qs) url += '?' + qs;

        // 2. Prepare Fetch Options
        const options = {
            method: currentRoute.method,
            headers: {}
        };

        // Inject Bearer token if set
        if (bearerToken) {
            options.headers['Authorization'] = `Bearer ${bearerToken}`;
        }

        const rawBodyInput = document.getElementById('rawBodyInput');
        const bodyInputs = document.querySelectorAll('#bodyFields input');
        const fileInputs = document.querySelectorAll('#fileFields input');

        if (fileInputs.length > 0) {
            // Send as FormData if files exist
            const formData = new FormData();
            bodyInputs.forEach(input => {
                if (input.value) formData.append(input.dataset.key, input.value);
            });
            fileInputs.forEach(input => {
                if (input.files.length > 0) formData.append(input.dataset.key, input.files[0]);
            });
            options.body = formData;
        } else if (rawBodyInput) {
            // Send raw JSON
            const value = rawBodyInput.value.trim();
            if (value) {
                try {
                    JSON.parse(value);
                } catch (e) {
                    alert("Warning: The payload is not valid JSON. Sending anyway...");
                }
            }
            options.headers['Content-Type'] = 'application/json';
            options.body = value;
        } else if (bodyInputs.length > 0) {
            // Send as JSON
            const jsonBody = {};
            bodyInputs.forEach(input => {
                if (input.value) jsonBody[input.dataset.key] = input.value;
            });
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(jsonBody);
        }

        if (currentRoute.method === 'GET' || currentRoute.method === 'HEAD') {
            delete options.body;
            delete options.headers['Content-Type'];
        }

        // 3. Execute
        const response = await fetch(url, options);

        // 4. Parse Response
        const text = await response.text();
        let jsonResponse;
        try {
            jsonResponse = JSON.parse(text);
        } catch (e) {
            jsonResponse = text; // Not JSON
        }

        const endTime = performance.now();
        renderResponse(response, Math.round(endTime - startTime), jsonResponse);

    } catch (err) {
        renderResponse({ status: 0, statusText: 'Network Error', headers: new Headers() }, 0, err.message);
    } finally {
        span.style.display = 'inline';
        loader.style.display = 'none';
        btn.disabled = false;
    }
}

function copyCurl() {
    if (!currentRoute) return;

    let url = window.location.origin + currentRoute.path;

    const queryInputs = document.querySelectorAll('#queryFields input');
    const queryParams = new URLSearchParams();
    queryInputs.forEach(input => {
        if (input.value) queryParams.append(input.dataset.key, input.value);
    });
    const qs = queryParams.toString();
    if (qs) url += '?' + qs;

    let curl = `curl -X ${currentRoute.method} "${url}"`;

    // Add Bearer token to cURL if set
    if (bearerToken) {
        curl += ` -H "Authorization: Bearer ${bearerToken}"`;
    }

    const rawBodyInput = document.getElementById('rawBodyInput');
    const bodyInputs = document.querySelectorAll('#bodyFields input');
    const fileInputs = document.querySelectorAll('#fileFields input');

    if (fileInputs.length > 0) {
        bodyInputs.forEach(input => {
            if (input.value) curl += ` -F "${input.dataset.key}=${input.value}"`;
        });
        fileInputs.forEach(input => {
            if (input.files.length > 0) curl += ` -F "${input.dataset.key}=@/path/to/${input.files[0].name}"`;
        });
    } else if (rawBodyInput && currentRoute.method !== 'GET' && currentRoute.method !== 'HEAD') {
        curl += ` -H "Content-Type: application/json" -d '${rawBodyInput.value.replace(/'/g, "'\\''")}'`;
    } else if (bodyInputs.length > 0 && currentRoute.method !== 'GET' && currentRoute.method !== 'HEAD') {
        const jsonBody = {};
        bodyInputs.forEach(input => {
            if (input.value) jsonBody[input.dataset.key] = input.value;
        });
        curl += ` -H "Content-Type: application/json" -d '${JSON.stringify(jsonBody)}'`;
    }

    navigator.clipboard.writeText(curl).then(() => {
        alert("cURL copied to clipboard!");
    }).catch(err => {
        alert("Failed to copy: " + err);
    });
}

function renderResponse(response, time, data) {
    lastResponseData = data;
    const copyBtn = document.getElementById('copyResponseBtn');
    if (copyBtn) {
        copyBtn.style.display = 'flex';
        copyBtn.innerHTML = `
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Copy
        `;
    }

    const status = response.status;
    const statusText = response.statusText;

    const statusEl = document.getElementById('responseStatus');
    statusEl.style.display = 'block';
    statusEl.innerText = `${status} ${statusText}`;
    statusEl.className = 'status-pill';

    if (status >= 200 && status < 300) statusEl.classList.add('status-200');
    else if (status >= 400 && status < 500) statusEl.classList.add('status-400');
    else statusEl.classList.add('status-500');

    document.getElementById('responseTime').innerText = `${time} ms`;

    if (typeof data === 'string') {
        document.getElementById('responseBody').innerHTML = escapeHtml(data);
    } else {
        document.getElementById('responseBody').innerHTML = syntaxHighlight(JSON.stringify(data, null, 4));
    }

    // Display headers
    let headersText = '';
    if (response && response.headers) {
        response.headers.forEach((value, name) => {
            headersText += `${name}: ${value}\n`;
        });
    }
    document.getElementById('responseHeaders').innerText = headersText || 'No headers returned';

    const tabsEl = document.getElementById('responseTabs');
    if (tabsEl) tabsEl.style.display = 'flex';
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function syntaxHighlight(json) {
    json = escapeHtml(json);
    return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
        let cls = 'number';
        if (/^"/.test(match)) {
            if (/:$/.test(match)) {
                cls = 'key';
            } else {
                cls = 'string';
            }
        } else if (/true|false/.test(match)) {
            cls = 'boolean';
        } else if (/null/.test(match)) {
            cls = 'null';
        }
        return '<span class="' + cls + '">' + match + '</span>';
    });
}

let lastResponseData = null;

function copyResponse() {
    if (!lastResponseData) return;
    const textToCopy = typeof lastResponseData === 'string'
        ? lastResponseData
        : JSON.stringify(lastResponseData, null, 4);

    navigator.clipboard.writeText(textToCopy).then(() => {
        const btn = document.getElementById('copyResponseBtn');
        if (btn) {
            btn.innerHTML = `
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Copied!
            `;
            setTimeout(() => {
                btn.innerHTML = `
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    Copy
                `;
            }, 2000);
        }
    }).catch(err => {
        alert("Failed to copy: " + err);
    });
}

function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.toggle('mobile-open');
    if (backdrop) backdrop.classList.toggle('active');
}

function closeMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (backdrop) backdrop.classList.remove('active');
}

function toggleAllGroups() {
    const anyOpen = Array.from(document.querySelectorAll('.accordion-body'))
        .some(body => body.classList.contains('open'));
    const shouldExpand = !anyOpen;

    const headers = document.querySelectorAll('.accordion-header');
    const bodies = document.querySelectorAll('.accordion-body');

    headers.forEach(header => {
        const chevron = header.querySelector('.accordion-chevron');
        if (shouldExpand) {
            header.classList.add('open');
            if (chevron) chevron.classList.add('open');
        } else {
            header.classList.remove('open');
            if (chevron) chevron.classList.remove('open');
        }
    });

    bodies.forEach(body => {
        if (shouldExpand) {
            body.classList.add('open');
        } else {
            body.classList.remove('open');
        }
    });

    updateToggleAllIcon(shouldExpand);
}

function updateToggleAllIcon(anyOpen) {
    const btn = document.getElementById('toggleAllBtn');
    const icon = document.getElementById('toggleAllIcon');
    if (!btn || !icon) return;

    if (anyOpen) {
        btn.title = "Collapse All Groups";
        icon.innerHTML = `
            <polyline points="17 11 12 6 7 11"></polyline>
            <polyline points="17 18 12 13 7 18"></polyline>
        `;
    } else {
        btn.title = "Expand All Groups";
        icon.innerHTML = `
            <polyline points="7 13 12 18 17 13"></polyline>
            <polyline points="7 6 12 11 17 6"></polyline>
        `;
    }
}

function formatRawJson() {
    const input = document.getElementById('rawBodyInput');
    if (!input) return;
    try {
        const parsed = JSON.parse(input.value);
        input.value = JSON.stringify(parsed, null, 2);
        saveEndpointInputs();
    } catch (e) {
        alert("Invalid JSON format. Please correct it before formatting.");
    }
}

function getStorageKey() {
    if (!currentRoute) return '';
    return `apipro_input_${currentRoute.method}_${currentRoute.path}`;
}

let activeResponseTab = 'body';
function switchResponseTab(tab) {
    activeResponseTab = tab;
    const bodyTab = document.getElementById('responseBody');
    const headersTab = document.getElementById('responseHeaders');
    const btnBody = document.getElementById('tabBtnBody');
    const btnHeaders = document.getElementById('tabBtnHeaders');
    
    if (!bodyTab || !headersTab || !btnBody || !btnHeaders) return;

    if (tab === 'body') {
        bodyTab.style.display = 'block';
        headersTab.style.display = 'none';
        btnBody.style.borderBottomColor = 'var(--accent-color)';
        btnBody.style.color = 'var(--text-color)';
        btnHeaders.style.borderBottomColor = 'transparent';
        btnHeaders.style.color = 'var(--text-muted)';
    } else {
        bodyTab.style.display = 'none';
        headersTab.style.display = 'block';
        btnHeaders.style.borderBottomColor = 'var(--accent-color)';
        btnHeaders.style.color = 'var(--text-color)';
        btnBody.style.borderBottomColor = 'transparent';
        btnBody.style.color = 'var(--text-muted)';
    }
}

function saveEndpointInputs() {
    const key = getStorageKey();
    if (!key) return;

    const data = {
        query: {},
        body: {},
        raw_body: ''
    };

    document.querySelectorAll('#queryFields input').forEach(input => {
        data.query[input.dataset.key] = input.value;
    });

    document.querySelectorAll('#bodyFields input').forEach(input => {
        data.body[input.dataset.key] = input.value;
    });

    const rawBodyInput = document.getElementById('rawBodyInput');
    if (rawBodyInput) {
        data.raw_body = rawBodyInput.value;
    }

    localStorage.setItem(key, JSON.stringify(data));
}

function restoreEndpointInputs() {
    const key = getStorageKey();
    if (!key) return;

    const stored = localStorage.getItem(key);
    if (!stored) return;

    try {
        const data = JSON.parse(stored);

        document.querySelectorAll('#queryFields input').forEach(input => {
            if (data.query && data.query[input.dataset.key] !== undefined) {
                input.value = data.query[input.dataset.key];
            }
        });

        document.querySelectorAll('#bodyFields input').forEach(input => {
            if (data.body && data.body[input.dataset.key] !== undefined) {
                input.value = data.body[input.dataset.key];
            }
        });

        const rawBodyInput = document.getElementById('rawBodyInput');
        if (rawBodyInput && data.raw_body !== undefined) {
            rawBodyInput.value = data.raw_body;
        }
    } catch (e) {
        // Ignore parsing errors
    }
}

// Init
initToken();
loadRoutes();
