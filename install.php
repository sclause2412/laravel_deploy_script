<?php

define('VERSION', '1.0.x');
define('RUNDEPLOY', false);

session_start();

$publicdir = false;
if (str_ends_with(dirname(__FILE__), 'public')) {
    chdir('..');
    $publicdir = true;
}

$is_windows = str_starts_with(PHP_OS_FAMILY, 'Windows');

$error = null;

$step = $_GET['step'] ?? 'start';
$home = $_SESSION['HOME'] ?? getenv('HOME') ?: getenv('HOMEDRIVE') . getenv('HOMEPATH');
if ($home == '')
    $home = posix_getpwuid(posix_getuid())['dir'];


$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$requestUri = $_SERVER['REQUEST_URI'];
$fullUrl = preg_replace('/install\.php.*/', '', $protocol . $host . $requestUri);

$error = $_SESSION['ERROR'] ?? '';
$_SESSION['ERROR'] = '';

function step($s, $error = null)
{
    global $fullUrl;
    if (!is_null($error)) {
        $_SESSION['ERROR'] = is_array($error) ? implode("\n", $error) : $error;
    }
    header('Location: ' . $fullUrl . 'install.php?step=' . $s);
    exit();
}

function steplink($s)
{
    global $fullUrl;
    return $fullUrl . 'install.php?step=' . $s;
}

function exec_or_step($cmd, $step, $message = null)
{
    if (is_null($message))
        $message = ['Error running command', $cmd];
    if (!is_array($message))
        $message = [$message];
    exec($cmd . ' 2>&1', $output, $retval);
    if ($retval != 0)
        step($step, array_merge($message, $output));
}

if (!function_exists('get_command_version')) {
    function get_command_version($command, $versionFlag = '--version')
    {
        exec("$command $versionFlag 2>&1", $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            foreach ($output as $o) {
                if (preg_match('/\d+(\.\d+)+(-?[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+)?/', $o, $matches)) {
                    return strtolower($matches[0]);
                }
            }
        }
        return false;
    }
}

if (!function_exists('get_php_executable')) {
    function get_php_executable()
    {
        $tryVersions = [
            'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'php' . PHP_MAJOR_VERSION,
            'php'
        ];

        foreach ($tryVersions as $phpExe) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("where $phpExe 2>&1", $output, $returnCode);
            } else {
                exec("command -v $phpExe 2>&1", $output, $returnCode);
            }
            if ($returnCode === 0 && !empty($output)) {
                foreach ($output as $phpPath) {
                    $phpVer = get_command_version(escapeshellarg($phpPath));
                    if ($phpVer == PHP_VERSION) {
                        return escapeshellarg($phpPath);
                    }
                }
            }
        }
        return 'php'; // Fallback
    }
}

