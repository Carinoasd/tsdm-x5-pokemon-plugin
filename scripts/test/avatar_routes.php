<?php
/** Run the real avatar router against files in an isolated temporary forum tree. */
if (($argv[1] ?? '') === '--worker') {
    $case = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    define('IN_DISCUZ', true);
    $_GET = ['endpoint' => 'avatar'] + $case['query'];
    $_G = ['uid' => 0, 'cache' => ['plugin' => ['pokemon' => ['is_open' => 0]]]];
    function loadcache($key) {}
    require $case['router'];
    exit;
}

$passed = 0;
$failed = 0;
$files = [];
$directories = [];
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tsdm-avatar-' . bin2hex(random_bytes(12));
$plugin = $root . '/source/plugin/pokemon';
$source = dirname(__DIR__, 2) . '/plugin';

function fixture_directory($path)
{
    if (is_dir($path)) return;
    if (!is_dir(dirname($path))) fixture_directory(dirname($path));
    if (!mkdir($path)) throw new RuntimeException('Cannot create avatar fixture directory');
    $GLOBALS['directories'][] = $path;
}

function fixture_file($path, $content)
{
    fixture_directory(dirname($path));
    if (file_put_contents($path, $content) !== strlen($content)) {
        throw new RuntimeException('Cannot write avatar fixture');
    }
    $GLOBALS['files'][] = $path;
}

function check_avatar($query, $expected, $label)
{
    $case = ['router' => $GLOBALS['plugin'] . '/pokemon.inc.php', 'query' => $query];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
        __FILE__, '--worker', base64_encode(json_encode($case, JSON_THROW_ON_ERROR))],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot run avatar fixture worker');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($process);
    if ($status === 0 && $error === '' && $output === $expected) {
        $GLOBALS['passed']++;
        echo 'PASS ', $label, PHP_EOL;
    } else {
        $GLOBALS['failed']++;
        echo 'FAIL ', $label, ' (exit=', $status, ', received=', strlen($output), ' bytes)', PHP_EOL;
        if ($error !== '') echo $error;
    }
}

try {
    if (!mkdir($root)) throw new RuntimeException('Cannot reserve isolated avatar fixture root');
    $directories[] = $root;
    // Copy source unchanged so __DIR__ resolves exactly as in a Discuz installation.
    foreach (['pokemon.inc.php', 'game_access.php', 'api/avatar.php', 'images/site/noavatar.svg'] as $file) {
        fixture_file($plugin . '/' . $file, file_get_contents($source . '/' . $file));
    }
    $legacy = file_get_contents($source . '/images/bg_dream0.jpg');
    $full = file_get_contents($source . '/images/bg_dream1.jpg');
    $placeholder = file_get_contents($source . '/images/site/noavatar.svg');
    if ($legacy === $full) throw new RuntimeException('Avatar priority fixtures must have different contents');

    // Official UCenter get_avatar(): sprintf('%09d', uid), then substr(uid, -2).
    // https://github.com/DiscuzTeam/DiscuzX/blob/master/upload/uc_server/avatar.php
    $legacy_paths = [
        1 => '000/00/00/01', 9 => '000/00/00/09', 10 => '000/00/00/10',
        99 => '000/00/00/99', 100 => '000/00/01/00', 101 => '000/00/01/01',
        109 => '000/00/01/09', 110 => '000/00/01/10',
        123456701 => '123/45/67/01', 123456709 => '123/45/67/09',
        123456710 => '123/45/67/10', 123456700 => '123/45/67/00',
    ];
    foreach ($legacy_paths as $uid => $path) {
        fixture_file($root . '/data/avatar/' . $path . '_avatar_middle.jpg', $legacy);
        check_avatar(['uid' => (string)$uid], $legacy, "UCenter padded filename for UID $uid");
    }

    fixture_file($root . '/data/avatar/000/00/01/000000101_avatar_middle.jpg', $full);
    check_avatar(['uid' => '101'], $full, 'Full nine-digit filename has priority over UCenter filename');
    fixture_file($root . '/data/avatar/000/00/02/000000209_avatar_big.jpg', $full);
    check_avatar(['uid' => '209', 'size' => 'big'], $full, 'Full nine-digit filename works without a UCenter file');

    fixture_file($root . '/data/avatar/000/00/01/09_avatar_big.jpg', $full);
    fixture_file($root . '/data/avatar/000/00/01/09_avatar_small.jpg', $full);
    foreach (['big', 'small', 'middle'] as $size) {
        check_avatar(['uid' => '109', 'size' => $size], $size === 'middle' ? $legacy : $full,
            "Known size $size selects the matching file");
    }
    foreach (['invalid', '', '../middle', ['middle'], ['nested' => ['big']]] as $size) {
        check_avatar(['uid' => '109', 'size' => $size], $full, 'Invalid size safely falls back to small: ' . json_encode($size));
    }
    foreach ([[], ['uid' => '0'], ['uid' => '-1'], ['uid' => '999999999'], ['uid' => '0', 'size' => []]] as $query) {
        check_avatar($query, $placeholder, 'Missing avatar returns built-in SVG: ' . json_encode($query));
    }
} finally {
    // Only remove paths created by this process; no existing forum tree is touched.
    foreach (array_reverse($files) as $file) unlink($file);
    foreach (array_reverse($directories) as $directory) rmdir($directory);
}

echo "\nAvatar route regressions: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
