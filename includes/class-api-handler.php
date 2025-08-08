<?php
/**
 * API处理类
 *
 * 负责与硅基流动API的通信
 * 生成标签的SEO友好描述
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 以下是可配置的API参数
 * 取消注释并修改这些值以直接在文件中设置参数
 * 这些值会覆盖从数据库中获取的配置
 * 
 * 使用说明:
 * 1. 取消注释需要自定义的参数行
 * 2. 修改参数值为您需要的设置
 * 3. 保存文件即可生效
 * 
 * 参数说明:
 * - AUTO_TAG_SEO_API_ENDPOINT: API端点URL
 * - AUTO_TAG_SEO_MODEL: 使用的模型名称
 * - AUTO_TAG_SEO_MAX_TOKENS: 生成描述的最大token数
 * - AUTO_TAG_SEO_TEMPERATURE: 温度参数，控制生成文本的随机性
 * - AUTO_TAG_SEO_TOP_P: 控制生成文本的多样性
 * - AUTO_TAG_SEO_FREQUENCY_PENALTY: 频率惩罚参数
 * - AUTO_TAG_SEO_SYSTEM_PROMPT: 系统提示词，用于指导AI生成描述
 * 
 * 注意事项:
 * - 不要在这里硬编码API密钥，应通过管理界面设置
 * - 所有参数值应符合API要求的格式和范围
 * - 更改这些值会影响所有标签描述的生成
 */
// define('AUTO_TAG_SEO_API_ENDPOINT', 'https://open.bigmodel.cn/api/paas/v4/chat/completions');
// define('AUTO_TAG_SEO_MODEL', 'glm-4.5-flash');
// define('AUTO_TAG_SEO_MAX_TOKENS', 200);
// define('AUTO_TAG_SEO_TEMPERATURE', 0.3);
// define('AUTO_TAG_SEO_TOP_P', 0.8);
// define('AUTO_TAG_SEO_FREQUENCY_PENALTY', 0.1);
// define('AUTO_TAG_SEO_SYSTEM_PROMPT', 'Generate a concise English SEO description for the given WordPress tag. Maximum 160 characters. SEO-focused keywords. Plain English only. No special characters or formatting.');
// 注意：不要在这里硬编码API密钥，应通过管理界面设置

class AutoTagSEO_API_Handler {

    /**
     * API配置选项
     */
    private $options;

    /**
     * 构造函数
     */
    public function __construct() {
        // 从数据库获取配置
        $this->options = get_option('auto_tag_seo_options', array());

        // 检查是否定义了硬编码的API参数，如果有则覆盖
        if (defined('AUTO_TAG_SEO_API_ENDPOINT')) {
            $this->options['api_endpoint'] = AUTO_TAG_SEO_API_ENDPOINT;
        }
        if (defined('AUTO_TAG_SEO_MODEL')) {
            $this->options['model'] = AUTO_TAG_SEO_MODEL;
        }
        if (defined('AUTO_TAG_SEO_MAX_TOKENS')) {
            $this->options['max_tokens'] = AUTO_TAG_SEO_MAX_TOKENS;
        }
        if (defined('AUTO_TAG_SEO_TEMPERATURE')) {
            $this->options['temperature'] = AUTO_TAG_SEO_TEMPERATURE;
        }
        if (defined('AUTO_TAG_SEO_TOP_P')) {
            $this->options['top_p'] = AUTO_TAG_SEO_TOP_P;
        }
        if (defined('AUTO_TAG_SEO_FREQUENCY_PENALTY')) {
            $this->options['frequency_penalty'] = AUTO_TAG_SEO_FREQUENCY_PENALTY;
        }
        if (defined('AUTO_TAG_SEO_SYSTEM_PROMPT')) {
            $this->options['system_prompt'] = AUTO_TAG_SEO_SYSTEM_PROMPT;
        }
    }

