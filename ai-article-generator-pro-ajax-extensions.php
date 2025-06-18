<?php
/*
Plugin Name: AI Article Generator PRO AJAX Extensions
Description: Версия 2.3 плагина с добавлением AJAX-генерации разделов и статьи и индикаторами загрузки,
а также поддержкой Google Gemini API, с улучшенным логированием ошибок.
Version: 2.4
Author: th7
Git: https://github.com/th777/aiag/
Text Domain: ai-generator-pro
Domain Path: /languages
*/

// Убедимся, что WordPress загружен
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * ========================================
 * Загрузка текстового домена для многоязычности
 * ========================================
 */
function aiagp_load_textdomain() {
    load_plugin_textdomain( 'ai-generator-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'aiagp_load_textdomain' );

/**
 * ========================================
 * Вспомогательные функции для дефолтных промптов
 * ========================================
 */
function aiagp_get_default_prompts() {
    return [
        'title' => [
            'UA Заголовок' => __('Generate a unique H1 headline for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Length: {{length}}. Title should be catchy, relevant and no more than 80 characters.', 'ai-generator-pro'),
            'EN Title' => __('Generate a unique H1 headline for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Length: {{length}}. Title should be catchy, relevant and no more than 80 characters.', 'ai-generator-pro')
        ],
        'section' => [
            'UA Розділи' => __('Generate a list of {{num_sections}} relevant section titles for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Length: {{length}} characters, Title: {{title}}.', 'ai-generator-pro'),
            'EN Sections' => __('Generate a list of {{num_sections}} relevant section titles for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Title: {{title}}, Length: {{length}} characters. Only section titles, one per line, nothing else.', 'ai-generator-pro')
        ],
        'main' => [
            'UA Статья' => __('Write a detailed article about {{topic}} in {{language}}. Title: {{title}}. Use these sections: {{sections}}. Tone: {{tone}}, Style: {{style}}. Length: {{length}} characters.', 'ai-generator-pro'),
            'EN Article' => __('Write a detailed article about {{topic}} in {{language}}. Title: {{title}}. Use these sections: {{sections}}. Tone: {{tone}}, Style: {{style}}. Length: {{length}} characters.', 'ai-generator-pro')
        ]
    ];
}


add_action('admin_menu', 'aiagp_menu');
function aiagp_menu() {
    add_menu_page(
        __('AI Article Generator', 'ai-generator-pro'),
        __('AI Articles', 'ai-generator-pro'),
        'manage_options',
        'ai-article-generator',
        'aiagp_page',
        'dashicons-edit-page',
        25
    );
}

// Функции для получения и сохранения API-ключей
function aiagp_get_api_keys() {
    return [
        'openai' => get_option('aiagp_openai_key', ''),
        'gemini' => get_option('aiagp_gemini_key', '')
    ];
}

function aiagp_save_api_keys($openai_key, $gemini_key) {
    if (!empty($openai_key)) {
        update_option('aiagp_openai_key', trim($openai_key));
    } else {
        delete_option('aiagp_openai_key');
    }

    if (!empty($gemini_key)) {
        update_option('aiagp_gemini_key', trim($gemini_key));
    } else {
        delete_option('aiagp_gemini_key');
    }
}

// Функции для получения и сохранения пользовательских промптов
function aiagp_get_prompts() {
    return [
        'title_prompt' => get_option('aiagp_title_prompt', ''),
        'section_prompt' => get_option('aiagp_section_prompt', ''),
        'main_prompt' => get_option('aiagp_main_prompt', '')
    ];
}

function aiagp_save_prompts($title_prompt, $section_prompt, $main_prompt) {
    update_option('aiagp_title_prompt', sanitize_textarea_field($title_prompt));
    update_option('aiagp_section_prompt', sanitize_textarea_field($section_prompt));
    update_option('aiagp_main_prompt', sanitize_textarea_field($main_prompt));
}


// Подключаем скрипт в админке
add_action('admin_enqueue_scripts', 'aiagp_enqueue_scripts');
function aiagp_enqueue_scripts($hook) {
    if ($hook !== 'toplevel_page_ai-article-generator') return;
    wp_enqueue_script('aiagp-admin-js', plugin_dir_url(__FILE__) . 'aiagp-admin.js', ['jquery'], null, true);
    wp_localize_script('aiagp-admin-js', 'aiagp_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aiagp_nonce'),
        // Локализованные строки для JS
        'messages' => [
            'success_generation' => __('Генерация завершена успешно!', 'ai-generator-pro'),
            'ajax_error' => __('AJAX Ошибка:', 'ai-generator-pro'),
            'api_error_openai' => __('Ошибка OpenAI (HTTP %d): %s', 'ai-generator-pro'),
            'api_error_gemini' => __('Ошибка Gemini (HTTP %d): %s', 'ai-generator-pro'),
            'no_rights' => __('У вас нет прав для выполнения этой операции.', 'ai-generator-pro'),
            'no_title_sections' => __('Заголовок и разделы обязательны', 'ai-generator-pro'),
            'error_saving_article_no_content' => __('Ошибка: Для сохранения статьи как черновика необходим заголовок и контент.', 'ai-generator-pro'),
            'error_saving_article_wp_error' => __('Ошибка при сохранении:', 'ai-generator-pro'),
            'article_saved' => __('Статья сохранена как черновик!', 'ai-generator-pro'),
            'edit_draft' => __('Редактировать черновик', 'ai-generator-pro'),
            'api_key_not_set_openai' => __('Ошибка: OpenAI API ключ не установлен.', 'ai-generator-pro'),
            'api_key_not_set_gemini' => __('Ошибка: Google Gemini API ключ не установлен.', 'ai-generator-pro'),
            'rate_limit_exceeded_openai' => __('Превышен лимит использования OpenAI API. Пожалуйста, проверьте свой тарифный план и платежные данные на платформе OpenAI.', 'ai-generator-pro'),
            'user_location_not_supported_gemini' => __('Ваше текущее местоположение не поддерживается для использования Google Gemini API. Пожалуйста, попробуйте воспользоваться VPN или другим хостингом.', 'ai-generator-pro'),
            'unknown_error_api' => __('Неизвестная ошибка API.', 'ai-generator-pro'),
            'no_expected_result_openai' => __('Ошибка OpenAI: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.', 'ai-generator-pro'),
            'no_expected_result_gemini' => __('Ошибка Gemini: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.', 'ai-generator-pro'),
            'api_connection_error' => __('Пожалуйста, проверьте ваше интернет-соединение или настройки сервера.', 'ai-generator-pro'),
        ]
    ]);
}

