<?php
/**
 * VideoFuse Pro v3 — API with queue & background processing
 * PHP 7.4+, FFmpeg required
 */

// Constants must be defined BEFORE the CLI worker check
define('BASE_DIR',    __DIR__);
define('UPLOAD_DIR',  __DIR__ . '/tmp_uploads/');
define('OUTPUT_DIR',  __DIR__ . '/tmp_outputs/');
define('JOBS_DIR',    __DIR__ . '/tmp_jobs/');
define('FFMPEG_BIN',  'ffmpeg');
define('FFPROBE_BIN', 'ffprobe');

foreach ([UPLOAD_DIR, OUTPUT_DIR, JOBS_DIR] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}

// Allow long-running for worker
if (php_sapi_name() === 'cli') {
    set_time_limit(0);
    // CLI worker mode
    if (($argv[1] ?? '') === 'worker') {
        workerRun($argv[2] ?? '');
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'check':       handleCheck(); break;
    case 'submit':      handleSubmit(); break;      // Submit batch jobs
    case 'status':      handleStatus(); break;       // Poll job statuses
    case 'job_status':  handleJobStatus(); break;    // Single job status
    case 'files':       handleFiles(); break;        // File manager
    case 'download':    handleDownload(); break;     // Download file
    case 'delete':      handleDelete(); break;       // Delete file(s)
    case 'download_all':handleDownloadAll(); break;  // Download all as ZIP
    case 'cleanup':     handleCleanup(); break;
    case 'clear_queue': handleClearQueue(); break;
    case 'server_stats':handleServerStats(); break;
    default:            jsonOut(['error' => 'Unknown action'], 400);
}

// ═══════════════════════════════════════════════════════════════
// CHECK
// ═══════════════════════════════════════════════════════════════
function handleCheck() {
    $ffmpeg = shell_exec(FFMPEG_BIN . ' -version 2>&1');
    $hasFFmpeg = strpos($ffmpeg, 'ffmpeg version') !== false;
    jsonOut([
        'ok' => $hasFFmpeg,
        'ffmpeg_version' => $hasFFmpeg ? explode("\n", $ffmpeg)[0] : null,
        'max_upload' => ini_get('upload_max_filesize'),
        'post_max' => ini_get('post_max_size'),
        'php_version' => PHP_VERSION,
    ]);
}

// ═══════════════════════════════════════════════════════════════
// SUBMIT — upload files + create background jobs
// ═══════════════════════════════════════════════════════════════
function handleSubmit() {
    try {
        $mode       = $_POST['mode'] ?? 'solo';
        $format     = $_POST['format'] ?? 'original';
        $uniqualize = !empty($_POST['uniqualize']);
        $quality    = $_POST['quality'] ?? 'high';
        $endcardDur = (float)($_POST['endcard_duration'] ?? 5);
        $endcardAnim= $_POST['endcard_animation'] ?? 'zoom';

        // Collect creative files (file1, file1_1, file1_2, ... or files[])
        $creatives = [];
        
        // Support multiple files via files[] array
        if (!empty($_FILES['files'])) {
            $count = is_array($_FILES['files']['name']) ? count($_FILES['files']['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                if (is_array($_FILES['files']['name'])) {
                    $creatives[] = [
                        'name'     => $_FILES['files']['name'][$i],
                        'tmp_name' => $_FILES['files']['tmp_name'][$i],
                        'size'     => $_FILES['files']['size'][$i],
                        'error'    => $_FILES['files']['error'][$i],
                    ];
                } else {
                    $creatives[] = $_FILES['files'];
                }
            }
        }
        
        // Also support file1, file1_1, file1_2...
        if (!empty($_FILES['file1'])) {
            $creatives[] = $_FILES['file1'];
        }
        for ($i = 1; $i <= 50; $i++) {
            $key = "file1_{$i}";
            if (!empty($_FILES[$key])) {
                $creatives[] = $_FILES[$key];
            }
        }

        if (empty($creatives)) {
            throw new Exception('Нет файлов для обработки');
        }

        // Endcard file (for merge mode)
        $endcardPath = null;
        $endcardType = 'video';
        if ($mode === 'merge') {
            if (empty($_FILES['file2'])) {
                throw new Exception('Для склейки нужна концовка');
            }
            $uid2 = uniqid('ec_', true);
            $endcardSaved = UPLOAD_DIR . $uid2 . '_' . sanitizeName($_FILES['file2']['name']);
            move_uploaded_file($_FILES['file2']['tmp_name'], $endcardSaved);
            $endcardPath = $endcardSaved;
            $endcardMime = mime_content_type($endcardSaved);
            $endcardType = strpos($endcardMime, 'image/') === 0 ? 'image' : 'video';
        }

        // Create a job for each creative
        $jobs = [];
        foreach ($creatives as $creative) {
            if ($creative['error'] !== UPLOAD_ERR_OK) continue;

            $jobId = uniqid('job_', true);
            $uid = uniqid('vf_', true);

            // Save creative
            $creativePath = UPLOAD_DIR . $uid . '_' . sanitizeName($creative['name']);
            move_uploaded_file($creative['tmp_name'], $creativePath);

            // Job data
            $job = [
                'id'              => $jobId,
                'status'          => 'queued',  // queued → processing → done → error
                'created_at'      => time(),
                'original_name'   => $creative['name'],
                'creative_path'   => $creativePath,
                'endcard_path'    => $endcardPath,
                'endcard_type'    => $endcardType,
                'mode'            => $mode,
                'format'          => $format,
                'uniqualize'      => $uniqualize,
                'quality'         => $quality,
                'endcard_duration'=> $endcardDur,
                'endcard_animation'=> $endcardAnim,
                'output_file'     => null,
                'output_size'     => null,
                'output_duration' => null,
                'error'           => null,
                'started_at'      => null,
                'finished_at'     => null,
            ];

            file_put_contents(JOBS_DIR . $jobId . '.json', json_encode($job, JSON_UNESCAPED_UNICODE));
            $jobs[] = ['id' => $jobId, 'name' => $creative['name']];

            // Launch background worker
            launchWorker($jobId);
        }

        jsonOut([
            'ok' => true,
            'jobs' => $jobs,
            'count' => count($jobs),
        ]);

    } catch (Exception $e) {
        jsonOut(['error' => $e->getMessage()], 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// WORKER — runs in background via CLI
// ═══════════════════════════════════════════════════════════════
function workerRun($jobId) {
    $jobFile = JOBS_DIR . $jobId . '.json';
    if (!file_exists($jobFile)) return;

    $job = json_decode(file_get_contents($jobFile), true);
    if (!$job || $job['status'] !== 'queued') return;

    // Mark processing
    $job['status'] = 'processing';
    $job['started_at'] = time();
    file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE));

    $tempFiles = [];

    try {
        $resolutions = [
            '9:16' => ['w' => 1080, 'h' => 1920],
            '1:1'  => ['w' => 1080, 'h' => 1080],
            '4:5'  => ['w' => 1080, 'h' => 1350],
            '16:9' => ['w' => 1920, 'h' => 1080],
        ];
        $qualityMap = [
            'high'   => ['crf' => '18', 'preset' => 'fast',     'ab' => '192k'],
            'medium' => ['crf' => '23', 'preset' => 'fast',     'ab' => '128k'],
            'low'    => ['crf' => '28', 'preset' => 'veryfast', 'ab' => '96k'],
        ];

        $q = $qualityMap[$job['quality']] ?? $qualityMap['high'];
        $file1Path = $job['creative_path'];

        if (!file_exists($file1Path)) throw new Exception('Creative file missing');

        // Detect if creative is an image
        $creativeMime = mime_content_type($file1Path) ?: '';
        $creativeIsImage = strpos($creativeMime, 'image/') === 0;

        // ── IMAGE MODE — uniqualize and output as image ──
        if ($creativeIsImage) {
            $ext = strtolower(pathinfo($job['original_name'], PATHINFO_EXTENSION)) ?: 'jpg';
            $baseName = pathinfo($job['original_name'], PATHINFO_FILENAME);
            $outputFile = OUTPUT_DIR . $baseName . '_unique_' . substr(md5(uniqid()), 0, 6) . '.' . $ext;

            if ($job['uniqualize']) {
                $vf = implode(',', [
                    'hue=h=' . rand(-2, 2) . ':s=' . (1 + rand(-3, 3) / 100),
                    'eq=brightness=' . (rand(-2, 2) / 100) . ':contrast=' . (1 + rand(-2, 2) / 100),
                    'noise=alls=' . rand(1, 2) . ':allf=t',
                ]);
                $cmd = sprintf('%s -i %s -vf "%s" -map_metadata -1 -q:v 2 -y %s 2>&1',
                    FFMPEG_BIN, escapeshellarg($file1Path), $vf, escapeshellarg($outputFile));
            } else {
                $cmd = sprintf('%s -i %s -map_metadata -1 -q:v 2 -y %s 2>&1',
                    FFMPEG_BIN, escapeshellarg($file1Path), escapeshellarg($outputFile));
            }

            $out = shell_exec($cmd);
            if (!file_exists($outputFile) || filesize($outputFile) < 100) {
                throw new Exception("Image FFmpeg error:\n" . substr($out, -300));
            }

            $job['status'] = 'done';
            $job['output_file'] = basename($outputFile);
            $job['output_size'] = filesize($outputFile);
            $job['output_duration'] = null;
            $job['resolution'] = null;
            $job['output_md5'] = substr(md5_file($outputFile), 0, 12);
            $job['finished_at'] = time();

            if (file_exists($job['creative_path'])) @unlink($job['creative_path']);
            file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE));
            return;
        }

        // Resolution (video only)
        if ($job['format'] === 'original') {
            $probe = getVideoInfo($file1Path);
            $w = $probe['w'] ?: 1080; $h = $probe['h'] ?: 1920;
            $w = $w % 2 === 0 ? $w : $w + 1;
            $h = $h % 2 === 0 ? $h : $h + 1;
        } else {
            $r = $resolutions[$job['format']] ?? $resolutions['9:16'];
            $w = $r['w']; $h = $r['h'];
        }

        $uid = uniqid('w_', true);
        $baseName = pathinfo($job['original_name'], PATHINFO_FILENAME);

        // ── SOLO MODE ──
        if ($job['mode'] === 'solo') {
            $outputFile = OUTPUT_DIR . $baseName . '_unique_' . substr(md5($uid), 0, 6) . '.mp4';

            $vf = buildScaleFilter($w, $h);
            if ($job['uniqualize']) {
                $vf .= ',' . implode(',', buildUniqualizationFilters());
            }
            $metaFlags = $job['uniqualize']
                ? '-map_metadata -1 -metadata:s:v:0 encoder="VF' . rand(100,999) . '" -fflags +bitexact -flags:v +bitexact -flags:a +bitexact'
                : '';

            $cmd = sprintf('%s -i %s -vf "%s" %s -c:v libx264 -preset %s -crf %s -c:a aac -b:a %s -ar 44100 -ac 2 -r 30 -y %s 2>&1',
                FFMPEG_BIN, escapeshellarg($file1Path), $vf, $metaFlags,
                $q['preset'], $q['crf'], $q['ab'], escapeshellarg($outputFile));

            $out = shell_exec($cmd);
            if (!file_exists($outputFile) || filesize($outputFile) < 1000) {
                throw new Exception("FFmpeg error:\n" . substr($out, -500));
            }
        }

        // ── MERGE MODE ──
        else {
            $endcardPath = $job['endcard_path'];
            if (!$endcardPath || !file_exists($endcardPath)) throw new Exception('Endcard file missing');

            $isImage = $job['endcard_type'] === 'image';

            // Part 1
            $part1 = UPLOAD_DIR . $uid . '_p1.mp4';
            $tempFiles[] = $part1;
            $vf1 = buildScaleFilter($w, $h);
            if ($job['uniqualize']) $vf1 .= ',' . implode(',', buildUniqualizationFilters());

            $cmd1 = sprintf('%s -i %s -vf "%s" -c:v libx264 -preset %s -crf %s -c:a aac -b:a %s -ar 44100 -ac 2 -r 30 -y %s 2>&1',
                FFMPEG_BIN, escapeshellarg($file1Path), $vf1, $q['preset'], $q['crf'], $q['ab'], escapeshellarg($part1));
            $out1 = shell_exec($cmd1);
            if (!file_exists($part1) || filesize($part1) < 1000) throw new Exception("Part1 error:\n".substr($out1,-400));

            // Part 2
            $part2 = UPLOAD_DIR . $uid . '_p2.mp4';
            $tempFiles[] = $part2;

            if ($isImage) {
                $part2 = processImageEndcard($endcardPath, $part2, $w, $h,
                    $job['endcard_duration'], $job['endcard_animation'], $q, $uid, $tempFiles);
            } else {
                $vf2 = buildScaleFilter($w, $h);
                if ($job['uniqualize']) $vf2 .= ',' . implode(',', buildUniqualizationFilters());
                $cmd2 = sprintf('%s -i %s -vf "%s" -c:v libx264 -preset %s -crf %s -c:a aac -b:a %s -ar 44100 -ac 2 -r 30 -y %s 2>&1',
                    FFMPEG_BIN, escapeshellarg($endcardPath), $vf2, $q['preset'], $q['crf'], $q['ab'], escapeshellarg($part2));
                $out2 = shell_exec($cmd2);
                if (!file_exists($part2) || filesize($part2) < 1000) throw new Exception("Part2 error:\n".substr($out2,-400));
            }

            // Concat
            $listFile = UPLOAD_DIR . $uid . '_list.txt';
            $tempFiles[] = $listFile;
            file_put_contents($listFile, "file '".realpath($part1)."'\nfile '".realpath($part2)."'\n");

            $outputFile = OUTPUT_DIR . $baseName . '_merged_' . substr(md5($uid), 0, 6) . '.mp4';

            if ($job['uniqualize']) {
                $tmpC = $outputFile . '.tmp.mp4'; $tempFiles[] = $tmpC;
                shell_exec(sprintf('%s -f concat -safe 0 -i %s -c copy -y %s 2>&1', FFMPEG_BIN, escapeshellarg($listFile), escapeshellarg($tmpC)));
                shell_exec(sprintf('%s -i %s -map_metadata -1 -metadata:s:v:0 encoder="VF%s" -fflags +bitexact -flags:v +bitexact -flags:a +bitexact -c:v libx264 -preset %s -crf %s -c:a aac -b:a %s -y %s 2>&1',
                    FFMPEG_BIN, escapeshellarg($tmpC), rand(100,999), $q['preset'], $q['crf'], $q['ab'], escapeshellarg($outputFile)));
            } else {
                shell_exec(sprintf('%s -f concat -safe 0 -i %s -c copy -y %s 2>&1', FFMPEG_BIN, escapeshellarg($listFile), escapeshellarg($outputFile)));
            }

            if (!file_exists($outputFile) || filesize($outputFile) < 1000) throw new Exception('Concat failed');
        }

        // Get info
        $duration = trim(shell_exec(sprintf('%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1', FFPROBE_BIN, escapeshellarg($outputFile))));

        $job['status'] = 'done';
        $job['output_file'] = basename($outputFile);
        $job['output_size'] = filesize($outputFile);
        $job['output_duration'] = round((float)$duration, 1);
        $job['resolution'] = "{$w}x{$h}";
        $job['output_md5'] = substr(md5_file($outputFile), 0, 12);
        $job['finished_at'] = time();

    } catch (Exception $e) {
        $job['status'] = 'error';
        $job['error'] = $e->getMessage();
        $job['finished_at'] = time();
    }

    // Cleanup temp
    foreach ($tempFiles as $f) { if (file_exists($f)) @unlink($f); }
    // Cleanup input creative (keep endcard for other jobs)
    if (file_exists($job['creative_path'])) @unlink($job['creative_path']);

    file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE));
}