    /**
     * 生成标签描述
     */
    public function generate_tag_description($tag_name) {
        // 验证API配置
        if (empty($this->options['api_key'])) {
            throw new Exception('API密钥未配置');
        }

        $max_retries = 3; // 最大重试次数
        $retry_count = 0;

        while ($retry_count < $max_retries) {
            // 构建请求数据
            $request_data = array(
                'model' => $this->options['model'] ?? 'glm-4.5-flash',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => $this->get_enhanced_system_prompt($retry_count)
                    ),
                    array(
                        'role' => 'user',
                        'content' => $tag_name
                    )
                ),
                'max_tokens' => (int) ($this->options['max_tokens'] ?? 200),
                'temperature' => (float) ($this->options['temperature'] ?? 0.3),
                'top_p' => (float) ($this->options['top_p'] ?? 0.8),
                'frequency_penalty' => (float) ($this->options['frequency_penalty'] ?? 0.1),
                'thinking' => array('type' => 'disabled')
            );

            // 发送API请求
            $response = $this->send_api_request($request_data);

            if (!$response) {
                throw new Exception('API请求失败');
            }

            // 解析响应
            $description = $this->parse_api_response($response);

            // 检查是否包含中文
            if (!$this->contains_chinese($description)) {
                // 验证描述长度（确保160字符以内）
                if (strlen($description) > 160) {
                    $description = substr($description, 0, 157) . '...';
                }

                return $description;
            }

            $retry_count++;

            // 如果包含中文，记录并重试
            if ($retry_count < $max_retries) {
                // 短暂延迟后重试
                sleep(1);
            }
        }

        // 如果重试次数用完仍有中文，抛出异常
        throw new Exception('生成的描述包含中文，重试' . $max_retries . '次后仍未成功');
    }

    /**
     * 发送API请求
     */
    private function send_api_request($data) {
        $api_endpoint = $this->options['api_endpoint'] ?? 'https://open.bigmodel.cn/api/paas/v4/chat/completions';

        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->options['api_key']
            ),
            'body' => json_encode($data),
            'timeout' => 30,
            'sslverify' => true
        );

        $response = wp_remote_post($api_endpoint, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return false;
        }

        return json_decode($response_body, true);
    }

    /**
     * 解析API响应
     */
    private function parse_api_response($response) {
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('API响应格式错误');
        }

        $content = trim($response['choices'][0]['message']['content']);

        // 清理可能的格式字符
        $content = preg_replace('/[*#\-\[\]`]/', '', $content);
        $content = preg_replace('/\s+/', ' ', $content);

        return $content;
    }

    /**
     * 获取增强的系统提示词（根据重试次数调整）
     */
    private function get_enhanced_system_prompt($retry_count = 0) {
        // 使用配置中的system_prompt，如果没有则使用默认值
        $base_prompt = $this->options['system_prompt'] ?? 'Generate a concise English SEO description for the given WordPress tag. Maximum 160 characters. SEO-focused keywords. Plain English only. No special characters or formatting.';

        if ($retry_count > 0) {
            $base_prompt .= ' IMPORTANT: You must respond ONLY in English. Do not use any Chinese characters, symbols, or non-English text.';
        }

        if ($retry_count > 1) {
            $base_prompt .= ' This is retry #' . $retry_count . '. Please ensure your response contains only English words and standard punctuation.';
        }

        return $base_prompt;
    }

    /**
     * 检查文本是否包含中文字符
     */
    private function contains_chinese($text) {
        // 使用正则表达式检测中文字符（包括中文标点符号）
        return preg_match('/[\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{20000}-\x{2a6df}\x{2a700}-\x{2b73f}\x{2b740}-\x{2b81f}\x{2b820}-\x{2ceaf}\x{2ceb0}-\x{2ebef}\x{30000}-\x{3134f}\x{ff00}-\x{ffef}]/u', $text);
    }

    /**
     * 获取默认系统提示词
     */
    private function get_default_system_prompt() {
        return $this->get_enhanced_system_prompt(0);
    }

    /**
     * 测试API连接
     */
    public function test_api_connection() {
        try {
            $test_description = $this->generate_tag_description('test');
            return array(
                'success' => true,
                'message' => 'API连接成功',
                'test_result' => $test_description
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'API连接失败: ' . $e->getMessage()
            );
        }
    }


}