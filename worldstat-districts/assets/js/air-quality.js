/**
 * Air Quality Admin JavaScript
 */
(function($) {
    'use strict';

    const wsair = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#import-air-quality').on('click', this.importData.bind(this));
            $('#run-ml-analysis').on('click', this.runAnalysis.bind(this));
        },

        importData: function() {
            const file = $('#air-quality-file')[0].files[0];
            if (!file) {
                alert('Пожалуйста, выберите CSV файл');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'wsair_upload');
            formData.append('nonce', wsairAdmin.nonce);
            formData.append('csv_file', file);

            $('#import-progress').show();
            $('#import-result').hide();

            $.ajax({
                url: wsairAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#import-progress').hide();
                        $('#import-result').show().html(`
                            <div class="notice notice-success">
                                <p>Импорт завершен!</p>
                                <p>Импортировано: ${response.data.imported}</p>
                                <p>Обновлено: ${response.data.updated}</p>
                                <p>Пропущено: ${response.data.skipped}</p>
                                ${response.data.errors ? `<details><summary>Ошибки (${response.data.errors.length})</summary><ul>${response.data.errors.map(e => `<li>${e}</li>`).join('')}</ul></details>` : ''}
                            </div>
                        `);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        $('#import-progress').hide();
                        $('#import-result').show().html(`
                            <div class="notice notice-error">
                                <p>Ошибка: ${response.data}</p>
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#import-progress').hide();
                    $('#import-result').show().html(`
                        <div class="notice notice-error">
                            <p>Ошибка при загрузке файла</p>
                        </div>
                    `);
                }
            });
        },

        runAnalysis: function() {
            $('#run-ml-analysis').prop('disabled', true).text('Анализ...');
            $('#analysis-result').show().html('<p>Запуск ML анализа...</p>');

            $.ajax({
                url: wsairAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wsair_run_analysis',
                    nonce: wsairAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#analysis-result').html(`
                            <div class="notice notice-success">
                                <p>Анализ завершен!</p>
                                <p>${response.data.message || 'Результаты сохранены в базу данных'}</p>
                            </div>
                        `);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        $('#analysis-result').html(`
                            <div class="notice notice-error">
                                <p>Ошибка: ${response.data}</p>
                            </div>
                        `);
                    }
                    $('#run-ml-analysis').prop('disabled', false).text('Запустить анализ');
                },
                error: function() {
                    $('#analysis-result').html(`
                        <div class="notice notice-error">
                            <p>Ошибка при выполнении анализа</p>
                        </div>
                    `);
                    $('#run-ml-analysis').prop('disabled', false).text('Запустить анализ');
                }
            });
        }
    };

    $(document).ready(function() {
        wsair.init();
    });

})(jQuery);