switch ($_POST['action'] ?? '') {
    case 'setrepotype':
        $type = $_POST['type'] ?? '';
        $_SESSION['REPOTYPE'] = $type;
        switch ($type) {
            case 'public':
                step('giturl_public');
                break;
            case 'private':
                step('home');
                break;
        }
        step('start', 'Please provide repository type');
        break;
    case 'createkey':
        $home = $_POST['home'];
        if ($home != "" && is_dir($home)) {
            $_SESSION['HOME'] = $home;
            $ssh = $home . '/.ssh';
            if (!is_dir($ssh))
                mkdir($ssh);
            if (@file_put_contents($ssh . '/config', "Host github.com\nUser git\nIdentityFile ~/.ssh/deploy-git\n") === false) {
                step('start', ['Cannot write file', $ssh . '/config']);
            }
            if (!is_file($ssh . '/deploy-git.pub')) {
                exec_or_step('cd "' . $ssh . '" && ssh-keygen -f deploy-git -N ""', 'start', 'Error generating ssh key');
            }
            if (!is_file($ssh . '/known_hosts')) {
                exec_or_step('ssh-keyscan -H github.com >> ' . $ssh . '/known_hosts', 'start', 'Error adding GithHb to known hosts');
            } else {
                exec('ssh-keygen -F github.com', $output, $retval);
                if ($retval != 0) {
                    exec_or_step('ssh-keyscan -H github.com >> ' . $ssh . '/known_hosts', 'start', 'Error adding GitHub to known hosts');
                }
            }
            step('giturl_private');
        } else
            step('start', 'Ivalid home path!');
        break;
    case 'clone':
        if (isset($_POST['url'])) {
            $_SESSION['URL'] = $_POST['url'];
            $_SESSION['BRANCH'] = $_POST['branch'];
            exec_or_step('git init', $step);
            exec_or_step('git remote add origin ' . $_POST['url'] . ' || git remote set-url origin ' . $_POST['url'], $step);
            exec_or_step('git fetch', $step);
            exec_or_step('git reset --hard', $step);
            exec_or_step('git checkout ' . $_POST['branch'] . ' --force', $step);
            exec_or_step('git pull', $step);
            step('env');
        } else {
            step($step, 'Missing URL!');
        }
        break;
    case 'setenv':
        if (isset($_POST['env'])) {
            set_time_limit(600);
            $env = $_POST['env'];
            $env = preg_replace('/APP_KEY=.*/', 'APP_KEY=' . $_SESSION['APP_KEY'], $env);
            file_put_contents('./.env', $env);
            if ($is_windows) {
                exec_or_step('set HOME=' . $home . ' && composer install', 'env');
            } else {
                exec_or_step('HOME=' . $home . ' composer install', 'env');
            }
            exec_or_step('npm install', 'env');
            if (!str_starts_with($_SESSION['APP_KEY'], 'base64')) {
                exec_or_step(get_php_executable() . ' artisan key:generate', 'env');
            }
            step('webhook');
        } else {
            step('env', 'Missing text!');
        }
        break;
    case 'install':
        $phpExe = get_php_executable();
        set_time_limit(600);
        exec_or_step($phpExe . ' artisan storage:link', 'webhook');
        exec_or_step($phpExe . ' artisan migrate --force', 'webhook');
        exec_or_step($phpExe . ' artisan optimize', 'webhook');
        if ($is_windows) {
            exec_or_step('set PATH=%PATH%;node_modules/.bin && npm run build', 'webhook');
        } else {
            exec_or_step('PATH=$PATH:node_modules/.bin npm run build', 'webhook');
        }
        step('done');
        break;
    case 'deleteinstall':
        unlink(__FILE__);
        header('Location: ' . $fullUrl);
        exit();
}

switch ($step) {
    case 'start':
        $type = 'public';
        $output = '';
        exec('git remote get-url origin', $output, $retval);
        if ($retval == 0) {
            $output = is_array($output) ? $output[0] : $output;
            if (str_starts_with($output, 'git@github.com:'))
                $type = 'private';
            $_SESSION['URL'] = $output;
        }
        $output = '';
        exec('git branch --show-current', $output, $retval);
        if ($retval == 0) {
            $output = is_array($output) ? $output[0] : $output;
            $_SESSION['BRANCH'] = $output;
        }
        break;
    case 'giturl_private':
        if (is_file($home . '/.ssh/deploy-git.pub')) {
            $key = file_get_contents($home . '/.ssh/deploy-git.pub');
            $_SESSION['SSHKEY'] = $key;
        } else {
            $error = 'Key not found!';
            $key = 'Key not found!';
        }
        break;
    case 'env':
        if (!is_file('.env')) {
            $env = '';
            if (is_file('.env.production')) {
                $env = file_get_contents('.env.production');
            } elseif (is_file('.env.example')) {
                $env = file_get_contents('.env.example');
            }
            $env = preg_replace('/APP_DEBUG=.*/', 'APP_DEBUG=false', $env);
            $env = preg_replace('/APP_URL=.*/', 'APP_URL=' . $fullUrl, $env);
            $env = preg_replace('/APP_ENV=.*/', 'APP_ENV=production', $env);
            $env = preg_replace('/LOG_LEVEL=.*/', 'LOG_LEVEL=critical', $env);
        } else {
            $env = file_get_contents('.env');
        }
        preg_match('/APP_KEY=(.*)/', $env, $matches);
        $_SESSION['APP_KEY'] = $matches[1];

        $env = preg_replace('/APP_KEY=.*/', 'APP_KEY=*****', $env);
        break;

    case 'webhook':
    case 'webhook_only':
        $token = null;
        $deployfile = $publicdir ? 'public/deploy.php' : 'deploy.php';
        if (is_file($deployfile)) {
            include_once($deployfile);
            $token = defined('DEPLOYSECRET') ? constant('DEPLOYSECRET') : null;
        }
        file_put_contents($deployfile, deployfile($token));
        include_once($deployfile);
        $token = defined('DEPLOYSECRET') ? constant('DEPLOYSECRET') : null;
        break;
}


