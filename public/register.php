<?php
// Registration.
//
// Two different forms live in this one file. Which one you get is decided
// by the URL, not by a dropdown on the page:
//
//     register.php?type=learner
//     register.php?type=corporate_hr
//
// Read it top to bottom: work out the role, handle the submitted data,
// and only at the very end print any HTML.

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload.php';

$type = get_field('type');          // '' when ?type= is missing

// The role comes from the browser, so it is untrusted. We never use it
// directly, we only ask whether it is one of ours. Listing what is allowed
// beats trying to think of every bad value someone might send.
//
// Note that 'employee' is not here, even though it is a real role. Nobody
// makes themselves staff by editing a URL.
$allowedTypes = ['learner', 'corporate_hr'];

if (!in_array($type, $allowedTypes, true)) {
    $pageTitle = 'Register';
    include __DIR__ . '/../includes/header.php';
    ?>
    <div class="intro">
        <h1>Create your account</h1>
        <p>Choose the option that describes you.</p>
    </div>

    <div class="choices">
        <a class="choice" href="register.php?type=learner">
            <h2>I am a learner</h2>
            <p>Join a NxtWave program and track your application.</p>
            <span class="choice-action">Register as a learner</span>
        </a>

        <a class="choice" href="register.php?type=corporate_hr">
            <h2>I am hiring</h2>
            <p>Find and recruit talent trained at NxtWave.</p>
            <span class="choice-action">Register as corporate HR</span>
        </a>
    </div>
    <?php
    include __DIR__ . '/../includes/footer.php';
    exit;                           // stop here, the rest is for a real role
}

$isLearner = ($type === 'learner');

// The three documents this role has to upload.
// One array drives the file inputs, the checking loop and the saving loop.
if ($isLearner) {
    $requiredDocuments = [
        'profile_photo' => ['label' => 'Profile Photo',        'folder' => 'profile'],
        'resume'        => ['label' => 'Resume / CV',          'folder' => 'resume'],
        'govt_id'       => ['label' => 'Govt ID / Student ID', 'folder' => 'documents'],
    ];
} else {
    $requiredDocuments = [
        'profile_photo'        => ['label' => 'Work Profile Photo', 'folder' => 'profile'],
        'business_id'          => ['label' => 'Official Business ID / Visiting Card', 'folder' => 'documents'],
        'company_registration' => ['label' => 'Company Registration Document', 'folder' => 'documents'],
    ];
}

// Dropdown options. The same arrays draw the <select> and check the answer
// that comes back. Keep them in one place or the two drift apart.
$degrees      = ['B.Tech', 'B.Sc', 'B.Com', 'BCA', 'M.Tech', 'MCA', 'Other'];
$techStacks   = ['Full Stack Development', 'Data Analytics', 'QA Automation', 'Cyber Security'];
$companySizes = ['1-10', '11-50', '51-200', '201-1000', '1000+'];

$errors    = [];
$savedUser = null;

