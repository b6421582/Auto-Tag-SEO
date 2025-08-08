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
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_prefix = $wpdb->prefix; // 自动检测表前缀 (wp_ 或 kt_ 等)
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
        $term_taxonomy_table = $this->table_prefix . 'term_taxonomy';

        $result = $this->wpdb->update(
            $term_taxonomy_table,
            array('description' => $description),
            array(
                'term_id' => $term_id,
                'taxonomy' => 'post_tag'
            ),
            array('%s'),
            array('%d', '%s')
        );

        if ($result === false) {
            return false;
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

        foreach ($tags as $tag) {
            try {
                // 调用AI生成描述
                $description = $api_handler->generate_tag_description($tag->name);

                if ($description && $this->update_tag_description($tag->term_id, $description)) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = "标签 '{$tag->name}' 描述生成失败";
                }

                // 添加延迟避免API限制
                sleep(1);

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

        foreach ($tags as $tag) {
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

                // 添加延迟避免API限制
                sleep(1);

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
     * 获取表前缀
     */
    public function get_table_prefix() {
        return $this->table_prefix;
    }
}