// ═══════════════════════════════════════════════════════════════
// WORKER LAUNCHER
// ═══════════════════════════════════════════════════════════════
function launchWorker($jobId) {
    // PHP_BINARY in FPM/web context points to the FPM binary, not CLI.
    // Find the actual CLI binary instead.
    if (php_sapi_name() === 'cli') {
        $phpBin = PHP_BINARY;
    } else {
        $phpBin = trim(shell_exec('which php 2>/dev/null'))
               ?: trim(shell_exec('which php8.4 2>/dev/null'))
               ?: trim(shell_exec('which php8.3 2>/dev/null'))
               ?: '/usr/bin/php';
    }
    $script = escapeshellarg(__FILE__);
    $jid    = escapeshellarg($jobId);
    if (PHP_OS_FAMILY !== 'Windows' && shell_exec('which nohup 2>/dev/null')) {
        @exec("nohup {$phpBin} {$script} worker {$jid} > /dev/null 2>&1 &");
    } else {
        @exec("{$phpBin} {$script} worker {$jid} > /dev/null 2>&1 &");
    }
}

// ═══════════════════════════════════════════════════════════════
// STATUS — poll all active jobs
// ═══════════════════════════════════════════════════════════════
function handleStatus() {
    $jobIds = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
    $jobs = [];

    if (empty($jobIds)) {
        // Return all recent jobs
        $files = glob(JOBS_DIR . 'job_*.json');
        usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
        $files = array_slice($files, 0, 100);
        foreach ($files as $f) {
            $j = json_decode(file_get_contents($f), true);
            if ($j) {
                $j = watchdogJob($j, $f);
                $jobs[] = formatJobForClient($j);
            }
        }
    } else {
        foreach ($jobIds as $id) {
            $id = preg_replace('/[^a-zA-Z0-9._]/', '', $id);
            $f = JOBS_DIR . $id . '.json';
            if (file_exists($f)) {
                $j = json_decode(file_get_contents($f), true);
                if ($j) {
                    $j = watchdogJob($j, $f);
                    $jobs[] = formatJobForClient($j);
                }
            }
        }
    }

    jsonOut(['ok' => true, 'jobs' => $jobs]);
}