/**
 * ========================================
 * Общая функция для выполнения запросов к AI
 * (Изменено: теперь выбрасывает исключение при отсутствии ключа)
 * ========================================
 */
function aiagp_call_ai($provider, $prompt, $max_tokens, $api_keys) {
    if ($provider === 'gemini') {
        if (empty($api_keys['gemini'])) {
            throw new Exception(aiagp_ajax['messages']['api_key_not_set_gemini'] ?? 'Ошибка: Google Gemini API ключ не установлен.'); // Использовать локализованную строку
        }
        return aiagp_gemini($prompt, $api_keys['gemini'], $max_tokens);
    } else { // 'openai' по умолчанию
        if (empty($api_keys['openai'])) {
            throw new Exception(aiagp_ajax['messages']['api_key_not_set_openai'] ?? 'Ошибка: OpenAI API ключ не установлен.'); // Использовать локализованную строку
        }
        return aiagp_gpt($prompt, $api_keys['openai'], $max_tokens);
    }
}

// AJAX генерация заголовка
add_action('wp_ajax_aiagp_generate_title', 'aiagp_ajax_generate_title');
function aiagp_ajax_generate_title() {
    check_ajax_referer('aiagp_nonce', 'security');
    if (!current_user_can('manage_options')) wp_send_json_error(__( 'У вас нет прав для выполнения этой операции.', 'ai-generator-pro' ));

    $topic = sanitize_text_field($_POST['topic'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'українська');
    $length = intval($_POST['length'] ?? 2000);
    $tone = sanitize_text_field($_POST['tone'] ?? 'neutral');
    $style = sanitize_text_field($_POST['style'] ?? 'blog');
    $num_sections = intval($_POST['num_sections'] ?? 4);
    $title_prompt = sanitize_textarea_field($_POST['title_prompt'] ?? '');
    $ai_provider = sanitize_text_field($_POST['ai_provider'] ?? 'openai');

    if (!$title_prompt) {
        $default_prompts = aiagp_get_default_prompts();
        $title_prompt = array_values($default_prompts['title'])[0];
    }

    $replace = [
        '{{topic}}' => $topic,
        '{{language}}' => $language,
        '{{length}}' => $length,
        '{{tone}}' => $tone,
        '{{style}}' => $style,
        '{{num_sections}}' => $num_sections,
    ];
    $prompt = strtr($title_prompt, $replace);

    $api_keys = aiagp_get_api_keys();
    try {
        $result = aiagp_call_ai($ai_provider, $prompt, 60, $api_keys); // Max tokens for title
        wp_send_json_success(trim($result));
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
    wp_die();
}

// AJAX генерация разделов
add_action('wp_ajax_aiagp_generate_sections', 'aiagp_ajax_generate_sections');
function aiagp_ajax_generate_sections() {
    check_ajax_referer('aiagp_nonce', 'security');
    if (!current_user_can('manage_options')) wp_send_json_error(__( 'У вас нет прав для выполнения этой операции.', 'ai-generator-pro' ));

    $topic = sanitize_text_field($_POST['topic'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'українська');
    $length = intval($_POST['length'] ?? 2000);
    $tone = sanitize_text_field($_POST['tone'] ?? 'neutral');
    $style = sanitize_text_field($_POST['style'] ?? 'blog');
    $num_sections = intval($_POST['num_sections'] ?? 4);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $section_prompt = sanitize_textarea_field($_POST['section_prompt'] ?? '');
    $ai_provider = sanitize_text_field($_POST['ai_provider'] ?? 'openai');

    if (!$section_prompt) {
        $default_prompts = aiagp_get_default_prompts();
        $section_prompt = array_values($default_prompts['section'])[0];
    }

    $replace = [
        '{{topic}}' => $topic,
        '{{language}}' => $language,
        '{{length}}' => $length,
        '{{tone}}' => $tone,
        '{{style}}' => $style,
        '{{num_sections}}' => $num_sections,
        '{{title}}' => $title,
    ];
    $prompt = strtr($section_prompt, $replace);

    $api_keys = aiagp_get_api_keys();
    try {
        $result = aiagp_call_ai($ai_provider, $prompt, 800, $api_keys); // Max tokens for sections
        wp_send_json_success(trim($result));
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
    wp_die();
}

// AJAX генерация статьи
add_action('wp_ajax_aiagp_generate_article', 'aiagp_ajax_generate_article');
function aiagp_ajax_generate_article() {
    check_ajax_referer('aiagp_nonce', 'security');
    if (!current_user_can('manage_options')) wp_send_json_error(__( 'У вас нет прав для выполнения этой операции.', 'ai-generator-pro' ));

    $topic = sanitize_text_field($_POST['topic'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'українська');
    $length = intval($_POST['length'] ?? 2000); //
    $tone = sanitize_text_field($_POST['tone'] ?? 'neutral');
    $style = sanitize_text_field($_POST['style'] ?? 'blog');
    $num_sections = intval($_POST['num_sections'] ?? 4);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $sections = sanitize_textarea_field($_POST['sections'] ?? '');
    $main_prompt = sanitize_textarea_field($_POST['main_prompt'] ?? '');
    $ai_provider = sanitize_text_field($_POST['ai_provider'] ?? 'openai');

    if (!$main_prompt) {
        $default_prompts = aiagp_get_default_prompts();
        $main_prompt = array_values($default_prompts['main'])[0];
    }

    if (empty($title) || empty($sections)) wp_send_json_error(__( 'Заголовок и разделы обязательны', 'ai-generator-pro' ));

    $replace = [
        '{{topic}}' => $topic,
        '{{language}}' => $language,
        '{{length}}' => $length,
        '{{tone}}' => $tone,
        '{{style}}' => $style,
        '{{sections}}' => preg_replace('/[\r\n]+/', '; ', trim($sections)),
        '{{title}}' => $title,
        '{{num_sections}}' => $num_sections,
    ];
    $prompt = strtr($main_prompt, $replace);

    $api_keys = aiagp_get_api_keys();

    // Определение max_tokens на основе желаемой длины статьи
    $target_max_tokens = 4000;
    if ($length > 2000) {
        $target_max_tokens = 8000;
    }
    if ($ai_provider === 'gemini' && $length > 5000) {
         $target_max_tokens = 16000;
    }

    try {
        $result = aiagp_call_ai($ai_provider, $prompt, $target_max_tokens, $api_keys);
        wp_send_json_success(trim($result));
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
    wp_die();
}


function aiagp_page() {
    // Сохранение API-ключей
    if (isset($_POST['save_api_keys'])) {
        check_admin_referer('aiagp_save_api_keys');
        aiagp_save_api_keys(
            sanitize_text_field($_POST['openai_api_key'] ?? ''),
            sanitize_text_field($_POST['gemini_api_key'] ?? '')
        );
        echo '<div class="notice notice-success">' . esc_html__('API ключи сохранены!', 'ai-generator-pro') . '</div>';
    }

    // Сохранение пользовательских промптов и других настроек
    if (isset($_POST['aiagp_settings_submitted'])) {
        aiagp_save_prompts(
            $_POST['title_prompt'] ?? '',
            $_POST['section_prompt'] ?? '',
            $_POST['main_prompt'] ?? ''
        );
        echo '<div class="notice notice-success">' . esc_html__('Настройки сохранены!', 'ai-generator-pro') . '</div>';
    }

    // Сохранение статьи как черновик
    if (isset($_POST['save_article'])) {
        check_admin_referer('aiagp_save_article_nonce');

        $post_title = sanitize_text_field($_POST['ai_title'] ?? '');
        $post_content = wp_kses_post($_POST['ai_article'] ?? '');

        if (empty($post_title) || empty($post_content)) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__('Ошибка:', 'ai-generator-pro') . '</strong> ' . esc_html__('Для сохранения статьи как черновика необходим заголовок и контент.', 'ai-generator-pro') . '</p></div>';
        } else {
            $post_data = array(
                'post_title'    => $post_title,
                'post_content'  => $post_content,
                'post_status'   => 'draft',
                'post_type'     => 'post',
            );
            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id)) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__('Ошибка при сохранении:', 'ai-generator-pro') . '</strong> ' . esc_html($post_id->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Статья сохранена как черновик!', 'ai-generator-pro') . ' <a href="' . get_edit_post_link($post_id) . '" target="_blank">' . esc_html__('Редактировать черновик', 'ai-generator-pro') . '</a></p></div>';
            }
        }
    }


    $api_keys = aiagp_get_api_keys();
    $saved_prompts = aiagp_get_prompts();
    $default_prompts_data = aiagp_get_default_prompts();

    $title_prompt = $saved_prompts['title_prompt'] ?: array_values($default_prompts_data['title'])[0];
    $main_prompt = $saved_prompts['main_prompt'] ?: array_values($default_prompts_data['main'])[0];
    $section_prompt = $saved_prompts['section_prompt'] ?: array_values($default_prompts_data['section'])[0];

    // Получаем остальные настройки из POST или используем дефолтные
    $custom_topic = sanitize_text_field($_POST['custom_topic'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'українська');
    $article_length = intval($_POST['article_length'] ?? 2000);
    $tone = sanitize_text_field($_POST['tone'] ?? 'neutral');
    $style = sanitize_text_field($_POST['style'] ?? 'blog');
    $num_sections = intval($_POST['num_sections'] ?? 4);
    $ai_provider = sanitize_text_field($_POST['ai_provider'] ?? 'openai');

    // Поля, которые заполняются через AJAX
    $ai_title = sanitize_text_field($_POST['ai_title'] ?? '');
    $sections_text = sanitize_textarea_field($_POST['sections_text'] ?? '');
    $ai_article = wp_kses_post($_POST['ai_article'] ?? '');

    $tones = [
        'neutral' => __('Нейтральный', 'ai-generator-pro'),
        'friendly' => __('Дружелюбный', 'ai-generator-pro'),
        'expert' => __('Экспертный', 'ai-generator-pro'),
        'formal' => __('Формальный', 'ai-generator-pro'),
        'informative' => __('Информативный', 'ai-generator-pro'),
        'sales' => __('Продажный', 'ai-generator-pro')
    ];
    $styles = [
        'blog' => __('Обычный блог', 'ai-generator-pro'),
        'scientific' => __('Научный', 'ai-generator-pro'),
        'simple' => __('Простой', 'ai-generator-pro'),
        'conversational' => __('Разговорный', 'ai-generator-pro'),
        'creative' => __('Креативный', 'ai-generator-pro'),
        'marketing' => __('Маркетинг', 'ai-generator-pro')
    ];

    render_ai_form([
        'title_prompt' => $title_prompt,
        'main_prompt' => $main_prompt,
        'section_prompt' => $section_prompt,
        'custom_topic' => $custom_topic,
        'language' => $language,
        'article_length' => $article_length,
        'tone' => $tone,
        'style' => $style,
        'num_sections' => $num_sections,
        'ai_title' => $ai_title,
        'sections_text' => $sections_text,
        'ai_article' => $ai_article,
        'openai_api_key' => $api_keys['openai'],
        'gemini_api_key' => $api_keys['gemini'],
        'ai_provider' => $ai_provider,
        'tones' => $tones,
        'styles' => $styles,
    ]);
}

function render_ai_form($vars) {
    extract($vars);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Article Generator PRO', 'ai-generator-pro'); ?></h1>

        <div id="aiagp_messages" style="display:none; margin-top: 15px;"></div>

        <h2><?php esc_html_e('1. OpenAI и Google Gemini API Ключи', 'ai-generator-pro'); ?></h2>
        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field('aiagp_save_api_keys'); ?>
            <label for="openai_api_key"><?php esc_html_e('OpenAI API Ключ:', 'ai-generator-pro'); ?></label><br>
            <input type="password" id="openai_api_key" name="openai_api_key" style="width:350px;" value="<?php echo esc_attr($openai_api_key); ?>" placeholder="<?php esc_attr_e('Введите OpenAI API ключ', 'ai-generator-pro'); ?>" autocomplete="off">
            <label style="font-weight: normal; margin-left: 10px;">
                <input type="checkbox" id="toggle_openai_key"> <?php esc_html_e('Показать ключ', 'ai-generator-pro'); ?>
            </label><br><br>

            <label for="gemini_api_key"><?php esc_html_e('Google Gemini API Ключ:', 'ai-generator-pro'); ?></label><br>
            <input type="password" id="gemini_api_key" name="gemini_api_key" style="width:350px;" value="<?php echo esc_attr($gemini_api_key); ?>" placeholder="<?php esc_attr_e('Введите Google Gemini API ключ', 'ai-generator-pro'); ?>" autocomplete="off">
            <label style="font-weight: normal; margin-left: 10px;">
                <input type="checkbox" id="toggle_gemini_key"> <?php esc_html_e('Показать ключ', 'ai-generator-pro'); ?>
            </label><br><br>

            <input type="submit" name="save_api_keys" class="button button-primary" value="<?php esc_attr_e('Сохранить ключи', 'ai-generator-pro'); ?>">
        </form>

        <h2><?php esc_html_e('2. Настройки и генерация', 'ai-generator-pro'); ?></h2>
        <form id="aiagp-main-form" method="post" style="max-width: 900px;">
            <?php wp_nonce_field('aiagp_save_article_nonce'); ?>
            <input type="hidden" name="aiagp_settings_submitted" value="1">

            <label><b><?php esc_html_e('Промпт для заголовка:', 'ai-generator-pro'); ?></b></label><br>
            <textarea name="title_prompt" rows="2" cols="90"><?php echo esc_textarea($title_prompt); ?></textarea><br><br>

            <label><b><?php esc_html_e('Промпт для статьи:', 'ai-generator-pro'); ?></b></label><br>
            <textarea name="main_prompt" rows="3" cols="90"><?php echo esc_textarea($main_prompt); ?></textarea><br><br>

            <label><b><?php esc_html_e('Промпт для разделов:', 'ai-generator-pro'); ?></b></label><br>
            <textarea name="section_prompt" rows="2" cols="90"><?php echo esc_textarea($section_prompt); ?></textarea><br><br>

            <label><b><?php esc_html_e('Тема статьи:', 'ai-generator-pro'); ?></b></label><br>
            <input type="text" name="custom_topic" style="width:330px;" value="<?php echo esc_attr($custom_topic); ?>"><br><br>

            <label><b><?php esc_html_e('Язык:', 'ai-generator-pro'); ?></b></label>
            <select name="language">
                <option value="українська" <?php selected($language, 'українська'); ?>><?php esc_html_e('Украинский', 'ai-generator-pro'); ?></option>
                <option value="русский" <?php selected($language, 'русский'); ?>><?php esc_html_e('Русский', 'ai-generator-pro'); ?></option>
                <option value="english" <?php selected($language, 'english'); ?>><?php esc_html_e('Английский', 'ai-generator-pro'); ?></option>
            </select>&nbsp;&nbsp;&nbsp;

            <label><b><?php esc_html_e('Длина (символов):', 'ai-generator-pro'); ?></b></label>
            <input type="number" name="article_length" min="500" max="10000" step="100" value="<?php echo esc_attr($article_length); ?>"><br><br>

            <label><b><?php esc_html_e('Тон:', 'ai-generator-pro'); ?></b></label>
            <select name="tone">
                <?php
                foreach ($tones as $val => $label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($tone, $val, false), esc_html($label));
                }
                ?>
            </select> &nbsp;

            <label><b><?php esc_html_e('Стиль:', 'ai-generator-pro'); ?></b></label>
            <select name="style">
                <?php
                foreach ($styles as $val => $label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($style, $val, false), esc_html($label));
                }
                ?>
            </select>&nbsp;&nbsp;&nbsp;

            <label><b><?php esc_html_e('Использовать AI:', 'ai-generator-pro'); ?></b></label>
            <select name="ai_provider">
                <option value="openai" <?php selected($ai_provider, 'openai'); ?>><?php esc_html_e('OpenAI (GPT-4o)', 'ai-generator-pro'); ?></option>
                <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>><?php esc_html_e('Google Gemini (Gemini 1.5 Pro)', 'ai-generator-pro'); ?></option>
            </select><br><br>

            <label><b><?php esc_html_e('Количество разделов:', 'ai-generator-pro'); ?></b></label>
            <select name="num_sections">
                <?php for ($i=2; $i<=7; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected($num_sections, $i); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select><br><br>

            <label><b><?php esc_html_e('Заголовок статьи ({{title}}):', 'ai-generator-pro'); ?></b></label><br>
            <input type="text" name="ai_title" id="ai_title_input" style="width:600px;" value="<?php echo esc_attr($ai_title); ?>">
            <button type="button" id="generate_title_btn" class="button" style="margin-left:5px;"><?php esc_html_e('Сгенерировать заголовок', 'ai-generator-pro'); ?></button>
            <span id="title_loader" style="display:none; margin-left: 10px;">⏳</span>
            <br><br>

            <label><b><?php esc_html_e('Список разделов (каждая строка — заголовок, {{sections}}):', 'ai-generator-pro'); ?></b></label><br>
            <textarea name="sections_text" id="sections_text_area" rows="5" cols="90"><?php echo esc_textarea($sections_text); ?></textarea>
            <button type="button" id="generate_sections_btn" class="button" style="margin-left:5px;"><?php esc_html_e('Сгенерировать разделы', 'ai-generator-pro'); ?></button>
            <span id="sections_loader" style="display:none; margin-left: 10px;">⏳</span>
            <br><br>

            <label><b><?php esc_html_e('Текст статьи ({{article}}):', 'ai-generator-pro'); ?></b></label><br>
            <textarea name="ai_article" id="ai_article_area" rows="15" cols="90"><?php echo esc_textarea($ai_article); ?></textarea>
            <br>

            <?php
            $disabled = (trim($ai_title) === '' || trim($sections_text) === '');
            ?>
            <button type="button" id="generate_article_btn" class="button button-primary" <?php echo $disabled ? 'disabled style="opacity:0.7;"' : ''; ?>><?php esc_html_e('Сгенерировать статью', 'ai-generator-pro'); ?></button>
            <span id="article_loader" style="display:none; margin-left: 10px;">⏳</span>

            <input type="submit" name="save_article" class="button button-secondary" value="<?php esc_attr_e('Сохранить как черновик', 'ai-generator-pro'); ?>" style="margin-left: 15px;">
        </form>
    </div>
    <?php
}

// Функция для вызова OpenAI API
function aiagp_gpt($prompt, $openai_api_key, $max_tokens=800) {
    $api_url = 'https://api.openai.com/v1/chat/completions';
    $request_body = json_encode([
        "model" => "gpt-4o",
        "messages" => [[ "role" => "user", "content" => $prompt ]],
        "max_tokens" => $max_tokens,
        "temperature" => 0.7
    ]);

    $response = wp_remote_post($api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $openai_api_key,
        ],
        'body'    => $request_body,
        'timeout' => 80,
    ]);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("AIAGP OpenAI API Error: " . $error_message . " | Prompt: " . substr($prompt, 0, 200));
        return sprintf(__( 'Ошибка API OpenAI: %s. %s', 'ai-generator-pro' ), $error_message, __( 'Пожалуйста, проверьте ваше интернет-соединение или настройки сервера.', 'ai-generator-pro' ));
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if ($http_code !== 200) {
        $error_details = '';
        $user_friendly_message = '';

        if (isset($body['error']['message'])) {
            $error_details = $body['error']['message'];
            if ($http_code === 429) {
                $user_friendly_message = __( 'Превышен лимит использования OpenAI API. Пожалуйста, проверьте свой тарифный план и платежные данные на платформе OpenAI.', 'ai-generator-pro' );
            } else {
                $user_friendly_message = sprintf(__( 'OpenAI API вернул ошибку: %s', 'ai-generator-pro' ), $error_details);
            }
        } else {
             $user_friendly_message = sprintf(__( 'OpenAI API вернул ошибку с HTTP кодом %d. Подробности: %s', 'ai-generator-pro' ), $http_code, $body_raw);
        }

        error_log("AIAGP OpenAI API HTTP Error ($http_code): " . $error_details . " | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
        return sprintf(__( 'Ошибка OpenAI (HTTP %d): %s', 'ai-generator-pro' ), $http_code, $user_friendly_message);
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    error_log("AIAGP OpenAI API Empty Content Error: No content in response. | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
    return __( 'Ошибка OpenAI: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.', 'ai-generator-pro' );
}

// Функция для вызова Google Gemini API
function aiagp_gemini($prompt, $gemini_api_key, $max_tokens=800) {
    $model_name = "gemini-1.5-pro-latest";
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model_name . ':generateContent?key=' . $gemini_api_key;
    $request_body = json_encode([
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "maxOutputTokens" => $max_tokens,
            "temperature" => 0.7,
        ],
    ]);

    $response = wp_remote_post($api_url, [
        'headers' => [
            'Content-Type'  => 'application/json',
        ],
        'body'    => $request_body,
        'timeout' => 80,
    ]);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("AIAGP Gemini API Error: " . $error_message . " | Prompt: " . substr($prompt, 0, 200));
        return sprintf(__( 'Ошибка API Gemini: %s. %s', 'ai-generator-pro' ), $error_message, __( 'Пожалуйста, проверьте ваше интернет-соединение или настройки сервера.', 'ai-generator-pro' ));
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if ($http_code !== 200) {
        $error_details = '';
        $user_friendly_message = '';

        if (isset($body['error']['message'])) {
            $error_details = $body['error']['message'];
            if (strpos($error_details, 'User location is not supported') !== false) {
                 $user_friendly_message = __( 'Ваше текущее местоположение не поддерживается для использования Google Gemini API. Пожалуйста, попробуйте воспользоваться VPN или другим хостингом.', 'ai-generator-pro' );
            } else {
                 $user_friendly_message = sprintf(__( 'Google Gemini API вернул ошибку: %s', 'ai-generator-pro' ), $error_details);
            }
        } else {
            $user_friendly_message = sprintf(__( 'Google Gemini API вернул ошибку с HTTP кодом %d. Подробности: %s', 'ai-generator-pro' ), $http_code, $body_raw);
        }

        error_log("AIAGP Gemini API HTTP Error ($http_code): " . $error_details . " | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
        return sprintf(__( 'Ошибка Gemini (HTTP %d): %s', 'ai-generator-pro' ), $http_code, $user_friendly_message);
    }

    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return $body['candidates'][0]['content']['parts'][0]['text'];
    }

    error_log("AIAGP Gemini API Empty Content Error: No content in response. | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
    return __( 'Ошибка Gemini: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.', 'ai-generator-pro' );
}
