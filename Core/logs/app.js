let intervalId = null;
let isConnected = false;
let rawLogs = '';
let searchQuery = '';
let matchCount = 0;
let currentMatchIndex = -1;

const consoleOutput = document.getElementById('consoleOutput');
const connectBtn = document.getElementById('connectBtn');
const passwordInput = document.getElementById('passwordInput');
const statusDot = document.getElementById('statusDot');
const searchInput = document.getElementById('searchInput');
const showTimeToggle = document.getElementById('showTimeToggle');

// Listen for Enter key on password input
passwordInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        toggleConnection();
    }
});

// Listen for input on search query
searchInput.addEventListener('input', (e) => {
    searchQuery = e.target.value.toLowerCase();
    currentMatchIndex = -1;
    renderLogs();
});

// Listen for Enter / Shift+Enter on search input to jump
searchInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (e.shiftKey) {
            prevMatch();
        } else {
            nextMatch();
        }
    }
});

let showTime = false;

window.toggleTime = function() {
    const toggleBtn = document.getElementById('showTimeToggle');
    showTime = !showTime;
    if (showTime) {
        toggleBtn.classList.add('active');
        consoleOutput.classList.remove('hide-timestamps');
    } else {
        toggleBtn.classList.remove('active');
        consoleOutput.classList.add('hide-timestamps');
    }
};

window.prevMatch = function() {
    if (matchCount === 0) return;
    currentMatchIndex = (currentMatchIndex - 1 + matchCount) % matchCount;
    updateSearchUI();
    highlightActiveMatch();
};

window.nextMatch = function() {
    if (matchCount === 0) return;
    currentMatchIndex = (currentMatchIndex + 1) % matchCount;
    updateSearchUI();
    highlightActiveMatch();
};

function updateSearchUI() {
    const searchCountEl = document.getElementById('searchCount');
    if (searchQuery && matchCount > 0) {
        searchCountEl.innerText = `${currentMatchIndex + 1}/${matchCount}`;
    } else {
        searchCountEl.innerText = '0/0';
    }
}

function highlightActiveMatch() {
    const activeMatches = consoleOutput.querySelectorAll('.search-match.active-match');
    activeMatches.forEach(el => el.classList.remove('active-match'));

    if (currentMatchIndex !== -1) {
        const el = consoleOutput.querySelector(`.search-match[data-match-index="${currentMatchIndex}"]`);
        if (el) {
            el.classList.add('active-match');
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function toggleConnection() {
    if (isConnected) {
        disconnect();
    } else {
        connect();
    }
}

function connect() {
    const password = passwordInput.value;
    if (!password) {
        alert("Please enter the Log Viewer password.");
        return;
    }

    isConnected = true;
    connectBtn.innerText = "Disconnect";
    connectBtn.className = "btn btn-disconnect";
    passwordInput.disabled = true;

    statusDot.classList.add('active');

    consoleOutput.innerHTML = '<div class="log-line">Connecting to stream...</div>';

    // Initial fetch
    fetchLogs(password);

    // Poll every 2 seconds
    intervalId = setInterval(() => fetchLogs(password), 2000);
}

function disconnect() {
    isConnected = false;
    connectBtn.innerText = "Connect";
    connectBtn.className = "btn";
    passwordInput.disabled = false;

    statusDot.classList.remove('active');

    if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
    }
}

async function fetchLogs(password) {
    try {
        const response = await fetch('/apipro/logs/read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ password: password })
        });

        const json = await response.json();

        if (json.success) {
            const logs = json.data?.logs ?? '';
            if (rawLogs !== logs) {
                rawLogs = logs;
                const isAtBottom = consoleOutput.scrollHeight - consoleOutput.clientHeight <= consoleOutput.scrollTop + 60;

                renderLogs();

                if (isAtBottom) {
                    consoleOutput.scrollTop = consoleOutput.scrollHeight;
                }
            }
        } else {
            disconnect();
            consoleOutput.innerHTML = `<div class="log-line log-level-error">Error: ${json.message}</div>`;
        }

    } catch (err) {
        disconnect();
        consoleOutput.innerHTML = `<div class="log-line log-level-error">Connection Error: Failed to reach log service.</div>`;
    }
}

async function clearLogs() {
    const password = passwordInput.value;
    if (!password) {
        alert("Please enter the password first.");
        return;
    }

    if (!confirm("Are you sure you want to clear the prolog.log file on the server?")) {
        return;
    }

    try {
        const response = await fetch('/apipro/logs/clear', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ password: password })
        });

        const json = await response.json();

        if (json.success) {
            rawLogs = '';
            renderLogs();
        } else {
            alert("Error: " + json.message);
        }
    } catch (err) {
        alert("Failed to clear logs: " + err.message);
    }
}

function renderLogs() {
    if (!rawLogs) {
        consoleOutput.innerHTML = '<div class="log-line" style="color:var(--text-muted);">[Log stream is empty]</div>';
        return;
    }

    const lines = rawLogs.split('\n');
    let html = '';
    let tempMatchCount = 0;

    lines.forEach(line => {
        if (!line.trim()) return;

        // Remove absolute file path and line number references (e.g. in /path/to/file.php on line 12)
        let cleanedLine = line.replace(/\s+in\s+\/[^\s]+\.php\s+on\s+line\s+\d+/gi, '');
        // Strip stack trace paths (e.g. #0 /path/to/file.php(47): method() -> #0: method())
        cleanedLine = cleanedLine.replace(/#\d+\s+\/[^\s()]+\.php\(\d+\):/gi, (match) => {
            return match.split(' ')[0] + ':';
        });

        let formattedLine = escapeHtml(cleanedLine);

        // Highlight matching search term in the line
        if (searchQuery) {
            const regex = new RegExp(`(${escapeRegExp(searchQuery)})`, 'gi');
            formattedLine = formattedLine.replace(regex, (match) => {
                const index = tempMatchCount++;
                return `<span class="search-match" data-match-index="${index}">${match}</span>`;
            });
        }

        // Colorize levels (e.g. [ERROR], [WARNING], [INFO])
        formattedLine = formattedLine
            .replace(/(\[ERROR\])/gi, '<span class="log-level-error">$1</span>')
            .replace(/(\[WARNING\])/gi, '<span class="log-level-warning">$1</span>')
            .replace(/(\[INFO\])/gi, '<span class="log-level-info">$1</span>');

        // Colorize timestamps (e.g. [26-Jun-2026 17:26:14 UTC] and/or [2026-06-26 17:26:14])
        formattedLine = formattedLine.replace(/^(\[[0-9a-zA-Z:\-\s,]+\])\s*(\[[0-9:\-\s]+\])?/g, (match, p1, p2) => {
            let res = `<span class="log-timestamp">${p1}</span>`;
            if (p2) {
                res += ` <span class="log-timestamp">${p2}</span>`;
            }
            return res + ' ';
        });

        html += `<div class="log-line">${formattedLine}</div>`;
    });

    consoleOutput.innerHTML = html;
    
    matchCount = tempMatchCount;

    // Update count display and active highlight
    if (searchQuery && matchCount > 0) {
        if (currentMatchIndex === -1 || currentMatchIndex >= matchCount) {
            currentMatchIndex = 0;
        }
        updateSearchUI();
        highlightActiveMatch();
    } else {
        currentMatchIndex = -1;
        updateSearchUI();
    }
}

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
