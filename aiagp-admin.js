jQuery(function($){
    // Функция для отображения сообщений пользователю
    function showMessage(msg, type = 'error') {
        const messagesDiv = $('#aiagp_messages');
        messagesDiv.removeClass('notice notice-success notice-error').addClass('notice is-dismissible');
        if (type === 'success') {
            messagesDiv.addClass('notice-success').html('<p><strong>' + msg + '</strong></p>');
        } else {
            messagesDiv.addClass('notice-error').html('<p><strong>' + msg + '</strong></p>');
        }
        messagesDiv.show();
        // Скрыть сообщение через 8 секунд
        setTimeout(() => messagesDiv.fadeOut(500), 8000);
    }

    // Общая функция для выполнения AJAX-запросов генерации
    function ajaxGenerate(button, loaderSpan, ajaxData, successCallback) {
        button.prop('disabled', true).css('opacity', '0.7');
        loaderSpan.show();
        $('#aiagp_messages').hide(); // Скрыть предыдущие сообщения

        $.post(aiagp_ajax.ajax_url, ajaxData, function(response){
            if(response.success){
                successCallback(response.data);
                showMessage('Генерация завершена успешно!', 'success');
            } else {
                showMessage(response.data);
            }
            loaderSpan.hide();
            button.prop('disabled', false).css('opacity', '1');
            updateArticleButtonState(); // Обновить состояние кнопки "Сгенерировать статью"
        }).fail(function(jqXHR, textStatus, errorThrown) {
            let errorMessage = 'AJAX Ошибка: ' + textStatus;
            if (errorThrown) {
                errorMessage += ' - ' + errorThrown;
            }
            if (jqXHR.responseText) {
                try {
                    const errorResponse = JSON.parse(jqXHR.responseText);
                    if (errorResponse.data) {
                        errorMessage = errorResponse.data; // Используем сообщение об ошибке из PHP
                    }
                } catch (e) {
                    // Если ответ не JSON, используем как есть
                    errorMessage += ' - ' + jqXHR.responseText;
                }
            }
            showMessage(errorMessage + '. Проверьте консоль для деталей.');
            console.error('AJAX Error:', textStatus, errorThrown, jqXHR);
            loaderSpan.hide();
            button.prop('disabled', false).css('opacity', '1');
        });
    }

    // Обновление состояния кнопки "Сгенерировать статью"
    function updateArticleButtonState() {
        const title = $('#ai_title_input').val().trim();
        const sections = $('#sections_text_area').val().trim();
        const generateArticleBtn = $('#generate_article_btn');

        if (title !== '' && sections !== '') {
            generateArticleBtn.prop('disabled', false).css('opacity', '1');
        } else {
            generateArticleBtn.prop('disabled', true).css('opacity', '0.7');
        }
    }

    // Обработчики событий DOMContentLoaded или jQuery(document).ready
    $(document).ready(function() {
        updateArticleButtonState(); // Вызов при загрузке страницы
        $('#ai_title_input, #sections_text_area').on('input', updateArticleButtonState); // Вызов при изменении полей

        // Переключатели видимости API ключей
        $('#toggle_openai_key').on('change', function() {
            var input = $('#openai_api_key');
            input.attr('type', this.checked ? 'text' : 'password');
        });
        $('#toggle_gemini_key').on('change', function() {
            var input = $('#gemini_api_key');
            input.attr('type', this.checked ? 'text' : 'password');
        });

        // Обработчик кнопки "Сгенерировать заголовок"
        $('#generate_title_btn').on('click', function(){
            ajaxGenerate(
                $(this),
                $('#title_loader'),
                {
                    action: 'aiagp_generate_title',
                    security: aiagp_ajax.nonce,
                    topic: $('input[name="custom_topic"]').val(),
                    language: $('select[name="language"]').val(),
                    length: $('input[name="article_length"]').val(),
                    tone: $('select[name="tone"]').val(),
                    style: $('select[name="style"]').val(),
                    num_sections: $('select[name="num_sections"]').val(),
                    title_prompt: $('textarea[name="title_prompt"]').val(),
                    ai_provider: $('select[name="ai_provider"]').val()
                },
                function(data){ $('#ai_title_input').val(data); }
            );
        });

        // Обработчик кнопки "Сгенерировать разделы"
        $('#generate_sections_btn').on('click', function(){
            ajaxGenerate(
                $(this),
                $('#sections_loader'),
                {
                    action: 'aiagp_generate_sections',
                    security: aiagp_ajax.nonce,
                    topic: $('input[name="custom_topic"]').val(),
                    language: $('select[name="language"]').val(),
                    length: $('input[name="article_length"]').val(),
                    tone: $('select[name="tone"]').val(),
                    style: $('select[name="style"]').val(),
                    num_sections: $('select[name="num_sections"]').val(),
                    title: $('input[name="ai_title"]').val(),
                    section_prompt: $('textarea[name="section_prompt"]').val(),
                    ai_provider: $('select[name="ai_provider"]').val()
                },
                function(data){ $('#sections_text_area').val(data); }
            );
        });

        // Обработчик кнопки "Сгенерировать статью"
        $('#generate_article_btn').on('click', function(){
            ajaxGenerate(
                $(this),
                $('#article_loader'),
                {
                    action: 'aiagp_generate_article',
                    security: aiagp_ajax.nonce,
                    topic: $('input[name="custom_topic"]').val(),
                    language: $('select[name="language"]').val(),
                    length: $('input[name="article_length"]').val(),
                    tone: $('select[name="tone"]').val(),
                    style: $('select[name="style"]').val(),
                    num_sections: $('select[name="num_sections"]').val(),
                    title: $('input[name="ai_title"]').val(),
                    sections: $('textarea[name="sections_text"]').val(),
                    main_prompt: $('textarea[name="main_prompt"]').val(),
                    ai_provider: $('select[name="ai_provider"]').val()
                },
                function(data){ $('#ai_article_area').val(data); }
            );
        });
    });
});
