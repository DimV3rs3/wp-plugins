/**
 * WorldStat Cities — Admin import JS.
 *
 * Universal AJAX-driven batch import with progress bar.
 * Supports 3 CSV types: main, br1 (Blocks & Roads Table 1), br2 (Table 2).
 */
(function($) {
    'use strict';

    /**
     * AJAX action mapping per import type.
     */
    var ACTIONS = {
        main: { upload: 'wscities_upload',     batch: 'wscities_process_batch' },
        br1:  { upload: 'wscities_upload_br1', batch: 'wscities_process_batch_br1' },
        br2:  { upload: 'wscities_upload_br2', batch: 'wscities_process_batch_br2' },
        greenspace: { upload: 'wscities_upload_greenspace', batch: 'wscities_process_batch_greenspace' }
    };

    /**
     * Per-type state objects.
     */
    var states = {};

    /** All upload types support conflict scan when «Обновлять существующие» is checked. */
    function supportsConflictScan(type) {
        return !!ACTIONS[type];
    }

    var conflictModal = {
        type: null,
        onContinue: null
    };

    function getState(type) {
        if (!states[type]) {
            states[type] = {
                file: '', total: 0, offset: 0,
                imported: 0, updated: 0, skipped: 0,
                errors: [], running: false,
                resolutions: { cities: {} }
            };
        }
        return states[type];
    }

    function prefix(type) {
        return '#wscities-' + type;
    }

    function resetUI(type) {
        var s = getState(type);
        s.file = ''; s.total = 0; s.offset = 0;
        s.imported = 0; s.updated = 0; s.skipped = 0;
        s.errors = []; s.running = false;
        s.resolutions = { cities: {} };

        var p = prefix(type);
        $(p + '-progress').hide();
        $(p + '-result').hide();
        $(p + '-stats').hide();
        $(p + '-errors').hide();
        $(p + '-error-list').empty();
        $(p + '-progress-fill').css('width', '0%');
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hideConflictModal() {
        var $m = $('#wscities-conflict-modal');
        $m.hide().attr('aria-hidden', 'true');
        conflictModal.type = null;
        conflictModal.onContinue = null;
    }

    function collectResolutionsFromModal() {
        var resolutions = { cities: {} };
        $('#wscities-conflict-list .wscities-conflict-field').each(function() {
            var $row = $(this);
            var cityId = String($row.data('city-id'));
            var fieldKey = String($row.data('field-key'));
            var choice = $row.find('input[type=radio]:checked').val() || 'replace';
            if (!resolutions.cities[cityId]) {
                resolutions.cities[cityId] = {};
            }
            resolutions.cities[cityId][fieldKey] = choice;
        });
        return resolutions;
    }

    function renderConflictModal(conflicts, truncated) {
        var html = '';
        conflicts.forEach(function(city) {
            html += '<div class="wscities-conflict-city">';
            html += '<h3 class="wscities-conflict-city__title">' + escapeHtml(city.city_name);
            if (city.country) {
                html += ' <span class="wscities-conflict-city__country">(' + escapeHtml(city.country) + ')</span>';
            }
            html += '</h3><table class="widefat striped wscities-conflict-table"><thead><tr>';
            html += '<th>Поле</th><th>В базе</th><th>В файле</th><th>Действие</th></tr></thead><tbody>';
            (city.fields || []).forEach(function(field) {
                var uid = 'cf-' + city.city_id + '-' + field.key.replace(/[^a-z0-9_-]/gi, '_');
                html += '<tr class="wscities-conflict-field" data-city-id="' + city.city_id + '" data-field-key="' + escapeHtml(field.key) + '">';
                html += '<td>' + escapeHtml(field.label || field.key) + '</td>';
                html += '<td><code>' + escapeHtml(field.old) + '</code></td>';
                html += '<td><code>' + escapeHtml(field.new) + '</code></td>';
                html += '<td class="wscities-conflict-choices">';
                html += '<label><input type="radio" name="' + uid + '" value="keep" /> Старое</label> ';
                html += '<label><input type="radio" name="' + uid + '" value="replace" checked /> Новое</label>';
                html += '</td></tr>';
            });
            html += '</tbody></table></div>';
        });
        $('#wscities-conflict-list').html(html);

        var $trunc = $('#wscities-conflict-truncated');
        if (truncated) {
            $trunc.text('Показаны первые ' + conflicts.length + ' городов с отличиями. Остальные при импорте обновятся по выбору «Заменить все новыми», если не указано иное.').show();
        } else {
            $trunc.hide().text('');
        }
    }

    function showConflictModal(type, conflicts, truncated, onContinue) {
        conflictModal.type = type;
        conflictModal.onContinue = onContinue;
        renderConflictModal(conflicts, truncated);
        var $m = $('#wscities-conflict-modal');
        $m.show().attr('aria-hidden', 'false');
    }

    function scanConflictsThenImport(type, callback) {
        var s = getState(type);
        var p = prefix(type);
        var update = $(p + '-update').is(':checked');

        if (!update || !supportsConflictScan(type)) {
            callback({});
            return;
        }

        $(p + '-progress-text').text('Проверка отличий от данных в базе...');

        $.post(wscitiesAdmin.ajaxUrl, {
            action: 'wscities_scan_conflicts',
            nonce: wscitiesAdmin.nonce,
            file: s.file,
            import_type: type
        }, function(res) {
            if (!res.success) {
                callback({});
                return;
            }
            var data = res.data || {};
            var conflicts = data.conflicts || [];
            if (!conflicts.length) {
                callback({});
                return;
            }
            showConflictModal(type, conflicts, !!data.truncated, callback);
        }).fail(function() {
            callback({});
        });
    }

    /* ── Upload Phase ──────────────────────────────── */
    async function uploadCSV(type) {
        var file = $(prefix(type) + '-csv-file')[0].files[0];
        if (!file) { alert('Выберите CSV файл.'); return; }

        var actions = ACTIONS[type];
        if (!actions) { alert('Неизвестный тип импорта: ' + type); return; }

        var formData = new FormData();
        formData.append('action', actions.upload);
        formData.append('nonce', wscitiesAdmin.nonce);
        formData.append('csv_file', file);

        resetUI(type);
        var s = getState(type);
        var p = prefix(type);

        $(p + '-progress').show();
        $(p + '-progress-text').text('Загрузка файла...');
        s.running = true;

        // BR2 can be very large; upload in chunks to avoid server limits.
        if (type === 'br2') {
            uploadCSVChunkedBR2(file, type);
            return;
        }

        $.ajax({
            url: wscitiesAdmin.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    s.file  = res.data.file;
                    s.total = res.data.total;
                    $(p + '-progress-text').text(
                        'Файл загружен. Строк данных: ' + s.total + '.'
                    );
                    $(p + '-stats').show();
                    scanConflictsThenImport(type, function(resolutions) {
                        s.resolutions = resolutions || { cities: {} };
                        $(p + '-progress-text').text(
                            'Импорт... (строк: ' + s.total + ')'
                        );
                        processNextBatch(type);
                    });
                } else {
                    alert('Ошибка: ' + (res.data || 'Unknown'));
                    s.running = false;
                }
            },
            error: function(xhr, status, errorThrown) {
                var details = '';
                var code = xhr && xhr.status ? xhr.status : 0;

                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    details = String(xhr.responseJSON.data);
                } else if (xhr && xhr.responseText) {
                    var text = String(xhr.responseText).trim();
                    if (text) {
                        try {
                            var parsed = JSON.parse(text);
                            if (parsed && parsed.data) {
                                details = String(parsed.data);
                            } else {
                                details = text.slice(0, 400);
                            }
                        } catch (e) {
                            details = text.slice(0, 400);
                        }
                    }
                }

                if (!details) {
                    if (status === 'timeout') {
                        details = 'Таймаут запроса при загрузке.';
                    } else if (errorThrown) {
                        details = String(errorThrown);
                    } else {
                        details = 'Нет ответа от сервера.';
                    }
                }

                alert('Ошибка загрузки (HTTP ' + code + '): ' + details);
                s.running = false;
            }
        });
    }

    function uploadCSVChunkedBR2(file, type) {
        var s = getState(type);
        var p = prefix(type);
        var chunkSize = 1024 * 1024; // 1 MB
        var totalChunks = Math.ceil(file.size / chunkSize);
        var uploadId = (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10));
        var index = 0;

        function sendNextChunk() {
            if (!s.running) return;
            if (index >= totalChunks) return;

            var start = index * chunkSize;
            var end = Math.min(start + chunkSize, file.size);
            var blob = file.slice(start, end);
            var fd = new FormData();
            fd.append('action', 'wscities_upload_br2_chunk');
            fd.append('nonce', wscitiesAdmin.nonce);
            fd.append('upload_id', uploadId);
            fd.append('chunk_index', index);
            fd.append('total_chunks', totalChunks);
            fd.append('chunk', blob, file.name + '.part' + index);

            $.ajax({
                url: wscitiesAdmin.ajaxUrl,
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                timeout: 120000,
                success: function(res) {
                    if (!res.success) {
                        alert('Ошибка: ' + (res.data || 'Unknown'));
                        s.running = false;
                        return;
                    }

                    index++;
                    var pct = Math.min(100, Math.round(index / totalChunks * 100));
                    $(p + '-progress-fill').css('width', pct + '%');
                    $(p + '-progress-pct').text(pct + '%');
                    $(p + '-progress-text').text('Загрузка файла по частям... ' + pct + '%');

                    if (res.data && res.data.done) {
                        s.file  = res.data.file;
                        s.total = res.data.total;
                        $(p + '-progress-text').text(
                            'Файл загружен. Строк данных: ' + s.total + '.'
                        );
                        $(p + '-stats').show();
                        scanConflictsThenImport(type, function(resolutions) {
                            s.resolutions = resolutions || { cities: {} };
                            $(p + '-progress-text').text(
                                'Импорт... (строк: ' + s.total + ')'
                            );
                            processNextBatch(type);
                        });
                        return;
                    }

                    sendNextChunk();
                },
                error: function(xhr, status, errorThrown) {
                    var msg = 'Ошибка сети при загрузке чанка.';
                    if (xhr && xhr.status) msg += ' HTTP ' + xhr.status + '.';
                    if (errorThrown) msg += ' ' + errorThrown;
                    $(p + '-progress-text').text(msg);
                    s.running = false;
                }
            });
        }

        sendNextChunk();
    }

    /* ── Batch Processing ──────────────────────────── */
    function processNextBatch(type) {
        var s = getState(type);
        if (!s.running) return;

        var actions = ACTIONS[type];
        var p = prefix(type);
        var update = $(p + '-update').is(':checked') ? 1 : 0;

        $.ajax({
            url: wscitiesAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: actions.batch,
                nonce: wscitiesAdmin.nonce,
                file: s.file,
                offset: s.offset,
                batch_size: parseInt(wscitiesAdmin.batchSize),
                update: update,
                resolutions: JSON.stringify(s.resolutions || { cities: {} })
            },
            timeout: 120000,
            success: function(res) {
                if (!res.success) {
                    $(p + '-progress-text').text('Ошибка: ' + (res.data || 'Unknown'));
                    s.running = false;
                    return;
                }

                var d = res.data;
                s.imported += d.imported || 0;
                s.updated  += d.updated || 0;
                s.skipped  += d.skipped || 0;
                s.offset   += parseInt(wscitiesAdmin.batchSize);

                if (d.errors && d.errors.length) {
                    s.errors = s.errors.concat(d.errors);
                    $(p + '-errors').show();
                    d.errors.forEach(function(e) {
                        $(p + '-error-list').append('<li>' + e + '</li>');
                    });
                }

                // Update UI
                var pct = Math.min(100, Math.round(s.offset / s.total * 100));
                $(p + '-progress-fill').css('width', pct + '%');
                $(p + '-progress-pct').text(pct + '%');
                $(p + '-stat-imported').text(s.imported);
                $(p + '-stat-updated').text(s.updated);
                $(p + '-stat-skipped').text(s.skipped);
                $(p + '-progress-text').text(
                    'Обработано ' + Math.min(s.offset, s.total) + ' из ' + s.total
                );

                if (s.offset >= s.total) {
                    finishImport(type);
                } else {
                    processNextBatch(type);
                }
            },
            error: function(xhr, status) {
                if (status === 'timeout') {
                    processNextBatch(type);
                } else {
                    $(p + '-progress-text').text('Ошибка сети. Повторная попытка через 3с...');
                    setTimeout(function() { processNextBatch(type); }, 3000);
                }
            }
        });
    }

    /* ── Finish ────────────────────────────────────── */
    function finishImport(type) {
        var s = getState(type);
        var p = prefix(type);

        s.running = false;
        $(p + '-progress-fill').css('width', '100%');
        $(p + '-progress-pct').text('100%');

        var msg = 'Импорт завершён! Импортировано: ' + s.imported +
                  ', обновлено: ' + s.updated +
                  ', пропущено: ' + s.skipped;

        if (s.errors.length > 0) {
            msg += ', ошибок: ' + s.errors.length;
        }

        $(p + '-result').show().find(p + '-result-text').text(msg);
        $(p + '-progress-text').text('Готово!');

        // Update total count only for main import
        if (type === 'main') {
            var currentCount = parseInt($('#wscities-count').text()) || 0;
            $('#wscities-count').text(currentCount + s.imported);
        }
    }

    /* ── Delete All ────────────────────────────────── */
    function deleteAll() {
        if (!confirm('Удалить ВСЕ импортированные города? Это действие нельзя отменить.')) return;

        $.post(wscitiesAdmin.ajaxUrl, {
            action: 'wscities_delete_all',
            nonce: wscitiesAdmin.nonce
        }, function(res) {
            if (res.success) {
                alert('Удалено городов: ' + res.data.deleted);
                $('#wscities-count').text('0');
                location.reload();
            } else {
                alert('Ошибка: ' + (res.data || 'Unknown'));
            }
        });
    }

    function mergeDuplicates() {
        if (!confirm('Объединить дубли городов (например, Saint/St.)? Это удалит лишние дубликаты и сохранит одну запись.')) return;

        $.post(wscitiesAdmin.ajaxUrl, {
            action: 'wscities_merge_duplicates',
            nonce: wscitiesAdmin.nonce
        }, function(res) {
            if (res.success) {
                var d = res.data || {};
                var msg = 'Готово. Групп дублей: ' + (d.groups || 0) + ', удалено записей: ' + (d.deleted || 0) + '.';
                alert(msg);
                location.reload();
            } else {
                alert('Ошибка: ' + (res.data || 'Unknown'));
            }
        });
    }

    function recalcErgonomics() {
        if (!confirm('Пересчитать эргономику для всех городов? Идёт пакетами по ' + (wscitiesAdmin.ergoRecalcBatchSize || 100) + ' городов (курсор по ID, без медленного OFFSET) — окно можно не закрывать до сообщения о завершении.')) return;

        var $btn = $('#wscities-recalc-ergonomics');
        var oldText = $btn.text();
        var batchSize = parseInt(wscitiesAdmin.ergoRecalcBatchSize, 10) || 100;
        if (batchSize < 1) batchSize = 100;
        if (batchSize > 400) batchSize = 400;

        var afterId = 0;
        var cumProcessed = 0;
        var cumUpdated = 0;
        var cumEmpty = 0;
        var total = 0;

        function finishOk() {
            alert(
                'Пересчёт завершён.\n' +
                'Всего городов: ' + total + '\n' +
                'С обновлённым индексом: ' + cumUpdated + '\n' +
                'Без достаточных данных: ' + cumEmpty
            );
            $btn.prop('disabled', false).text(oldText);
        }

        function runBatch() {
            $btn.prop('disabled', true).text(
                total ? ('Пересчёт… ' + Math.min(cumProcessed, total) + ' / ' + total) : 'Пересчёт…'
            );
            $.post(wscitiesAdmin.ajaxUrl, {
                action: 'wscities_recalc_ergonomics',
                nonce: wscitiesAdmin.nonce,
                after_id: afterId,
                batch_size: batchSize
            }, function(res) {
                if (!res.success) {
                    alert('Ошибка: ' + (res.data || 'Unknown'));
                    $btn.prop('disabled', false).text(oldText);
                    return;
                }
                var d = res.data || {};
                total = d.total != null ? parseInt(d.total, 10) : total;
                cumUpdated += (d.batch_updated != null ? parseInt(d.batch_updated, 10) : 0);
                cumEmpty += (d.batch_empty != null ? parseInt(d.batch_empty, 10) : 0);
                var nProc = d.processed != null ? parseInt(d.processed, 10) : 0;
                cumProcessed += nProc;
                afterId = d.next_after_id != null ? parseInt(d.next_after_id, 10) : afterId;

                if (d.done) {
                    finishOk();
                } else {
                    runBatch();
                }
            }).fail(function(xhr, textStatus, errorThrown) {
                var msg = 'Пересчёт прерван после ID ' + afterId + ' (обработано записей: ' + cumProcessed + ').';
                var raw = (xhr && xhr.responseText) ? String(xhr.responseText).trim() : '';

                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    msg += '\n' + xhr.responseJSON.data;
                } else if (raw === '-1' || raw === '0') {
                    msg += '\nСессия или проверка безопасности устарели — обновите страницу (Ctrl+F5) и попробуйте снова.';
                } else if (textStatus === 'parsererror') {
                    msg += '\nОтвет сервера не JSON';
                    if (xhr && xhr.status) {
                        msg += ' (HTTP ' + xhr.status + ')';
                    }
                    msg += '. Частые причины: ошибка PHP (см. wp-content/debug.log при WP_DEBUG_LOG), вывод до JSON, обрыв соединения.';
                    if (raw.length) {
                        msg += '\nФрагмент ответа: ' + raw.substring(0, 400).replace(/\s+/g, ' ');
                    }
                } else if (xhr && xhr.status) {
                    msg += '\nHTTP ' + xhr.status + (xhr.statusText ? ' ' + xhr.statusText : '');
                    if (xhr.status === 0) {
                        msg += '\nНет ответа сервера — проверьте сеть, max_execution_time или уменьшите пакет (фильтр wscities_recalc_ergonomics_batch_size).';
                    } else if (raw.length && raw.indexOf('{') !== 0) {
                        msg += '\n' + raw.substring(0, 400).replace(/\s+/g, ' ');
                    }
                } else if (errorThrown) {
                    msg += '\n' + errorThrown;
                }
                alert(msg);
                $btn.prop('disabled', false).text(oldText);
            });
        }

        runBatch();
    }

    /* ── Init ──────────────────────────────────────── */
    $(document).ready(function() {
        // Universal import button handler
        $(document).on('click', '.wscities-start-import', function() {
            var type = $(this).data('type');
            uploadCSV(type);
        });

        $('#wscities-delete-all').on('click', deleteAll);
        $('#wscities-merge-duplicates').on('click', mergeDuplicates);
        $('#wscities-recalc-ergonomics').on('click', recalcErgonomics);

        $('#wscities-conflict-keep-all').on('click', function() {
            $('#wscities-conflict-list input[value=keep]').prop('checked', true);
        });
        $('#wscities-conflict-replace-all').on('click', function() {
            $('#wscities-conflict-list input[value=replace]').prop('checked', true);
        });
        $('#wscities-conflict-apply').on('click', function() {
            var type = conflictModal.type;
            var cb = conflictModal.onContinue;
            var resolutions = collectResolutionsFromModal();
            hideConflictModal();
            if (typeof cb === 'function') {
                cb(resolutions);
            }
        });
        $('#wscities-conflict-cancel').on('click', function() {
            var type = conflictModal.type;
            hideConflictModal();
            if (type) {
                var s = getState(type);
                s.running = false;
                $(prefix(type) + '-progress-text').text('Импорт отменён.');
            }
        });
        $('#wscities-conflict-modal .wscities-modal__backdrop').on('click', function() {
            $('#wscities-conflict-cancel').trigger('click');
        });
    });

})(jQuery);