/**
 * Watchdog: re-launch workers for stuck queued jobs,
 * and fail jobs stuck in processing for too long.
 */
function watchdogJob($job, $jobFile) {
    $now = time();

    // Job stuck in 'queued' for >20s → worker never started, re-launch
    if ($job['status'] === 'queued' && ($now - $job['created_at']) > 20) {
        launchWorker($job['id']);
    }

    // Job stuck in 'processing' for >30min → worker crashed, mark as error
    if ($job['status'] === 'processing' && $job['started_at'] && ($now - $job['started_at']) > 1800) {
        $job['status'] = 'error';
        $job['error'] = 'Таймаут: обработка зависла (>30 мин)';
        $job['finished_at'] = $now;
        file_put_contents($jobFile, json_encode($job, JSON_UNESCAPED_UNICODE));
    }

    return $job;
}

function handleJobStatus() {
    $id = preg_replace('/[^a-zA-Z0-9._]/', '', $_GET['id'] ?? '');
    $f = JOBS_DIR . $id . '.json';
    if (!file_exists($f)) { jsonOut(['error' => 'Job not found'], 404); return; }
    $j = json_decode(file_get_contents($f), true);
    jsonOut(['ok' => true, 'job' => formatJobForClient($j)]);
}

function formatJobForClient($j) {
    return [
        'id'            => $j['id'],
        'status'        => $j['status'],
        'original_name' => $j['original_name'],
        'mode'          => $j['mode'],
        'format'        => $j['format'] ?? null,
        'uniqualized'   => $j['uniqualize'] ?? false,
        'output_file'   => $j['output_file'],
        'output_size'   => $j['output_size'],
        'output_size_mb'=> $j['output_size'] ? round($j['output_size']/1024/1024, 1) : null,
        'output_duration'=> $j['output_duration'],
        'output_md5'    => $j['output_md5'] ?? null,
        'resolution'    => $j['resolution'] ?? null,
        'error'         => $j['error'],
        'created_at'    => $j['created_at'],
        'started_at'    => $j['started_at'],
        'finished_at'   => $j['finished_at'],
        'elapsed'       => ($j['finished_at'] && $j['started_at']) ? ($j['finished_at'] - $j['started_at']) : null,
    ];
}

