// Live email check.
//
// When the user leaves the email box we ask the server whether that address
// is free, without reloading the page. That is all AJAX means.

const emailInput = document.getElementById('email');
const emailMessage = document.getElementById('email-message');

// 'blur' fires when the field loses focus, so they have finished typing.
// No brackets after checkEmail - we are handing over the function, not
// calling it now.
emailInput.addEventListener('blur', checkEmail);

async function checkEmail() {
    const email = emailInput.value.trim();

    if (email === '') {
        show('', '');
        return;
    }

    show('Checking...', 'busy');

    try {
        // encodeURIComponent escapes & + and spaces so the address survives
        // being inside a URL.
        const url = '../api/check-email.php?email=' + encodeURIComponent(email);

        // fetch resolves once the headers arrive, .json() waits for the
        // rest of the body and parses it. Hence two awaits.
        const response = await fetch(url);
        const data = await response.json();

        show(data.message, data.available ? 'ok' : 'bad');
    } catch (error) {
        // No internet, server down, or PHP printed an error page instead
        // of JSON. Without this the user stares at "Checking..." forever.
        show('Could not check right now', 'busy');
    }
}

function show(text, cssClass) {
    emailMessage.textContent = text;
    emailMessage.className = 'field-message ' + cssClass;
}
