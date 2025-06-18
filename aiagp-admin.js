jQuery(function($){
    // Function to display messages to the user
    function showMessage(msg, type = 'error') {
        const messagesDiv = $('#aiagp_messages');
        messagesDiv.removeClass('notice notice-success notice-error').addClass('notice is-dismissible');
        if (type === 'success') {
            messagesDiv.addClass('notice-success').html('<p><strong>' + msg + '</strong></p>');
        } else {
            messagesDiv.addClass('notice-error').html('<p><strong>' + msg + '</strong></p>');
        }
        messagesDiv.show();
        // Hide message after 8 seconds
        setTimeout(() => messagesDiv.fadeOut(500), 8000);
    }

    // Generic function for making AJAX generation requests
    function ajaxGenerate(button, loaderSpan, ajaxData, successCallback) {
        button.prop('disabled', true).css('opacity', '0.7');
        loaderSpan.show();
        $('#aiagp_messages').hide(); // Hide previous messages

        $.post(aiagp_ajax.ajax_url, ajaxData, function(response){
            if(response.success){
                successCallback(response.data);
                showMessage(aiagp_ajax.messages.success_generation, 'success');
            } else {
                showMessage(response.data); // PHP already returns the localized error message
            }
            loaderSpan.hide();
            button.prop('disabled', false).css('opacity', '1');
            updateArticleButtonState(); // Update the state of the "Generate Article" button
        }).fail(function(jqXHR, textStatus, errorThrown) {
            let errorMessage = aiagp_ajax.messages.ajax_error + ' ' + textStatus;
            if (errorThrown) {
                errorMessage += ' - ' + errorThrown;
            }
            if (jqXHR.responseText) {
                try {
                    const errorResponse = JSON.parse(jqXHR.responseText);
                    if (errorResponse.data) {
                        errorMessage = errorResponse.data; // Use the error message from PHP
                    }
                } catch (e) {
                    // If response is not JSON, use as is
                    errorMessage += ' - ' + jqXHR.responseText;
                }
            }
            showMessage(errorMessage + '. ' + aiagp_ajax.messages.api_connection_error);
            console.error('AJAX Error:', textStatus, errorThrown, jqXHR);
            loaderSpan.hide();
            button.prop('disabled', false).css('opacity', '1');
        });
    }

    // Update the state of the "Generate Article" button
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

    // DOMContentLoaded or jQuery(document).ready handlers
    $(document).ready(function() {
        updateArticleButtonState(); // Call on page load
        $('#ai_title_input, #sections_text_area').on('input', updateArticleButtonState); // Call when fields change

        // API key visibility toggles
        $('#toggle_openai_key').on('change', function() {
            var input = $('#openai_api_key');
            input.attr('type', this.checked ? 'text' : 'password');
        });
        $('#toggle_gemini_key').on('change', function() {
            var input = $('#gemini_api_key');
            input.attr('type', this.checked ? 'text' : 'password');
        });

        // "Generate Title" button handler
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

        // "Generate Sections" button handler
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

        // "Generate Article" button handler
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
