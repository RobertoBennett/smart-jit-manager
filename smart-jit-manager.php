<?php
/**
 * Plugin Name: Smart JIT Manager
 * Description: Умное управление OPcache JIT для WordPress. Баланс скорости и стабильности.
 * Version: 1.0.2
 * Author: Your Name
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class SmartJITManager {
    
    private $is_bot = false;
    private $safe_mode = true;
    private $current_jit_buffer = 0;
    
    public function __construct() {
        $this->init();
    }
    
    public function init() {
        // Получаем текущий размер буфера
        $this->current_jit_buffer = ini_get('opcache.jit_buffer_size');
        
        // Загружаем настройки
        add_action('plugins_loaded', [$this, 'load_settings'], 1);
        
        // Определяем тип пользователя
        add_action('plugins_loaded', [$this, 'detect_user_type'], 2);
        
        // Управление JIT настройками
        add_action('wp_loaded', [$this, 'manage_jit_settings']);
        
        // Решение проблем с кешированием
        add_action('save_post', [$this, 'clear_opcache_on_content_update'], 10, 3);
        add_action('delete_post', [$this, 'clear_opcache_on_content_update']);
        add_action('wp_trash_post', [$this, 'clear_opcache_on_content_update']);
        
        // Добавляем заголовки для отладки
        add_action('send_headers', [$this, 'add_debug_headers']);
        
        // Исправление 404 ошибок
        add_action('template_redirect', [$this, 'fix_404_for_cached_posts']);
        
        // Админ-панель
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        
        // Виджет статуса
        add_action('admin_bar_menu', [$this, 'add_admin_bar_status'], 100);
        
        // Предупреждение в админке о размере буфера
        add_action('admin_notices', [$this, 'show_buffer_warning']);
    }
    
    public function load_settings() {
        $this->safe_mode = get_option('smart_jit_safe_mode', '1') === '1';
    }
    
    public function detect_user_type() {
        if (is_admin()) {
            return;
        }
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->is_bot = $this->is_search_bot($user_agent);
        
        if (!$this->is_bot) {
            $this->is_bot = $this->is_search_engine_ip();
        }
    }
    
    public function manage_jit_settings() {
        if (is_admin()) {
            return;
        }
        
        if ($this->is_bot) {
            $this->set_bot_safe_settings();
        } else {
            $this->set_human_optimized_settings();
        }
    }
    
    private function set_bot_safe_settings() {
        $critical_bots = ['googlebot', 'yandex', 'bingbot'];
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        foreach ($critical_bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                // ПОЛНОЕ отключение для важных ПС
                ini_set('opcache.jit', 'disable');
                ini_set('opcache.enable', '0');
                return;
            }
        }
        
        // Для остальных ботов - минимальный JIT
        ini_set('opcache.jit', 'function');
    }
    
    private function set_human_optimized_settings() {
        if ($this->safe_mode) {
            // Безопасный режим
            ini_set('opcache.jit', 'function');
        } else {
            // Экспертный режим
            ini_set('opcache.jit', 'tracing');
        }
        
        // Общие настройки (которые можно менять в runtime)
        ini_set('opcache.revalidate_freq', '30');
        ini_set('opcache.validate_timestamps', '1');
    }
    
    public function show_buffer_warning() {
        $screen = get_current_screen();
        if ($screen->id !== 'settings_page_smart-jit-manager') {
            return;
        }
        
        $recommended_size = 100; // MB
        $current_size = $this->convert_to_mb($this->current_jit_buffer);
        
        if ($current_size < $recommended_size) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Внимание:</strong> Текущий размер буфера JIT: ' . $current_size . 'MB. ';
            echo 'Рекомендуется установить не менее ' . $recommended_size . 'MB в php.ini:</p>';
            echo '<code>opcache.jit_buffer_size=100M</code>';
            echo '</div>';
        }
    }
    
    private function convert_to_mb($size) {
        $size = strtoupper(trim($size));
        $unit = preg_replace('/[^A-Z]/', '', $size);
        $value = (int)preg_replace('/[^0-9]/', '', $size);
        
        switch ($unit) {
            case 'G': return $value * 1024;
            case 'M': return $value;
            case 'K': return round($value / 1024);
            default: return round($value / (1024 * 1024));
        }
    }
    
    public function is_search_bot($user_agent = '') {
        if (empty($user_agent)) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        if (empty($user_agent)) {
            return false;
        }
        
        $bots_patterns = [
            '/googlebot/i', '/yandex/i', '/bingbot/i', '/duckduckbot/i',
            '/baiduspider/i', '/sogou/i', '/exabot/i', '/facebot/i',
            '/facebookexternalhit/i', '/ahrefsbot/i', '/semrushbot/i',
            '/mj12bot/i', '/dotbot/i', '/megaindex/i', '/mail\.ru/i',
            '/petalbot/i', '/zoominfobot/i', '/serpstatbot/i'
        ];
        
        foreach ($bots_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function is_search_engine_ip() {
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        if (empty($client_ip)) {
            return false;
        }
        
        $search_engine_ips = [
            '66.249.64.0/19',    '66.249.64.0/20',    // Google
            '77.88.0.0/18',      '5.255.0.0/16',      // Yandex
            '213.180.193.0/24',  '207.46.0.0/16',     // Bing
            '157.55.0.0/16',                          // Bing
        ];
        
        foreach ($search_engine_ips as $cidr) {
            if ($this->ip_in_range($client_ip, $cidr)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function ip_in_range($ip, $cidr) {
        list($subnet, $mask) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $mask);
        $subnet &= $mask;
        
        return ($ip & $mask) == $subnet;
    }
    
    public function clear_opcache_on_content_update($post_id, $post = null, $update = null) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $this->log('OPcache reset after content update. Post ID: ' . $post_id);
        }
    }
    
    public function add_debug_headers() {
        if (headers_sent() || is_admin()) {
            return;
        }
        
        $jit_mode = ini_get('opcache.jit');
        $buffer_size = ini_get('opcache.jit_buffer_size');
        
        header('X-JIT-Mode: ' . ($this->is_bot ? 'SAFE-for-bot' : ($this->safe_mode ? 'BALANCED' : 'TURBO')));
        header('X-JIT-Buffer: ' . $buffer_size);
        header('X-JIT-Actual: ' . $jit_mode);
    }
    
    public function fix_404_for_cached_posts() {
        if (!is_404() || is_user_logged_in()) {
            return;
        }
        
        $requested_url = $_SERVER['REQUEST_URI'] ?? '';
        $slug = basename(trim($requested_url, '/'));
        
        $post = get_page_by_path($slug, OBJECT, ['post', 'page']);
        
        if ($post && $post->post_status === 'publish') {
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            wp_redirect(get_permalink($post->ID), 302);
            exit;
        }
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Smart JIT Settings',
            'JIT Manager',
            'manage_options',
            'smart-jit-manager',
            [$this, 'options_page_html']
        );
    }
    
    public function settings_init() {
        register_setting('smart_jit_settings', 'smart_jit_safe_mode');
        
        add_settings_section(
            'smart_jit_section',
            'Управление JIT режимом',
            [$this, 'section_callback'],
            'smart_jit_settings'
        );
        
        add_settings_field(
            'safe_mode',
            'Режим работы',
            [$this, 'safe_mode_callback'],
            'smart_jit_settings',
            'smart_jit_section'
        );
        
        add_settings_field(
            'buffer_info',
            'Текущий размер буфера',
            [$this, 'buffer_info_callback'],
            'smart_jit_settings',
            'smart_jit_section'
        );
    }
    
    public function section_callback() {
        echo '<div class="notice notice-info">';
        echo '<p><strong>Информация:</strong> Размер буфера JIT задается в php.ini и не может быть изменен во время выполнения.</p>';
        echo '<p>Текущий размер: <strong>' . $this->current_jit_buffer . '</strong></p>';
        echo '</div>';
    }
    
    public function safe_mode_callback() {
        $safe_mode = get_option('smart_jit_safe_mode', '1');
        ?>
        <label>
            <input type="radio" name="smart_jit_safe_mode" value="1" <?php checked($safe_mode, '1'); ?>>
            🟢 Безопасный режим (function) - баланс скорости и стабильности
        </label>
        <br>
        <label>
            <input type="radio" name="smart_jit_safe_mode" value="0" <?php checked($safe_mode, '0'); ?>>
            🟡 Экспертный режим (tracing) - максимальная производительность
        </label>
        <?php
    }
    
    public function buffer_info_callback() {
        $recommended = '100M';
        $current_mb = $this->convert_to_mb($this->current_jit_buffer);
        $recommended_mb = $this->convert_to_mb($recommended);
        
        echo '<div style="padding: 10px; background: #f6f7f7; border-left: 4px solid #00a0d2;">';
        echo '<p><strong>Текущий размер:</strong> ' . $this->current_jit_buffer . ' (' . $current_mb . 'MB)</p>';
        echo '<p><strong>Рекомендуется:</strong> ' . $recommended . ' (' . $recommended_mb . 'MB)</p>';
        
        if ($current_mb < $recommended_mb) {
            echo '<p style="color: #d63638;">⚠️ Для лучшей производительности увеличьте буфер в php.ini:</p>';
            echo '<code>opcache.jit_buffer_size=100M</code>';
        } else {
            echo '<p style="color: #00a32a;">✅ Размер буфера оптимален</p>';
        }
        
        echo '</div>';
    }
    
    public function options_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Умное управление JIT</h1>
            
            <div class="card">
                <h2>Текущая конфигурация</h2>
                <p><strong>Режим работы:</strong> <?php echo $this->safe_mode ? '🟢 Безопасный' : '🟡 Экспертный'; ?></p>
                <p><strong>Размер буфера:</strong> <?php echo $this->current_jit_buffer; ?></p>
                <p><strong>JIT активен:</strong> <?php echo ini_get('opcache.jit') !== 'disable' ? 'Да' : 'Нет'; ?></p>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('smart_jit_settings');
                do_settings_sections('smart_jit_settings');
                submit_button('Сохранить настройки');
                ?>
            </form>
            
            <div class="card">
                <h2>Инструкция по настройке буфера</h2>
                <p>Для изменения размера буфера JIT добавьте в php.ini или .user.ini:</p>
                <pre>opcache.jit_buffer_size=100M
opcache.jit=tracing
opcache.jit_max_trace_points=100000</pre>
                <p>После изменения перезагрузите PHP-FPM или веб-сервер.</p>
            </div>
        </div>
        <?php
    }
    
    public function add_admin_bar_status($admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $status = $this->safe_mode ? '🟢 Безопасный' : '🟡 Экспертный';
        $jit_status = ini_get('opcache.jit') !== 'disable' ? 'Вкл' : 'Выкл';
        
        $admin_bar->add_node([
            'id'    => 'jit-status',
            'title' => "JIT: {$status} | Буфер: {$this->current_jit_buffer}",
            'href'  => admin_url('options-general.php?page=smart-jit-manager'),
            'meta'  => ['title' => 'Статус JIT оптимизации']
        ]);
    }
    
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[SmartJIT] ' . $message);
        }
    }
}

new SmartJITManager();