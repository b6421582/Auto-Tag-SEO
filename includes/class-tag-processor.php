<?php
/**
 * 标签处理类
 *
 * 负责处理WordPress标签的描述生成
 * 使用WordPress全局数据库连接，自动检测表前缀
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class AutoTagSEO_Tag_Processor {

    /**
     * WordPress数据库实例
     */
    private $wpdb;

    /**
     * 表前缀
     */
    private $table_prefix;

    /**
     * 队列存储option键名
     */
    private $job_option_key;

    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix; // 自动检测表前缀 (wp_ 或 kt_ 等)
        $this->job_option_key = 'auto_tag_seo_jobs'; // 存储简易任务队列
    }

    /**
     * 获取需要生成描述的标签
     * 只处理 taxonomy = 'post_tag' 且描述为空的标签
     */
    public function get_tags_without_description($limit = 10) {
        $terms_table = $this->table_prefix . 'terms';
        $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';

        $sql = $this->wpdb->prepare("
            SELECT t.term_id, t.name, t.slug, tt.description
            FROM {$terms_table} t
            JOIN {$term_taxonomy_table} tt ON t.term_id = tt.term_id
            WHERE tt.taxonomy = 'post_tag'
            AND (tt.description IS NULL OR tt.description = '')
            AND tt.count > 0
            ORDER BY tt.count DESC
            LIMIT %d
        ", $limit);

        return $this->wpdb->get_results($sql);
    }

    /**
     * 更新标签描述
     */
    public function update_tag_description($term_id, $description) {
        // 使用 WP API 更新并触发缓存/钩子
        $clean_desc = trim(wp_strip_all_tags((string) $description));
        if ($clean_desc === '') {
            return false;
        }
        // 长度兜底（与 API 层一致，最大 160 字符）
        if (strlen($clean_desc) > 160) {
            $clean_desc = substr($clean_desc, 0, 157) . '...';
        }

        $result = wp_update_term((int)$term_id, 'post_tag', array('description' => $clean_desc));
        if (is_wp_error($result)) {
            // 兜底：失败时尝试直接更新并清理缓存
            $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';
            $db_update = $this->wpdb->update(
                $term_taxonomy_table,
                array('description' => $clean_desc),
                array(
                    'term_id' => (int)$term_id,
                    'taxonomy' => 'post_tag'
                ),
                array('%s'),
                array('%d', '%s')
            );
            if ($db_update === false) {
                return false;
            }
            clean_term_cache((int)$term_id, 'post_tag');
        }
        return true;
    }

    /**
     * 获取标签统计信息
     */
    public function get_tag_statistics() {
        $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';

        // 总标签数
        $total_tags = $this->wpdb->get_var("
            SELECT COUNT(*)
            FROM {$term_taxonomy_table}
            WHERE taxonomy = 'post_tag'
        ");

        // 有描述的标签数
        $tags_with_description = $this->wpdb->get_var("
            SELECT COUNT(*)
            FROM {$term_taxonomy_table}
            WHERE taxonomy = 'post_tag'
            AND description IS NOT NULL
            AND description != ''
        ");

        // 无描述的标签数
        $tags_without_description = $total_tags - $tags_with_description;

        return array(
            'total' => (int) $total_tags,
            'with_description' => (int) $tags_with_description,
            'without_description' => (int) $tags_without_description,
            'completion_rate' => $total_tags > 0 ? round(($tags_with_description / $total_tags) * 100, 2) : 0
        );
    }

    /**
     * 批量处理标签描述生成
     */
    public function batch_generate_descriptions($batch_size = 10) {
        $tags = $this->get_tags_without_description($batch_size);
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );

        if (empty($tags)) {
            return $results;
        }

        $api_handler = auto_tag_seo()->get_api_handler();

        foreach ($tags as $index => $tag) {
            try {
                // 调用AI生成描述
                $description = $api_handler->generate_tag_description($tag->name);

                if ($description && $this->update_tag_description($tag->term_id, $description)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "标签 '{$tag->name}' 描述生成失败";
                }

                // 优化延迟策略：减少不必要的等待时间
                $this->smart_delay($index + 1);

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "标签 '{$tag->name}' 处理异常: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * 批量处理指定标签列表的描述生成
     */
    public function batch_generate_descriptions_for_tags($tags) {
        $results = array(
            'success' => 0,
            'failed' => 0,
            'errors' => array()
        );

        if (empty($tags)) {
            return $results;
        }

        $api_handler = auto_tag_seo()->get_api_handler();

        foreach ($tags as $index => $tag) {
            // 只处理没有描述的标签
            if (!empty($tag->description)) {
                continue;
            }

            try {
                // 调用AI生成描述
                $description = $api_handler->generate_tag_description($tag->name);

                if ($description && $this->update_tag_description($tag->term_id, $description)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "标签 '{$tag->name}' 描述生成失败";
                }

                // 优化延迟策略：减少不必要的等待时间
                $this->smart_delay($index + 1);

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "标签 '{$tag->name}' 处理异常: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * 验证表前缀和数据库连接
     */
    public function validate_database() {
        $terms_table = $this->table_prefix . 'terms';
        $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';

        // 检查表是否存在
        $tables_exist = $this->wpdb->get_var("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name IN ('{$terms_table}', '{$term_taxonomy_table}')
        ");

        if ($tables_exist != 2) {
            return false;
        }

        return true;
    }

    /**
     * 获取带分页的标签列表
     */
    public function get_tags_with_pagination($offset = 0, $limit = 20, $filter = 'all') {
        $terms_table = $this->table_prefix . 'terms';
        $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';

        // 根据过滤条件构建WHERE子句
        $where_condition = "tt.taxonomy = 'post_tag'";
        if ($filter === 'pending') {
            $where_condition .= " AND (tt.description = '' OR tt.description IS NULL)";
        } elseif ($filter === 'completed') {
            $where_condition .= " AND tt.description != '' AND tt.description IS NOT NULL";
        }

        // 获取总数
        $total_sql = "
            SELECT COUNT(*)
            FROM {$terms_table} t
            JOIN {$term_taxonomy_table} tt ON t.term_id = tt.term_id
            WHERE {$where_condition}
        ";
        $total = $this->wpdb->get_var($total_sql);

        // 获取分页数据
        $sql = $this->wpdb->prepare("
            SELECT t.term_id, t.name, t.slug, tt.description, tt.count
            FROM {$terms_table} t
            JOIN {$term_taxonomy_table} tt ON t.term_id = tt.term_id
            WHERE {$where_condition}
            ORDER BY tt.count DESC, t.name ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset);


        $tags = $this->wpdb->get_results($sql);

        return array(
            'tags' => $tags,
            'total' => (int) $total
        );
    }


    /**
     * 创建队列任务
     * @param array $term_ids 要处理的 term_id 列表
     * @param int $chunk_size 每批处理数量
     * @param int $interval_seconds 批次间隔秒
     * @return string $job_id
     */
    public function create_queue_job($term_ids, $chunk_size = 5, $interval_seconds = 10) {
        $term_ids = array_values(array_unique(array_map('intval', (array)$term_ids)));
        if (empty($term_ids)) { return ''; }
        $job_id = 'ats_job_' . time() . '_' . wp_generate_uuid4();
        $jobs = get_option($this->job_option_key, array());
        $jobs[$job_id] = array(
            'total' => count($term_ids),
            'pending' => $term_ids,
            'success' => 0,
            'failed' => 0,
            'errors' => array(),
            'chunk_size' => max(1, intval($chunk_size)),
            'interval' => max(5, intval($interval_seconds)),
            'next_run' => time(),
        );
        update_option($this->job_option_key, $jobs);
        // 立即安排首次执行
        wp_schedule_single_event(time() + 1, 'auto_tag_seo_process_queue', array($job_id));
        return $job_id;
    }

    /**
     * 处理队列任务的一个批次
     */
    public function process_queue($job_id) {
        $jobs = get_option($this->job_option_key, array());
        if (!isset($jobs[$job_id])) { return; }
        $job = $jobs[$job_id];
        $chunk = array_splice($job['pending'], 0, $job['chunk_size']);
        if (empty($chunk)) {
            // 完成，写回并结束
            $jobs[$job_id] = $job;
            update_option($this->job_option_key, $jobs);
            return;
        }

        $api_handler = auto_tag_seo()->get_api_handler();
        foreach ($chunk as $index => $term_id) {
            $term = get_term($term_id, 'post_tag');
            if (!$term || is_wp_error($term)) {
                $job['failed']++;
                $job['errors'][] = 'Term not found: ' . $term_id;
                continue;
            }
            try {
                $desc = $api_handler->generate_tag_description($term->name);
                if ($desc && $this->update_tag_description($term_id, $desc)) {
                    $job['success']++;
                } else {
                    $job['failed']++;
                    $job['errors'][] = 'Update failed for term_id ' . $term_id;
                }
                // 优化延迟策略：队列处理中使用智能延迟
                $this->smart_delay($index + 1);
            } catch (Exception $e) {
                $job['failed']++;
                $job['errors'][] = 'Exception for term_id ' . $term_id . ': ' . $e->getMessage();
            }
        }

        // 写回当前进度
        $jobs[$job_id] = $job;
        update_option($this->job_option_key, $jobs);

        // 若仍有剩余，调度下一次
        if (!empty($job['pending'])) {
            $next = time() + (int)$job['interval'];
            wp_schedule_single_event($next, 'auto_tag_seo_process_queue', array($job_id));
        }
    }

    /**
     * 查询队列状态
     */
    public function get_queue_status($job_id) {
        $jobs = get_option($this->job_option_key, array());
        if (!isset($jobs[$job_id])) { return null; }
        $job = $jobs[$job_id];
        return array(
            'total' => (int)$job['total'],
            'pending' => count($job['pending']),
            'success' => (int)$job['success'],
            'failed' => (int)$job['failed'],
            'done' => count($job['pending']) === 0,
            'errors' => $job['errors'],
        );
    }

    /**
     * 强制执行队列任务（不依赖WordPress Cron）
     */
    public function force_execute_queue($job_id) {
        $jobs = get_option($this->job_option_key, array());
        if (!isset($jobs[$job_id])) {
            return null;
        }

        $job = $jobs[$job_id];

        // 如果任务已完成，返回最终状态
        if (empty($job['pending'])) {
            return array(
                'continue' => false,
                'status' => array(
                    'total' => (int)$job['total'],
                    'pending' => 0,
                    'success' => (int)$job['success'],
                    'failed' => (int)$job['failed'],
                    'done' => true,
                    'errors' => $job['errors'],
                )
            );
        }

        // 执行一个批次
        $this->process_queue($job_id);

        // 获取更新后的状态
        $updated_status = $this->get_queue_status($job_id);
        if ($updated_status === null) {
            return null;
        }

        return array(
            'continue' => !$updated_status['done'],
            'status' => $updated_status
        );
    }

    /**
     * 智能延迟策略：根据处理数量动态调整延迟时间
     */
    private function smart_delay($processed_count) {
        if ($processed_count <= 3) {
            // 小批量处理：减少延迟时间
            usleep(200000); // 0.2秒
        } elseif ($processed_count % 5 == 0) {
            // 每处理5个后稍长延迟
            sleep(1);
        } else {
            // 其他情况短延迟
            usleep(300000); // 0.3秒
        }
    }

    /**
     * 获取表前缀
     */
    public function get_table_prefix() {
        return $this->table_prefix;
    }
}