// ═══════════════════════════════════════════════════════════════
// FILE MANAGER
// ═══════════════════════════════════════════════════════════════
function handleFiles() {
    $files = [];
    $patterns = ['*.mp4', '*.jpg', '*.jpeg', '*.png', '*.webp', '*.gif'];
    foreach ($patterns as $pat) {
        foreach (glob(OUTPUT_DIR . $pat) as $f) {
            $files[] = [
                'name' => basename($f),
                'size' => filesize($f),
                'size_mb' => round(filesize($f)/1024/1024, 1),
                'created' => filemtime($f),
                'url' => 'tmp_outputs/' . basename($f),
            ];
        }
    }
    usort($files, fn($a,$b) => $b['created'] - $a['created']);
    jsonOut(['ok' => true, 'files' => $files, 'count' => count($files)]);
}

function handleDownload() {
    $name = basename($_GET['file'] ?? '');
    $path = OUTPUT_DIR . $name;
    if (!$name || !file_exists($path)) { jsonOut(['error' => 'File not found'], 404); return; }
    $mime = mime_content_type($path) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function handleDelete() {
    $names = $_POST['files'] ?? [];
    if (is_string($names)) $names = json_decode($names, true) ?: [$names];
    $deleted = 0;
    foreach ($names as $name) {
        $name = basename($name);
        $path = OUTPUT_DIR . $name;
        if (file_exists($path)) { @unlink($path); $deleted++; }
    }
    jsonOut(['ok' => true, 'deleted' => $deleted]);
}

function handleDownloadAll() {
    $files = [];
    foreach (['*.mp4','*.jpg','*.jpeg','*.png','*.webp','*.gif'] as $pat) {
        $files = array_merge($files, glob(OUTPUT_DIR . $pat) ?: []);
    }
    if (empty($files)) { jsonOut(['error' => 'Нет файлов'], 404); return; }

    $zipPath = OUTPUT_DIR . 'videofuse_all_' . date('Y-m-d_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        jsonOut(['error' => 'Cannot create ZIP'], 500); return;
    }
    foreach ($files as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// HELPERS (same as v2)
// ═══════════════════════════════════════════════════════════════
function buildScaleFilter($w, $h) {
    return "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:black,setsar=1";
}

function buildUniqualizationFilters() {
    $f = [];
    $f[] = "hue=h=" . rand(-2, 2) . ":s=" . (1 + rand(-3, 3) / 100);
    $l=rand(0,2); $r=rand(0,2); $t=rand(0,2); $b=rand(0,2);
    $f[] = "crop=iw-{$l}-{$r}:ih-{$t}-{$b}:{$l}:{$t}";
    $f[] = "noise=alls=" . rand(1,3) . ":allf=t";
    $f[] = "eq=brightness=" . (rand(-2,2)/100) . ":contrast=" . (1 + rand(-2,2)/100);
    $f[] = "eq=gamma=" . (1 + rand(-2,2)/100);
    return $f;
}

function buildImageAnimation($type, $w, $h) {
    $base = "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2:black";
    $w2=$w*2; $h2=$h*2;
    switch ($type) {
        case 'zoom':  return "scale={$w2}:{$h2}:force_original_aspect_ratio=decrease,pad={$w2}:{$h2}:(ow-iw)/2:(oh-ih)/2:black,zoompan=z='min(zoom+0.002,1.3)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=150:s={$w}x{$h}:fps=30";
        case 'fade':  return "{$base},fade=t=in:st=0:d=1.5,fade=t=out:st=3.5:d=1.5";
        case 'slide': return "scale={$w}:{$h2}:force_original_aspect_ratio=decrease,pad={$w}:{$h2}:(ow-iw)/2:(oh-ih)/2:black,crop={$w}:{$h}:0:'min(ih-{$h},max(0,(ih-{$h})*((t)/3)))':exact=1";
        case 'pulse': return "scale={$w2}:{$h2}:force_original_aspect_ratio=decrease,pad={$w2}:{$h2}:(ow-iw)/2:(oh-ih)/2:black,zoompan=z='1+0.05*sin(2*PI*t/2)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=150:s={$w}x{$h}:fps=30,fade=t=in:st=0:d=0.8";
        default:      return $base;
    }
}

function processImageEndcard($imgPath, $outPath, $w, $h, $dur, $anim, $q, $uid, &$tempFiles) {
    $animFilter = buildImageAnimation($anim, $w, $h);
    $vf = "{$animFilter},format=yuv420p";
    $cmd = sprintf('%s -loop 1 -i %s -t %.1f -vf "%s" -c:v libx264 -preset %s -crf %s -an -r 30 -y %s 2>&1',
        FFMPEG_BIN, escapeshellarg($imgPath), $dur, $vf, $q['preset'], $q['crf'], escapeshellarg($outPath));
    shell_exec($cmd);
    if (!file_exists($outPath) || filesize($outPath) < 500) {
        $vfS = "scale={$w}:{$h}:force_original_aspect_ratio=decrease,pad={$w}:{$h}:(ow-iw)/2:(oh-ih)/2,format=yuv420p";
        shell_exec(sprintf('%s -loop 1 -i %s -t %.1f -vf "%s" -c:v libx264 -preset %s -crf %s -an -r 30 -y %s 2>&1',
            FFMPEG_BIN, escapeshellarg($imgPath), $dur, $vfS, $q['preset'], $q['crf'], escapeshellarg($outPath)));
    }
    $withA = UPLOAD_DIR . $uid . '_p2a.mp4'; $tempFiles[] = $withA;
    shell_exec(sprintf('%s -i %s -f lavfi -i anullsrc=channel_layout=stereo:sample_rate=44100 -c:v copy -c:a aac -b:a %s -shortest -y %s 2>&1',
        FFMPEG_BIN, escapeshellarg($outPath), $q['ab'], escapeshellarg($withA)));
    return (file_exists($withA) && filesize($withA) > 500) ? $withA : $outPath;
}

function getVideoInfo($path) {
    $json = shell_exec(sprintf('%s -v error -select_streams v:0 -show_entries stream=width,height -of json %s 2>&1', FFPROBE_BIN, escapeshellarg($path)));
    $data = json_decode($json, true); $s = $data['streams'][0] ?? [];
    return ['w' => (int)($s['width'] ?? 0), 'h' => (int)($s['height'] ?? 0)];
}

function sanitizeName($name) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
}

function handleServerStats() {
    $load = sys_getloadavg();

    // CPU count and usage %
    $cpuCount = (int)trim(shell_exec('nproc 2>/dev/null') ?: '1') ?: 1;
    $cpuPct   = min(100, (int)round($load[0] / $cpuCount * 100));

    // RAM from /proc/meminfo
    $memTotal = 0; $memAvail = 0;
    if (file_exists('/proc/meminfo')) {
        foreach (file('/proc/meminfo') as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m))     $memTotal = (int)$m[1];
            if (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) $memAvail = (int)$m[1];
        }
    }
    $memUsedPct  = $memTotal ? round(($memTotal - $memAvail) / $memTotal * 100) : 0;
    $memUsedGb   = round(($memTotal - $memAvail) / 1024 / 1024, 1);
    $memTotalGb  = round($memTotal / 1024 / 1024, 1);

    // Disk
    $diskFree    = disk_free_space(OUTPUT_DIR) ?: 0;
    $diskTotal   = disk_total_space(OUTPUT_DIR) ?: 0;
    $diskUsedPct = $diskTotal ? round(($diskTotal - $diskFree) / $diskTotal * 100) : 0;
    $diskFreeGb  = round($diskFree / 1024 / 1024 / 1024, 1);

    // Active workers
    $activeJobs = 0;
    foreach (glob(JOBS_DIR . 'job_*.json') ?: [] as $f) {
        $j = json_decode(file_get_contents($f), true);
        if ($j && $j['status'] === 'processing') $activeJobs++;
    }

    jsonOut([
        'ok'           => true,
        'cpu_pct'      => $cpuPct,
        'cpu_count'    => $cpuCount,
        'load1'        => round($load[0], 2),
        'mem_used_pct' => $memUsedPct,
        'mem_used_gb'  => $memUsedGb,
        'mem_total_gb' => $memTotalGb,
        'disk_used_pct'=> $diskUsedPct,
        'disk_free_gb' => $diskFreeGb,
        'active_jobs'  => $activeJobs,
    ]);
}

function handleClearQueue() {
    $cleared = 0;
    foreach (glob(JOBS_DIR . 'job_*.json') as $f) {
        $j = json_decode(file_get_contents($f), true);
        if ($j && in_array($j['status'], ['queued', 'processing'])) {
            // Clean up uploaded source file if it exists
            if (!empty($j['creative_path']) && file_exists($j['creative_path'])) {
                @unlink($j['creative_path']);
            }
            @unlink($f);
            $cleared++;
        }
    }
    jsonOut(['ok' => true, 'cleared' => $cleared]);
}

function handleCleanup() {
    $count = 0; $maxAge = 86400; // 24h
    foreach ([UPLOAD_DIR, OUTPUT_DIR, JOBS_DIR] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . '*') as $f) {
            if (is_file($f) && (time() - filemtime($f)) > $maxAge) { @unlink($f); $count++; }
        }
    }
    jsonOut(['ok' => true, 'deleted' => $count]);
}

function jsonOut($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
