# Lesson 02 — PHP basics, and the role that comes from the URL

**Goal:** the language essentials, learned by solving one real problem —
making `register.php?type=learner` and `register.php?type=corporate_hr`
behave differently.

We deliberately do not teach "PHP syntax" as a separate chapter. Everything
below appears because the feature needs it.

---

## Part A — the language, in one sitting

### Variables

```php
$name = "Asha";
$age  = 21;
$fee  = 4999.50;
$isActive = true;
$college  = null;
```

Every variable starts with `$`. You never declare a type; PHP works it out.

> **Compared to what you know**
> Same idea as JavaScript's `let` or Python's plain assignment, except the
> `$` is mandatory. `name = "Asha"` is a syntax error in PHP.

Check a type when you are unsure:

```php
var_dump($age);        // int(21)
var_dump("21");        // string(2) "21"
```

`var_dump()` is your debugger. So is `print_r()` for arrays. Both will save
you in lesson 06.

### Strings

```php
$first = "Asha";
$last  = 'Rao';

echo "Hello $first";            // Hello Asha     <- double quotes interpolate
echo 'Hello $first';            // Hello $first   <- single quotes do not
echo "Hello " . $first;         // Hello Asha     <- . joins strings
echo "Hi {$first} Rao";         // braces when the variable touches other text
```

Useful string functions, all of which we use in this project:

```php
trim("  asha  ")                  // "asha"          strips spaces
strtolower("Asha@X.COM")          // "asha@x.com"
strlen("secret")                  // 6               bytes
mb_strlen("नमस्ते")                 // characters, not bytes - use for names
str_replace('_', ' ', 'govt_id')  // "govt id"
substr("9876543210", 0, 5)        // "98765"
```

> Python calls these `.strip()`, `.lower()`, `len()`, `.replace()`.
> JavaScript calls them `.trim()`, `.toLowerCase()`, `.length`,
> `.replace()`. PHP just puts the string inside the function instead of
> before the dot.

### Arrays — PHP's list *and* dictionary

```php
// a list
$stacks = ['Full Stack Development', 'Data Analytics', 'QA Automation'];
echo $stacks[0];              // Full Stack Development
echo count($stacks);          // 3

// a dictionary (called an "associative array")
$user = [
    'name'  => 'Asha',
    'email' => 'asha@example.com',
];
echo $user['name'];           // Asha
```

There is only one type. `['a','b']` is really `[0 => 'a', 1 => 'b']`.

> Python has `list` and `dict` as two types. JavaScript has `Array` and
> `Object`. PHP squashes both into `array`, and `=>` does the job of
> Python's `:` in a dict literal.

Adding and checking:

```php
$errors = [];                       // empty
$errors[] = 'Email is required.';   // push to the end   (JS: .push)
isset($user['email'])               // true  - key exists and is not null
in_array('B.Tech', $degrees, true)  // true  - value exists in the list
array_keys($user)                   // ['name', 'email']
```

`in_array` takes a third argument, `true`, meaning "compare types too". Get
into the habit. Without it, PHP compares loosely and `in_array(0, ['a','b'])`
used to return `true` in older versions — the kind of bug you lose an
afternoon to.

### Comparison: `==` vs `===`

```php
"5"  ==  5     // true   - PHP converts, then compares
"5"  === 5     // false  - different types, no conversion
null == false  // true
null === false // false
```

Use `===` unless you have a reason not to. This is exactly JavaScript's
`==` / `===` story, and the advice is the same.

### if / elseif / else

```php
if ($type === 'learner') {
    $title = 'Learner Registration';
} elseif ($type === 'corporate_hr') {
    $title = 'Corporate HR Registration';
} else {
    $title = 'Pick a registration link';
}
```

Note `elseif`, one word. (`else if` also works, but pick one and be
consistent — this project uses `elseif`.)

The short form, used all over our templates:

```php
$status = $isLearner ? 'active' : 'pending';     // condition ? yes : no
```

### The `??` operator — the most-used line in this project

```php
$type = $_GET['type'] ?? '';
```

Read it as: "the value of `$_GET['type']`, or `''` if that key is missing or
null". Without it you would write:

```php
if (isset($_GET['type'])) { $type = $_GET['type']; } else { $type = ''; }
```

> JavaScript has the same `??`. Python's closest is `d.get('type', '')`.

Do not confuse it with `empty()`:

```php
empty("")     // true
empty("0")    // true   <- surprise. "0" is falsy in PHP
empty(null)   // true
empty("abc")  // false
```

Because of the `"0"` case, this project checks `$value === ''` instead of
`empty($value)` when validating text. A city literally named `0` is silly,
but a quantity of `0` is not, and the habit matters.

### Loops

```php
foreach ($stacks as $stack) {
    echo $stack;
}

foreach ($user as $key => $value) {
    echo "$key = $value";
}

for ($i = 0; $i < 5; $i++) { ... }
while ($row = $stmt->fetch()) { ... }
```

> `foreach ($a as $v)` is Python's `for v in a`.
> `foreach ($a as $k => $v)` is Python's `for k, v in d.items()`.

### Functions

```php
function is_valid_phone(string $phone): bool
{
    return preg_match('/^[6-9][0-9]{9}$/', $phone) === 1;
}
```

