<?php
/**
 * 管理界面类
 *
 * 负责WordPress后台管理界面
 * 使用Tailwind CSS构建现代化UI
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class AutoTagSEO_Admin {

    /**
     * 构造函数
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_auto_tag_seo_batch_process', array($this, 'ajax_batch_process'));
        add_action('wp_ajax_auto_tag_seo_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_auto_tag_seo_generate_single', array($this, 'ajax_generate_single'));
        add_action('wp_ajax_auto_tag_seo_update_config', array($this, 'ajax_update_config'));
        add_action('wp_ajax_auto_tag_seo_queue_status', array($this, 'ajax_queue_status')); // 新增队列状态查询
    }

    /**
     * 添加管理菜单
     */
    public function add_admin_menu() {
        add_options_page(
            'Auto Tag SEO 设置',
            'Auto Tag SEO',
            'manage_options',
            'auto-tag-seo',
            array($this, 'admin_page')
        );
    }

    /**
     * 注册设置
     */
    public function register_settings() {
        register_setting('auto_tag_seo_options', 'auto_tag_seo_options', array($this, 'validate_options'));
    }

    /**
     * 加载管理界面资源
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_auto-tag-seo') {
            return;
        }

        // 加载自定义CSS
        wp_enqueue_style(
            'auto-tag-seo-admin',
            AUTO_TAG_SEO_PLUGIN_URL . 'assets/admin.css',
            array(),
            AUTO_TAG_SEO_VERSION
        );

        // 加载jQuery与自定义管理脚本
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'auto-tag-seo-admin-js',
            AUTO_TAG_SEO_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            AUTO_TAG_SEO_VERSION,
            true
        );

        // 本地化脚本到自定义句柄（而非绑定到 jQuery）
        wp_localize_script('auto-tag-seo-admin-js', 'autoTagSeoAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auto_tag_seo_nonce'),
            'strings' => array(
                'processing' => '处理中...',
                'success' => '成功',
                'error' => '错误',
                'confirm_batch' => '确定要批量生成标签描述吗？'
            )
        )); // Confirmed via 寸止
    }

    /**
     * 管理页面
     */
    public function admin_page() {
        $options = get_option('auto_tag_seo_options', array());
        $tag_processor = auto_tag_seo()->get_tag_processor();

        // 获取统计信息
        $stats = $tag_processor->get_tag_statistics();
        $table_prefix = $tag_processor->get_table_prefix();

        // 分页参数
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(5, min(100, intval($_GET['per_page']))) : 10;
        $offset = ($current_page - 1) * $per_page;

        // 过滤参数 - 默认只显示待处理的标签
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'pending';

        // 获取标签列表
        $tags_data = $tag_processor->get_tags_with_pagination($offset, $per_page, $filter);
        $tags = $tags_data['tags'];
        $total_tags = $tags_data['total'];
        $total_pages = ceil($total_tags / $per_page);

        // 处理表单提交（字段级校验与清洗）
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'auto_tag_seo_options-options')) {
            $raw = isset($_POST['auto_tag_seo_options']) ? (array) $_POST['auto_tag_seo_options'] : array();
            $options = $this->validate_options($raw); // sanitize + validate
            update_option('auto_tag_seo_options', $options);
            echo '<div class="notice notice-success"><p>设置已保存！</p></div>';
        }

        ?>
        <div class="auto-tag-seo-container">
            <!-- 页面标题 -->
            <div class="ats-header">
                <h1>Auto Tag SEO</h1>
                <p>使用BigModel GLM-4.5-Flash为WordPress标签生成SEO友好的英文描述</p>
            </div>

            <!-- 统计卡片 -->
            <div class="ats-stats-grid">
                <div class="ats-stat-card blue">
                    <div class="ats-stat-icon">🏷️</div>
                    <div class="ats-stat-content">
                        <h3>总标签数</h3>
                        <p class="ats-stat-number"><?php echo $stats['total']; ?></p>
                    </div>
                </div>

                <div class="ats-stat-card green">
                    <div class="ats-stat-icon">✅</div>
                    <div class="ats-stat-content">
                        <h3>已有描述</h3>
                        <p class="ats-stat-number"><?php echo $stats['with_description']; ?></p>
                    </div>
                </div>

                <div class="ats-stat-card yellow">
                    <div class="ats-stat-icon">⚠️</div>
                    <div class="ats-stat-content">
                        <h3>待处理</h3>
                        <p class="ats-stat-number"><?php echo $stats['without_description']; ?></p>
                    </div>
                </div>

                <div class="ats-stat-card purple">
                    <div class="ats-stat-icon">📊</div>
                    <div class="ats-stat-content">
                        <h3>完成率</h3>
                        <p class="ats-stat-number"><?php echo $stats['completion_rate']; ?>%</p>
                    </div>
                </div>
            </div>

            <!-- 操作按钮 -->
            <div class="ats-actions">
                <h2>快速操作</h2>
                <div class="ats-button-group">
                    <button id="test-api-btn" class="ats-btn ats-btn-primary">
                        测试API连接
                    </button>
                    <button id="batch-process-btn" class="ats-btn ats-btn-success"
                            <?php echo $stats['without_description'] == 0 ? 'disabled' : ''; ?>>
                        批量生成当前页描述 (<?php echo min($stats['without_description'], $per_page); ?>个)
                    </button>

                    <button id="refresh-stats-btn" class="ats-btn ats-btn-secondary">
                        刷新统计
                    </button>
                </div>
                <div id="operation-result" class="ats-message hidden"></div>
            </div>


            <!-- 新标签列表区块 -->
            <div class="ats-card">
                <div class="ats-card-title">标签列表</div>
                <div class="ats-toolbar">
                    <div class="ats-toolbar-group">
                        <span class="ats-toolbar-stat">共 <?php echo $total_tags; ?> 个标签</span>
                        <span class="ats-toolbar-stat">当前第 <?php echo $current_page; ?> 页</span>
                        <span class="ats-toolbar-stat">每页显示:</span>
                        <select id="per-page-select" class="ats-select">
                            <option value="5" <?php echo $per_page == 5 ? 'selected' : ''; ?>>5条</option>
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10条</option>
                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                        </select>
                    </div>
                    <div class="ats-toolbar-group">
                        <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=pending&per_page=' . $per_page); ?>" class="ats-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>">待处理 (<?php echo $stats['without_description']; ?>)</a>
                        <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=completed&per_page=' . $per_page); ?>" class="ats-tab <?php echo $filter === 'completed' ? 'active' : ''; ?>">已完成 (<?php echo $stats['with_description']; ?>)</a>
                        <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=all&per_page=' . $per_page); ?>" class="ats-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">全部 (<?php echo $stats['total']; ?>)</a>
                    </div>
                </div>
                <div class="ats-table-wrap">
                    <table class="ats-table">
                        <thead>
                            <tr>
                                <th>标签名称</th>
                                <th>使用次数</th>
                                <th>描述状态</th>
                                <th>描述内容</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tags)): ?>
                                <tr>
                                    <td colspan="5" class="ats-empty">暂无标签数据</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tags as $tag): ?>
                                    <tr>
                                        <td>
                                            <div class="ats-tag-name"><?php echo esc_html($tag->name); ?></div>
                                            <div class="ats-tag-slug"><?php echo esc_html($tag->slug); ?></div>
                                        </td>
                                        <td><?php echo $tag->count; ?></td>
                                        <td>
                                            <?php if (empty($tag->description)): ?>
                                                <span class="ats-badge ats-badge-danger">无描述</span>
                                            <?php else: ?>
                                                <span class="ats-badge ats-badge-success">已完成</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($tag->description)): ?>
                                                <div class="ats-description" title="<?php echo esc_attr($tag->description); ?>"><?php echo esc_html($tag->description); ?></div>
                                            <?php else: ?>
                                                <span class="ats-description empty">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (empty($tag->description)): ?>
                                                <button class="ats-btn ats-btn-primary generate-single-btn" data-term-id="<?php echo $tag->term_id; ?>" data-tag-name="<?php echo esc_attr($tag->name); ?>">生成描述</button>
                                            <?php elseif ($filter === 'completed'): ?>
                                                <button class="ats-btn ats-btn-warning regenerate-btn regenerate" data-term-id="<?php echo $tag->term_id; ?>" data-tag-name="<?php echo esc_attr($tag->name); ?>">重新生成</button>
                                            <?php else: ?>
                                                <span class="ats-status-done">✓ 已完成</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                <div class="ats-pagination">
                    <span class="ats-pagination-info">显示第 <?php echo (($current_page - 1) * $per_page + 1); ?> - <?php echo min($current_page * $per_page, $total_tags); ?> 条，共 <?php echo $total_tags; ?> 条</span>
                    <div class="ats-pagination-nav">
                        <?php if ($current_page > 1): ?>
                            <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=' . $filter . '&per_page=' . $per_page . '&paged=' . ($current_page - 1)); ?>" class="ats-page-link">上一页</a>
                        <?php endif; ?>
                        <?php
                        $start_page = max(1, $current_page - 2);
                        $end_page = min($total_pages, $current_page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=' . $filter . '&per_page=' . $per_page . '&paged=' . $i); ?>" class="ats-page-link<?php echo $i == $current_page ? ' current' : ''; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                        <?php if ($current_page < $total_pages): ?>
                            <a href="<?php echo admin_url('options-general.php?page=auto-tag-seo&filter=' . $filter . '&per_page=' . $per_page . '&paged=' . ($current_page + 1)); ?>" class="ats-page-link">下一页</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- API配置 -->
            <div class="ats-config-form">
                <h2>API参数配置</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('auto_tag_seo_options-options'); ?>
                    <div class="ats-form-grid">
                        <div class="ats-form-group">
                            <label for="api_endpoint" class="ats-form-label">API端点URL</label>
                            <input type="text" id="api_endpoint" name="auto_tag_seo_options[api_endpoint]" value="<?php echo esc_attr($options['api_endpoint'] ?? 'https://open.bigmodel.cn/api/paas/v4/chat/completions'); ?>" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="model" class="ats-form-label">模型名称</label>
                            <input type="text" id="model" name="auto_tag_seo_options[model]" value="<?php echo esc_attr($options['model'] ?? 'glm-4.5-flash'); ?>" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="max_tokens" class="ats-form-label">最大Tokens</label>
                            <input type="number" id="max_tokens" name="auto_tag_seo_options[max_tokens]" value="<?php echo esc_attr($options['max_tokens'] ?? 200); ?>" min="1" max="1000" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="temperature" class="ats-form-label">温度参数</label>
                            <input type="number" id="temperature" name="auto_tag_seo_options[temperature]" value="<?php echo esc_attr($options['temperature'] ?? 0.3); ?>" step="0.1" min="0" max="2" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="top_p" class="ats-form-label">Top P</label>
                            <input type="number" id="top_p" name="auto_tag_seo_options[top_p]" value="<?php echo esc_attr($options['top_p'] ?? 0.8); ?>" step="0.1" min="0" max="1" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="frequency_penalty" class="ats-form-label">频率惩罚</label>
                            <input type="number" id="frequency_penalty" name="auto_tag_seo_options[frequency_penalty]" value="<?php echo esc_attr($options['frequency_penalty'] ?? 0.1); ?>" step="0.1" min="0" max="2" class="ats-form-input">
                        </div>

                        <div class="ats-form-group">
                            <label for="system_prompt" class="ats-form-label">系统提示词</label>
                            <textarea id="system_prompt" name="auto_tag_seo_options[system_prompt]" rows="4" class="ats-form-input" style="resize: vertical;"><?php echo esc_textarea($options['system_prompt'] ?? 'Generate a concise English SEO description for the given WordPress tag. Maximum 160 characters. SEO-focused keywords. Plain English only. No special characters or formatting.'); ?></textarea>
                            <p class="ats-form-hint" style="font-size: 12px; color: #666; margin-top: 4px;">指导AI生成描述的提示词，不超过500个字符</p>
                        </div>

                        <div class="ats-form-group">
                            <label for="api_key" class="ats-form-label">API密钥</label>
                            <input type="password" id="api_key" name="auto_tag_seo_options[api_key]" value="<?php echo esc_attr($options['api_key'] ?? ''); ?>" class="ats-form-input" autocomplete="off" placeholder="请填写您的 BigModel API Key">
                            <p class="ats-form-hint" style="font-size: 12px; color: #666; margin-top: 4px;">
                                请前往 <a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank">BigModel 控制台</a> 获取并复制您自己的 API Key，粘贴到此处。<br>
                                <b>安全提示：</b> 请勿将您的 API Key 泄露给他人。
                            </p>
                        </div>
                    </div>
                    <div class="ats-button-group">
                        <button type="submit" name="submit" class="ats-btn ats-btn-primary">保存配置</button>
                    </div>
                </form>
            </div>


        </div>

        <!-- 已迁移至 assets/admin.js -->

        <?php
    }

    /**
     * AJAX处理批量生成 - 仅处理当前分页页面的标签
     */
    public function ajax_batch_process() {
        check_ajax_referer('auto_tag_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        // 获取当前页面参数
        $current_page = isset($_POST['current_page']) ? max(1, intval($_POST['current_page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(5, min(100, intval($_POST['per_page']))) : 10;

        $tag_processor = auto_tag_seo()->get_tag_processor();

        // 获取当前页面的待处理标签
        $offset = ($current_page - 1) * $per_page;
        $tags_data = $tag_processor->get_tags_with_pagination($offset, $per_page, 'pending'); // 强制只处理待处理的
        $tags = $tags_data['tags'];

        if (empty($tags)) {
            wp_send_json_error('当前页面没有待处理的标签');
        }

        // 队列化：总量=per_page，分批=5，间隔=10秒
        $term_ids = array_map(function($t){ return intval($t->term_id); }, $tags);
        $job_id = $tag_processor->create_queue_job($term_ids, 5, 10);
        if (!$job_id) {
            wp_send_json_error('无法创建队列任务');
        }
        wp_send_json_success(array('job_id' => $job_id));
    }

    /**
     * AJAX处理API测试
     */
    public function ajax_test_api() {
        check_ajax_referer('auto_tag_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        $api_handler = auto_tag_seo()->get_api_handler();
        $result = $api_handler->test_api_connection();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX处理单个标签生成
     */
    public function ajax_generate_single() {
        check_ajax_referer('auto_tag_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        $term_id = intval($_POST['term_id']);

        if (!$term_id) {
            wp_send_json_error('参数错误');
        }

        try {
            $api_handler = auto_tag_seo()->get_api_handler();
            $tag_processor = auto_tag_seo()->get_tag_processor();

            // 服务端获取真实标签名称，避免信任客户端传入
            $term = get_term($term_id, 'post_tag');
            if (!$term || is_wp_error($term)) {
                wp_send_json_error('标签不存在');
            }
            $tag_name = $term->name;

            // 生成描述
            $description = $api_handler->generate_tag_description($tag_name);

            if ($description && $tag_processor->update_tag_description($term_id, $description)) {
                wp_send_json_success(array(
                    'description' => $description,
                    'message' => '描述生成成功'
                ));
            } else {
                wp_send_json_error('描述生成失败');
            }
        } catch (Exception $e) {
            wp_send_json_error('生成异常: ' . $e->getMessage());
        }
    }

    /**
     * 验证选项
     */
    public function validate_options($input) {
        $out = array();
        $out['api_endpoint'] = isset($input['api_endpoint']) ? esc_url_raw($input['api_endpoint']) : 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $out['model'] = isset($input['model']) ? sanitize_text_field($input['model']) : 'glm-4.5-flash';
        $out['max_tokens'] = isset($input['max_tokens']) ? max(1, min(1000, intval($input['max_tokens']))) : 200;
        $out['temperature'] = isset($input['temperature']) ? max(0, min(2, floatval($input['temperature']))) : 0.3;
        $out['top_p'] = isset($input['top_p']) ? max(0, min(1, floatval($input['top_p']))) : 0.8;
        $out['frequency_penalty'] = isset($input['frequency_penalty']) ? max(0, min(2, floatval($input['frequency_penalty']))) : 0.1;
        $out['system_prompt'] = isset($input['system_prompt']) ? wp_kses_post(wp_trim_words($input['system_prompt'], 200, '')) : '';
        $out['api_key'] = isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '';
        return $out;
    }


    /**
     * AJAX 查询队列状态
     */
    public function ajax_queue_status() {
        check_ajax_referer('auto_tag_seo_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('权限不足'); }
        $job_id = isset($_REQUEST['job_id']) ? sanitize_text_field($_REQUEST['job_id']) : '';
        if (!$job_id) { wp_send_json_error('缺少job_id'); }
        $tag_processor = auto_tag_seo()->get_tag_processor();
        $status = $tag_processor->get_queue_status($job_id);
        if ($status === null) { wp_send_json_error('任务不存在或已完成'); }
        wp_send_json_success($status);
    }

    /**
     * AJAX处理配置更新
     */
    public function ajax_update_config() {
        check_ajax_referer('auto_tag_seo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }

        try {
            auto_tag_seo()->update_to_bigmodel_config();
            wp_send_json_success('配置已更新到BigModel');
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}