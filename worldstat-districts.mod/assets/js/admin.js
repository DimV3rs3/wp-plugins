/**
 * Admin JavaScript for Districts extension
 */
(function($) {
    'use strict';
    
    console.log('Admin JS loaded');
    
    // ============================================
    // ИМПОРТ РАЙОНОВ
    // ============================================
    
    $(document).on('click', '.wsdistricts-start-import', function() {
        var file = $('#wsdistricts-csv-file')[0].files[0];
        if (!file) { alert('Выберите файл'); return; }
        
        var formData = new FormData();
        formData.append('action', 'wsdistricts_upload');
        formData.append('nonce', wsdistrictsAdmin.nonce);
        formData.append('csv_file', file);
        
        $('#wsdistricts-progress').show();
        
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    processDistrictsBatch(res.data.file, 0, res.data.total);
                } else { alert('Ошибка: ' + res.data); }
            },
            error: function(xhr, status, error) {
                alert('Ошибка: ' + error);
                $('#wsdistricts-progress').hide();
            }
        });
    });
    
    function processDistrictsBatch(file, offset, total) {
        var update = $('#wsdistricts-update').is(':checked') ? 1 : 0;
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wsdistricts_process_batch',
                nonce: wsdistrictsAdmin.nonce,
                file: file, offset: offset,
                batch_size: 50, update: update
            },
            success: function(res) {
                if (res.success) {
                    $('#wsdistricts-stat-imported').text(parseInt($('#wsdistricts-stat-imported').text()) + (res.data.imported || 0));
                    $('#wsdistricts-stat-updated').text(parseInt($('#wsdistricts-stat-updated').text()) + (res.data.updated || 0));
                    $('#wsdistricts-stat-skipped').text(parseInt($('#wsdistricts-stat-skipped').text()) + (res.data.skipped || 0));
                    $('#wsdistricts-stats').show();
                    
                    var percent = Math.min(100, Math.round((offset + 50) / total * 100));
                    $('#wsdistricts-progress-fill').css('width', percent + '%');
                    
                    if (offset + 50 < total) {
                        processDistrictsBatch(file, offset + 50, total);
                    } else {
                        alert('Импорт районов завершен!');
                        location.reload();
                    }
                } else {
                    alert('Ошибка: ' + (res.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Ошибка: ' + error);
            }
        });
    }
    
    // ============================================
    // ИМПОРТ КАЧЕСТВА ВОЗДУХА
    // ============================================
    
    $(document).on('click', '.wsair-start-import', function() {
        var file = $('#wsair-csv-file')[0].files[0];
        if (!file) { alert('Выберите файл'); return; }
        
        var formData = new FormData();
        formData.append('action', 'wsair_prepare');
        formData.append('nonce', wsdistrictsAdmin.nonce);
        formData.append('csv_file', file);
        
        $('#wsair-progress').show();
        
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    processAirBatch(res.data.file, 0, res.data.total);
                } else { alert('Ошибка: ' + res.data); }
            },
            error: function(xhr, status, error) {
                alert('Ошибка: ' + error);
                $('#wsair-progress').hide();
            }
        });
    });
    
    function processAirBatch(file, offset, total) {
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wsair_process_batch',
                nonce: wsdistrictsAdmin.nonce,
                file: file, offset: offset, batch_size: 100
            },
            success: function(res) {
                if (res.success) {
                    $('#wsair-stat-imported').text(parseInt($('#wsair-stat-imported').text()) + (res.data.imported || 0));
                    $('#wsair-stat-updated').text(parseInt($('#wsair-stat-updated').text()) + (res.data.updated || 0));
                    $('#wsair-stat-skipped').text(parseInt($('#wsair-stat-skipped').text()) + (res.data.skipped || 0));
                    $('#wsair-stats').show();
                    
                    var percent = Math.min(100, Math.round((offset + 100) / total * 100));
                    $('#wsair-progress-fill').css('width', percent + '%');
                    
                    if (offset + 100 < total) {
                        processAirBatch(file, offset + 100, total);
                    } else {
                        alert('Импорт качества воздуха завершен!');
                        location.reload();
                    }
                } else {
                    alert('Ошибка: ' + (res.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Ошибка: ' + error);
            }
        });
    }
    
    // ============================================
    // ИМПОРТ ДАННЫХ О ПРЕСТУПНОСТИ
    // ============================================
    
    $(document).on('click', '.wscrime-start-import', function(e) {
        e.preventDefault();
        console.log('Кнопка импорта преступности нажата');
        
        var file = $('#wscrime-csv-file')[0].files[0];
        if (!file) {
            alert('Пожалуйста, выберите CSV файл с данными о преступности');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'wscrime_import');
        formData.append('nonce', wsdistrictsAdmin.nonce);
        formData.append('csv_file', file);
        
        $('#wscrime-progress').show();
        $('#wscrime-stats').hide();
        $('#wscrime-analysis-result').hide();
        
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 60000,
            success: function(response) {
                if (response.success) {
                    $('#wscrime-stat-imported').text(response.data.imported || 0);
                    $('#wscrime-stat-updated').text(response.data.updated || 0);
                    $('#wscrime-stat-skipped').text(response.data.skipped || 0);
                    $('#wscrime-stats').show();
                    $('#wscrime-progress-fill').css('width', '100%');
                    
                    if (response.data.analysis) {
                        $('#wscrime-analysis-result').html('<strong>Результаты анализа:</strong><br>' + response.data.analysis.message).show();
                    }
                    
                    setTimeout(function() { location.reload(); }, 3000);
                } else {
                    alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                    $('#wscrime-progress').hide();
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка AJAX:', error);
                alert('Ошибка при импорте: ' + error);
                $('#wscrime-progress').hide();
            }
        });
    });
    
    // ============================================
    // ИМПОРТ ДАННЫХ ПЕШЕХОДНОЙ МОБИЛЬНОСТИ
    // ============================================
    
    $(document).on('click', '.wspedestrian-start-import', function(e) {
        e.preventDefault();
        console.log('Кнопка импорта пешеходных данных НАЖАТА!');
        
        var fileInput = $('#wspedestrian-csv-file')[0];
        if (!fileInput) {
            alert('Элемент выбора файла не найден');
            return;
        }
        
        var file = fileInput.files[0];
        if (!file) {
            alert('Пожалуйста, выберите CSV файл с данными пешеходной мобильности');
            return;
        }
        
        console.log('Файл выбран:', file.name, 'размер:', file.size);
        
        var formData = new FormData();
        formData.append('action', 'wspedestrian_import');
        formData.append('nonce', wsdistrictsAdmin.nonce);
        formData.append('csv_file', file);
        
        $('#wspedestrian-progress').show();
        $('#wspedestrian-stats').hide();
        $('#wspedestrian-analysis-result').hide();
        $('#wspedestrian-progress-fill').css('width', '0%');
        
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 60000,
            success: function(response) {
                console.log('Ответ сервера:', response);
                if (response.success) {
                    $('#wspedestrian-progress-fill').css('width', '100%');
                    
                    $('#wspedestrian-stat-imported').text(response.data.imported || 0);
                    $('#wspedestrian-stat-updated').text(response.data.updated || 0);
                    $('#wspedestrian-stat-skipped').text(response.data.skipped || 0);
                    $('#wspedestrian-stats').show();
                    
                    if (response.data.analysis) {
                        var msg = response.data.analysis.message || '';
                        $('#wspedestrian-analysis-result').html('<strong>Результаты анализа:</strong><br>' + msg).show();
                    }
                    
                    alert('Импорт завершен! Импортировано: ' + (response.data.imported || 0));
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    alert('Ошибка: ' + (response.data || 'Неизвестная ошибка'));
                    $('#wspedestrian-progress').hide();
                }
            },
            error: function(xhr, status, error) {
                console.error('Ошибка AJAX:', error);
                alert('Ошибка при импорте: ' + error);
                $('#wspedestrian-progress').hide();
            }
        });
    });
    
    // ============================================
    // ML АНАЛИЗ
    // ============================================
    
    $(document).on('click', '#wsair-run-analysis', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Анализ...');
        $('#wsair-analysis-status').text('Запуск ML анализа...');
        
        $.ajax({
            url: wsdistrictsAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'wsair_run_analysis',
                nonce: wsdistrictsAdmin.nonce
            },
            success: function(res) {
                if (res.success) {
                    $('#wsair-analysis-status').html('✅ ' + (res.data.message || 'Анализ завершен'));
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    $('#wsair-analysis-status').html('❌ Ошибка: ' + (res.data || 'Unknown'));
                }
                $btn.prop('disabled', false).text('Запустить ML анализ');
            },
            error: function(xhr, status, error) {
                $('#wsair-analysis-status').html('❌ Ошибка: ' + error);
                $btn.prop('disabled', false).text('Запустить ML анализ');
            }
        });
    });
    
    // ============================================
    // УДАЛЕНИЕ
    // ============================================
    
    $(document).on('click', '#wsdistricts-delete-all', function() {
        if (confirm('Удалить все районы?')) {
            $.ajax({
                url: wsdistrictsAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'wsdistricts_delete_all', nonce: wsdistrictsAdmin.nonce },
                success: function(res) { if (res.success) location.reload(); },
                error: function() { alert('Ошибка удаления'); }
            });
        }
    });
    
    $(document).on('click', '#wsair-delete-all', function() {
        if (confirm('Удалить данные о качестве воздуха?')) {
            $.ajax({
                url: wsdistrictsAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'wsair_delete_all', nonce: wsdistrictsAdmin.nonce },
                success: function(res) { if (res.success) location.reload(); },
                error: function() { alert('Ошибка удаления'); }
            });
        }
    });
    
    $(document).on('click', '#wscrime-delete-all', function() {
        if (confirm('Удалить данные о преступности?')) {
            $.ajax({
                url: wsdistrictsAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'wscrime_delete_all', nonce: wsdistrictsAdmin.nonce },
                success: function(res) { if (res.success) location.reload(); },
                error: function() { alert('Ошибка удаления'); }
            });
        }
    });
    
    $(document).on('click', '#wspedestrian-delete-all', function() {
        if (confirm('Удалить данные о пешеходной мобильности?')) {
            $.ajax({
                url: wsdistrictsAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'wspedestrian_delete_all', nonce: wsdistrictsAdmin.nonce },
                success: function(res) { if (res.success) location.reload(); },
                error: function() { alert('Ошибка удаления'); }
            });
        }
    });
    
    console.log('Все обработчики зарегистрированы');
    
})(jQuery);