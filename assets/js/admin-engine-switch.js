jQuery(document).ready(function($) {
    $('#trp-translation-engines').on('change', function() {
        var selectedEngine = $(this).val();
        $('.trp-engine').hide();

        if (selectedEngine === 'hhgfotr_gemini' || selectedEngine === 'hhg_gemini') {
            $('#hhgfotr_gemini').show();
        } else if (selectedEngine === 'hhgfotr_hunyuan' || selectedEngine === 'hhg_hunyuan') {
            $('#hhgfotr_hunyuan').show();
        } else if (selectedEngine === 'hhgfotr_openai' || selectedEngine === 'hhg_openai') {
            $('#hhgfotr_openai').show();
        } else if (selectedEngine === 'hhgfotr_zhipu' || selectedEngine === 'hhg_zhipu') {
            $('#hhgfotr_zhipu').show();
        } else if (selectedEngine === 'hhgfotr_yandex' || selectedEngine === 'hhg_yandex') {
            $('#hhgfotr_yandex').show();
        }
    });

    var currentEngine = $('#trp-translation-engines').val();
    if (currentEngine === 'hhgfotr_gemini' || currentEngine === 'hhg_gemini') {
        $('#hhgfotr_gemini').show();
    } else if (currentEngine === 'hhgfotr_hunyuan' || currentEngine === 'hhg_hunyuan') {
        $('#hhgfotr_hunyuan').show();
    } else if (currentEngine === 'hhgfotr_openai' || currentEngine === 'hhg_openai') {
        $('#hhgfotr_openai').show();
    } else if (currentEngine === 'hhgfotr_zhipu' || currentEngine === 'hhg_zhipu') {
        $('#hhgfotr_zhipu').show();
    } else if (currentEngine === 'hhgfotr_yandex' || currentEngine === 'hhg_yandex') {
        $('#hhgfotr_yandex').show();
    }
});