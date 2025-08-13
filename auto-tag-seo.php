<?php
/**
 * Plugin Name: Auto Tag SEO
 * Plugin URI: https://github.com/b6421582/Auto-Tag-SEO
 * Description: Automatically generates SEO-friendly English descriptions for WordPress tags using BigModel GLM-4.5-Flash.
 * Version: 1.3.0
 * Author: CatchIdeas
 * Author URI: https://catchideas.com
 * Text Domain: auto-tag-seo

 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 8.0
 *
 * Copyright 2025 CatchIdeas (email: contact@catchideas.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('AUTO_TAG_SEO_VERSION', '1.3.0');
define('AUTO_TAG_SEO_PLUGIN_FILE', __FILE__);
define('AUTO_TAG_SEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AUTO_TAG_SEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AUTO_TAG_SEO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Auto Tag SEO 主类
 */
class AutoTagSEO {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * API处理实例
     */
    private $api_handler;

    /**
     * 管理界面实例
     */
    private $admin;

    /**
     * 标签处理实例
     */
    private $tag_processor;

    /**
     * 获取单例实例
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->init();
    }

    /**
     * 初始化插件
     */
    private function init() {
        // 加载依赖文件
        $this->load_dependencies();

        // 初始化组件
        $this->init_components();

        // 注册钩子
        $this->register_hooks();
    }

    /**
     * 加载依赖文件
     */
    private function load_dependencies() {
        require_once AUTO_TAG_SEO_PLUGIN_DIR . 'includes/class-api-handler.php';
        require_once AUTO_TAG_SEO_PLUGIN_DIR . 'includes/class-tag-processor.php';
        require_once AUTO_TAG_SEO_PLUGIN_DIR . 'management/class-admin.php';
    }

    /**
     * 初始化组件
     */
    private function init_components() {
        $this->api_handler = new AutoTagSEO_API_Handler();
        $this->tag_processor = new AutoTagSEO_Tag_Processor();

        // 只在管理后台加载管理界面
        if (is_admin()) {
            $this->admin = new AutoTagSEO_Admin();
        }
    }

    /**
     * 注册WordPress钩子
     */
    private function register_hooks() {
        // 插件激活/停用钩子
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // 插件加载完成后的初始化
        add_action('plugins_loaded', array($this, 'plugins_loaded'));

        // 添加设置链接到插件页面
        add_filter('plugin_action_links_' . AUTO_TAG_SEO_PLUGIN_BASENAME, array($this, 'add_action_links'));

        // 注册WP Cron处理队列的hook（异步分批任务）
        add_action('auto_tag_seo_process_queue', array($this, 'handle_cron_process_queue'), 10, 1);
    }

    /**
     * 插件激活时执行
     */
    public function activate() {
        // 检查PHP版本
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(AUTO_TAG_SEO_PLUGIN_BASENAME);
            wp_die(__('Auto Tag SEO 需要 PHP 8.0 或更高版本。', 'auto-tag-seo'));
        }

        // 检查WordPress版本
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            deactivate_plugins(AUTO_TAG_SEO_PLUGIN_BASENAME);
            wp_die(__('Auto Tag SEO 需要 WordPress 5.0 或更高版本。', 'auto-tag-seo'));
        }

        // 设置默认选项（仅在首次安装时写入，不覆盖已有配置）
        $this->set_default_options(false); // Confirmed via 寸止
    }

    /**
     * 插件停用时执行
     */
    public function deactivate() {
        // 清理计划任务
        wp_clear_scheduled_hook('auto_tag_seo_batch_process');
    }

    /**
     * 设置默认选项
     */
    private function set_default_options($force_update = false) {
        $default_options = array(
            'api_key' => '', // 请在下方设置页面填写您的 BigModel API Key
            'api_endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
            'model' => 'glm-4.5-flash',
            'max_tokens' => 200,
            'temperature' => 0.3,
            'top_p' => 0.8,
            'frequency_penalty' => 0.1,
            'batch_size' => 5,
            'auto_generate' => false,
            'system_prompt' => 'Generate a concise English SEO description for the given WordPress tag. Maximum 160 characters. SEO-focused keywords. Plain English only. No special characters or formatting.',
        );

        if ($force_update) {
            // 强制更新选项（用于切换API提供商时）
            update_option('auto_tag_seo_options', $default_options);
        } else {
            // 只在选项不存在时添加
            add_option('auto_tag_seo_options', $default_options);
        }
    }

    /**
     * 插件加载完成后执行
     */
    public function plugins_loaded() {
        // 插件初始化完成
    }

    /**
     * 添加设置链接到插件页面
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=auto-tag-seo') . '">' . __('Settings', 'auto-tag-seo') . '</a>';
        $links[] = $settings_link; // Append so the order is: Deactivate | Settings
        return $links;
    }

    /**
     * 获取API处理实例
     */
    public function get_api_handler() {
        return $this->api_handler;
    }

    /**
     * 获取标签处理实例
     */
    public function get_tag_processor() {
        return $this->tag_processor;
    }

    /**
     * 获取管理界面实例
     */
    public function get_admin() {
        return $this->admin;
    }

    /**
     * WP Cron 回调：处理分批任务队列
     */
    public function handle_cron_process_queue($job_id) {
        if (!$job_id) { return; }
        if ($this->tag_processor) {
            $this->tag_processor->process_queue($job_id);
        }
    }

    /**
     * 手动更新配置到BigModel
     */
    public function update_to_bigmodel_config() {
        $this->set_default_options(true);
        return true;
    }
}

/**
 * 获取插件主实例
 */
function auto_tag_seo() {
    return AutoTagSEO::get_instance();
}

// 初始化插件
auto_tag_seo();