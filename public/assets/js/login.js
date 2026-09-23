// Login without leaving the page.

const form = document.getElementById('login-form');
const button = document.getElementById('login-button');
const messageBox = document.getElementById('login-message');

form.addEventListener('submit', async function (event) {
    // Without this the browser reloads the page and cancels our fetch
    // halfway through.
    event.preventDefault();

    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;

    // Otherwise an impatient user sends five login requests.
    button.disabled = true;
    button.textContent = 'Checking...';
    messageBox.innerHTML = '';

    try {
        const response = await fetch('../api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // The body has to be text, so the object gets stringified.
            // PHP reads it back with json_decode().
            body: JSON.stringify({ email: email, password: password })
        });

        const data = await response.json();

        // The three replies the endpoint promised us. We switch on status,
        // never on the message, so the wording stays free to change.
        if (data.status === 'success') {
            showMessage('success', 'Logged in. Taking you to your dashboard...');
            window.location.href = data.redirect;
            return;
        }

        if (data.status === 'account_pending_review') {
            showMessage('info', data.message);
        } else {
            showMessage('error', data.message);
        }
    } catch (error) {
        showMessage('error', 'Something went wrong. Please try again.');
    }

    button.disabled = false;
    button.textContent = 'Log in';
});

function showMessage(kind, text) {
    messageBox.innerHTML = '<div class="alert alert-' + kind + '"></div>';
    // textContent, not innerHTML, so a message can never become markup.
    messageBox.firstChild.textContent = text;
}
