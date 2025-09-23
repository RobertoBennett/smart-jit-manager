<?php
/**
 * Plugin Name: Smart JIT Manager
 * Description: Умное управление OPcache JIT для WordPress. Баланс скорости и стабильности.
 * Plugin URI: https://github.com/RobertoBennett/smart-jit-manager
 * Version: 1.2.2
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
    private $log_enabled = true;
    
    // НАСТРОЙКИ ОТКЛЮЧЕНИЯ КЭШИРОВАНИЯ
    private $disable_jit_for_bots = true;
    private $disable_jit_for_speed_tests = true;
    private $disable_page_cache_for_speed_tests = false;
    private $disable_object_cache_for_speed_tests = false;
    private $disable_browser_cache_for_speed_tests = true;
    private $disable_optimization_for_speed_tests = false;
    
    public function __construct() {
        $this->init();
    }
    
    public function init() {
        $this->current_jit_buffer = ini_get('opcache.jit_buffer_size');
        $this->jit_configurable = $this->check_jit_configurability();
        $this->opcache_restricted = $this->check_opcache_restrictions();
        $this->last_post_modified = get_option('smart_jit_last_post_modified', '');
        $this->log_enabled = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
        
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
        
        // WP-CLI поддержка
        if (defined('WP_CLI') && WP_CLI) {
            $this->register_wp_cli_commands();
        }
    }
    
    private function log($message) {
        if ($this->log_enabled) {
            error_log('[SmartJIT] ' . $message);
        }
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
        $this->disable_jit_for_speed_tests = get_option('smart_jit_disable_jit_for_speed_tests', '1') === '1';
        $this->disable_page_cache_for_speed_tests = get_option('smart_jit_disable_page_cache_for_speed_tests', '0') === '1';
        $this->disable_object_cache_for_speed_tests = get_option('smart_jit_disable_object_cache_for_speed_tests', '0') === '1';
        $this->disable_browser_cache_for_speed_tests = get_option('smart_jit_disable_browser_cache_for_speed_tests', '1') === '1';
        $this->disable_optimization_for_speed_tests = get_option('smart_jit_disable_optimization_for_speed_tests', '0') === '1';
    }
    
    public function detect_user_type() {
        if (is_admin()) {
            return;
        }
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->is_bot = $this->is_search_bot($user_agent);
        $this->is_speed_test_bot = $this->check_speed_test_bot($user_agent);
        
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
    
    public function check_speed_test_bot($user_agent = '') {
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
        
        // Проверяем через DNS для точного определения
        if ($this->verify_bot_by_dns($client_ip)) {
            return true;
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
    
    private function verify_bot_by_dns($ip) {
        if (!function_exists('gethostbyaddr')) {
            return false;
        }
        
        try {
            $hostname = gethostbyaddr($ip);
            if ($hostname === $ip) {
                return false;
            }
            
            $bot_domains = [
                'googlebot.com', 'google.com',
                'yandex.ru', 'yandex.net', 'yandex.com',
                'bing.com', 'search.msn.com',
                'baidu.com', 'facebook.com', 'twitter.com'
            ];
            
            foreach ($bot_domains as $domain) {
                if (strpos($hostname, $domain) !== false) {
                    $this->log("Bot verified by DNS: {$ip} -> {$hostname}");
                    return true;
                }
            }
        } catch (Exception $e) {
            // Ошибка DNS - пропускаем проверку
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
            // ИСПРАВЛЕНА ОПЕЧАТКА: wp_super_cache_eanbled -> wp_super_cache_enabled
            add_filter('wp_super_cache_enabled', '__return_false');
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
                // ИСПРАВЛЕНА ОПЕЧАТКА: DONOTCACHCEOBJECT -> DONOTCACHEOBJECT
                define('DONOTCACHEOBJECT', true);
            }
            
            // Отключаем кеширование в WP Super Cache
            if (defined('WP_CACHE') && WP_CACHE) {
                define('DONOTCACHEPAGE', true);
            }
        }
        
        // Отключаем оптимизацию только если включена соответствующая опция
        if ($this->disable_optimization_for_speed_tests) {
            add_filter('autoptimize_filter_noptimize', '__return_true');
        }
        
        $this->log('Caching plugins disabled with selective settings');
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
        
        $this->log('No-cache headers set for speed test');
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
        
        $this->log('Temporary safe mode activated for recent post changes');
    }
    
    private function set_speed_test_settings() {
        if ($this->disable_jit_for_speed_tests) {
            // 🚫 ОТКЛЮЧАЕМ JIT ДЛЯ ТЕСТОВ СКОРОСТИ (убираем задержку 2 секунды)
            @ini_set('opcache.jit', 'disable');
            @ini_set('opcache.enable', '0');
            $this->log('JIT DISABLED for speed tests to remove 2-second delay');
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
            
            $this->log('Maximum performance mode for speed test bot');
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
            $this->log('JIT disabled for critical bot: ' . $user_agent);
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
        $this->log(sprintf(
            'Cache clear after post %d: rewrite_rules=1, opcache=%s, object_cache=%s',
            $post_id,
            $opcache_cleared ? 'yes' : 'no',
            $object_cache_cleared ? 'yes' : 'no'
        ));
    }
    
    private function flush_rewrite_rules_safe() {
        // Безопасное сброс rewrite rules
        try {
            flush_rewrite_rules(false);
            $this->log('Rewrite rules flushed successfully');
            return true;
        } catch (Exception $e) {
            $this->log('Error flushing rewrite rules: ' . $e->getMessage());
            return false;
        }
    }
    
    private function safe_opcache_clear() {
        // Безопасная очистка Opcache с проверкой прав
        if (!function_exists('opcache_reset')) {
            return false;
        }
        
        if ($this->opcache_restricted) {
            $this->log('Opcache reset restricted by opcache.restrict_api');
            return false;
        }
        
        try {
            $result = opcache_reset();
            if ($result) {
                $this->log('Opcache reset successfully');
                usleep(100000);
                return true;
            } else {
                $this->log('Opcache reset failed');
                return false;
            }
        } catch (Exception $e) {
            $this->log('Opcache reset error: ' . $e->getMessage());
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
            $this->log('Object cache cleared');
        }
        
        return $cleared;
    }
    
    private function aggressive_cache_clear() {
        // Агрессивная очистка для новых постов
        $this->log('Starting aggressive cache clear for new post');
        
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
        
        $this->log('Aggressive cache clear completed');
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
            $this->log('404 fix triggered for existing post: ' . $slug);
            
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
        $opcache_reset_count = get_option('smart_jit_opcache_reset_count', 0);
        
        if ($this->is_speed_test_bot) {
            $bot_type = 'speed-test';
            $speed_test = 'yes';
            $jit_disabled_for_speed = $this->disable_jit_for_speed_tests ? 'yes' : 'no';
            
            // Определяем настройки кэширования для тестов скорости
            $cache_settings = [];
            if ($this->disable_page_cache_for_speed_tests) $cache_settings[] = 'no-page-cache';
            if ($this->disable_object_cache_for_speed_tests) $cache_settings[] = 'no-object-cache';
            if ($this->disable_browser_cache_for_speed_tests) $cache_settings[] = 'no-browser-cache';
            if ($this->disable_optimization_for_speed_tests) $cache_settings[] = 'no-optimization';
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
        header('X-Safe-Mode: ' . ($this->safe_mode ? 'yes' : 'no'));
        header('X-Last-Post-Modified: ' . ($this->last_post_modified ? date('Y-m-d H:i:s', $this->last_post_modified) : 'never'));
        header('X-OPCache-Reset-Count: ' . $opcache_reset_count);
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
        register_setting('smart_jit_settings', 'smart_jit_disable_jit_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_page_cache_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_object_cache_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_browser_cache_for_speed_tests');
        register_setting('smart_jit_settings', 'smart_jit_disable_optimization_for_speed_tests');
        
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
        $opcache_reset_count = get_option('smart_jit_opcache_reset_count', 0);
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>Информация:</strong> Основные настройки JIT задаются в php.ini.</p>';
        echo '<p>Текущий размер буфера: <strong>' . esc_html($this->current_jit_buffer) . '</strong></p>';
        echo '<p>JIT конфигурируем: <strong>' . ($this->jit_configurable ? 'Да' : 'Нет') . '</strong></p>';
        echo '<p>Opcache ограничен: <strong>' . ($this->opcache_restricted ? 'Да' : 'Нет') . '</strong></p>';
        echo '<p>Последнее изменение поста: <strong>' . ($this->last_post_modified ? date('Y-m-d H:i:s', $this->last_post_modified) : 'Никогда') . '</strong></p>';
        echo '<p>Сбросов Opcache: <strong>' . $opcache_reset_count . '</strong></p>';
        echo '</div>';
    }
    
    public function safe_mode_callback() {
        $safe_mode = get_option('smart_jit_safe_mode', '1');
        $disable_jit_for_bots = get_option('smart_jit_disable_jit_for_bots', '1');
        $disable_jit_for_speed_tests = get_option('smart_jit_disable_jit_for_speed_tests', '1');
        
        echo '<fieldset>';
        echo '<label><input type="radio" name="smart_jit_safe_mode" value="1" ' . checked('1', $safe_mode, false) . '> ';
        echo 'Безопасный режим (рекомендуется)</label><br>';
        echo '<label><input type="radio" name="smart_jit_safe_mode" value="0" ' . checked('0', $safe_mode, false) . '> ';
        echo 'Турбо режим (только для тестирования)</label>';
        echo '</fieldset>';
        
        echo '<br><fieldset>';
        echo '<label><input type="checkbox" name="smart_jit_disable_jit_for_bots" value="1" ' . checked('1', $disable_jit_for_bots, false) . '> ';
        echo 'Отключать JIT для поисковых ботов</label><br>';
        echo '<label><input type="checkbox" name="smart_jit_disable_jit_for_speed_tests" value="1" ' . checked('1', $disable_jit_for_speed_tests, false) . '> ';
        echo 'Отключать JIT для тестов скорости (убирает задержку 2 секунды)</label>';
        echo '</fieldset>';
    }
    
    public function cache_settings_callback() {
        $disable_page_cache = get_option('smart_jit_disable_page_cache_for_speed_tests', '0');
        $disable_object_cache = get_option('smart_jit_disable_object_cache_for_speed_tests', '0');
        $disable_browser_cache = get_option('smart_jit_disable_browser_cache_for_speed_tests', '1');
        $disable_optimization = get_option('smart_jit_disable_optimization_for_speed_tests', '0');
        
        echo '<p><strong>Настройки отключения для тестов скорости:</strong></p>';
        echo '<fieldset style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">';
        echo '<label><input type="checkbox" name="smart_jit_disable_page_cache_for_speed_tests" value="1" ' . checked('1', $disable_page_cache, false) . '> ';
        echo 'Отключать кэш страниц (WP Super Cache, W3TC, WP Rocket)</label>';
        
        echo '<label><input type="checkbox" name="smart_jit_disable_object_cache_for_speed_tests" value="1" ' . checked('1', $disable_object_cache, false) . '> ';
        echo 'Отключать объектный кэш (Memcached, Redis)</label>';
        
        echo '<label><input type="checkbox" name="smart_jit_disable_browser_cache_for_speed_tests" value="1" ' . checked('1', $disable_browser_cache, false) . '> ';
        echo 'Отключать браузерный кэш (Cache-Control headers)</label>';
        
        echo '<label><input type="checkbox" name="smart_jit_disable_optimization_for_speed_tests" value="1" ' . checked('1', $disable_optimization, false) . '> ';
        echo 'Отключать оптимизацию (Autoptimize)</label>';
        echo '</fieldset>';
    }
    
    public function options_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Tabbed Interface -->
            <h2 class="nav-tab-wrapper">
                <a href="#tab-main" class="nav-tab nav-tab-active">Основные настройки JIT</a>
                <a href="#tab-bot-cache" class="nav-tab">Кэширование для ботов</a>
                <a href="#tab-diagnostics" class="nav-tab">Диагностика</a>
            </h2>
            
            <div id="tab-main" class="tab-content active">
                <form action="options.php" method="post">
                    <?php
                    settings_fields('smart_jit_settings');
                    do_settings_sections('smart_jit_settings');
                    submit_button('Сохранить настройки');
                    ?>
                </form>
            </div>
            
            <div id="tab-bot-cache" class="tab-content" style="display: none;">
                <h3>Настройки кэширования для ботов</h3>
                <p>Здесь вы можете настроить поведение кэширования для различных типов ботов.</p>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Тип бота</th>
                            <th>JIT режим</th>
                            <th>Кэш страниц</th>
                            <th>Объектный кэш</th>
                            <th>Браузерный кэш</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Поисковые боты (Google, Yandex)</td>
                            <td><?php echo $this->disable_jit_for_bots ? 'Отключен' : 'Безопасный'; ?></td>
                            <td>Включен</td>
                            <td>Включен</td>
                            <td>Включен</td>
                        </tr>
                        <tr>
                            <td>Тесты скорости (PageSpeed, GTmetrix)</td>
                            <td><?php echo $this->disable_jit_for_speed_tests ? 'Отключен' : 'Максимальный'; ?></td>
                            <td><?php echo $this->disable_page_cache_for_speed_tests ? 'Отключен' : 'Включен'; ?></td>
                            <td><?php echo $this->disable_object_cache_for_speed_tests ? 'Отключен' : 'Включен'; ?></td>
                            <td><?php echo $this->disable_browser_cache_for_speed_tests ? 'Отключен' : 'Включен'; ?></td>
                        </tr>
                        <tr>
                            <td>Обычные пользователи</td>
                            <td><?php echo $this->safe_mode ? 'Сбалансированный' : 'Турбо'; ?></td>
                            <td>Включен</td>
                            <td>Включен</td>
                            <td>Включен</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="tab-diagnostics" class="tab-content" style="display: none;">
                <h3>Диагностическая информация</h3>
                
                <table class="widefat">
                    <tr>
                        <th>Параметр</th>
                        <th>Значение</th>
                    </tr>
                    <tr>
                        <td>Текущий JIT буфер</td>
                        <td><?php echo esc_html($this->current_jit_buffer); ?></td>
                    </tr>
                    <tr>
                        <td>JIT конфигурируем</td>
                        <td><?php echo $this->jit_configurable ? 'Да' : 'Нет'; ?></td>
                    </tr>
                    <tr>
                        <td>Opcache ограничен</td>
                        <td><?php echo $this->opcache_restricted ? 'Да' : 'Нет'; ?></td>
                    </tr>
                    <tr>
                        <td>Последнее изменение поста</td>
                        <td><?php echo $this->last_post_modified ? date('Y-m-d H:i:s', $this->last_post_modified) : 'Никогда'; ?></td>
                    </tr>
                    <tr>
                        <td>Сбросов Opcache</td>
                        <td><?php echo get_option('smart_jit_opcache_reset_count', 0); ?></td>
                    </tr>
                    <tr>
                        <td>Безопасный режим</td>
                        <td><?php echo $this->safe_mode ? 'Включен' : 'Выключен'; ?></td>
                    </tr>
                    <tr>
                        <td>Логирование</td>
                        <td><?php echo $this->log_enabled ? 'Включено' : 'Выключено'; ?></td>
                    </tr>
                </table>
                
                <h4>Тестирование определения ботов</h4>
                <p>Текущий User-Agent: <code><?php echo esc_html($_SERVER['HTTP_USER_AGENT'] ?? 'Не определен'); ?></code></p>
                <p>Определен как бот: <?php echo $this->is_bot ? 'Да' : 'Нет'; ?></p>
                <p>Определен как тест скорости: <?php echo $this->is_speed_test_bot ? 'Да' : 'Нет'; ?></p>
                
                <h4>Действия</h4>
                <form method="post">
                    <?php wp_nonce_field('smart_jit_actions'); ?>
                    <button type="submit" name="smart_jit_clear_cache" class="button">Очистить кэш вручную</button>
                    <button type="submit" name="smart_jit_reset_counters" class="button">Сбросить счетчики</button>
                </form>
            </div>
        </div>
        
        <style>
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .nav-tab-wrapper { margin: 20px 0; }
        .nav-tab { cursor: pointer; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab-wrapper a').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.tab-content').hide();
                $(target).show();
            });
        });
        </script>
        <?php
    }
    
    public function add_admin_bar_status($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $jit_mode = ini_get('opcache.jit');
        $mode_text = 'JIT: ';
        
        if ($this->is_speed_test_bot) {
            $mode_text .= $this->disable_jit_for_speed_tests ? '❌ Speed Test' : '🚀 Speed Test';
        } elseif ($this->is_bot) {
            $mode_text .= $this->disable_jit_for_bots ? '🤖 Bot Safe' : '🤖 Bot Opt';
        } else {
            $mode_text .= $this->safe_mode ? '⚡ Balanced' : '🔥 Turbo';
        }
        
        $wp_admin_bar->add_node([
            'id' => 'jit-status',
            'title' => $mode_text,
            'href' => admin_url('options-general.php?page=smart-jit-manager')
        ]);
    }
    
    private function register_wp_cli_commands() {
        WP_CLI::add_command('smart-jit', 'SmartJIT_CLI_Commands');
    }
}

// WP-CLI команды
if (defined('WP_CLI') && WP_CLI) {
    class SmartJIT_CLI_Commands {
        
        public function status() {
            $manager = new SmartJITManager();
            
            WP_CLI::line('=== Smart JIT Manager Status ===');
            WP_CLI::line('JIT Buffer: ' . ini_get('opcache.jit_buffer_size'));
            WP_CLI::line('JIT Mode: ' . ini_get('opcache.jit'));
            WP_CLI::line('Safe Mode: ' . (get_option('smart_jit_safe_mode') ? 'Yes' : 'No'));
            WP_CLI::line('Last Post Modified: ' . get_option('smart_jit_last_post_modified', 'Never'));
            WP_CLI::line('Opcache Reset Count: ' . get_option('smart_jit_opcache_reset_count', 0));
        }
        
        public function reset($args, $assoc_args) {
            $type = $args[0] ?? 'all';
            
            switch ($type) {
                case 'counters':
                    delete_option('smart_jit_opcache_reset_count');
                    WP_CLI::success('Counters reset');
                    break;
                    
                case 'cache':
                    if (function_exists('opcache_reset')) {
                        opcache_reset();
                        WP_CLI::success('Opcache reset');
                    } else {
                        WP_CLI::error('Opcache not available');
                    }
                    break;
                    
                case 'all':
                    delete_option('smart_jit_opcache_reset_count');
                    delete_option('smart_jit_last_post_modified');
                    
                    if (function_exists('opcache_reset')) {
                        opcache_reset();
                    }
                    
                    WP_CLI::success('All reset completed');
                    break;
                    
                default:
                    WP_CLI::error('Unknown reset type: ' . $type);
            }
        }
        
        public function test_bot_detection($args, $assoc_args) {
            $user_agent = $args[0] ?? '';
            $manager = new SmartJITManager();
            
            WP_CLI::line('Testing bot detection for: ' . $user_agent);
            WP_CLI::line('Is search bot: ' . ($manager->is_search_bot($user_agent) ? 'Yes' : 'No'));
            WP_CLI::line('Is speed test bot: ' . ($manager->check_speed_test_bot($user_agent) ? 'Yes' : 'No'));
        }
    }
}

// Инициализация плагина
add_action('plugins_loaded', function() {
    new SmartJITManager();
});

// Активация плагина
register_activation_hook(__FILE__, function() {
    add_option('smart_jit_safe_mode', '1');
    add_option('smart_jit_disable_jit_for_bots', '1');
    add_option('smart_jit_disable_jit_for_speed_tests', '1');
    add_option('smart_jit_disable_browser_cache_for_speed_tests', '1');
    add_option('smart_jit_last_post_modified', '');
    add_option('smart_jit_opcache_reset_count', 0);
});

// Деактивация плагина
register_deactivation_hook(__FILE__, function() {
    // Восстанавливаем стандартные настройки
    @ini_set('opcache.jit', '1235');
    @ini_set('opcache.revalidate_freq', '2');
    @ini_set('opcache.validate_timestamps', '1');
});