function generateToken()
{
    $chars = "ABCDEFGHJKLMNPQRSTUVWXYZ123456789";
    $token = "";
    for ($i = 0; $i < 25; $i++) {
        $token .= substr($chars, random_int(0, strlen($chars) - 1), 1);
    }
    return implode('-', str_split($token, 5));
}



$left = '<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 inline" width="1.5rem" height="1.5rem" fill="currentColor" viewBox="0 0 256 256"><path d="M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z"></path></svg>';
$right = '<svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 inline" width="1.5rem" height="1.5rem" fill="currentColor" viewBox="0 0 256 256"><path d="M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z"></path></svg>';


?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Installation</title>

    <!-- Bunny Fonts: Inter -->
    <link href="https://fonts.bunny.net/css?family=inter:400,600" rel="stylesheet" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 m-8 rounded shadow-md w-4/5">
        <h1 class="text-2xl font-semibold text-gray-800 mb-6">Laravel automatic installation and deploy</h1>
        <?php if ($error): ?>
            <h2 class="text-lg font-semibold text-red-600 mb-6"><?= nl2br($error) ?></h2>
        <?php endif; ?>
        <?php if ($step === 'start'): ?>
            <div class="text-gray-800 mb-4">This tool tries to install an automatic deployment solution for your GitHub
                repository with a Laravel app. Please follow the steps.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="setrepotype" />
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700">What kind of GitHub repository to
                        use?</label>
                    <select id="type" name="type" required
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200">
                        <option value="public" <?= $type != 'private' ? 'selected' : '' ?>>Public (https://github.com/...)
                        </option>
                        <option value="private" <?= $type == 'private' ? 'selected' : '' ?>>Private (git@github.com:...)
                        </option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <a href="<?= steplink('webhook_only') ?>"
                        class="w-1/4 bg-teal-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-teal-800 transition duration-200">
                        Just create deploy file
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Continue <?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'home'): ?>
            <div class="text-gray-800 mb-4">To use a private GitHub repository a SSH key must be generated. Please verify
                your home directory (this is where the .ssh folder is saved - not your webroot)</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="createkey" />
                <div>
                    <label for="home" class="block text-sm font-medium text-gray-700">Home directory</label>
                    <input type="text" id="home" name="home" required
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200"
                        value="<?= $home ?>" />
                </div>
                <div class="flex gap-2">
                    <a href="<?= steplink('start') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Create deploy key <?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'giturl_public'): ?>
            <div class="text-gray-800 mb-4">Please enter the public URL (https://github.com/....) below.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="clone" />
                <div>
                    <label for="url" class="block text-sm font-medium text-gray-700">URL to your GitHub Repository</label>
                    <input type="text" id="url" name="url" required
                        value="<?= str_replace('git@github.com:', 'https://github.com/', $_SESSION['URL'] ?? 'https://github.com/') ?>"
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200" />
                </div>
                <div>
                    <label for="branch" class="block text-sm font-medium text-gray-700">Branch which contains latest
                        released version</label>
                    <input type="text" id="branch" name="branch" required value="<?= $_SESSION['BRANCH'] ?? 'main' ?>"
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200" />
                </div>
                <div class="flex gap-2">
                    <a href="<?= steplink('start') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Checkout <?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'giturl_private'): ?>
            <div class="text-gray-800 mb-4">Please add the SSH Key to your GitHub repository.</div>
            <div class="font-mono text-green-800 mb-4 break-all"><?= nl2br($key) ?></div>
            <div class="text-gray-800 mb-4">Then enter the SSH URL (git@github.com:....) below.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="clone" />
                <div>
                    <label for="url" class="block text-sm font-medium text-gray-700">URL to your GitHub Repository</label>
                    <input type="text" id="url" name="url" required
                        value="<?= str_replace('https://github.com/', 'git@github.com:', $_SESSION['URL'] ?? 'git@github.com:') ?>"
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200" />
                </div>
                <div>
                    <label for="branch" class="block text-sm font-medium text-gray-700">Branch which contains latest
                        released version</label>
                    <input type="text" id="branch" name="branch" required value="<?= $_SESSION['BRANCH'] ?? 'main' ?>"
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200" />
                </div>
                <div class="flex gap-2">
                    <a href="<?= steplink('home') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Checkout <?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'env'): ?>
            <div class="text-gray-800 mb-4">Please check your .env file. If you do not need one then just keep the field
                empty.</div>
            <div class="text-gray-800 mb-4">You can leave your APP_KEY empty.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="setenv" />
                <div>
                    <label for="env" class="block text-sm font-medium text-gray-700">.env file</label>
                    <textarea type="text" id="env" name="env" rows="20"
                        class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200"><?= $env ?></textarea>
                </div>
                <div class="flex gap-2">
                    <a href="<?= steplink(str_starts_with($_SESSION['URL'], 'git@github.com:') ? 'giturl_private' : 'giturl_public') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Save .env file and install dependencies<?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'webhook'): ?>
            <div class="text-gray-800 mb-4">All preparations are done. Please add a webhook on your repository:</div>
            <ul class="list-disc list-inside mb-4 text-gray-800">
                <li>Go to your repo settings: Settings → Webhooks → Add webhook</li>
                <li>Payload URL: <?= $fullUrl ?>deploy.php</li>
                <li>Content type: application/json</li>
                <li>Secret: <span class="font-mono"><?= $token ?></span></li>
                <li>Events: Just the push event</li>
            </ul>
            <div class="text-gray-800 mb-4">In case you cannot use a webhook, you can directly call the payload URL and run
                it manually.</div>
            <div class="text-green-800 font-bold mb-4">Save the secret if you want to do a manual deploy. You cannot view it
                later anymore.</div>
            <div class="text-gray-800 mb-4">Press the install button now to finish installation.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="install" />
                <div class="flex gap-2">
                    <a href="<?= steplink('env') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                        Finish installation <?= $right ?>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'webhook_only'): ?>
            <div class="text-gray-800 mb-4">Deploy file generated. Please add a webhook on your repository:</div>
            <ul class="list-disc list-inside mb-4 text-gray-800">
                <li>Go to your repo settings: Settings → Webhooks → Add webhook</li>
                <li>Payload URL: <?= $fullUrl ?>deploy.php</li>
                <li>Content type: application/json</li>
                <li>Secret: <span class="font-mono"><?= $token ?></span></li>
                <li>Events: Just the push event</li>
            </ul>
            <div class="text-gray-800 mb-4">In case you cannot use a webhook, you can directly call the payload URL and run
                it manually.</div>
            <div class="text-green-800 font-bold mb-4">Save the secret if you want to do a manual deploy. You cannot view it
                later anymore.</div>
            <div class="text-gray-800 mb-4">Installation done. The install.php file should now be deleted to avoid security
                issues.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="deleteinstall" />
                <div class="flex gap-2">
                    <a href="<?= steplink('start') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-red-600 text-white font-semibold py-2 px-4 rounded hover:bg-red-800 transition duration-200">
                        Delete install.php
                    </button>
                </div>
            </form>
        <?php elseif ($step === 'done'): ?>
            <div class="text-gray-800 mb-4">Installation done. The install.php file should now be deleted to avoid security
                issues.</div>
            <form action="" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="deleteinstall" />
                <div class="flex gap-2">
                    <a href="<?= steplink('webhook') ?>"
                        class="w-1/4 bg-gray-600 text-white text-center font-semibold py-2 px-4 rounded hover:bg-gray-800 transition duration-200">
                        <?= $left ?> Back
                    </a>
                    <button type="submit"
                        class="w-3/4 bg-red-600 text-white font-semibold py-2 px-4 rounded hover:bg-red-800 transition duration-200">
                        Delete install.php
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <div class="absolute right-0 top-0 m-2 text-gray-400">v<?= VERSION ?></div>

</body>

</html>

<?php
function deployfile($token)
{
    $deployfile = <<<'EOD'
    <?php

    define('DEPLOYSECRET', '####TOKEN####');

    function check_github_deploy()
    {
        $headers = getallheaders();
        if (!isset($headers['X-Hub-Signature-256']))
            return false;

        $payload = file_get_contents('php://input');
        $signature = hash_hmac('sha256', $payload, DEPLOYSECRET);

        if ($headers['X-Hub-Signature-256'] !== 'sha256=' . $signature) {
            http_response_code(403);
            exit('Invalid signature');
        }

        return true;
    }

    if (!function_exists('get_command_version')) {
        function get_command_version($command, $versionFlag = '--version')
        {
            exec("$command $versionFlag 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                foreach ($output as $o) {
                    if (preg_match('/\d+(\.\d+)+(-?[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+)?/', $o, $matches)) {
                        return strtolower($matches[0]);
                    }
                }
            }
            return false;
        }
    }
    if(!function_exists('get_php_executable')) {
        function get_php_executable()
        {
            $tryVersions = [
                'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'php' . PHP_MAJOR_VERSION,
                'php'
            ];

            foreach ($tryVersions as $phpExe) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("where $phpExe 2>&1", $output, $returnCode);
                } else {
                    exec("command -v $phpExe 2>&1", $output, $returnCode);
                }
                if ($returnCode === 0 && !empty($output)) {
                    foreach ($output as $phpPath) {
                        $phpVer = get_command_version(escapeshellarg($phpPath));
                        if ($phpVer == PHP_VERSION) {
                            return escapeshellarg($phpPath);
                        }
                    }
                }
            }
            return 'php'; // Fallback
        }
    }

    function deploy()
    {
        if (str_ends_with(dirname(__FILE__), 'public')) {
            chdir('..');
        }
    
        $home = getenv('HOME') ?: getenv('HOMEDRIVE') . getenv('HOMEPATH');
        if ($home == '')
            $home = posix_getpwuid(posix_getuid())['dir'];

        set_time_limit(600);
        $phpExe = get_php_executable();
        $ret = [];
        $ret = array_merge($ret, run($phpExe . ' artisan down'));
        $ret = array_merge($ret, run($phpExe . ' artisan optimize:clear'));
        $ret = array_merge($ret, run('git fetch'));
        $ret = array_merge($ret, run('git pull'));
        if (PHP_OS_FAMILY === 'Windows') {
            $ret = array_merge($ret, run('set HOME=' . $home . ' && composer install'));
        } else {
            $ret = array_merge($ret, run('HOME=' . $home . ' composer install'));
        }
        $ret = array_merge($ret, run('npm install'));
        $ret = array_merge($ret, run($phpExe . ' artisan migrate --force'));
        if(PHP_OS_FAMILY === 'Windows') {
            $ret = array_merge($ret, run('set PATH=%PATH%;node_modules/.bin && npm run build'));
        } else {
            $ret = array_merge($ret, run('PATH=$PATH:node_modules/.bin npm run build'));
        }
        $ret = array_merge($ret, run($phpExe . ' artisan optimize'));
        $ret = array_merge($ret, run($phpExe . ' artisan up'));
        return $ret;
    }

    function run($cmd)
    {
        $ret = [];
        exec( $cmd . ' 2>&1', $output, $retval);
        if ($retval != 0) {
            $ret = array_merge([$cmd], $output);
        }
        return $ret;
    }

    if (!defined('RUNDEPLOY'))
        define('RUNDEPLOY', true);

    if (!RUNDEPLOY)
        return;

    define('GITHUB', check_github_deploy());

    if (GITHUB) {
        deploy();
        exit();
    }

    $error = '';
    $success = '';

    session_start();
    $error_count = $_SESSION['DEPLOY_ERROR_COUNT'] ?? 0;
    $error_time = $_SESSION['DEPLOY_ERROR_TIME'] ?? 0;
    $locked = false;

    if ($error_time + 300 < time()) {
        $error_count = 0;
    }

    if ($error_count >= 5) {
        $locked = true;
    }

    if (!$locked && ($_POST['action'] ?? '') == 'deploy') {

        $secret = $_POST['secret'] ?? '';
        if ($secret == '') {
            $error = 'Please provide the secret!';
        } elseif ($secret != DEPLOYSECRET) {
            $error_count++;
            $_SESSION['DEPLOY_ERROR_COUNT'] = $error_count;
            $_SESSION['DEPLOY_ERROR_TIME'] = time();
            $error = 'Wrong secret! Please try again.';
        } else {
            $ret = deploy();
            if (count($ret) > 0) {
                $error = 'An error occured!' . "\n" . implode("\n", $ret);
            } else {
                $success = 'Deployment successfully executed!';
            }
        }
    }

    if ($error_count >= 5) {
        $error = 'You entered the wrong key too many times. Please try again in ' . ($error_time + 300 - time()) . ' seconds.';
        $locked = true;
    }
    ?>
    <!DOCTYPE html>
    <html lang="de">

    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Deployment</title>

        <!-- Bunny Fonts: Inter -->
        <link href="https://fonts.bunny.net/css?family=inter:400,600" rel="stylesheet" />

        <!-- Tailwind CSS CDN -->
        <script src="https://cdn.tailwindcss.com"></script>

        <style>
            body {
                font-family: 'Inter', sans-serif;
            }
        </style>
    </head>

    <body class="bg-gray-100 flex items-center justify-center min-h-screen">

        <div class="bg-white p-8 m-8 rounded shadow-md w-4/5">
            <h1 class="text-2xl font-semibold text-gray-800 mb-6">Laravel deployment</h1>
            <?php if ($error): ?>
                <h2 class="text-lg font-semibold text-red-600 mb-6"><?= nl2br($error) ?></h2>
            <?php endif; ?>
            <?php if ($success): ?>
                <h2 class="text-lg font-semibold text-green-600 mb-6"><?= nl2br($success) ?></h2>
            <?php endif; ?>
            <?php if (!$locked): ?>
                <div class="text-gray-800 mb-4">Please enter your deployment secret</div>
                <form action="" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="deploy" />
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-700">Secret</label>
                        <input type="password" id="secret" name="secret" required
                            class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring focus:ring-blue-200" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                            class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded hover:bg-blue-800 transition duration-200">
                            Deploy
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <div class="absolute right-0 top-0 m-2 text-gray-400">v####VERSION####</div>

    </body>

    </html>
    EOD;
    $deployfile = str_replace('####VERSION####', VERSION, $deployfile);
    $deployfile = str_replace('####TOKEN####', $token ?? generateToken(), $deployfile);
    return $deployfile;
}