// GET shows the form. POST means they filled it in.
if (is_post()) {

    $fullName = post_field('full_name');
    $email    = strtolower(post_field('email'));
    $phone    = post_field('phone');
    $city     = post_field('city');

    // Passwords are not trimmed. A space can be a real character in one,
    // and silently changing what someone typed breaks their next login.
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Full name is required.';
    } elseif (mb_strlen($fullName) < 3) {
        $errors[] = 'Full name looks too short.';
    }

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!is_valid_email($email)) {
        $errors[] = 'That email address is not valid.';
    }

    if (!is_valid_phone($phone)) {
        $errors[] = 'Phone must be a 10 digit mobile number.';
    }

    if ($city === '') {
        $errors[] = 'City is required.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    // The four fields that depend on the role. Whichever role we are not
    // stays null, and MySQL stores NULL in those columns.
    $college = $degree = $techStack = $graduationYear = null;
    $companyName = $workEmail = $companySize = $designation = null;

    if ($isLearner) {
        $college        = post_field('college');
        $degree         = post_field('degree');
        $graduationYear = post_field('graduation_year');
        $techStack      = post_field('tech_stack');

        if ($college === '') {
            $errors[] = 'College name is required.';
        }

        // A <select> is not a restriction. Anyone can post degree=Professor.
        if (!in_array($degree, $degrees, true)) {
            $errors[] = 'Please choose a degree from the list.';
        }

        // ctype_digit means every character is 0-9. "2k25" fails it.
        // Check the format first: (int) "abc" quietly becomes 0.
        if (!ctype_digit($graduationYear)) {
            $errors[] = 'Graduation year must be a 4 digit year.';
        } else {
            $graduationYear = (int) $graduationYear;
            $thisYear = (int) date('Y');

            if ($graduationYear < 1990 || $graduationYear > $thisYear + 5) {
                $errors[] = 'Graduation year must be between 1990 and ' . ($thisYear + 5) . '.';
            }
        }

        if (!in_array($techStack, $techStacks, true)) {
            $errors[] = 'Please choose a target tech stack.';
        }
    } else {
        $companyName = post_field('company_name');
        $workEmail   = strtolower(post_field('work_email'));
        $companySize = post_field('company_size');
        $designation = post_field('designation');

        if ($companyName === '') {
            $errors[] = 'Company name is required.';
        }

        if (!is_valid_email($workEmail)) {
            $errors[] = 'Work email is not a valid email address.';
        }

        if (!in_array($companySize, $companySizes, true)) {
            $errors[] = 'Please choose a company size.';
        }

        if ($designation === '') {
            $errors[] = 'Designation is required.';
        }
    }

    // The AJAX check on the page is there to be polite. This one is what
    // actually protects the table.
    if ($email !== '' && is_valid_email($email)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
    }

    // Check all three files before moving any of them. Otherwise a form
    // that fails on the third document leaves two orphans on disk.
    foreach ($requiredDocuments as $field => $doc) {
        $problem = validate_upload($_FILES[$field] ?? null, $doc['label']);

        if ($problem !== '') {
            $errors[] = $problem;
        }
    }

    if (!$errors) {

        // The roles table owns the list of roles, so we look up the id.
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$type]);
        $roleId = (int) $stmt->fetchColumn();

        // Learners can start straight away. Corporate accounts wait for a
        // NxtWave employee to approve them, which is where the
        // account_pending_review login reply comes from.
        $status = $isLearner ? 'active' : 'pending';

        try {
            // Either the user and all three documents get saved, or none of
            // them do. A user row with half its documents is not fixable.
            $pdo->beginTransaction();

            $sql = 'INSERT INTO users
                        (role_id, full_name, email, phone, city, password_hash,
                         college, degree, graduation_year, tech_stack,
                         company_name, work_email, company_size, designation, status)
                    VALUES
                        (:role_id, :full_name, :email, :phone, :city, :password_hash,
                         :college, :degree, :graduation_year, :tech_stack,
                         :company_name, :work_email, :company_size, :designation, :status)';

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':role_id'         => $roleId,
                ':full_name'       => $fullName,
                ':email'           => $email,
                ':phone'           => $phone,
                ':city'            => $city,

                // The password itself is never stored. Only this is.
                ':password_hash'   => password_hash($password, PASSWORD_DEFAULT),

                ':college'         => $college,
                ':degree'          => $degree,
                ':graduation_year' => $graduationYear,
                ':tech_stack'      => $techStack,
                ':company_name'    => $companyName,
                ':work_email'      => $workEmail,
                ':company_size'    => $companySize,
                ':designation'     => $designation,
                ':status'          => $status,
            ]);

            // AUTO_INCREMENT chose the id inside MySQL, so we ask for it.
            // The documents need it to know whose they are.
            $userId = (int) $pdo->lastInsertId();

            // Prepared once, run three times.
            $docStmt = $pdo->prepare(
                'INSERT INTO user_documents
                    (user_id, document_type, file_path, original_name, file_size, mime_type)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            foreach ($requiredDocuments as $field => $doc) {
                $stored = save_upload($_FILES[$field], $doc['folder']);

                if ($stored === null) {
                    // throw jumps straight to the catch, which rolls back.
                    throw new RuntimeException('Could not save ' . $doc['label'] . '.');
                }

                $docStmt->execute([
                    $userId,
                    $field,                      // profile_photo, resume, ...
                    $stored['file_path'],
                    $stored['original_name'],
                    $stored['file_size'],
                    $stored['mime_type'],
                ]);
            }

            $pdo->commit();

            $savedUser = ['name' => $fullName, 'email' => $email, 'status' => $status];
        } catch (Exception $e) {
            $pdo->rollBack();

            // The real reason goes to the log. Showing it here would print
            // our SQL, our column names and sometimes the whole query.
            error_log('Registration failed: ' . $e->getMessage());

            $errors[] = 'Something went wrong while creating your account. Please try again.';
        }
    }
}

