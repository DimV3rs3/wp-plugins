jQuery(document).ready(function($) {
    console.log('WSZ Admin JS loaded');
    
    // Простое подтверждение для кнопки удаления всех файлов
    $('#wsz-delete-all').click(function() {
        return confirm('Удалить все CSV файлы?');
    });
});