The `string` and `: bool` are type hints. They are optional, and they are
worth writing: PHP will throw an error the moment someone passes the wrong
thing, instead of producing nonsense three files later.

Defaults and nullable types:

```php
function json_response(array $data, int $httpStatus = 200): void { ... }
function e(?string $value): string { ... }        // ? means "or null"
```

**Important scope rule:** a function cannot see outside variables.

```php
$pdo = new PDO(...);

function findUser() {
    $stmt = $pdo->prepare(...);   // ERROR: $pdo is undefined in here
}
```

Python and JavaScript would happily reach outward. PHP does not. That is why
every function in this project that needs the database takes `$pdo` as a
parameter, or the query is written directly in the page.

### Alternative syntax for templates

Inside HTML, braces get lost. PHP offers a second spelling:

```php
<?php if ($errors): ?>
    <div class="alert alert-error">Something went wrong</div>
<?php endif; ?>

<?php foreach ($documents as $doc): ?>
    <li><?= e($doc['original_name']) ?></li>
<?php endforeach; ?>
```

`if:` … `endif;`, `foreach:` … `endforeach;`. Same behaviour, far easier to
read when there are 40 lines of HTML in between. Use braces in logic, use
this form in templates.

---

## Part B — the actual feature

### The requirement

> Detect the target role strictly via the URL parameter, with no role
> selector on the page.

Two links go out into the world:

```
/public/register.php?type=learner
/public/register.php?type=corporate_hr
```

Everything after `?` is the **query string**. Each `key=value` is a **query
parameter**, joined by `&`. PHP parses them for you into `$_GET`.

### Superglobals

`$_GET` is a *superglobal*: an array PHP fills in before your code runs, and
which is visible inside functions without any `global` keyword.

| | filled from |
|---|---|
| `$_GET` | the query string |
| `$_POST` | a submitted form body |
| `$_FILES` | uploaded files (lesson 06) |
| `$_SESSION` | this visitor's server-side memory (lesson 10) |
| `$_SERVER` | request info: method, URL, headers |

Prove it to yourself. In `public/register.php`, temporarily:

```php
<?php
print_r($_GET);
```

Visit `register.php?type=learner&colour=blue` and you get:

```
Array ( [type] => learner [colour] => blue )
```

Now visit it with no `?` at all. `Array ( )`. Which is exactly why we need
`??` — reading `$_GET['type']` directly would emit a warning.

### Step 1: read it

```php
$type = $_GET['type'] ?? '';
```

We wrapped this in a helper so every page reads parameters the same way —
see `get_field()` in [includes/functions.php](../includes/functions.php):

```php
function get_field(string $key): string
{
    return trim($_GET[$key] ?? '');
}
```

### Step 2: do not trust it

This is the security lesson of the day, and it is bigger than it looks.

The URL is typed by the user. They can put anything in it:

```
register.php?type=admin
register.php?type=employee
register.php?type=<script>alert(1)</script>
```

So we never *use* `$type`. We only ever ask **"is it one of ours?"**

```php
$allowedTypes = ['learner', 'corporate_hr'];

if (!in_array($type, $allowedTypes, true)) {
    // show the "pick a link" page and stop
}
```

This pattern has a name: an **allow list**. You list what is permitted and
reject everything else. The opposite — a block list, where you try to think
of every bad value — loses, always, because you cannot imagine every input a
stranger can send.

Notice `employee` is *not* in the list, even though it is a real role in our
`roles` table. Nobody gets to make themselves a NxtWave employee by editing
a URL. Staff accounts are made by `php bin/create-employee.php`, from a
terminal, by someone who already has server access.

### Step 3: branch

```php
$isLearner = ($type === 'learner');
```

One boolean, computed once at the top. The rest of the file asks
`if ($isLearner)` instead of repeating string comparisons — cheaper to read,
and impossible to typo in only one of the seven places.

### Step 4: stop cleanly when the type is wrong

Look at the top of [public/register.php](../public/register.php): when the
type is invalid we print a small "pick a link" page and then call `exit;`.

`exit` (and its identical twin `die`) stops the script right there. Nothing
below runs. Without it, PHP would keep going and try to render a form for a
role that does not exist.

---

## Try to break it

Run through these in the browser and explain each result:

| URL | What happens | Why |
|---|---|---|
| `register.php?type=learner` | learner form | in the allow list |
| `register.php?type=corporate_hr` | HR form | in the allow list |
| `register.php` | "pick a link" page | `??` gave us `''` |
| `register.php?type=admin` | "pick a link" page | not in the allow list |
| `register.php?type=LEARNER` | "pick a link" page | `===` is case sensitive |
| `register.php?type=learner&type=x` | "pick a link" page | PHP keeps the **last** duplicate |

That last row is worth ten minutes of discussion. Different servers resolve
duplicate parameters differently, and attackers know it. Our allow list makes
it a non-event either way.

---

## Check yourself

- [ ] `register.php?type=learner` shows one heading, `?type=corporate_hr` shows another.
- [ ] Any other value shows the fallback page and nothing else runs.
- [ ] You can say, in one sentence, why an allow list beats a block list.
- [ ] You can explain the difference between `==` and `===`, and between
      `$_GET['x'] ?? ''` and `$_GET['x']`.

→ [Lesson 03 — The form and server side validation](03-the-form-and-validation.md)
