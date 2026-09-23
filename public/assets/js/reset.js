// The three step reset, all driven by fetch().

const stepEmail = document.getElementById('step-email');
const stepOtp = document.getElementById('step-otp');
const stepPassword = document.getElementById('step-password');
const messageBox = document.getElementById('reset-message');

// Kept so the user types their address once. This is convenience only -
// after step 2 the server knows who they are from the session.
let userEmail = '';

// Step 1: ask for a code.
stepEmail.addEventListener('submit', async function (event) {
    event.preventDefault();

    userEmail = document.getElementById('email').value.trim();

    const data = await callApi('../api/request-otp.php', { email: userEmail });

    if (data.status === 'sent') {
        document.getElementById('otp-target').textContent = userEmail;
        showStep(stepOtp);
        showMessage('info', data.message);
    } else {
        showMessage('error', data.message);
    }
});

// Step 2: check the code.
stepOtp.addEventListener('submit', async function (event) {
    event.preventDefault();

    const otp = document.getElementById('otp').value.trim();

    const data = await callApi('../api/verify-otp.php', { email: userEmail, otp: otp });

    if (data.status === 'verified') {
        showStep(stepPassword);
        showMessage('success', data.message);
    } else if (data.status === 'expired') {
        // An expired code is worth its own status: there is no point
        // letting them retype a dead one, so we go back to step 1.
        showStep(stepEmail);
        showMessage('error', data.message);
    } else {
        showMessage('error', data.message);
    }
});

// Step 3: set the new password.
stepPassword.addEventListener('submit', async function (event) {
    event.preventDefault();

    const data = await callApi('../api/reset-password.php', {
        password: document.getElementById('password').value,
        confirm_password: document.getElementById('confirm_password').value
    });

    if (data.status === 'success') {
        stepPassword.hidden = true;
        showMessage('success', data.message);
        setTimeout(function () {
            window.location.href = 'login.php';
        }, 1500);
    } else {
        showMessage('error', data.message);
    }
});

// All three steps do the same thing: post some JSON, read some JSON back.
async function callApi(url, payload) {
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        return await response.json();
    } catch (error) {
        return { status: 'error', message: 'Network problem. Please try again.' };
    }
}

function showStep(stepToShow) {
    [stepEmail, stepOtp, stepPassword].forEach(function (step) {
        step.hidden = (step !== stepToShow);
    });
}

function showMessage(kind, text) {
    messageBox.innerHTML = '<div class="alert alert-' + kind + '"></div>';
    messageBox.firstChild.textContent = text;
}