$pageTitle = $isLearner ? 'Learner Registration' : 'Corporate HR Registration';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($savedUser): ?>

    <div class="card">
        <div class="alert alert-success">
            <strong>Account created.</strong>
            Welcome, <?= e($savedUser['name']) ?>.
        </div>

        <?php if ($savedUser['status'] === 'pending'): ?>
            <p>
                Corporate accounts are reviewed by the NxtWave team before the
                first login. You will get an email once yours is approved.
            </p>
        <?php else: ?>
            <p>You can log in now with <strong><?= e($savedUser['email']) ?></strong>.</p>
        <?php endif; ?>

        <p><a href="login.php">Go to login</a></p>
    </div>

<?php else: ?>

    <div class="card">
        <h1><?= e($pageTitle) ?></h1>
        <p class="subtitle">
            All fields are required. Documents must be JPG, PNG or PDF and under 2 MB.
        </p>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <strong>Please fix the following:</strong>
                <ul>
                    <?php foreach ($errors as $message): ?>
                        <li><?= e($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- enctype is what makes the browser actually send the files.
             Leave it out and $_FILES arrives empty, with no error. -->
        <form method="post"
              action="register.php?type=<?= e($type) ?>"
              enctype="multipart/form-data"
              novalidate>

            <h2>Your details</h2>

            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" value="<?= old('full_name') ?>">

            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= old('email') ?>">
            <!-- register.js fills this in when you leave the field -->
            <small class="hint">You will use this to log in.</small>
            <div class="field-message" id="email-message"></div>

            <label for="phone">Mobile number</label>
            <input type="tel" id="phone" name="phone" value="<?= old('phone') ?>">
            <small class="hint">10 digits, for example 9876543210</small>

            <label for="city">City</label>
            <input type="text" id="city" name="city" value="<?= old('city') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password">
            <small class="hint">At least 8 characters.</small>

            <label for="confirm_password">Confirm password</label>
            <input type="password" id="confirm_password" name="confirm_password">

            <?php if ($isLearner): ?>

                <h2>Education</h2>

                <label for="college">College</label>
                <input type="text" id="college" name="college" value="<?= old('college') ?>">

                <label for="degree">Degree</label>
                <select id="degree" name="degree">
                    <option value="">-- select --</option>
                    <?php foreach ($degrees as $option): ?>
                        <option value="<?= e($option) ?>"
                            <?= (($_POST['degree'] ?? '') === $option) ? 'selected' : '' ?>>
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="graduation_year">Graduation year</label>
                <input type="number" id="graduation_year" name="graduation_year"
                       value="<?= old('graduation_year') ?>" min="1990" max="<?= date('Y') + 5 ?>">

                <label for="tech_stack">Target tech stack</label>
                <select id="tech_stack" name="tech_stack">
                    <option value="">-- select --</option>
                    <?php foreach ($techStacks as $option): ?>
                        <option value="<?= e($option) ?>"
                            <?= (($_POST['tech_stack'] ?? '') === $option) ? 'selected' : '' ?>>
                            <?= e($option) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php else: ?>

                <h2>Company</h2>

                <label for="company_name">Company name</label>
                <input type="text" id="company_name" name="company_name" value="<?= old('company_name') ?>">

                <label for="work_email">Work email</label>
                <input type="email" id="work_email" name="work_email" value="<?= old('work_email') ?>">
                <small class="hint">Your official company address.</small>

                <label for="company_size">Company size</label>
                <select id="company_size" name="company_size">
                    <option value="">-- select --</option>
                    <?php foreach ($companySizes as $option): ?>
                        <option value="<?= e($option) ?>"
                            <?= (($_POST['company_size'] ?? '') === $option) ? 'selected' : '' ?>>
                            <?= e($option) ?> employees
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="designation">Designation</label>
                <input type="text" id="designation" name="designation" value="<?= old('designation') ?>">

            <?php endif; ?>

            <h2>Documents</h2>

            <?php foreach ($requiredDocuments as $field => $doc): ?>
                <label for="<?= e($field) ?>"><?= e($doc['label']) ?></label>
                <input type="file" id="<?= e($field) ?>" name="<?= e($field) ?>"
                       accept=".jpg,.jpeg,.png,.pdf">
            <?php endforeach; ?>

            <button type="submit">Create account</button>
        </form>
    </div>

<?php endif; ?>

<script src="assets/js/register.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
