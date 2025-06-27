<?php
/**
 * Plugin Name:       Güvenlik Tarayıcı
 * Plugin URI:        https://muratkose.online
 * Description:       WordPress sitenizdeki dosyaları analiz ederek değiştirilmiş, eksik, bilinmeyen ve şüpheli dosyaları bulan bir güvenlik tarama aracı.
 * Version:           1.0.0
 * Author:            Kodlama Desteği && Murat Köse
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guvenlik-tarayici
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Doğrudan erişimi engelle

// 1. ADIM: EKLENTİ MENÜSÜNÜ OLUŞTURMA
add_action( 'admin_menu', 'gt_create_admin_menu' );
function gt_create_admin_menu() {
    add_menu_page(
        'Güvenlik Taraması',      // Sayfa başlığı
        'Güvenlik Taraması',      // Menü başlığı
        'manage_options',         // Yetki (sadece yöneticiler)
        'guvenlik-tarayici-page', // Menü slug (benzersiz kimlik)
        'gt_scanner_page_html',   // Sayfa içeriğini oluşturan fonksiyon
        'dashicons-shield-alt',   // İkon
        2                         // Pozisyon
    );
}

// 2. ADIM: TARAYICI SAYFASININ HTML'İNİ OLUŞTURMA
function gt_scanner_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <div id="scanner-app-container">
             <div id="start-area" class="start-screen">
                <h1>Tarama Başlatmaya Hazır</h1>
                <p>Bu araç sitenizin dosyalarını analiz ederek çekirdek bütünlüğünü kontrol edecek, şüpheli kodları ve bilinmeyen dosyaları arayacaktır.</p>
                <button id="start-scan-btn" class="scan-button">Taramayı Başlat</button>
                <div class="progress-container" id="progress-container">
                    <div class="progress-bar"><div class="progress-bar-inner" id="progress-bar-inner">0%</div></div>
                    <p id="status-text">Tarama başlatılıyor...</p>
                </div>
            </div>
            <div id="scan-results"></div>
        </div>
    </div>
    <?php
}

// 3. ADIM: GEREKLİ CSS VE JS DOSYALARINI YÜKLEME
add_action( 'admin_enqueue_scripts', 'gt_enqueue_assets' );
function gt_enqueue_assets($hook) {
    // Sadece bizim eklenti sayfamızda bu dosyaları yükle
    if ( 'toplevel_page_guvenlik-tarayici-page' != $hook ) {
        return;
    }
    // CSS dosyamızı ekleyelim
    wp_enqueue_style(
        'gt-scanner-styles',
        plugin_dir_url( __FILE__ ) . 'scanner.css',
        [],
        '1.0.0'
    );
    // JS dosyamızı ekleyelim
    wp_enqueue_script(
        'gt-scanner-scripts',
        plugin_dir_url( __FILE__ ) . 'scanner.js',
        [], // Bağımlılık yok
        '1.0.0',
        true // body sonunda yükle
    );
    // JS dosyamıza veri göndermek için (AJAX URL'si ve nonce gibi)
    wp_localize_script('gt-scanner-scripts', 'scanner_ajax_object', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('gt_scanner_nonce')
    ]);
}


// 4. ADIM: WORDPRESS AJAX İSTEKLERİNİ TANIMLAMA
add_action('wp_ajax_gt_run_scan_task', 'gt_run_scan_task_callback');
function gt_run_scan_task_callback() {
    check_ajax_referer('gt_scanner_nonce', 'nonce');

    if(!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Yetkiniz yok.']);
        return;
    }

    $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';
    $results = [];

    switch ($task) {
        case 'core_check':
            $results = gt_task_check_core_files();
            break;
        case 'suspicious_scan':
            $results = gt_task_scan_suspicious_patterns();
            break;
        case 'unknown_files_scan': 
            $results = gt_task_scan_unknown_files();
            break;
        default:
            wp_send_json_error(['message' => 'Geçersiz görev.']);
            return;
    }
    
    wp_send_json_success($results);
}


// 5. ADIM: TÜM TARAMA FONKSİYONLARINI EKLENTİ İÇİNE TAŞIMA
// Not: Fonksiyon adlarını, diğer eklentilerle çakışmaması için "gt_task_" ön ekiyle güncelledim.

function gt_task_get_official_checksums() {
    global $wp_version;
    $locale = get_locale();
    $api_url = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}&locale={$locale}";
    $response = @wp_remote_get($api_url, ['timeout' => 20]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return false; }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (!isset($data['checksums']) || !is_array($data['checksums'])) {
         $api_url = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}";
         $response = @wp_remote_get($api_url, ['timeout' => 20]);
         $body = wp_remote_retrieve_body($response);
         $data = json_decode($body, true);
         if (!isset($data['checksums']) || !is_array($data['checksums'])) return false;
    }
    return $data['checksums'];
}

function gt_task_check_core_files() {
    $official_checksums = gt_task_get_official_checksums();
    if ($official_checksums === false) return ['success' => false, 'message' => 'WordPress.org API\'sinden checksum verisi alınamadı.'];
    $results = ['modified' => [], 'missing' => [], 'ok_count' => 0];
    foreach ($official_checksums as $file => $expected_md5) {
        if (strpos($file, 'wp-content/') !== false) continue;
        $filepath = ABSPATH . $file;
        if (!file_exists($filepath)) {
            if (strpos($file, 'wp-config-sample.php') === false) $results['missing'][] = $file;
        } elseif (md5_file($filepath) !== $expected_md5) {
            $results['modified'][] = $file;
        } else {
            $results['ok_count']++;
        }
    }
    return $results;
}

function gt_task_scan_suspicious_patterns() {
    $results = ['suspicious_files' => []];
    $patterns = [
        '/(eval|assert|exec|shell_exec|passthru|popen|system|proc_open)\s*\(\s*base64_decode\s*\(/i',
        '/(eval|assert|exec|shell_exec|passthru|popen|system|proc_open)\s*\(\s*gzinflate\s*\(\s*base64_decode\s*\(/i',
        '/\$_(POST|GET|REQUEST|COOKIE)\[.{0,15}\]\s*\(.*\$_(POST|GET|REQUEST|COOKIE)\[.{0,15}\]\s*\)/i',
        '/create_function\s*\(/i',
    ];
    $scan_path = WP_CONTENT_DIR . '/';
    if (!is_dir($scan_path)) return $results;
    $all_files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scan_path, FilesystemIterator::SKIP_DOTS));
    $php_files = new RegexIterator($all_files, '/\.php$/i');
    foreach ($php_files as $file) {
        if ($file->isDir()) continue;
        $content = @file_get_contents($file->getPathname());
        if ($content === false) continue;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $results['suspicious_files'][] = str_replace(ABSPATH, '', $file->getPathname());
                break;
            }
        }
    }
    return $results;
}

function gt_task_scan_unknown_files() {
    $official_checksums = gt_task_get_official_checksums();
    if ($official_checksums === false) return ['success' => false, 'message' => 'WordPress.org API\'sinden checksum verisi alınamadı.'];
    $official_files = array_flip(array_keys($official_checksums));
    $results = ['unknown_files' => []];
    $server_files = [];
    $root_iterator = new DirectoryIterator(ABSPATH);
    foreach ($root_iterator as $fileinfo) {
        if ($fileinfo->isFile()) $server_files[] = $fileinfo->getFilename();
    }
    $scan_dirs = [ABSPATH . 'wp-admin', ABSPATH . 'wp-includes'];
    foreach ($scan_dirs as $dir) {
        if (!is_dir($dir)) continue;
        $recursive_iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($recursive_iterator as $file) {
            if ($file->isDir()) continue;
            $server_files[] = str_replace(ABSPATH, '', $file->getPathname());
        }
    }
    $server_files = array_map(fn($file) => str_replace('\\', '/', $file), $server_files);
    $ignore_list = ['wp-config.php', '.htaccess', basename(__FILE__), 'php.ini', '.user.ini', 'error_log', 'robots.txt'];
    foreach($server_files as $server_file) {
        if (!isset($official_files[$server_file]) && !in_array($server_file, $ignore_list)) {
            $results['unknown_files'][] = $server_file;
        }
    }
    return $results;
}