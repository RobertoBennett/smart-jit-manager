<?php
/**
 * Plugin Name: Smart JIT Manager
 * Description: Умное управление OPcache JIT для WordPress. Баланс скорости и стабильности.
 * Plugin URI: https://github.com/RobertoBennett/smart-jit-manager
 * Version: 1.2.0
 * Author: Robert Bennett
 * Text Domain: Smart JIT Manager
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

class SmartJITManager {
    
    private $is_bot = false;
    private $is_speed_test_bot = false;
    private $safe_mode = true;
    private $current_jit_buffer = 0;
    private $jit_configurable = true;
    private $last_post_modified = '';
    private $opcache_restricted = false;
    
    // НАСТРОЙКИ ОТКЛЮЧЕНИЯ КЭШИРОВАНИЯ
    private $disable_jit_for_bots = true;
    private $disable_jit_for_speed_tests = true; // НОВЫЙ ПАРАМЕТР
    private $disable_page_cache_for_speed_tests = false;
    private $disable_object_cache_for_speed_tests = false;
    private $disable_browser_cache_for_speed_tests = true;
    
    public function __construct() {
        $this->init();
    }
    
    public function init() {
        $this->current_jit_buffer = ini_get('opcache.jit_buffer_size');
        $this->jit_configurable = $this->check_jit_configurability();
        $this->opcache_restricted = $this->check_opcache_restrictions();
        $this->last_post_modified = get_option('smart_jit_last_post_modified', '');
        
        add_action('plugins_loaded', [$this, 'load_settings'], 1);
        add_action('plugins_loaded', [$this, 'detect_user_type'], 2);
        add_action('wp_loaded', [$this, 'manage_jit_settings']);
        
        // Улучшенная обработка кеширования для новых постов
        add_action('save_post', [$this, 'on_post_save'], 10, 3);
        add_action('delete_post', [$this, 'on_post_delete']);
        add_action('wp_trash_post', [$this, 'on_post_trash']);
        
        // Приоритетная очистка кеша для новых постов
        add_action('publish_post', [$this, 'force_cache_clear'], 99, 2);
        add_action('publish_page', [$this, 'force_cache_clear'], 99, 2);
        
        // Исправление 404 ошибок в ранней фазе
        add_action('template_redirect', [$this, 'fix_404_early'], 1);
        
        // Отключение кеширования для ботов проверки скорости
        add_action('send_headers', [$this, 'add_no_cache_headers']);
        add_action('wp_headers', [$this, 'modify_response_headers']);
        
        add_action('send_headers', [$this, 'add_debug_headers']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_status'], 100);
        add_action('admin_notices', [$this, 'show_buffer_warning']);
        
        // Отключение плагинов кеширования для тестовых ботов
        add_action('plugins_loaded', [$this, 'maybe_disable_caching_plugins'], 1);
    }
    
    // ДОБАВЛЕННЫЕ МЕТОДЫ ДЛЯ ПРОВЕРКИ КОНФИГУРАЦИИ
    private function check_jit_configurability() {
        if (!function_exists('ini_get')) {
            return false;
        }
        
        $jit = ini_get('opcache.jit');
        return $jit !== false;
    }
    
    private function check_opcache_restrictions() {
        if (!function_exists('ini_get')) {
            return true;
        }
        
        $restrict_api = ini_get('opcache.restrict_api');
        return !empty($restrict_api);
    }
    
    public function load_settings() {
        $this->safe_mode = get_option('smart_jit_safe_mode', '1') === '1';
        
        // НАСТРОЙКИ ОТКЛЮЧЕНИЯ КЭШИРОВАНИЯ
        $this->disable_jit_for_bots = get_option('smart_jit_disable_jit_for_bots', '1') === '1';
        $this->disable_jit_for_speed_tests = get_option('smart_jit_disable_jit_for_speed_tests', '1') === '1'; // ПО УМОЛЧАНИЮ ВКЛЮЧЕНО
        $this->disable_page_cache_for_speed_tests = get_option('smart_jit_disable_page_cache_for_speed_tests', '0') === '1';
        $this->disable_object_cache_for_speed_tests = get_option('smart_jit_disable_object_cache_for_speed_tests', '0') === '1';
        $this->disable_browser_cache_for_speed_tests = get_option('smart_jit_disable_browser_cache_for_speed_tests', '1') === '1';
    }
    
    public function detect_user_type() {
        if (is_admin()) {
            return;
        }
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->is_bot = $this->is_search_bot($user_agent);
        $this->is_speed_test_bot = $this->is_speed_test_bot($user_agent);
        
        if (!$this->is_bot) {
            $this->is_bot = $this->is_search_engine_ip();
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
            // Поисковые боты
            '/googlebot/i', '/yandex/i', '/bingbot/i',
            '/baiduspider/i', '/duckduckbot/i',
            
            // Социальные сети
            '/facebookexternalhit/i', '/pinterest/i', '/vk/i', 
            '/twitterbot/i', '/linkedinbot/i', '/flipboard/i',
            
            // SEO-инструменты
            '/ahrefsbot/i', '/semrushbot/i', '/mj12bot/i',
            '/dotbot/i', '/megaindex/i', '/mail\.ru/i',
            '/petalbot/i', '/zoominfobot/i', '/serpstatbot/i',
            '/exabot/i', '/facebot/i'
        ];
        
        foreach ($bots_patterns as $pattern) {
            if (preg_match($pattern, $user_agent)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function is_speed_test_bot($user_agent = '') {
        if (empty($user_agent)) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        }
        
        $speed_test_patterns = [
            '/pagespeed/i', '/lighthouse/i', '/gtmetrix/i',
            '/pingdom/i', '/webpagetest/i', '/speedcurve/i',
            '/catchpoint/i', '/speedtest/i', '/yellowlab/i',
            '/webpagetest/i', '/sitespeed/i', '/calibre/i',
            '/speedtracker/i', '/treo/i', '/perfbot/i'
        ];
        
        foreach ($speed_test_patterns as $pattern) {
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
            // Google (PageSpeed Insights, Lighthouse)
            '66.249.64.0/19', '64.233.160.0/19', '72.14.192.0/18',
            '74.125.0.0/16', '108.177.8.0/21',
            
            // GTmetrix
            '104.219.234.0/24', '45.79.0.0/16', '45.56.0.0/18',
            
            // Pingdom
            '178.255.152.0/24', '159.253.140.0/24', '185.39.146.0/24',
            
            // WebPageTest
            '23.235.32.0/20', '43.249.72.0/22', '103.4.116.0/22',
            
            // SpeedCurve
            '54.252.0.0/16', '52.62.0.0/15',
            
            // Catchpoint
            '69.25.88.0/22', '208.71.106.0/24',
            
            // Стандартные поисковые системы
            '77.88.0.0/18', '207.46.0.0/16', '157.55.0.0/16',
            '54.236.0.0/16', '87.240.128.0/18', '54.84.0.0/16',
            
            // Социальные сети
            '31.13.0.0/16', // Facebook
            '199.16.156.0/22', // Twitter
            '108.174.0.0/16' // LinkedIn
        ];
        
        foreach ($search_engine_ips as $cidr) {
            if ($this->ip_in_range($client_ip, $cidr)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function ip_in_range($ip, $cidr) {
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }
        
        list($subnet, $mask) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        
        if ($ip === false || $subnet === false) {
            return false;
        }
        
        $mask = -1 << (32 - (int)$mask);
        $subnet &= $mask;
        
        return ($ip & $mask) == $subnet;
    }
    
    public function maybe_disable_caching_plugins() {
        if (!$this->is_speed_test_bot || is_admin()) {
            return;
        }
        
        // Отключаем плагины кеширования только если включена соответствующая опция
        if ($this->disable_page_cache_for_speed_tests) {
            add_filter('wp_super_cache_eanbled', '__return_false');
            add_filter('w3tc_late_caching_content', '__return_false');
            add_filter('w3tc_can_cache', '__return_false');
            add_filter('rocket_htaccess_mod_rewrite', '__return_false');
            
            // Отключаем кеширование в WP Rocket
            if (defined('WP_ROCKET_VERSION')) {
                add_filter('do_rocket_generate_caching_files', '__return_false');
                add_filter('rocket_display_varnish_options_tab', '__return_false');
            }
            
            // Отключаем кеширование в W3 Total Cache
            if (defined('W3TC_VERSION')) {
                define('DONOTCACHEPAGE', true);
                define('DONOTCDN', true);
                define('DONOTCACHCEOBJECT', true);
            }
            
            // Отключаем кеширование в WP Super Cache
            if (defined('WP_CACHE') && WP_CACHE) {
                define('DONOTCACHEPAGE', true);
            }
        }
        
        // Отключаем оптимизацию только если отключено кеширование страниц
        if ($this->disable_page_cache_for_speed_tests) {
            add_filter('autoptimize_filter_noptimize', '__return_true');
        }
        
        error_log('[SmartJIT] Caching plugins disabled with selective settings');
    }
    
    public function add_no_cache_headers() {
        if (!$this->is_speed_test_bot || is_admin() || !$this->disable_browser_cache_for_speed_tests) {
            return;
        }
        
        // Убираем стандартные заголовки кеширования
        header_remove('Cache-Control');
        header_remove('Expires');
        header_remove('Pragma');
        header_remove('ETag');
        header_remove('Last-Modified');
        
        // Устанавливаем заголовки против кеширования
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Cache-Enabled: false');
        header('X-Accel-Expires: 0');
        
        error_log('[SmartJIT] No-cache headers set for speed test');
    }
    
    public function modify_response_headers($headers) {
        if (!$this->is_speed_test_bot || is_admin() || !$this->disable_browser_cache_for_speed_tests) {
            return $headers;
        }
        
        // Модифицируем заголовки для отключения кеширования
        $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate, max-age=0';
        $headers['Pragma'] = 'no-cache';
        $headers['Expires'] = '0';
        $headers['X-Cache-Enabled'] = 'false';
        
        return $headers;
    }
    
    public function manage_jit_settings() {
        if (is_admin() || !$this->jit_configurable) {
            return;
        }
        
        // Для новых постов временно отключаем JIT для предотвращения 404
        if ($this->is_recent_post_modified()) {
            $this->set_safe_mode_temporarily();
            return;
        }
        
        if ($this->is_speed_test_bot) {
            $this->set_speed_test_settings();
        } elseif ($this->is_bot) {
            $this->set_bot_safe_settings();
        } else {
            $this->set_human_optimized_settings();
        }
    }
    
    private function is_recent_post_modified() {
        if (empty($this->last_post_modified)) {
            return false;
        }
        
        $time_diff = current_time('timestamp') - $this->last_post_modified;
        
        // Считаем "недавним" изменение в последние 30 минут
        return $time_diff < (30 * 60);
    }
    
    private function set_safe_mode_temporarily() {
        // Временное безопасное решение для новых постов
        @ini_set('opcache.jit', '1255');
        @ini_set('opcache.revalidate_freq', '1');
        @ini_set('opcache.validate_timestamps', '1');
        
        error_log('[SmartJIT] Temporary safe mode activated for recent post changes');
    }
    
    private function set_speed_test_settings() {
        if ($this->disable_jit_for_speed_tests) {
            // 🚫 ОТКЛЮЧАЕМ JIT ДЛЯ ТЕСТОВ СКОРОСТИ (убираем задержку 2 секунды)
            @ini_set('opcache.jit', 'disable');
            @ini_set('opcache.enable', '0');
            error_log('[SmartJIT] JIT DISABLED for speed tests to remove 2-second delay');
        } else {
            // 📊 МАКСИМАЛЬНАЯ ПРОИЗВОДИТЕЛЬНОСТЬ для тестов скорости
            @ini_set('opcache.jit', '1235');
            @ini_set('opcache.revalidate_freq', '0'); // Минимальная задержка
            @ini_set('opcache.validate_timestamps', '1'); // Всегда свежий контент
            @ini_set('opcache.enable', '1');
            @ini_set('opcache.enable_cli', '0');
            
            // Оптимальные лимиты для максимальной скорости
            @ini_set('opcache.jit_max_trace_points', '15000');
            @ini_set('opcache.jit_max_polymorphic_calls', '6000');
            @ini_set('opcache.jit_max_loop_unrolls', '10');
            @ini_set('opcache.jit_hot_func', '150');
            @ini_set('opcache.jit_hot_loop', '150');
            
            error_log('[SmartJIT] Maximum performance mode for speed test bot');
        }
        
        // Отключаем ограничения для тестов (всегда)
        @ini_set('max_execution_time', '300');
        @ini_set('max_input_time', '300');
        @ini_set('memory_limit', '512M');
    }
    
    private function set_bot_safe_settings() {
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // Критические боты - отключаем JIT если включена опция
        $critical_bots = ['googlebot', 'yandex', 'bingbot'];
        $is_critical_bot = false;
        
        foreach ($critical_bots as $bot) {
            if (strpos($user_agent, $bot) !== false) {
                $is_critical_bot = true;
                break;
            }
        }
        
        if ($is_critical_bot && $this->disable_jit_for_bots) {
            @ini_set('opcache.jit', 'disable');
            @ini_set('opcache.enable', '0');
            error_log('[SmartJIT] JIT disabled for critical bot: ' . $user_agent);
        } else {
            @ini_set('opcache.jit', '1255');
            $this->set_optimized_limits();
        }
    }
    
    private function set_human_optimized_settings() {
        @ini_set('opcache.jit', '1235');
        @ini_set('opcache.revalidate_freq', '2');
        @ini_set('opcache.validate_timestamps', '1');
        @ini_set('opcache.enable_file_override', '1');
        $this->set_optimized_limits();
    }
    
    private function set_optimized_limits() {
        @ini_set('opcache.jit_max_trace_points', '12000');
        @ini_set('opcache.jit_max_polymorphic_calls', '5000');
        @ini_set('opcache.jit_max_loop_unrolls', '8');
        @ini_set('opcache.jit_max_recursive_returns', '10');
        @ini_set('opcache.jit_hot_func', '100');
        @ini_set('opcache.jit_hot_loop', '100');
        @ini_set('opcache.jit_hot_return', '100');
        @ini_set('opcache.jit_max_exit', '50');
    }
    
    public function on_post_save($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Обновляем время последнего изменения
        update_option('smart_jit_last_post_modified', current_time('timestamp'));
        $this->last_post_modified = get_option('smart_jit_last_post_modified');
        
        // Для новых постов - агрессивная очистка кеша
        if (!$update) {
            $this->aggressive_cache_clear();
        }
    }
    
    public function on_post_delete($post_id) {
        update_option('smart_jit_last_post_modified', current_time('timestamp'));
        $this->force_cache_clear($post_id);
    }
    
    public function on_post_trash($post_id) {
        update_option('smart_jit_last_post_modified', current_time('timestamp'));
        $this->force_cache_clear($post_id);
    }
    
    public function force_cache_clear($post_id, $post = null) {
        // 1. Сбрасываем rewrite rules (критически важно!)
        $this->flush_rewrite_rules_safe();
        
        // 2. Пытаемся очистить Opcache (если доступно)
        $opcache_cleared = $this->safe_opcache_clear();
        
        // 3. Очищаем объектный кеш (если включена опция)
        $object_cache_cleared = false;
        if ($this->disable_object_cache_for_speed_tests) {
            $object_cache_cleared = $this->clear_object_cache();
        }
        
        // Логируем результат
        error_log(sprintf(
            '[SmartJIT] Cache clear after post %d: rewrite_rules=1, opcache=%s, object_cache=%s',
            $post_id,
            $opcache_cleared ? 'yes' : 'no',
            $object_cache_cleared ? 'yes' : 'no'
        ));
    }
    
    private function flush_rewrite_rules_safe() {
        // Безопасное сброс rewrite rules
        try {
            flush_rewrite_rules(false);
            error_log('[SmartJIT] Rewrite rules flushed successfully');
            return true;
        } catch (Exception $e) {
            error_log('[SmartJIT] Error flushing rewrite rules: ' . $e->getMessage());
            return false;
        }
    }
    
    private function safe_opcache_clear() {
        // Безопасная очистка Opcache с проверкой прав
        if (!function_exists('opcache_reset')) {
            return false;
        }
        
        if ($this->opcache_restricted) {
            error_log('[SmartJIT] Opcache reset restricted by opcache.restrict_api');
            return false;
        }
        
        try {
            $result = opcache_reset();
            if ($result) {
                error_log('[SmartJIT] Opcache reset successfully');
                usleep(100000);
                return true;
            } else {
                error_log('[SmartJIT] Opcache reset failed');
                return false;
            }
        } catch (Exception $e) {
            error_log('[SmartJIT] Opcache reset error: ' . $e->getMessage());
            return false;
        }
    }
    
    private function clear_object_cache() {
        // Очистка различных типов объектного кеша
        $cleared = false;
        
        // WordPress Object Cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $cleared = true;
        }
        
        // APCu
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
            $cleared = true;
        }
        
        // APCu (новое API)
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
            $cleared = true;
        }
        
        if ($cleared) {
            error_log('[SmartJIT] Object cache cleared');
        }
        
        return $cleared;
    }
    
    private function aggressive_cache_clear() {
        // Агрессивная очистка для новых постов
        error_log('[SmartJIT] Starting aggressive cache clear for new post');
        
        // Многократная очистка rewrite rules
        for ($i = 0; $i < 2; $i++) {
            $this->flush_rewrite_rules_safe();
            usleep(50000);
        }
        
        // Попытка очистки Opcache
        if (!$this->opcache_restricted) {
            for ($i = 0; $i < 3; $i++) {
                $this->safe_opcache_clear();
                usleep(30000);
            }
        }
        
        // Очистка объектного кеша (если включена)
        if ($this->disable_object_cache_for_speed_tests) {
            $this->clear_object_cache();
        }
        
        error_log('[SmartJIT] Aggressive cache clear completed');
    }
    
    public function fix_404_early() {
        // Ранняя обработка 404 ошибок для исправления проблем с JIT
        if (!is_404()) {
            return;
        }
        
        $requested_url = $_SERVER['REQUEST_URI'] ?? '';
        $slug = basename(trim($requested_url, '/'));
        
        if (empty($slug)) {
            return;
        }
        
        // Проверяем, существует ли пост с таким slug
        $post = get_page_by_path($slug, OBJECT, ['post', 'page']);
        
        if ($post && $post->post_status === 'publish') {
            // Если пост существует, но выдается 404 - это проблема кеширования
            error_log('[SmartJIT] 404 fix triggered for existing post: ' . $slug);
            
            // Пытаемся очистить кеш и исправить проблему
            $this->force_cache_clear($post->ID);
            
            // Даем серверу время на обработку
            usleep(50000);
            
            // Перенаправляем на корректный URL
            wp_redirect(get_permalink($post->ID), 302);
            exit;
        }
    }
    
    public function add_debug_headers() {
        if (headers_sent() || is_admin()) {
            return;
        }
        
        $jit_mode = ini_get('opcache.jit');
        $buffer_size = ini_get('opcache.jit_buffer_size');
        $opcache_enabled = ini_get('opcache.enable');
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $mode = 'UNKNOWN';
        $bot_type = 'human';
        $speed_test = 'no';
        $cache_enabled = 'yes';
        $cache_settings = 'default';
        $jit_disabled_for_speed = 'no';
        
        if ($this->is_speed_test_bot) {
            $bot_type = 'speed-test';
            $speed_test = 'yes';
            $jit_disabled_for_speed = $this->disable_jit_for_speed_tests ? 'yes' : 'no';
            
            // Определяем настройки кэширования для тестов скорости
            $cache_settings = [];
            if ($this->disable_page_cache_for_speed_tests) $cache_settings[] = 'no-page-cache';
            if ($this->disable_object_cache_for_speed_tests) $cache_settings[] = 'no-object-cache';
            if ($this->disable_browser_cache_for_speed_tests) $cache_settings[] = 'no-browser-cache';
            $cache_settings = empty($cache_settings) ? 'full-cache' : implode(',', $cache_settings);
            
            $cache_enabled = $cache_settings === 'full-cache' ? 'yes' : 'partial';
            $mode = $this->disable_jit_for_speed_tests ? 'JIT-DISABLED-for-speed' : 'MAX-PERFORMANCE';
        } elseif ($this->is_bot) {
            $critical_bots = ['googlebot', 'yandex', 'bingbot'];
            $user_agent_lower = strtolower($user_agent);
            $is_critical = false;
            
            foreach ($critical_bots as $bot) {
                if (strpos($user_agent_lower, $bot) !== false) {
                    $is_critical = true;
                    $bot_type = $bot;
                    break;
                }
            }
            
            if ($is_critical && $this->disable_jit_for_bots) {
                $mode = 'DISABLED-for-critical-bot';
                $cache_enabled = 'jit-disabled';
            } else {
                $mode = 'SAFE-for-bot';
                if (stripos($user_agent, 'pinterest') !== false) $bot_type = 'pinterest';
                elseif (stripos($user_agent, 'vk') !== false) $bot_type = 'vk';
                elseif (stripos($user_agent, 'facebook') !== false) $bot_type = 'facebook';
                else $bot_type = 'other-bot';
            }
        } else {
            $mode = $this->safe_mode ? 'BALANCED' : 'TURBO';
        }
        
        header('X-JIT-Mode: ' . $mode);
        header('X-JIT-Buffer: ' . $buffer_size);
        header('X-JIT-Actual: ' . $jit_mode);
        header('X-OPCache-Enabled: ' . $opcache_enabled);
        header('X-JIT-Configurable: ' . ($this->jit_configurable ? 'yes' : 'no'));
        header('X-OPCache-Restricted: ' . ($this->opcache_restricted ? 'yes' : 'no'));
        header('X-Speed-Test-Bot: ' . $speed_test);
        header('X-Cache-Enabled: ' . $cache_enabled);
        header('X-Cache-Settings: ' . $cache_settings);
        header('X-Detected-Bot: ' . $bot_type);
        header('X-JIT-Disabled-For-Bots: ' . ($this->disable_jit_for_bots ? 'yes' : 'no'));
        header('X-JIT-Disabled-For-Speed-Tests: ' . $jit_disabled_for_speed);
    }
    
    public function show_buffer_warning() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'settings_page_smart-jit-manager') {
            return;
        }
        
        $recommended_size = 100;
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
        if (empty($size)) return 0;
        
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
        register_setting('smart_jit_settings', 'smart_jit_disable_jit_for_bots');
        register_setting('smart_jit_settings', 'smart_jit_disable_jit_for_speed_tests'); // НОВАЯ ОПЦИЯ
        register_setting('smart_jit_settings', 'smart_jit_disable_page_cache_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_object_cache_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_browser_cache_for_speed_tests');
        
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
            'cache_settings',
            'Настройки отключения кэширования',
            [$this, 'cache_settings_callback'],
            'smart_jit_settings',
            'smart_jit_section'
        );
    }
    
    public function section_callback() {
        echo '<div class="notice notice-info">';
        echo '<p><strong>Информация:</strong> Основные настройки JIT задаются в php.ini.</p>';
        echo '<p>Текущий размер буфера: <strong>' . esc_html($this->current_jit_buffer) . '</strong></p>';
        echo '<p>JIT конфигурируем: <strong>' . ($this->jit_configurable ? 'Да' : 'Нет') . '</strong></p>';
        echo '<p>Opcache ограничен: <strong>' . ($this->opcache_restricted ? 'Да' : 'Нет') . '</strong></p>';
        echo '<p>Последнее изменение поста: <strong>' . ($this->last_post_modified ? date('Y-m-d H:i:s', $this->last_post_modified) : 'Никогда') . '</strong></p>';
        echo '</div>';
    }
    
    public function safe_mode_callback() {
        $safe_mode = get_option('smart_jit_safe_mode', '1');
        ?>
        <label>
            <input type="radio" name="smart_jit_safe_mode" value="1" <?php checked($safe_mode, '1'); ?>>
            🟢 Безопасный режим (1235) - оптимальный баланс для WordPress
        </label>
        <br>
        <label>
            <input type="radio" name="smart_jit_safe_mode" value="0" <?php checked($safe_mode, '0'); ?>>
            🟡 Экспертный режим (1235) - максимальная производительность
        </label>
        <p class="description">Оба режима используют оптимальные настройки 1235. Для ботов применяются специальные правила.</p>
        <?php
    }
    
    public function cache_settings_callback() {
        $disable_jit_for_bots = get_option('smart_jit_disable_jit_for_bots', '1');
        $disable_jit_for_speed_tests = get_option('smart_jit_disable_jit_for_speed_tests', '1'); // ПО УМОЛЧАНИЮ ВКЛЮЧЕНО
        $disable_page_cache = get_option('smart_jit_disable_page_cache_for_speed_tests', '0');
        $disable_object_cache = get_option('smart_jit_disable_object_cache_for_speed_tests', '0');
        $disable_browser_cache = get_option('smart_jit_disable_browser_cache_for_speed_tests', '1');
        ?>
        
        <h4>Для поисковых ботов (Google, Yandex, Bing):</h4>
        <label>
            <input type="checkbox" name="smart_jit_disable_jit_for_bots" value="1" <?php checked($disable_jit_for_bots, '1'); ?>>
            Отключать JIT для критических поисковых ботов
        </label>
        <p class="description">Рекомендуется для стабильности индексации</p>
        
        <h4>Для тестов скорости (PageSpeed, GTmetrix, Pingdom):</h4>
        <label>
            <input type="checkbox" name="smart_jit_disable_jit_for_speed_tests" value="1" <?php checked($disable_jit_for_speed_tests, '1'); ?>>
            <strong>🚫 ОТКЛЮЧАТЬ JIT для тестов скорости (убирает задержку 2 секунды)</strong>
        </label>
        <p class="description"><strong>ВАЖНО:</strong> JIT может добавлять задержку при первом запуске. Отключение ускоряет тесты.</p>
        
        <label>
            <input type="checkbox" name="smart_jit_disable_page_cache_for_speed_tests" value="1" <?php checked($disable_page_cache, '1'); ?>>
            Отключать кэширование страниц (плагины кэширования)
        </label>
        <br>
        <label>
            <input type="checkbox" name="smart_jit_disable_object_cache_for_speed_tests" value="1" <?php checked($disable_object_cache, '1'); ?>>
            Отключать объектный кэш (Redis, Memcached, APCu)
        </label>
        <br>
        <label>
            <input type="checkbox" name="smart_jit_disable_browser_cache_for_speed_tests" value="1" <?php checked($disable_browser_cache, '1'); ?>>
            Отключать браузерное кэширование (Cache-Control headers)
        </label>
        <p class="description">Настройте, какие типы кэширования отключать при тестировании скорости</p>
        <?php
    }
    
    public function options_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Умное управление JIT v1.2.1</h1>
            
            <div class="card">
                <h2>🚀 Выборочное отключение кэширования</h2>
                <p><strong>Статус:</strong> <?php echo $this->is_recent_post_modified() ? '🟡 Активен безопасный режим' : '🟢 Нормальный режим'; ?></p>
                <p><strong>Opcache доступен:</strong> <?php echo $this->opcache_restricted ? '❌ Ограничен' : '✅ Доступен'; ?></p>
                <p><strong>Отключение JIT для ботов:</strong> <?php echo $this->disable_jit_for_bots ? '✅ Включено' : '❌ Выключено'; ?></p>
                <p><strong>Отключение JIT для тестов скорости:</strong> <?php echo $this->disable_jit_for_speed_tests ? '✅ Включено' : '❌ Выключено'; ?></p>
                <p><strong>Отключение кэша страниц:</strong> <?php echo $this->disable_page_cache_for_speed_tests ? '✅ Включено' : '❌ Выключено'; ?></p>
                <p><strong>Отключение объектного кэша:</strong> <?php echo $this->disable_object_cache_for_speed_tests ? '✅ Включено' : '❌ Выключено'; ?></p>
                <p><strong>Отключение браузерного кэша:</strong> <?php echo $this->disable_browser_cache_for_speed_tests ? '✅ Включено' : '❌ Выключено'; ?></p>
            </div>
            
            <div class="card" style="border-left-color: #ff6b6b;">
                <h2>⚠️ Важная информация о JIT и тестах скорости</h2>
                <p><strong>Проблема:</strong> JIT компиляция может добавлять задержку до 2 секунд при первом запуске.</p>
                <p><strong>Решение:</strong> Отключение JIT для тестов скорости устраняет эту задержку и показывает реальную производительность.</p>
                <p><strong>Рекомендация:</strong> Оставьте включенным "Отключать JIT для тестов скорости" для точных замеров.</p>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('smart_jit_settings');
                do_settings_sections('smart_jit_settings');
                submit_button('Сохранить настройки');
                ?>
            </form>
            
            <div class="card">
                <h3>📊 Текущие настройки JIT</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Параметр</th>
                            <th>Значение</th>
                            <th>Описание</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>opcache.jit</code></td>
                            <td><strong><?php echo esc_html(ini_get('opcache.jit')); ?></strong></td>
                            <td>Режим JIT компиляции</td>
                        </tr>
                        <tr>
                            <td><code>opcache.jit_buffer_size</code></td>
                            <td><strong><?php echo esc_html(ini_get('opcache.jit_buffer_size')); ?></strong></td>
                            <td>Размер буфера для JIT кода</td>
                        </tr>
                        <tr>
                            <td><code>opcache.enable</code></td>
                            <td><strong><?php echo esc_html(ini_get('opcache.enable')); ?></strong></td>
                            <td>Включен ли Opcache</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-left: 4px solid #0073aa;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        </style>
        <?php
    }
    
    public function add_admin_bar_status($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $jit_mode = ini_get('opcache.jit');
        $jit_buffer = ini_get('opcache.jit_buffer_size');
        $opcache_enabled = ini_get('opcache.enable');
        
        $title = 'JIT: ';
        
        if ($opcache_enabled !== '1') {
            $title .= '❌ Off';
        } elseif ($jit_mode === 'disable' || empty($jit_mode)) {
            $title .= '🚫 Disabled';
        } else {
            $title .= '🚀 ' . $jit_mode;
        }
        
        // Добавляем информацию о настройках скорости
        if ($this->disable_jit_for_speed_tests) {
            $title .= ' | ⚡ No-JIT for tests';
        }
        
        $wp_admin_bar->add_node([
            'id' => 'jit-status',
            'title' => $title,
            'href' => admin_url('options-general.php?page=smart-jit-manager'),
            'meta' => [
                'title' => 'JIT Buffer: ' . $jit_buffer . ' | Opcache: ' . ($opcache_enabled ? 'On' : 'Off')
            ]
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'jit-settings',
            'parent' => 'jit-status',
            'title' => '⚙️ Настройки JIT',
            'href' => admin_url('options-general.php?page=smart-jit-manager')
        ]);
        
        $wp_admin_bar->add_node([
            'id' => 'jit-speed-settings',
            'parent' => 'jit-status',
            'title' => '📊 Тесты скорости: ' . 
                ($this->disable_jit_for_speed_tests ? 'JIT отключен' : 'JIT включен'),
            'href' => admin_url('options-general.php?page=smart-jit-manager#cache-settings')
        ]);
    }
}

// Инициализация плагина
new SmartJITManager();

// Хук активации плагина
register_activation_hook(__FILE__, function() {
    add_option('smart_jit_safe_mode', '1');
    add_option('smart_jit_disable_jit_for_bots', '1');
    add_option('smart_jit_disable_jit_for_speed_tests', '1'); // ПО УМОЛЧАНИЮ ВКЛЮЧЕНО
    add_option('smart_jit_disable_page_cache_for_speed_tests', '0');
    add_option('smart_jit_disable_object_cache_for_speed_tests', '0');
    add_option('smart_jit_disable_browser_cache_for_speed_tests', '1');
    add_option('smart_jit_last_post_modified', '');
});

// Хук деактивации
register_deactivation_hook(__FILE__, function() {
    // Восстанавливаем стандартные настройки при деактивации
    if (function_exists('ini_set')) {
        @ini_restore('opcache.jit');
        @ini_restore('opcache.revalidate_freq');
        @ini_restore('opcache.validate_timestamps');
    }
});
?>

new SmartJITManager();


