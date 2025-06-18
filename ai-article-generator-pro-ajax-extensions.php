Я понял. Мы интегрируем улучшения из пунктов 2, 3 и 4 в ваш файл `ai-article-generator-pro-ajax-extensions.php`.

**Изменения, которые будут внесены:**

* **Пункт 2 (Оптимизация `max_tokens`):** Изменим логику расчета `max_tokens` для основной статьи, чтобы она была более гибкой и учитывала потенциал Gemini 1.5 Pro для более длинных текстов, сохраняя при этом контроль.
* **Пункт 3 (Избегать дублирования дефолтных промптов):** Вынесем дефолтные промпты в отдельные функции или константы для предотвращения дублирования кода.
* **Пункт 4 (Улучшить обработку ошибок API в `aiagp_call_ai`):** Изменим `aiagp_call_ai()` так, чтобы она генерировала ошибку, если API-ключ отсутствует, а не возвращала строку. Обработка этой ошибки будет перемещена в AJAX-обработчики.

Вот готовый код файла `ai-article-generator-pro-ajax-extensions.php` с интегрированными изменениями:

```php
<?php
/*
Plugin Name: AI Article Generator PRO AJAX Extensions
Description: Версия 2.3 плагина с добавлением AJAX-генерации разделов и статьи и индикаторами загрузки,
а также поддержкой Google Gemini API, с улучшенным логированием ошибок.
Version: 2.4
Author: th7
Git: https://github.com/th777/aiag/
*/

// Убедимся, что WordPress загружен
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * ========================================
 * Вспомогательные функции для дефолтных промптов
 * ========================================
 */
function aiagp_get_default_prompts() {
    return [
        'title' => [
            'UA Заголовок' => 'Сгенеруй унікальний головний заголовок (H1) для статті на тему {{topic}} мовою {{language}}. Враховуй тон: {{tone}}, стиль: {{style}} та бажану довжину: не більше 80 символів.',
            'EN Title' => 'Generate a unique H1 headline for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Length: {{length}}. Title should be catchy, relevant and no more than 80 characters.'
        ],
        'section' => [
            'UA Розділи' => 'Сформуй список із {{num_sections}} заголовків розділів для статті на тему {{topic}} українською мовою. Враховуй тон: {{tone}}, стиль: {{style}}, обсяг: {{length}} символів, заголовок: {{title}}.',
            'EN Sections' => 'Generate a list of {{num_sections}} relevant section titles for an article about {{topic}} in {{language}}. Tone: {{tone}}, Style: {{style}}, Title: {{title}}, Length: {{length}} characters. Only section titles, one per line, nothing else.'
        ],
        'main' => [
            'UA Статья' => 'Напиши статтю на тему {{topic}} українською мовою. Заголовок: {{title}}. Структуруй по розділам: {{sections}}. Тон: {{tone}}, стиль: {{style}}. Довжина: {{length}} символів.',
            'EN Article' => 'Write a detailed article about {{topic}} in {{language}}. Title: {{title}}. Use these sections: {{sections}}. Tone: {{tone}}, Style: {{style}}. Length: {{length}} characters.'
        ]
    ];
}


add_action('admin_menu', 'aiagp_menu');
function aiagp_menu() {
    add_menu_page(
        'AI Article Generator',
        'AI статьи',
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
        'nonce' => wp_create_nonce('aiagp_nonce')
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
            throw new Exception('Ошибка: Google Gemini API ключ не установлен.');
        }
        return aiagp_gemini($prompt, $api_keys['gemini'], $max_tokens);
    } else { // 'openai' по умолчанию
        if (empty($api_keys['openai'])) {
            throw new Exception('Ошибка: OpenAI API ключ не установлен.');
        }
        return aiagp_gpt($prompt, $api_keys['openai'], $max_tokens);
    }
}

// AJAX генерация заголовка
add_action('wp_ajax_aiagp_generate_title', 'aiagp_ajax_generate_title');
function aiagp_ajax_generate_title() {
    check_ajax_referer('aiagp_nonce', 'security');
    if (!current_user_can('manage_options')) wp_send_json_error('Нет прав');

    $topic = sanitize_text_field($_POST['topic'] ?? '');
    $language = sanitize_text_field($_POST['language'] ?? 'українська');
    $length = intval($_POST['length'] ?? 2000);
    $tone = sanitize_text_field($_POST['tone'] ?? 'neutral');
    $style = sanitize_text_field($_POST['style'] ?? 'blog');
    $num_sections = intval($_POST['num_sections'] ?? 4);
    $title_prompt = sanitize_textarea_field($_POST['title_prompt'] ?? '');
    $ai_provider = sanitize_text_field($_POST['ai_provider'] ?? 'openai');

    if (!$title_prompt) {
        $default_prompts = aiagp_get_default_prompts(); //
        $title_prompt = array_values($default_prompts['title'])[0]; //
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
    if (!current_user_can('manage_options')) wp_send_json_error('Нет прав');

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
        $default_prompts = aiagp_get_default_prompts(); //
        $section_prompt = array_values($default_prompts['section'])[0]; //
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
    if (!current_user_can('manage_options')) wp_send_json_error('Нет прав');

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
        $default_prompts = aiagp_get_default_prompts(); //
        $main_prompt = array_values($default_prompts['main'])[0]; //
    }

    if (empty($title) || empty($sections)) wp_send_json_error('Заголовок и разделы обязательны');

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
    // Используем более высокий лимит для Gemini, если он выбран, учитывая его возможности.
    // GPT-4o также поддерживает большие контексты, но 4000 токенов - это хороший минимум для длинной статьи.
    $target_max_tokens = 4000; // Базовый разумный лимит для большинства статей
    if ($length > 2000) { // Если пользователь запросил очень длинную статью (более 2000 символов)
        $target_max_tokens = 8000; // Можем попробовать до 8000 токенов, если провайдер позволяет
    }
    // Можно еще увеличить для Gemini, если уверены, что модель справится и это не приведет к перерасходу.
    if ($ai_provider === 'gemini' && $length > 5000) { // Например, для очень больших статей
         $target_max_tokens = 16000; // Gemini 1.5 Pro поддерживает до 1 млн токенов, но это очень много для одной статьи.
    }

    try {
        $result = aiagp_call_ai($ai_provider, $prompt, $target_max_tokens, $api_keys); //
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
        echo '<div class="notice notice-success">API ключи сохранены!</div>';
    }

    // Сохранение пользовательских промптов и других настроек
    if (isset($_POST['aiagp_settings_submitted'])) {
        aiagp_save_prompts(
            $_POST['title_prompt'] ?? '',
            $_POST['section_prompt'] ?? '',
            $_POST['main_prompt'] ?? ''
        );
        echo '<div class="notice notice-success">Настройки сохранены!</div>';
    }

    // Сохранение статьи как черновик
    if (isset($_POST['save_article'])) {
        check_admin_referer('aiagp_save_article_nonce');

        $post_title = sanitize_text_field($_POST['ai_title'] ?? '');
        $post_content = wp_kses_post($_POST['ai_article'] ?? '');

        if (empty($post_title) || empty($post_content)) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Ошибка:</strong> Для сохранения статьи как черновика необходим заголовок и контент.</p></div>';
        } else {
            $post_data = array(
                'post_title'    => $post_title,
                'post_content'  => $post_content,
                'post_status'   => 'draft',
                'post_type'     => 'post',
            );
            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id)) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Ошибка при сохранении:</strong> ' . esc_html($post_id->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Статья сохранена как черновик! <a href="' . get_edit_post_link($post_id) . '" target="_blank">Редактировать черновик</a></p></div>';
            }
        }
    }


    $api_keys = aiagp_get_api_keys();
    $saved_prompts = aiagp_get_prompts();
    $default_prompts_data = aiagp_get_default_prompts(); //

    $title_prompt = $saved_prompts['title_prompt'] ?: array_values($default_prompts_data['title'])[0]; //
    $main_prompt = $saved_prompts['main_prompt'] ?: array_values($default_prompts_data['main'])[0]; //
    $section_prompt = $saved_prompts['section_prompt'] ?: array_values($default_prompts_data['section'])[0]; //

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
        'neutral' => 'Нейтральный',
        'friendly' => 'Дружелюбный',
        'expert' => 'Экспертный',
        'formal' => 'Формальный',
        'informative' => 'Информативный',
        'sales' => 'Продажный'
    ];
    $styles = [
        'blog' => 'Обычный блог',
        'scientific' => 'Научный',
        'simple' => 'Простой',
        'conversational' => 'Разговорный',
        'creative' => 'Креативный',
        'marketing' => 'Маркетинг'
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
        <h1>AI Article Generator PRO</h1>

        <div id="aiagp_messages" style="display:none; margin-top: 15px;"></div>

        <h2>1. OpenAI и Google Gemini API Ключи</h2>
        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field('aiagp_save_api_keys'); ?>
            <label for="openai_api_key">OpenAI API Ключ:</label><br>
            <input type="password" id="openai_api_key" name="openai_api_key" style="width:350px;" value="<?php echo esc_attr($openai_api_key); ?>" placeholder="Введите OpenAI API ключ" autocomplete="off">
            <label style="font-weight: normal; margin-left: 10px;">
                <input type="checkbox" id="toggle_openai_key"> Показать ключ
            </label><br><br>

            <label for="gemini_api_key">Google Gemini API Ключ:</label><br>
            <input type="password" id="gemini_api_key" name="gemini_api_key" style="width:350px;" value="<?php echo esc_attr($gemini_api_key); ?>" placeholder="Введите Google Gemini API ключ" autocomplete="off">
            <label style="font-weight: normal; margin-left: 10px;">
                <input type="checkbox" id="toggle_gemini_key"> Показать ключ
            </label><br><br>

            <input type="submit" name="save_api_keys" class="button button-primary" value="Сохранить ключи">
        </form>

        <h2>2. Настройки и генерация</h2>
        <form id="aiagp-main-form" method="post" style="max-width: 900px;">
            <?php wp_nonce_field('aiagp_save_article_nonce'); ?>
            <input type="hidden" name="aiagp_settings_submitted" value="1">

            <label><b>Промпт для заголовка:</b></label><br>
            <textarea name="title_prompt" rows="2" cols="90"><?php echo esc_textarea($title_prompt); ?></textarea><br><br>

            <label><b>Промпт для статьи:</b></label><br>
            <textarea name="main_prompt" rows="3" cols="90"><?php echo esc_textarea($main_prompt); ?></textarea><br><br>

            <label><b>Промпт для разделов:</b></label><br>
            <textarea name="section_prompt" rows="2" cols="90"><?php echo esc_textarea($section_prompt); ?></textarea><br><br>

            <label><b>Тема статьи:</b></label><br>
            <input type="text" name="custom_topic" style="width:330px;" value="<?php echo esc_attr($custom_topic); ?>"><br><br>

            <label><b>Язык:</b></label>
            <select name="language">
                <option value="українська" <?php selected($language, 'українська'); ?>>українська</option>
                <option value="русский" <?php selected($language, 'русский'); ?>>русский</option>
                <option value="english" <?php selected($language, 'english'); ?>>english</option>
            </select>&nbsp;&nbsp;&nbsp;

            <label><b>Длина (символов):</b></label>
            <input type="number" name="article_length" min="500" max="10000" step="100" value="<?php echo esc_attr($article_length); ?>"><br><br>

            <label><b>Тон:</b></label>
            <select name="tone">
                <?php
                foreach ($tones as $val => $label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($tone, $val, false), esc_html($label));
                }
                ?>
            </select> &nbsp;

            <label><b>Стиль:</b></label>
            <select name="style">
                <?php
                foreach ($styles as $val => $label) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($style, $val, false), esc_html($label));
                }
                ?>
            </select>&nbsp;&nbsp;&nbsp;

            <label><b>Использовать AI:</b></label>
            <select name="ai_provider">
                <option value="openai" <?php selected($ai_provider, 'openai'); ?>>OpenAI (GPT-4o)</option>
                <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>>Google Gemini (Gemini 1.5 Pro)</option>
            </select><br><br>

            <label><b>Количество разделов:</b></label>
            <select name="num_sections">
                <?php for ($i=2; $i<=7; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php selected($num_sections, $i); ?>><?php echo $i; ?></option>
                <?php endfor; ?>
            </select><br><br>

            <label><b>Заголовок статьи ({{title}}):</b></label><br>
            <input type="text" name="ai_title" id="ai_title_input" style="width:600px;" value="<?php echo esc_attr($ai_title); ?>">
            <button type="button" id="generate_title_btn" class="button" style="margin-left:5px;">Сгенерировать заголовок</button>
            <span id="title_loader" style="display:none; margin-left: 10px;">⏳</span>
            <br><br>

            <label><b>Список разделов (каждая строка — заголовок, {{sections}}):</b></label><br>
            <textarea name="sections_text" id="sections_text_area" rows="5" cols="90"><?php echo esc_textarea($sections_text); ?></textarea>
            <button type="button" id="generate_sections_btn" class="button" style="margin-left:5px;">Сгенерировать разделы</button>
            <span id="sections_loader" style="display:none; margin-left: 10px;">⏳</span>
            <br><br>

            <label><b>Текст статьи ({{article}}):</b></label><br>
            <textarea name="ai_article" id="ai_article_area" rows="15" cols="90"><?php echo esc_textarea($ai_article); ?></textarea>
            <br>

            <?php
            $disabled = (trim($ai_title) === '' || trim($sections_text) === '');
            ?>
            <button type="button" id="generate_article_btn" class="button button-primary" <?php echo $disabled ? 'disabled style="opacity:0.7;"' : ''; ?>>Сгенерировать статью</button>
            <span id="article_loader" style="display:none; margin-left: 10px;">⏳</span>

            <input type="submit" name="save_article" class="button button-secondary" value="Сохранить как черновик" style="margin-left: 15px;">
        </form>
    </div>

    <script>
    // Все JS переносим в aiagp-admin.js
    // document.getElementById('toggle_openai_key').addEventListener('change', function() {
    //     var input = document.getElementById('openai_api_key');
    //     input.type = this.checked ? 'text' : 'password';
    // });
    // document.getElementById('toggle_gemini_key').addEventListener('change', function() {
    //     var input = document.getElementById('gemini_api_key');
    //     input.type = this.checked ? 'text' : 'password';
    // });

    // jQuery(function($){
    //     // Функция для отображения сообщений пользователю
    //     function showMessage(msg, type = 'error') {
    //         const messagesDiv = $('#aiagp_messages');
    //         messagesDiv.removeClass('notice notice-success notice-error').addClass('notice is-dismissible');
    //         if (type === 'success') {
    //             messagesDiv.addClass('notice-success').html('<p><strong>' + msg + '</strong></p>');
    //         } else {
    //             messagesDiv.addClass('notice-error').html('<p><strong>' + msg + '</strong></p>');
    //         }
    //         messagesDiv.show();
    //         // Скрыть сообщение через 8 секунд
    //         setTimeout(() => messagesDiv.fadeOut(500), 8000);
    //     }

    //     function ajaxGenerate(button, loaderSpan, ajaxData, successCallback) {
    //         button.prop('disabled', true).css('opacity', '0.7');
    //         loaderSpan.show();
    //         $('#aiagp_messages').hide(); // Скрыть предыдущие сообщения

    //         $.post(aiagp_ajax.ajax_url, ajaxData, function(response){
    //             if(response.success){
    //                 successCallback(response.data);
    //                 showMessage('Генерация завершена успешно!', 'success');
    //             } else {
    //                 showMessage(response.data);
    //             }
    //             loaderSpan.hide();
    //             button.prop('disabled', false).css('opacity', '1');
    //             updateArticleButtonState(); // Обновить состояние кнопки "Сгенерировать статью"
    //         }).fail(function(jqXHR, textStatus, errorThrown) {
    //             showMessage('AJAX Ошибка: ' + textStatus + (errorThrown ? ' - ' + errorThrown : '') + '. Проверьте консоль для деталей.');
    //             console.error('AJAX Error:', textStatus, errorThrown, jqXHR);
    //             loaderSpan.hide();
    //             button.prop('disabled', false).css('opacity', '1');
    //         });
    //     }

    //     // Обновление состояния кнопки "Сгенерировать статью"
    //     function updateArticleButtonState() {
    //         const title = $('#ai_title_input').val().trim();
    //         const sections = $('#sections_text_area').val().trim();
    //         const generateArticleBtn = $('#generate_article_btn');

    //         if (title !== '' && sections !== '') {
    //             generateArticleBtn.prop('disabled', false).css('opacity', '1');
    //         } else {
    //             generateArticleBtn.prop('disabled', true).css('opacity', '0.7');
    //         }
    //     }

    //     // Вызов при загрузке страницы и при изменении полей
    //     $(document).ready(function() {
    //         updateArticleButtonState();
    //         $('#ai_title_input, #sections_text_area').on('input', updateArticleButtonState);
    //     });

    //     $('#generate_title_btn').click(function(){
    //         ajaxGenerate(
    //             $(this),
    //             $('#title_loader'),
    //             {
    //                 action: 'aiagp_generate_title',
    //                 security: aiagp_ajax.nonce,
    //                 topic: $('input[name="custom_topic"]').val(),
    //                 language: $('select[name="language"]').val(),
    //                 length: $('input[name="article_length"]').val(),
    //                 tone: $('select[name="tone"]').val(),
    //                 style: $('select[name="style"]').val(),
    //                 num_sections: $('select[name="num_sections"]').val(),
    //                 title_prompt: $('textarea[name="title_prompt"]').val(),
    //                 ai_provider: $('select[name="ai_provider"]').val()
    //             },
    //             function(data){ $('#ai_title_input').val(data); }
    //         );
    //     });

    //     $('#generate_sections_btn').click(function(){
    //         ajaxGenerate(
    //             $(this),
    //             $('#sections_loader'),
    //             {
    //                 action: 'aiagp_generate_sections',
    //                 security: aiagp_ajax.nonce,
    //                 topic: $('input[name="custom_topic"]').val(),
    //                 language: $('select[name="language"]').val(),
    //                 length: $('input[name="article_length"]').val(),
    //                 tone: $('select[name="tone"]').val(),
    //                 style: $('select[name="style"]').val(),
    //                 num_sections: $('select[name="num_sections"]').val(),
    //                 title: $('input[name="ai_title"]').val(),
    //                 section_prompt: $('textarea[name="section_prompt"]').val(),
    //                 ai_provider: $('select[name="ai_provider"]').val()
    //             },
    //             function(data){ $('#sections_text_area').val(data); }
    //         );
    //     });

    //     $('#generate_article_btn').click(function(){
    //         ajaxGenerate(
    //             $(this),
    //             $('#article_loader'),
    //             {
    //                 action: 'aiagp_generate_article',
    //                 security: aiagp_ajax.nonce,
    //                 topic: $('input[name="custom_topic"]').val(),
    //                 language: $('select[name="language"]').val(),
    //                 length: $('input[name="article_length"]').val(),
    //                 tone: $('select[name="tone"]').val(),
    //                 style: $('select[name="style"]').val(),
    //                 num_sections: $('select[name="num_sections"]').val(),
    //                 title: $('input[name="ai_title"]').val(),
    //                 sections: $('textarea[name="sections_text"]').val(),
    //                 main_prompt: $('textarea[name="main_prompt"]').val(),
    //                 ai_provider: $('select[name="ai_provider"]').val()
    //             },
    //             function(data){ $('#ai_article_area').val(data); }
    //         );
    //     });
    // });
    </script>
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
        return 'Ошибка API OpenAI: ' . $error_message . ' Пожалуйста, проверьте ваше интернет-соединение или настройки сервера.';
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if ($http_code !== 200) {
        $error_details = 'Неизвестная ошибка.';
        if (isset($body['error']['message'])) {
            $error_details = $body['error']['message'];
            if ($http_code === 429) {
                $user_friendly_message = 'Превышен лимит использования OpenAI API. Пожалуйста, проверьте свой тарифный план и платежные данные на платформе OpenAI.';
            } else {
                $user_friendly_message = 'OpenAI API вернул ошибку: ' . $error_details;
            }
        } else {
             $user_friendly_message = 'OpenAI API вернул ошибку с HTTP кодом ' . $http_code . '. Подробности: ' . $body_raw;
        }

        error_log("AIAGP OpenAI API HTTP Error ($http_code): " . $error_details . " | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
        return 'Ошибка OpenAI (HTTP ' . $http_code . '): ' . $user_friendly_message;
    }

    if (isset($body['choices'][0]['message']['content'])) {
        return $body['choices'][0]['message']['content'];
    }

    error_log("AIAGP OpenAI API Empty Content Error: No content in response. | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
    return 'Ошибка OpenAI: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.';
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
        return 'Ошибка API Gemini: ' . $error_message . ' Пожалуйста, проверьте ваше интернет-соединение или настройки сервера.';
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);

    if ($http_code !== 200) {
        $error_details = 'Неизвестная ошибка.';
        $user_friendly_message = 'Gemini API вернул ошибку.';

        if (isset($body['error']['message'])) {
            $error_details = $body['error']['message'];
            if (strpos($error_details, 'User location is not supported') !== false) {
                 $user_friendly_message = 'Ваше текущее местоположение не поддерживается для использования Google Gemini API. Пожалуйста, попробуйте воспользоваться VPN или другим хостингом.';
            } else {
                 $user_friendly_message = 'Google Gemini API вернул ошибку: ' . $error_details;
            }
        } else {
            $user_friendly_message = 'Google Gemini API вернул ошибку с HTTP кодом ' . $http_code . '. Подробности: ' . $body_raw;
        }

        error_log("AIAGP Gemini API HTTP Error ($http_code): " . $error_details . " | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
        return 'Ошибка Gemini (HTTP ' . $http_code . '): ' . $user_friendly_message;
    }

    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return $body['candidates'][0]['content']['parts'][0]['text'];
    }

    error_log("AIAGP Gemini API Empty Content Error: No content in response. | Prompt: " . substr($prompt, 0, 200) . " | Response Body: " . $body_raw);
    return 'Ошибка Gemini: не получен ожидаемый результат. Пожалуйста, попробуйте еще раз.';
}
